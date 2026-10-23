#!/bin/sh
set -eu

# PGACC_TEST_ID isolates concurrent runs (e.g. separate worktrees): it
# suffixes Compose project names and locally built image tags.
test_id=${PGACC_TEST_ID:-}
case "$test_id" in
  *[!a-z0-9_-]*)
    echo "PGACC_TEST_ID may only contain a-z, 0-9, '_' and '-'" >&2
    exit 2
    ;;
esac
PGACC_TEST_SUFFIX=${test_id:+-$test_id}
export PGACC_TEST_SUFFIX

php_version=${1:-all}

case "$php_version" in
  all)
    matrix_status=0
    sh tests/php-syntax-matrix.sh || matrix_status=1
    for test_php in 7.4 5.6 8.0 8.5; do
      sh tests/run.sh "$test_php" || matrix_status=1
    done
    exit "$matrix_status"
    ;;
  7.4|5.6|8.0|8.5) ;;
  *)
    echo "Usage: sh tests/run.sh [all|7.4|5.6|8.0|8.5]" >&2
    exit 2
    ;;
esac


compose()
{
  if [ "$php_version" = 5.6 ]; then
    docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php56" \
      -f compose.test.yaml -f compose.php56.yaml "$@"
  elif [ "$php_version" = 8.0 ]; then
    docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php80" \
      -f compose.test.yaml -f compose.php80.yaml "$@"
  elif [ "$php_version" = 8.5 ]; then
    docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php85" \
      -f compose.test.yaml -f compose.php85.yaml "$@"
  else
    docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php74" \
      -f compose.test.yaml -f compose.coverage.yaml "$@"
  fi
}

cleanup()
{
  compose down --volumes --remove-orphans
}

trap cleanup EXIT INT TERM

if [ "$php_version" = 7.4 ]; then
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
fi

echo "Running application tests on PHP $php_version"
compose up --detach --wait database
compose run --rm php-lint
compose run --rm database-test
compose up --build --detach --wait application
compose run --rm application-test
