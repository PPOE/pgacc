#!/bin/sh
# Deploys a pgacc build archive on a host set up by deploy/provision.sh
# (installed there as /usr/local/bin/pgacc-deploy). See deploy/README.md.
#
#   pgacc-deploy v<version>        a release tag, e.g. v2.1.0
#   pgacc-deploy <commit>          a commit (7 to 40 hex digits); only if
#                                  PGACC_ALLOW_COMMIT_DEPLOY=1 (staging)
#   pgacc-deploy --file <archive>  a local pgacc-<version>.tar.gz or
#                                  pgacc-<commit>.tar.gz with its .sha256 next
#                                  to it (testing)
#
# Runs as the deploy user (PGACC_USER, normally pgacc); sudo is used only for
# `systemctl reload <php-fpm>` (/etc/sudoers.d/pgacc-deploy). Nothing is built
# on the host: the archive already contains vendor/.
#
# Steps: download the archive and its .sha256 (or take the local file), check
# the checksum and the archive's entries, unpack it into releases/<id>, link
# shared/constants.php and shared/files, switch the current symlink
# atomically, reload php-fpm and request the login page (HTTP 200 and the
# login form). If that fails, switch back to the previous release and exit 1.
# Keeps the newest PGACC_KEEP_RELEASES releases. A release that is already
# current is left alone (no-op).
#
# There are no database migrations: pgacc has no migration mechanism. Steps a
# release needs are listed in UPGRADING.md and are done by hand.
set -eu

env_file=${PGACC_ENV_FILE:-/etc/pgacc/pgacc.env}

usage() {
  echo "Usage: pgacc-deploy v<version> | <commit> | --file <pgacc-...tar.gz>" >&2
  exit 2
}

say() { printf '%s\n' "deploy: $*"; }
die() {
  printf '%s\n' "deploy: $*" >&2
  exit 1
}
matches() { printf '%s\n' "$1" | grep -Eq "$2"; }

# --- Settings (written by provision.sh) ----------------------------------------

[ -f "$env_file" ] || die "no $env_file; run deploy/provision.sh first"
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in '' | '#'*) continue ;; esac
  key=${line%%=*}
  # shellcheck disable=SC2034 # used by the eval below
  value=${line#*=}
  case "$key" in
    PGACC_ROOT | PGACC_USER | PGACC_HOSTNAME | PGACC_HTTP_PORT | PGACC_PHP_FPM_SERVICE | \
      PGACC_GITHUB_REPO | PGACC_ALLOW_COMMIT_DEPLOY | PGACC_KEEP_RELEASES | \
      PGACC_RELEASE_BASE_URL | PGACC_GITHUB_API_URL)
      eval "$key=\$value"
      ;;
    *) die "unknown setting in $env_file: $key" ;;
  esac
done <"$env_file"

root=${PGACC_ROOT:?}
user=${PGACC_USER:?}
hostname=${PGACC_HOSTNAME:?}
port=${PGACC_HTTP_PORT:-80}
fpm_service=${PGACC_PHP_FPM_SERVICE:?}
repo=${PGACC_GITHUB_REPO:?}
allow_commit=${PGACC_ALLOW_COMMIT_DEPLOY:-0}
keep=${PGACC_KEEP_RELEASES:-5}
matches "$root" '^/[A-Za-z0-9._/-]*[A-Za-z0-9]$' || die "invalid PGACC_ROOT"
matches "$port" '^[0-9]{1,5}$' || die "invalid PGACC_HTTP_PORT"
matches "$keep" '^[1-9][0-9]*$' || die "invalid PGACC_KEEP_RELEASES"
matches "$repo" '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$' || die "invalid PGACC_GITHUB_REPO"

# --- Where archives come from (the only place that defines it) --------------------
#
# Release tag v<version>: the GitHub release of the tag (.github/workflows/
# release.yml, scripts/release.sh):
#   <base>/v<version>/pgacc-<version>.tar.gz and .sha256,
#   top directory pgacc-<version>/.
# Commit <sha> (40 hex digits): the rolling GitHub pre-release `staging`:
#   <base>/staging/pgacc-<sha>.tar.gz and .sha256, top directory pgacc-<sha>/.
# <base> is https://github.com/<PGACC_GITHUB_REPO>/releases/download unless
# PGACC_RELEASE_BASE_URL is set (testing). A short commit is resolved to the
# full one with the GitHub API (PGACC_GITHUB_API_URL, default
# https://api.github.com).
staging_release=staging
base_url=${PGACC_RELEASE_BASE_URL:-https://github.com/$repo/releases/download}
api_url=${PGACC_GITHUB_API_URL:-https://api.github.com}
for url in "$base_url" "$api_url"; do
  matches "$url" '^https?://[A-Za-z0-9._:/-]+$' || die "invalid URL: $url"
done

# Sets name (archive name without .tar.gz, also its top directory) and url
# for kind ($1: tag or commit) and id ($2: v<version> or the full sha).
archive_location() {
  case "$1" in
    tag)
      name=pgacc-${2#v}
      url=$base_url/$2/$name.tar.gz
      ;;
    commit)
      name=pgacc-$2
      url=$base_url/$staging_release/$name.tar.gz
      ;;
  esac
}

tag_regex='^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'
full_sha_regex='^[0-9a-f]{40}$'

# --- Arguments -------------------------------------------------------------------

[ $# -ge 1 ] || usage
local_file=
case "$1" in
  --file)
    [ $# -eq 2 ] || usage
    local_file=$2
    base=$(basename "$local_file")
    # The character check first: it also rejects newlines.
    case "$base" in *[!0-9A-Za-z.-]*) die "invalid archive name: $base" ;; esac
    stem=${base%.tar.gz}
    [ "$stem" != "$base" ] || die "the archive must be named pgacc-<version>.tar.gz or pgacc-<commit>.tar.gz"
    stem=${stem#pgacc-}
    if matches "$stem" "$full_sha_regex"; then
      kind=commit id=$stem
    elif matches "v$stem" "$tag_regex"; then
      kind=tag id=v$stem
    else
      die "the archive must be named pgacc-<version>.tar.gz or pgacc-<40-hex commit>.tar.gz: $base"
    fi
    ;;
  -*) usage ;;
  *)
    [ $# -eq 1 ] || usage
    arg=$1
    case "$arg" in *[!0-9A-Za-z.-]*) die "invalid argument (a tag v<version> or a commit)" ;; esac
    if matches "$arg" "$tag_regex"; then
      kind=tag id=$arg
    elif matches "$arg" '^[0-9a-fA-F]{7,40}$'; then
      kind=commit id=$(printf '%s' "$arg" | tr 'A-F' 'a-f')
    else
      die "not a release tag (v1.2.3, v1.2.3-rc.1) or a commit (7 to 40 hex digits): $arg"
    fi
    ;;
esac

if [ "$kind" = commit ] && [ "$allow_commit" != 1 ]; then
  die "commit deploys are disabled on this host (PGACC_ALLOW_COMMIT_DEPLOY=0); deploy a release tag"
fi

[ "$(id -un)" = "$user" ] || die "run as $user (sudo -u $user pgacc-deploy ...)"
umask 027

releases=$root/releases
log=$root/shared/logs/deploy.log
[ -d "$releases" ] || die "no $releases; run deploy/provision.sh first"
[ -f "$root/shared/constants.php" ] || die "no $root/shared/constants.php; run deploy/provision.sh first"
[ -d "$root/shared/files" ] || die "no $root/shared/files; run deploy/provision.sh first"

log_line() {
  printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"$log" || true
}

# One deploy at a time.
exec 9>"$root/.deploy.lock"
flock -n 9 || die "another deploy is running"

if [ "$kind" = commit ] && ! matches "$id" "$full_sha_regex"; then
  # Short commit: resolve it to the full one (the archive is named by it).
  case "$api_url" in https://*) proto='=https' ;; *) proto='=http,https' ;; esac
  full=$(curl -fsSL --proto "$proto" --max-time 30 -H 'Accept: application/vnd.github.sha' \
    "$api_url/repos/$repo/commits/$id") || die "could not resolve commit $id"
  full=$(printf '%s' "$full" | tr -d '\r\n')
  matches "$full" "$full_sha_regex" || die "could not resolve commit $id (unexpected answer)"
  case "$full" in "$id"*) ;; *) die "commit $id resolved to $full, which does not start with it" ;; esac
  say "commit $id is $full"
  id=$full
fi

archive_location "$kind" "$id"
release=$releases/$id
current_target=$(readlink "$root/current" 2>/dev/null || true)

if [ "$current_target" = "releases/$id" ] && [ -d "$release" ]; then
  say "$id is already the current release; nothing to do"
  exit 0
fi

work=
created=false
cleanup() {
  [ -z "$work" ] || rm -rf "$work"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

if [ -d "$release" ]; then
  say "$id is already unpacked; switching to it"
else
  work=$(mktemp -d "$releases/.incoming.XXXXXX")
  if [ -n "$local_file" ]; then
    [ -f "$local_file" ] && [ -f "$local_file.sha256" ] ||
      die "need $local_file and $local_file.sha256"
    cp "$local_file" "$work/$name.tar.gz"
    cp "$local_file.sha256" "$work/$name.tar.gz.sha256"
    source=$local_file
  else
    case "$base_url" in https://*) proto='=https' ;; *) proto='=http,https' ;; esac
    say "downloading $url"
    curl -fsSL --proto "$proto" --proto-redir "$proto" --max-time 300 -o "$work/$name.tar.gz" "$url" ||
      die "download failed: $url"
    curl -fsSL --proto "$proto" --proto-redir "$proto" --max-time 60 -o "$work/$name.tar.gz.sha256" "$url.sha256" ||
      die "download failed: $url.sha256"
    source=$url
  fi

  # The .sha256 line must name exactly this archive.
  expected=$(awk 'NR == 1 { print $1 }' "$work/$name.tar.gz.sha256")
  listed=$(awk 'NR == 1 { sub(/^\*/, "", $2); print $2 }' "$work/$name.tar.gz.sha256")
  matches "$expected" '^[0-9a-f]{64}$' || die "the .sha256 file has no SHA-256 checksum"
  [ "$listed" = "$name.tar.gz" ] || die "the .sha256 file is for '$listed', not $name.tar.gz"
  actual=$(sha256sum "$work/$name.tar.gz" | awk '{ print $1 }')
  [ "$actual" = "$expected" ] || die "checksum mismatch for $name.tar.gz"
  say "checksum ok ($actual)"

  # Only regular files and directories, all under the top directory, no "..".
  if tar -tvzf "$work/$name.tar.gz" | cut -c1 | grep -qv '^[-d]$'; then
    die "the archive contains entries other than files and directories"
  fi
  if tar -tzf "$work/$name.tar.gz" | grep -Ev "^$name/" | grep -q .; then
    die "the archive has entries outside $name/"
  fi
  if tar -tzf "$work/$name.tar.gz" | grep -Eq '(^|/)\.\.(/|$)'; then
    die "the archive has entries with '..'"
  fi
  tar -xzf "$work/$name.tar.gz" -C "$work" --no-same-owner --no-same-permissions
  unpacked=$work/$name
  [ -f "$unpacked/index.php" ] && [ -f "$unpacked/vendor/autoload.php" ] ||
    die "the archive has no index.php or vendor/autoload.php"
  if [ -e "$unpacked/constants.php" ] || [ -e "$unpacked/files" ]; then
    die "the archive contains constants.php or files/"
  fi

  ln -s "$root/shared/constants.php" "$unpacked/constants.php"
  ln -s "$root/shared/files" "$unpacked/files"
  sha=$actual
  cat >"$unpacked/.pgacc-release" <<EOF
kind=$kind
id=$id
sha256=$sha
source=$source
unpacked=$(date -u +%Y-%m-%dT%H:%M:%SZ)
EOF
  mv "$unpacked" "$release"
  created=true
  say "unpacked $name into $release"
  say "database migrations: none (pgacc has none; see UPGRADING.md for manual steps)"
fi

reload_fpm() {
  sudo -n /usr/bin/systemctl reload "$fpm_service"
}

# Switches current to releases/$1 (atomically: rename of a new symlink).
switch_to() {
  ln -sfn "releases/$1" "$root/.current.new"
  mv -T "$root/.current.new" "$root/current"
}

smoke_test() {
  i=0
  while [ "$i" -lt 5 ]; do
    i=$((i + 1))
    status=$(curl -sS -o "$root/.smoke-body" -w '%{http_code}' --max-time 20 \
      -H "Host: $hostname" -H 'X-Forwarded-Proto: https' \
      "http://127.0.0.1:$port/index.php?action=login" 2>/dev/null) || status=000
    if [ "$status" = 200 ] && grep -q 'name="pass"' "$root/.smoke-body"; then
      rm -f "$root/.smoke-body"
      return 0
    fi
    sleep 1
  done
  rm -f "$root/.smoke-body"
  say "smoke test failed: the login page answered HTTP $status"
  return 1
}

previous=${current_target#releases/}
[ -n "$current_target" ] && [ -d "$root/$current_target" ] || previous=

switch_to "$id"
touch "$release"
say "current -> releases/$id"
if reload_fpm && smoke_test; then
  log_line "deployed $kind $id (previous: ${previous:-none})"
  say "deployed $id (previous: ${previous:-none})"
else
  if [ -n "$previous" ]; then
    switch_to "$previous"
    reload_fpm || true
    if smoke_test; then
      say "rolled back to $previous"
    else
      say "rolled back to $previous, but its smoke test fails too"
    fi
  else
    rm -f "$root/current"
    say "no previous release; removed the current symlink"
  fi
  if [ "$created" = true ]; then
    rm -rf "$release"
  fi
  log_line "FAILED $kind $id; rolled back to ${previous:-nothing}"
  die "deploy of $id failed"
fi

# Keep the newest $keep releases (by activation time), never the current or
# the previous one.
# Release names are tags or hex commits (no spaces or newlines); the
# dot-named work directories are skipped.
count=0
find "$releases" -mindepth 1 -maxdepth 1 -type d ! -name '.*' -printf '%T@ %f\n' |
  sort -rn | cut -d' ' -f2 | while IFS= read -r entry; do
  count=$((count + 1))
  [ "$count" -gt "$keep" ] || continue
  [ "$entry" != "$id" ] && [ "$entry" != "$previous" ] || continue
  rm -rf "${releases:?}/$entry"
  say "removed old release $entry"
done
