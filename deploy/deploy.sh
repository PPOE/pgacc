#!/bin/sh
# Deploys a pgacc release on a host set up by deploy/ansible (installed there
# as /usr/local/bin/pgacc-deploy). See deploy/README.md.
#
#   sudo -u pgacc-deploy pgacc-deploy v<version>
#   sudo -u pgacc-deploy pgacc-deploy --file <dir>/pgacc-<version>.tar.gz
#
# Verifies and unpacks the release archive, switches /srv/pgacc/current
# atomically, smoke-tests the login page, the allow list and the internal
# sync listener (if it is set up) and rolls back if that fails.
set -eu

root=/srv/pgacc
releases=$root/releases
etc=/etc/pgacc

say() { echo "deploy: $*"; }
die() { echo "deploy: $*" >&2 && exit 1; }

file=
tag=${1:-}
if [ $# -eq 2 ] && [ "$1" = --file ]; then
  file=$2 base=$(basename -- "$2")
  tag=v${base#pgacc-} && tag=${tag%.tar.gz}
elif [ $# -ne 1 ]; then
  die "usage: pgacc-deploy v<version> | --file pgacc-<version>.tar.gz"
fi
# The character check first: it also rejects newlines.
case "$tag" in *[!0-9A-Za-z.-]*) die "invalid tag: $tag" ;; esac
echo "$tag" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$' ||
  die "not a release tag v1.2.3 or v1.2.3-rc.1: $tag"
name=pgacc-${tag#v}
[ -z "$file" ] || [ "$base" = "$name.tar.gz" ] || die "the archive must be named pgacc-<version>.tar.gz"

[ "$(id -un)" = pgacc-deploy ] || die "run as pgacc-deploy (sudo -u pgacc-deploy pgacc-deploy ...)"
[ -f "$root/shared/constants.php" ] || die "no $root/shared/constants.php"
umask 022
exec 9>"$releases/.lock"
flock -n 9 || die "another deploy is running"

switch_to() {
  ln -sfn "releases/$1" "$root/.current.new"
  mv -T "$root/.current.new" "$root/current"
}
status() {
  curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -H 'X-Forwarded-Proto: https' "http://127.0.0.1$1"
}
# Reloads PHP-FPM, then expects the login form (a 2xx answer) within 5 tries
# and, unless "login" is given, a 404 for two paths inside public/ that the
# allow list does not serve and, with $etc/sync-url (written by the playbook
# while the internal sync listener is on), a 403 from that listener.
check() {
  sudo -n /usr/bin/systemctl reload php8.5-fpm.service || return 1
  for _ in 1 2 3 4 5; do
    if curl -fsS --max-time 20 -H 'X-Forwarded-Proto: https' 'http://127.0.0.1/index.php?action=login' |
      grep -q 'name="pass"'; then
      [ "${1:-}" = login ] && return 0
      if [ "$(status /js/README.md)" != 404 ] || [ "$(status /index.php/x)" != 404 ]; then
        say "the allow list is not active: /js/README.md or /index.php/x does not answer 404"
        return 1
      fi
      # The internal sync listener, if the playbook set it up: without the
      # token, internal/mbapi.php answers 403.
      [ -f "$etc/sync-url" ] || return 0
      [ "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 --proto =https --cacert "$etc/sync-tls.crt" \
        "$(cat "$etc/sync-url")")" = 403 ] && return 0
      say "the internal sync listener does not answer 403 without the token: $(cat "$etc/sync-url")"
      return 1
    fi
    sleep 1
  done
  return 1
}

release=$releases/$tag
previous=$(readlink "$root/current" 2>/dev/null || true)
previous=${previous#releases/}
[ -n "$previous" ] && [ -d "$releases/$previous" ] || previous=
if [ "$previous" = "$tag" ]; then
  check || die "$tag is already the current release, but its smoke test fails"
  say "$tag is already the current release"
  exit 0
fi

work=
trap '[ -z "$work" ] || rm -rf "$work"' EXIT
created=false
if [ ! -d "$release" ]; then
  work=$(mktemp -d "$releases/.incoming.XXXXXX")
  archive=$work/$name.tar.gz
  if [ -n "$file" ]; then
    cp -- "$file" "$archive"
    cp -- "$file.sha256" "$archive.sha256"
  else
    url=https://github.com/PPOE/pgacc/releases/download/$tag/$name.tar.gz
    curl -fsSL --fail-early --proto =https --proto-redir =https --max-time 300 \
      -o "$archive" "$url" -o "$archive.sha256" "$url.sha256" || die "download failed: $url"
  fi
  # Exactly one line, naming this archive. It proves integrity, not authorship.
  awk -v n="$name.tar.gz" 'END { exit !(NR == 1 && ($2 == n || $2 == "*" n)) }' "$archive.sha256" ||
    die "the .sha256 file must have one line for $name.tar.gz"
  (cd "$work" && sha256sum -c --strict --quiet "$name.tar.gz.sha256") || die "checksum mismatch"

  # Only files and directories, all under $name/, no "..", no constants.php, sync-secrets.php or files/.
  tar -tvzf "$archive" | cut -c1 | grep -qv '^[-d]$' && die "the archive has entries other than files and directories"
  tar -tzf "$archive" | awk -v p="$name/" 'index($0, p) != 1 || /(^|\/)\.\.(\/|$)/ || $0 == p "constants.php" ||
    $0 == p "sync-secrets.php" || $0 == p "files" || index($0, p "files/") == 1 { bad = 1 } END { exit !bad }' &&
    die "the archive has entries outside $name/, with '..', or a constants.php, sync-secrets.php or files/"
  tar -xzf "$archive" -C "$work" --no-same-owner --no-same-permissions
  [ -f "$work/$name/public/index.php" ] && [ -f "$work/$name/vendor/autoload.php" ] ||
    die "the archive has no public/index.php or vendor/autoload.php"
  # internal/ is the document root of the internal sync listener: mbapi.php only.
  [ -f "$work/$name/internal/mbapi.php" ] && [ "$(ls -A "$work/$name/internal")" = mbapi.php ] ||
    die "the archive's internal/ must hold mbapi.php only"
  ln -s "$root/shared/constants.php" "$work/$name/constants.php"
  # Written by the playbook on every run; without it the sync refuses (deploy/README.md, "Membership system").
  ln -s "$root/shared/sync-secrets.php" "$work/$name/sync-secrets.php"
  ln -s "$root/shared/files" "$work/$name/files"
  mv "$work/$name" "$release"
  created=true
fi
# The web server serves current/public/ (UPGRADING.md, "Document root
# public/"); a release from before that layout cannot be switched back to.
[ -f "$release/public/index.php" ] ||
  die "$tag has no public/index.php (a release before the public/ document root); see UPGRADING.md"
touch "$release" # the pruning order: newest activation first


switch_to "$tag"
if ! check; then
  # A release from before the public/ document root works only while the
  # web server still serves current/ (the new playbook not yet run). Go back
  # to it if its login page answers; otherwise keep the new release (with
  # current/public/ served, the old one can only answer 404).
  if [ -n "$previous" ] && [ ! -f "$releases/$previous/public/index.php" ]; then
    say "the smoke test of $tag fails; trying $previous (no public/)"
    switch_to "$previous"
    if check login; then
      [ "$created" = false ] || rm -rf "$release"
      echo "deploy: rolled back to $previous: the web server does not serve current/public/ yet." >&2
      echo "deploy: Run the playbook of the new release's checkout, then pgacc-deploy $tag" >&2
      echo "deploy: again (UPGRADING.md, \"Document root public/\")." >&2
      exit 1
    fi
    switch_to "$tag"
    echo "deploy: the smoke tests of $tag and of $previous fail. $tag stays the current release" >&2
    echo "deploy: (the web server serves current/public/; $previous has none). Fix the problem" >&2
    echo "deploy: (web server and PHP-FPM logs; UPGRADING.md, \"Document root public/\"), then" >&2
    echo "deploy: check the login page; no new deploy of $tag is needed. To go back to $previous" >&2
    echo "deploy: instead, run the playbook of its checkout, then its pgacc-deploy $previous." >&2
    exit 1
  fi
  say "the smoke test of $tag fails; rolling back to ${previous:-nothing}"
  if [ -n "$previous" ]; then
    switch_to "$previous"
    check || say "WARNING: the smoke test of $previous fails too"
  else
    rm -f "$root/current"
  fi
  [ "$created" = false ] || rm -rf "$release"
  die "deploy of $tag failed"
fi
say "deployed $tag (previous: ${previous:-none})"

# Keep the newest 5 releases, never the current or the previous one.
find "$releases" -mindepth 1 -maxdepth 1 -type d ! -name '.*' -printf '%T@ %f\n' |
  sort -rn | cut -d' ' -f2 | tail -n +6 | while IFS= read -r old; do
  [ "$old" = "$tag" ] || [ "$old" = "$previous" ] || rm -rf "${releases:?}/$old"
done
