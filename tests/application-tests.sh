#!/bin/sh
set -eu

# PGACC_COVERAGE_DIR is set by compose.coverage.yaml (tests/run.sh): the
# application server then writes per-request coverage there, and the PHPUnit
# coverage is merged with it after all groups have run. Every group is
# attempted, so that all failures stay visible; the exit status is nonzero if
# any group failed.
coverage_dir=${PGACC_COVERAGE_DIR:-}
phpunit_coverage=
phpunit_memory=128M
if [ -n "$coverage_dir" ]; then
  phpunit_coverage="--coverage-php $coverage_dir/phpunit.cov"
  if [ "${PGACC_COVERAGE_BRANCHES:-1}" != 0 ]; then
    # Branch coverage needs Xdebug path analysis, which exceeds the default
    # memory_limit for functions.php; this only affects the PHPUnit process.
    phpunit_coverage="$phpunit_coverage --path-coverage"
    phpunit_memory=-1
  fi
  report_dir=${PGACC_COVERAGE_REPORT_DIR:?}
  if [ -n "${PGACC_COVERAGE_OWNER:-}" ]; then
    # Hand the bind-mounted report back to the user who started the run.
    trap 'chown -R "$PGACC_COVERAGE_OWNER" "$report_dir" || true' EXIT
  fi
fi

test_status=0
php --version | head -n 1
php tests/http-smoke.php || test_status=1
php tests/http-login.php || test_status=1
php tests/http-session-lifecycle.php || test_status=1
php tests/http-listing-prefs.php || test_status=1
php tests/http-redirects.php || test_status=1
php tests/http-donations.php || test_status=1
# shellcheck disable=SC2086 # intentional word splitting of the optional flags
XDEBUG_MODE=coverage php -d memory_limit="$phpunit_memory" vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --coverage-text $phpunit_coverage || test_status=1
php tests/show-uncovered-functions.php /tmp/functions-clover.xml || test_status=1
php tests/write-workflows.php || test_status=1
php tests/http-import.php || test_status=1
php tests/http-receipts.php || test_status=1
php tests/http-output-escaping.php || test_status=1
php tests/http-csrf.php || test_status=1
php tests/http-connect-failure.php || test_status=1

if [ -n "$coverage_dir" ]; then
  XDEBUG_MODE=coverage php -d memory_limit=1G tests/coverage/merge.php "$coverage_dir" "$report_dir" \
    || test_status=1
  if [ -f "$report_dir/changed-lines.txt" ]; then
    set -- "$report_dir/clover.xml" "$report_dir/changed-lines.txt"
    if [ -f "$report_dir/uncovered-branches.txt" ]; then
      set -- "--branches=$report_dir/uncovered-branches.txt" "$@"
      if [ "${PGACC_COVERAGE_STRICT_BRANCHES:-0}" = 1 ]; then
        set -- --fail-on-branches "$@"
      fi
    fi
    php tests/coverage/check-changed-lines.php "$@" || test_status=1
  fi
fi
exit "$test_status"
