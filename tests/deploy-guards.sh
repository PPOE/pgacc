#!/bin/sh
# The public/ guards of deploy/deploy.sh, on a fake /srv/pgacc in a temporary
# directory: id, sudo, sleep and curl are stubs on PATH. The curl stub answers
# like the web server: it serves current/public/ (FAKE_SERVES=public) or
# current/ (FAKE_SERVES=root); a release with a file "broken" fails its
# login page; the allow list paths answer 404. No network, no root.
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
case "$*" in *-w*) echo 404 && exit 0 ;; esac
[ -f "$dir/index.php" ] && [ ! -e "$FAKE_ROOT/current/broken" ] || exit 22
echo '<input name="pass">'
EOF
chmod +x "$tmp/bin/"*
export FAKE_ROOT="$root" PATH="$tmp/bin:$PATH"

# release <tag> public|root [broken]: a release of the new or the old layout.
release() {
  rm -rf "$root/releases/$1" && mkdir -p "$root/releases/$1/public"
  if [ "$2" = public ]; then : >"$root/releases/$1/public/index.php"; else : >"$root/releases/$1/index.php"; fi
  [ -z "${3:-}" ] || : >"$root/releases/$1/broken"
}
current() { ln -sfn "releases/$1" "$root/current"; }
failures=0
# expect <exit> <current afterwards> <label> <deploy arguments>
expect() {
  want=$1 want_current=$2 label=$3
  shift 3
  status=0
  sh "$tmp/deploy.sh" "$@" >"$tmp/out" 2>&1 || status=$?
  got_current=$(readlink "$root/current")
  if [ "$status" -ne "$want" ] || [ "$got_current" != "releases/$want_current" ]; then
    echo "FAIL $label: exit $status (want $want), current $got_current (want releases/$want_current)" >&2
    sed 's/^/  /' "$tmp/out" >&2
    failures=$((failures + 1))
  else
    echo "ok   $label"
  fi
}

export FAKE_SERVES=public
release v1.0.0 public && release v2.0.0 root && current v1.0.0
expect 1 v1.0.0 "refuses to switch to a release without public/" v2.0.0
grep -q 'has no public/index.php' "$tmp/out" || { echo "FAIL: no refusal message" >&2 && failures=$((failures + 1)); }

release v2.0.0 public broken && current v2.0.0
expect 1 v2.0.0 "already current, smoke test fails: exit 1" v2.0.0
rm "$root/releases/v2.0.0/broken"
expect 0 v2.0.0 "already current, smoke test passes: exit 0" v2.0.0

# First upgrade to public/: the previous release has none.
release v1.0.0 root && release v2.0.0 public broken && current v1.0.0
expect 1 v2.0.0 "public/ served, previous fails too: keeps the new release" v2.0.0
grep -q 'stays the current release' "$tmp/out" || { echo "FAIL: no recovery steps" >&2 && failures=$((failures + 1)); }

export FAKE_SERVES=root
current v1.0.0
expect 1 v1.0.0 "release root still served: back to the previous release" v2.0.0
grep -q 'Run the playbook' "$tmp/out" || { echo "FAIL: no recovery steps" >&2 && failures=$((failures + 1)); }

[ "$failures" -eq 0 ] || { echo "$failures deploy guard test(s) failed" >&2 && exit 1; }
echo "deploy.sh guard tests passed"
