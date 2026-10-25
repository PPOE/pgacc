#!/bin/sh
set -eu

# Target-driven mutation runner (see tests/mutation/README.md).
#
#   sh tests/mutation/run.sh 8.5 <target> [--changed-since=<base>] [runner options]
#   sh tests/mutation/run.sh <lane> --self-test
#
# --changed-since=<base> (Git revision; its merge base with HEAD is used, as
# for PGACC_COVERAGE_BASE) runs only the mutations whose search region
# overlaps a line changed since then in the working tree, untracked files
# included; with none, the target passes without running anything. Files
# whose token stream is unchanged (tests/coverage/token-equivalence.php)
# count as unchanged.
#
# Runs tests/mutation/runner.php in a one-off container of the lane's
# application image, on the lane's isolated database and internal network.
# Results go to build/mutation/<target>-php<lane>/ (override with
# PGACC_MUTATION_HOST_DIR). PGACC_TEST_ID isolates concurrent runs as for
# tests/run.sh.

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
  echo "Usage: sh tests/mutation/run.sh 8.5 <target|--self-test> [--changed-since=<base>] [--only=id,...] [--check]" >&2
  exit 2
}

[ $# -ge 2 ] || usage
php_version=$1
target=$2
shift 2

case "$php_version" in
  8.5) lane=php85 ;;
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
    -f compose.test.yaml "$@"
}

cleanup()
{
  compose down --volumes --remove-orphans
}
trap cleanup EXIT INT TERM

compose build application

if [ "$target" = --self-test ]; then
  # Unit tests of tests/mutation/lib.php; no database needed. Also prints
  # their line and branch coverage of lib.php.
  # Branch analysis needs more than the default memory_limit (PHPUnit only).
  command='XDEBUG_MODE=coverage php -d memory_limit=-1 vendor/bin/phpunit \
    --configuration tests/mutation/tests/phpunit.xml --coverage-php /tmp/lib.cov \
    && php tests/mutation/tests/coverage-summary.php /tmp/lib.cov'
  compose run --rm --no-deps application sh -c "$command"
  exit
fi

host_dir=${PGACC_MUTATION_HOST_DIR:-$repo_root/build/mutation/$target-$lane}
rm -rf "$host_dir"
mkdir -p "$host_dir"

# --changed-since: the diff is taken here (the container has no .git) and
# passed to the runner as /results/changed.diff.
for option; do
  shift
  case "$option" in
    --changed-since=*)
      # Whitespace-only changes select nothing (tests/mutation/changed-diff.sh).
      sh tests/mutation/changed-diff.sh "${option#--changed-since=}" >"$host_dir/changed.diff"
      set -- "$@" --changed-diff=/results/changed.diff
      ;;
    *) set -- "$@" "$option" ;;
  esac
done

compose up --detach --wait database membership
status=0
compose run --rm --no-deps --volume "$host_dir:/results" \
  --env PGACC_MUTATION_OWNER="$(id -u):$(id -g)" application \
  sh -c 'php tests/mutation/runner.php "$@"; status=$?; chown -R "$PGACC_MUTATION_OWNER" /results; exit $status' \
  runner --target="$target" --output=/results "$@" || status=$?
echo "Mutation results: $host_dir"
exit "$status"
