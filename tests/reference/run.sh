#!/bin/sh
set -eu

# PHP 5 reference recordings (golden master); see tests/README.md.
#
#   sh tests/reference/run.sh compare [8.5]   run the scenario on the PHP 8.5 /
#                                             PostgreSQL 18 lane and compare it
#                                             with tests/reference/php5/
#
# tests/reference/php5/ was recorded on the production pair (PHP 5.3,
# PostgreSQL 8.4) before php5-last and is kept as the historical golden
# master; that lane no longer exists, so it cannot be re-recorded. Differences
# must match reviewed tests/reference/allowlist.php entries. PGACC_TEST_ID works
# as for tests/run.sh. Results go to build/reference/php85/ (report.txt,
# diffs/ and the actual recording).

usage()
{
  echo "Usage: [PGACC_TEST_ID=<id>] sh tests/reference/run.sh compare [8.5]" >&2
  exit 2
}

mode=${1:-}
case "$mode" in
  compare) lane=${2:-8.5} ;;
  *) usage ;;
esac
case "$lane" in
  8.5) ;;
  *) usage ;;
esac

repo_root=$(cd "$(dirname "$0")/../.." && pwd)
cd "$repo_root"

test_id=${PGACC_TEST_ID:-}
case "$test_id" in
  *[!a-z0-9_-]*)
    echo "PGACC_TEST_ID may only contain a-z, 0-9, '_' and '-'" >&2
    exit 2
    ;;
esac
PGACC_TEST_SUFFIX=${test_id:+-$test_id}
export PGACC_TEST_SUFFIX

lane_tag=php85
lane_files="-f compose.test.yaml"
reference_files="-f tests/reference/compose.yaml"
project="pgacc-reference${PGACC_TEST_SUFFIX}-$lane_tag"

PGACC_REFERENCE_BASE_IMAGE=pgacc-test-application$PGACC_TEST_SUFFIX
PGACC_REFERENCE_IMAGE=pgacc-test-reference-$lane_tag$PGACC_TEST_SUFFIX
# libfaketime of the lane image's Debian release (trixie).
PGACC_REFERENCE_FAKETIME=0.9.10+2024-06-05+gba9ed5b2-0.6
PGACC_REFERENCE_MODE=$mode
PGACC_REFERENCE_LANE=$lane
PGACC_REFERENCE_OWNER=$(id -u):$(id -g)
PGACC_REFERENCE_OUT=$repo_root/build/reference/$lane_tag
export PGACC_REFERENCE_BASE_IMAGE PGACC_REFERENCE_IMAGE PGACC_REFERENCE_FAKETIME \
  PGACC_REFERENCE_MODE PGACC_REFERENCE_LANE PGACC_REFERENCE_OWNER PGACC_REFERENCE_OUT

# The recording is replaced as a whole, so removed steps do not linger.
rm -rf "$PGACC_REFERENCE_OUT"
mkdir -p "$PGACC_REFERENCE_OUT"

lane_compose()
{
  # shellcheck disable=SC2086 # intentional word splitting of the file list
  docker compose --project-name "$project" $lane_files "$@"
}
compose()
{
  # shellcheck disable=SC2086 # intentional word splitting of the file list
  lane_compose $reference_files "$@"
}
cleanup()
{
  compose down --volumes --remove-orphans
}
trap cleanup EXIT INT TERM

echo "PHP 5 reference: $mode on PHP $lane with PostgreSQL 18"
# The lane's own image first; the reference image is built on top of it.
lane_compose build application
compose build application
compose up --detach --wait database
compose run --rm database-test
compose up --detach --wait application
compose run --rm reference-test
