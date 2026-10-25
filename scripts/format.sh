#!/bin/sh
# Formatting: PER Coding Style 3.0, whitespace rules only
# (.php-cs-fixer.dist.php), with the PHP-CS-Fixer version pinned in
# tests/style/composer.lock, on PHP 8.5 in the image of tests/style/Dockerfile
# (no network).
#
#   sh scripts/format.sh [files...]          format the files (default: the tree)
#   sh scripts/format.sh --check [files...]  dry run: print the diff, exit
#                                            non-zero if a file needs it (CI)
#
# Files outside the config's scope (vendor/, tests/fixtures/, the reference
# recordings, the canaries, see .php-cs-fixer.dist.php) are skipped. The image
# is built once per Dockerfile/lock content and then reused. After formatting
# existing code, prove the result with
# sh tests/coverage/token-equivalence.sh --check <base>.
set -eu

mode=fix
if [ "${1:-}" = --check ]; then
  mode=check
  shift
fi
case "${1:-}" in
  -*)
    echo "Usage: sh scripts/format.sh [--check] [files...]" >&2
    exit 2
    ;;
esac

test_id=${PGACC_TEST_ID:-}
case "$test_id" in
  *[!a-z0-9_-]*)
    echo "PGACC_TEST_ID may only contain a-z, 0-9, '_' and '-'" >&2
    exit 2
    ;;
esac

repo_dir=$(cd "$(dirname "$0")/.." && pwd -P)

# Container paths of the given files (relative to the current directory or
# absolute, inside the repository).
paths=
for file; do
  case "$file" in
    /*) absolute=$file ;;
    *) absolute=$(pwd -P)/$file ;;
  esac
  directory=$(cd "$(dirname "$absolute")" 2>/dev/null && pwd -P) || {
    echo "No such file: $file" >&2
    exit 2
  }
  absolute=$directory/$(basename "$absolute")
  case "$absolute" in
    "$repo_dir"/*) ;;
    *)
      echo "Not inside the repository: $file" >&2
      exit 2
      ;;
  esac
  case "$absolute" in
    *[!A-Za-z0-9_./-]*)
      echo "Unsupported file name: $file" >&2
      exit 2
      ;;
  esac
  paths="$paths /app${absolute#"$repo_dir"}"
done

hash=$(cat "$repo_dir/tests/style/Dockerfile" "$repo_dir/tests/style/composer.json" \
  "$repo_dir/tests/style/composer.lock" | sha256sum | cut -c1-12)
image="pgacc-style${test_id:+-$test_id}:$hash"
if ! docker image inspect "$image" >/dev/null 2>&1; then
  docker build --quiet --tag "$image" --file "$repo_dir/tests/style/Dockerfile" "$repo_dir" >/dev/null
fi

if [ "$mode" = check ]; then
  set -- check --diff
  mount=ro
else
  set -- fix
  mount=rw
fi
# --path-mode=intersection: given files are formatted only if the config's
# finder includes them. Without files, the finder's whole tree.
if [ -n "$paths" ]; then
  set -- "$@" --path-mode=intersection
fi
# shellcheck disable=SC2086 # $paths: plain names, checked above
docker run --rm --network none --read-only --tmpfs /tmp:exec,mode=1777 \
  --user "$(id -u):$(id -g)" --volume "$repo_dir:/app:$mount" "$image" \
  php /tools/vendor/bin/php-cs-fixer "$@" --config=/app/.php-cs-fixer.dist.php \
  --show-progress=none --no-interaction $paths
