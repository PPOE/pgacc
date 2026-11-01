#!/bin/sh
# The public/ guards of deploy/deploy.sh, on a fake /srv/pgacc in a temporary
# directory: id, sudo, sleep and curl are stubs on PATH. The curl stub answers
# like the web server: it serves current/public/ (FAKE_SERVES=public) or
# current/ (FAKE_SERVES=root); a release with a file "broken" fails its
# login page; the allow list probes answer 404, or 200 for /js/README.md
# (FAKE_ALLOW=js) or /index.php/x (FAKE_ALLOW=pathinfo), an inactive allow
# list. No network, no root.
set -eu

repo_dir=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
root=$tmp/srv
mkdir -p "$tmp/bin" "$root/releases" "$root/shared/files"
: >"$root/shared/constants.php"
sed "s|^root=/srv/pgacc\$|root=$root|" "$repo_dir/deploy/deploy.sh" >"$tmp/deploy.sh"
grep -q "^root=$root\$" "$tmp/deploy.sh" || { echo "deploy.sh has no root=/srv/pgacc line" >&2 && exit 1; }

printf '#!/bin/sh\necho pgacc-deploy\n' >"$tmp/bin/id"
printf '#!/bin/sh\nexit 0\n' >"$tmp/bin/sudo"
printf '#!/bin/sh\nexit 0\n' >"$tmp/bin/sleep"
cat >"$tmp/bin/curl" <<'EOF'
#!/bin/sh
dir=$FAKE_ROOT/current
[ "$FAKE_SERVES" = root ] || dir=$dir/public
case "$*" in *-w*)
  case "$FAKE_ALLOW:$*" in js:*/js/README.md | pathinfo:*/index.php/x) echo 200 ;; *) echo 404 ;; esac
  exit 0 ;;
esac
[ -f "$dir/index.php" ] && [ ! -e "$FAKE_ROOT/current/broken" ] || exit 22
echo '<input name="pass">'
EOF
chmod +x "$tmp/bin/"*
export FAKE_ROOT="$root" FAKE_ALLOW=closed PATH="$tmp/bin:$PATH"

# release <tag> public|root [broken]: a release of the new or the old layout.
release() {
  rm -rf "$root/releases/$1" && mkdir -p "$root/releases/$1/public"
  if [ "$2" = public ]; then : >"$root/releases/$1/public/index.php"; else : >"$root/releases/$1/index.php"; fi
  [ -z "${3:-}" ] || : >"$root/releases/$1/broken"
}
current() { ln -sfn "releases/$1" "$root/current"; }
failures=0
# has <text> <label>: the last deploy printed <text>.
has() { grep -q "$1" "$tmp/out" || { echo "FAIL $2: no \"$1\" in the output" >&2 && failures=$((failures + 1)); }; }
# expect <exit> <current afterwards> <label> <deploy arguments>
expect() {
  want=$1 want_current=$2 label=$3
  shift 3
  status=0
  sh "$tmp/deploy.sh" "$@" >"$tmp/out" 2>&1 || status=$?
  got_current=$(readlink "$root/current" || echo none)
  if [ "$status" -ne "$want" ] || [ "$got_current" != "releases/$want_current" ]; then
    echo "FAIL $label: exit $status (want $want), current $got_current (want releases/$want_current)" >&2
    sed 's/^/  /' "$tmp/out" >&2
    failures=$((failures + 1))
  else
    echo "ok   $label"
  fi
}

export FAKE_SERVES=public
release v1.0.0 public && release v2.0.0 public && current v1.0.0
expect 0 v2.0.0 "public/ to public/: deployed" v2.0.0
has 'deployed v2.0.0' "public/ to public/"

release v2.0.0 public broken && current v1.0.0
expect 1 v1.0.0 "new release fails: back to the previous one" v2.0.0
[ -d "$root/releases/v2.0.0" ] || { echo "FAIL: the existing v2.0.0 directory was removed" >&2 && failures=$((failures + 1)); }

for FAKE_ALLOW in js pathinfo; do
  release v2.0.0 public && current v1.0.0
  expect 1 v1.0.0 "allow list inactive ($FAKE_ALLOW): back to the previous release" v2.0.0
  has 'allow list is not active' "allow list inactive ($FAKE_ALLOW)"
done
current v2.0.0
expect 1 v2.0.0 "already current, allow list inactive: exit 1" v2.0.0
FAKE_ALLOW=closed

# --file: an archive without public/index.php is refused.
mkdir -p "$tmp/pgacc-3.0.0/vendor" && : >"$tmp/pgacc-3.0.0/vendor/autoload.php" && : >"$tmp/pgacc-3.0.0/index.php"
(cd "$tmp" && tar -czf pgacc-3.0.0.tar.gz pgacc-3.0.0 && sha256sum pgacc-3.0.0.tar.gz >pgacc-3.0.0.tar.gz.sha256)
expect 1 v2.0.0 "--file without public/index.php: refused" --file "$tmp/pgacc-3.0.0.tar.gz"
has 'no public/index.php or vendor/autoload.php' "--file without public/"
[ ! -e "$root/releases/v3.0.0" ] || { echo "FAIL: releases/v3.0.0 was created" >&2 && failures=$((failures + 1)); }

# --file: internal/ must hold mbapi.php only; then sync.php is linked like constants.php.
# archive <version> <file...>: an archive with public/index.php, vendor/autoload.php and the files.
archive() {
  version=$1 && shift
  rm -rf "$tmp/pgacc-$version" && mkdir -p "$tmp/pgacc-$version/public" "$tmp/pgacc-$version/vendor"
  for file in public/index.php vendor/autoload.php "$@"; do
    mkdir -p "$(dirname "$tmp/pgacc-$version/$file")" && : >"$tmp/pgacc-$version/$file"
  done
  (cd "$tmp" && tar -czf "pgacc-$version.tar.gz" "pgacc-$version" && sha256sum "pgacc-$version.tar.gz" >"pgacc-$version.tar.gz.sha256")
}
for case in none:"" extra:"internal/mbapi.php internal/evil.php"; do
  # shellcheck disable=SC2086 # the file list is split on purpose
  archive 3.0.0 ${case#*:}
  expect 1 v2.0.0 "--file, internal/ with ${case%%:*}: refused" --file "$tmp/pgacc-3.0.0.tar.gz"
  has "internal/ must hold mbapi.php only" "--file, internal/ with ${case%%:*}"
done
archive 3.0.0 internal/mbapi.php
expect 0 v3.0.0 "--file with internal/mbapi.php: deployed" --file "$tmp/pgacc-3.0.0.tar.gz"
[ "$(readlink "$root/releases/v3.0.0/sync.php")" = "$root/shared/sync.php" ] ||
  { echo "FAIL: sync.php is not linked to shared/" >&2 && failures=$((failures + 1)); }
current v2.0.0

release v1.0.0 public && release v2.0.0 root && current v1.0.0
expect 1 v1.0.0 "refuses to switch to a release without public/" v2.0.0
has 'has no public/index.php' "release without public/"

release v2.0.0 public broken && current v2.0.0
expect 1 v2.0.0 "already current, smoke test fails: exit 1" v2.0.0
rm "$root/releases/v2.0.0/broken"
expect 0 v2.0.0 "already current, smoke test passes: exit 0" v2.0.0

# First upgrade to public/: the previous release has none.
release v1.0.0 root && release v2.0.0 public broken && current v1.0.0
expect 1 v2.0.0 "public/ served, previous fails too: keeps the new release" v2.0.0
has 'stays the current release' "previous fails too"

export FAKE_SERVES=root
current v1.0.0
expect 1 v1.0.0 "release root still served: back to the previous release" v2.0.0
has 'Run the playbook' "release root still served"

[ "$failures" -eq 0 ] || { echo "$failures deploy guard test(s) failed" >&2 && exit 1; }
echo "deploy.sh guard tests passed"
