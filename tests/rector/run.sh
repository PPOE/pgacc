#!/bin/sh
# Mechanical syntax modernization with the Rector pinned in
# tests/rector/composer.lock and the rules of tests/rector/rector.php, on
# PHP 8.5, in a one-off container (no network, read-only root, no
# capabilities, no-new-privileges where the Docker host allows it). Only the
# repository is mounted read-write, .git read-only.
#
#   sh tests/rector/run.sh [--dry-run] [files...]
#       default files: the tracked application pages (top-level *.php
#       without .php-cs-fixer.dist.php), public/*.php and src/*.php
#
# Afterwards: sh scripts/format.sh (PER-CS layout) and
# sh tests/coverage/ast-equivalence.sh --check <base> (the proof).
set -eu

rector_dir=$(cd "$(dirname "$0")" && pwd -P) || exit 2
repo_dir=$(cd "$rector_dir/../.." && pwd -P) || exit 2
dry=
if [ "${1:-}" = --dry-run ]; then
  dry=--dry-run
  shift
fi
if [ $# -eq 0 ]; then
  # shellcheck disable=SC2046 # plain file names ([A-Za-z0-9_.-])
  set -- $({ git -C "$repo_dir" ls-files ':(top)*.php' | grep -v / | grep -vx '.php-cs-fixer.dist.php'; git -C "$repo_dir" ls-files ':(top)public/*.php' ':(top)src/*.php' | grep -v '^\(public\|src\)/.*/'; })
fi
for file; do
  case "$file" in
    /*|-*|*[!A-Za-z0-9_./-]*)
      echo "run.sh: unsupported file name (relative to the repository root): $file" >&2
      exit 2
      ;;
  esac
done

hash=$(cat "$rector_dir/Dockerfile" "$rector_dir/composer.json" "$rector_dir/composer.lock" | sha256sum | cut -c1-12)
image=pgacc-rector:$hash
if ! docker image inspect "$image" >/dev/null 2>&1; then
  docker build --quiet --tag "$image" --file "$rector_dir/Dockerfile" "$rector_dir" >/dev/null || exit 2
fi
set -- --rm --network none --read-only --tmpfs /tmp:mode=1777 --cap-drop ALL --user "$(id -u):$(id -g)" \
  --mount "type=bind,src=$repo_dir,dst=/app" \
  --mount "type=bind,src=$repo_dir/.git,dst=/app/.git,readonly" \
  --mount "type=bind,src=$rector_dir/rector.php,dst=/config/rector.php,readonly" \
  "$image" php /tools/vendor/bin/rector process --config=/config/rector.php --no-progress-bar $dry "$@"
mkdir -p "$repo_dir/build" || exit 2
status=0
docker run --security-opt no-new-privileges "$@" 2>"$repo_dir/build/rector.err" || status=$?
if [ "$status" -ge 125 ] && grep -q 'operation not permitted' "$repo_dir/build/rector.err"; then
  echo "run.sh: this Docker host refuses no-new-privileges; running without it" >&2
  status=0
  docker run "$@" || status=$?
else
  cat "$repo_dir/build/rector.err" >&2
fi
rm -f "$repo_dir/build/rector.err"
exit "$status"
