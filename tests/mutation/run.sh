#!/bin/sh
set -eu

# Target-driven mutation runner (see tests/mutation/README.md).
#
#   sh tests/mutation/run.sh <5.6|7.4|8.0|8.5> <target> [runner options]
#   sh tests/mutation/run.sh <lane> --self-test
#
# Runs tests/mutation/runner.php in a one-off container of the lane's
# application image, on the lane's isolated database and internal network.
# Results go to build/mutation/<target>-php<lane>/ (override with
# PGACC_MUTATION_HOST_DIR). PGACC_TEST_ID isolates concurrent runs exactly as
# for tests/run.sh.

test_id=${PGACC_TEST_ID:-}
case "$test_id" in
  *[!a-z0-9_-]*)
    echo "PGACC_TEST_ID may only contain a-z, 0-9, '_' and '-'" >&2
    exit 2
    ;;
esac
PGACC_TEST_SUFFIX=${test_id:+-$test_id}
export PGACC_TEST_SUFFIX

usage()
{
  echo "Usage: sh tests/mutation/run.sh <5.6|7.4|8.0|8.5> <target|--self-test> [--only=id,...] [--check]" >&2
  exit 2
}

[ $# -ge 2 ] || usage
php_version=$1
target=$2
shift 2

case "$php_version" in
  5.6) lane=php56 lane_file=compose.php56.yaml ;;
  7.4) lane=php74 lane_file= ;;
  8.0) lane=php80 lane_file=compose.php80.yaml ;;
  8.5) lane=php85 lane_file=compose.php85.yaml ;;
  *) usage ;;
esac
case "$target" in
  --self-test) ;;
  *[!A-Za-z0-9_-]*|'') usage ;;
esac

repo_root=$(cd "$(dirname "$0")/../.." && pwd)
cd "$repo_root"

compose()
{
  # The lane's services without compose.coverage.yaml: the mutant servers use
  # the same settings as the regular application service.
  docker compose --project-name "pgacc-mutation${PGACC_TEST_SUFFIX}-$lane" \
    -f compose.test.yaml ${lane_file:+-f "$lane_file"} "$@"
}

cleanup()
{
  compose down --volumes --remove-orphans
}
trap cleanup EXIT INT TERM

compose build application

if [ "$target" = --self-test ]; then
  # Unit tests of tests/mutation/lib.php; no database needed. PHP 7.4 also
  # prints their line and branch coverage of lib.php.
  if [ "$php_version" = 5.6 ]; then
    command='php /opt/test-tools/phpunit-5.7.27.phar --no-configuration --colors=never \
      --bootstrap tests/mutation/tests/bootstrap.php tests/mutation/tests'
  elif [ "$php_version" = 7.4 ]; then
    # Branch analysis needs more than the default memory_limit (PHPUnit only).
    command='XDEBUG_MODE=coverage php -d memory_limit=-1 vendor/bin/phpunit \
      --configuration tests/mutation/tests/phpunit.xml --coverage-php /tmp/lib.cov \
      && php tests/mutation/tests/coverage-summary.php /tmp/lib.cov'
  else
    command='XDEBUG_MODE=off php vendor/bin/phpunit \
      --configuration tests/mutation/tests/phpunit.xml --no-coverage'
  fi
  compose run --rm --no-deps application sh -c "$command"
  exit
fi

host_dir=${PGACC_MUTATION_HOST_DIR:-$repo_root/build/mutation/$target-$lane}
rm -rf "$host_dir"
mkdir -p "$host_dir"

compose up --detach --wait database
status=0
compose run --rm --no-deps --volume "$host_dir:/results" \
  --env PGACC_MUTATION_OWNER="$(id -u):$(id -g)" application \
  sh -c 'php tests/mutation/runner.php "$@"; status=$?; chown -R "$PGACC_MUTATION_OWNER" /results; exit $status' \
  runner --target="$target" --output=/results "$@" || status=$?
echo "Mutation results: $host_dir"
exit "$status"
