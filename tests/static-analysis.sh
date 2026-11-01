#!/bin/sh
# Static analysis of the tracked top-level application PHP files with pinned
# PHPCompatibility (PHP_CodeSniffer) and PHPStan, in a dedicated image that
# is separate from the application runtime image. The analysis itself
# runs without network access, on a read-only mount of the repository.
#
#   sh tests/static-analysis.sh                     check against the baselines
#   sh tests/static-analysis.sh --report            list every finding
#   sh tests/static-analysis.sh --update-baselines  rewrite the baselines
set -eu

test_id=${PGACC_TEST_ID:-}
case "$test_id" in
  *[!a-z0-9_-]*)
    echo "PGACC_TEST_ID may only contain a-z, 0-9, '_' and '-'" >&2
    exit 2
    ;;
esac
image="pgacc-static-analysis${test_id:+-$test_id}"

case "${1:-}" in
  '') mode=check ;;
  --report) mode=report ;;
  --update-baselines) mode=update-baselines ;;
  *)
    echo "Usage: sh tests/static-analysis.sh [--report|--update-baselines]" >&2
    exit 2
    ;;
esac

repo_dir=$(pwd -P)
if [ ! -f "$repo_dir/tests/static-analysis/Dockerfile" ]; then
  echo "Run from the repository root" >&2
  exit 2
fi

# Tracked top-level application files, the entry points in public/ and the
# library files in src/ only:
# vendor/, tests/, files/ and proxy/ are excluded, as is the untracked
# deployment constants.php.
files=$({ git -C "$repo_dir" ls-files -- '*.php' | grep -v /; git -C "$repo_dir" ls-files -- 'public/*.php' 'src/*.php' | grep -v '^\(public\|src\)/.*/'; } || true)
if [ -z "$files" ]; then
  echo "No tracked top-level PHP files found" >&2
  exit 1
fi

docker build --quiet --tag "$image" \
  --file "$repo_dir/tests/static-analysis/Dockerfile" "$repo_dir" >/dev/null

set -- --rm --network none --read-only --tmpfs /tmp:exec,mode=1777 \
  --user "$(id -u):$(id -g)" \
  --volume "$repo_dir:/app:ro"
if [ "$mode" = update-baselines ]; then
  set -- "$@" --volume "$repo_dir/tests/static-analysis:/baseline-output"
fi

# shellcheck disable=SC2086 # $files is a newline-separated list of plain names
docker run "$@" "$image" sh /app/tests/static-analysis/analyse.sh "$mode" $files
