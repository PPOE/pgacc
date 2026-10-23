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
# PGACC_TEST_PG (default 17) selects the PostgreSQL server; non-default
# versions add "-pgNN" to the suffix.
. "$(dirname "$0")/postgres-version.sh"
PGACC_TEST_SUFFIX=${test_id:+-$test_id}$PGACC_TEST_PG_TAG
export PGACC_TEST_SUFFIX

php_version=${1:-all}

case "$php_version" in
  all)
    matrix_status=0
    sh tests/php-syntax-matrix.sh || matrix_status=1
    for test_php in 7.4 5.6 5.3 8.0 8.5; do
      sh tests/run.sh "$test_php" || matrix_status=1
    done
    exit "$matrix_status"
    ;;
  7.4|5.6|5.3|8.0|8.5) ;;
  *)
    echo "Usage: [PGACC_TEST_PG=9.4|14|17|18] sh tests/run.sh [all|7.4|5.6|5.3|8.0|8.5]" >&2
    exit 2
    ;;
esac

# Coverage is collected only on PHP 7.4 with the default PostgreSQL 17 server.
coverage=
if [ "$php_version" = 7.4 ] && [ "$PGACC_TEST_PG" = 17 ]; then
  coverage=1
fi

compose()
{
  if [ "$php_version" = 5.3 ]; then
    docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php53" \
      -f compose.test.yaml -f compose.php53.yaml "$@"
  elif [ "$php_version" = 5.6 ]; then
    docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php56" \
      -f compose.test.yaml -f compose.php56.yaml "$@"
  elif [ "$php_version" = 8.0 ]; then
    docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php80" \
      -f compose.test.yaml -f compose.php80.yaml "$@"
  elif [ "$php_version" = 8.5 ]; then
    docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php85" \
      -f compose.test.yaml -f compose.php85.yaml "$@"
  elif [ -n "$coverage" ]; then
    docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php74" \
      -f compose.test.yaml -f compose.coverage.yaml "$@"
  else
    docker compose --project-name "pgacc-test${PGACC_TEST_SUFFIX}-php74" \
      -f compose.test.yaml "$@"
  fi
}

cleanup()
{
  compose down --volumes --remove-orphans
}

trap cleanup EXIT INT TERM

if [ -n "$coverage" ]; then
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

echo "Running application tests on PHP $php_version with PostgreSQL $PGACC_TEST_PG"
compose up --detach --wait database
compose run --rm php-lint
compose run --rm database-test
compose up --build --detach --wait application
if [ "$php_version" = 5.3 ]; then
  # PHPUnit on PHP 5.3 runs before the write workflows change the fixtures
  # (as on the other lanes). The test client is the PHP 5.6 image
  # (compose.php53.yaml); build it so that it carries the current scripts.
  compose run --rm application-phpunit
  compose build application-test
fi
compose run --rm application-test
