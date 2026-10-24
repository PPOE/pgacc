#!/bin/sh
set -eu

# The PHP 8.5 / PostgreSQL 18 lane, the only one after php5-last
# (tests/README.md), with coverage (compose.coverage.yaml).
#
#   sh tests/run.sh [8.5]
#
# PGACC_TEST_ID isolates concurrent runs (e.g. separate worktrees): it
# suffixes Compose project names and locally built image tags.
test_id=${PGACC_TEST_ID:-}
case "$test_id" in
  *[!a-z0-9_-]*)
    echo "PGACC_TEST_ID may only contain a-z, 0-9, '_' and '-'" >&2
    exit 2
    ;;
esac
php_version=${1:-8.5}

case "$php_version" in
  8.5) ;;
  *)
    echo "Usage: sh tests/run.sh [8.5]" >&2
    exit 2
    ;;
esac

PGACC_TEST_SUFFIX=${test_id:+-$test_id}
export PGACC_TEST_SUFFIX

compose()
{
  docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php85" \
    -f compose.test.yaml -f compose.coverage.yaml "$@"
}

cleanup()
{
  compose down --volumes --remove-orphans
}

trap cleanup EXIT INT TERM

# Merged PHPUnit + HTTP coverage report (see tests/README.md, "Coverage").
repo_root=$(cd "$(dirname "$0")/.." && pwd)
PGACC_COVERAGE_HOST_DIR=${PGACC_COVERAGE_HOST_DIR:-$repo_root/build/coverage}
PGACC_COVERAGE_OWNER=$(id -u):$(id -g)
PGACC_COVERAGE_BRANCHES=${PGACC_COVERAGE_BRANCHES:-1}
export PGACC_COVERAGE_HOST_DIR PGACC_COVERAGE_OWNER PGACC_COVERAGE_BRANCHES
mkdir -p "$PGACC_COVERAGE_HOST_DIR"
rm -f "$PGACC_COVERAGE_HOST_DIR/changed-lines.txt"
if [ -n "${PGACC_COVERAGE_BASE:-}" ]; then
  # Every changed executable line must be covered (tests/README.md, "Coverage").
  sh "$repo_root/tests/coverage/changed-lines.sh" "$PGACC_COVERAGE_BASE" \
    >"$PGACC_COVERAGE_HOST_DIR/changed-lines.txt"
fi

echo "Running application tests on PHP $php_version with PostgreSQL 18"
compose up --detach --wait database
compose run --rm php-lint
compose run --rm database-test
compose up --build --detach --wait application
compose run --rm application-test
