#!/bin/sh
set -eu

# PHP 5 reference recordings (golden master); see tests/README.md.
#
#   sh tests/reference/run.sh record          record on PHP 5.6 into tests/reference/php5/
#   sh tests/reference/run.sh compare 7.4     run the same scenario on a lane and compare
#   sh tests/reference/run.sh compare 5.6     reproducibility check of the recording
#
# PGACC_TEST_ID and PGACC_TEST_PG work as for tests/run.sh. Compare results go
# to build/reference/php<lane>/ (report.txt, diffs/ and the actual recording).

usage()
{
  echo "Usage: [PGACC_TEST_ID=<id>] sh tests/reference/run.sh record | compare 5.6|7.4|8.0|8.5" >&2
  exit 2
}

mode=${1:-}
case "$mode" in
  record) lane=${2:-5.6} ;;
  compare) lane=${2:-} ;;
  *) usage ;;
esac
case "$lane" in
  5.6|7.4|8.0|8.5) ;;
  *) usage ;;
esac
if [ "$mode" = record ] && [ "$lane" != 5.6 ]; then
  echo "The reference is recorded on PHP 5.6 only." >&2
  exit 2
fi

repo_root=$(cd "$(dirname "$0")/../.." && pwd)
cd "$repo_root"

test_id=${PGACC_TEST_ID:-}
case "$test_id" in
  *[!a-z0-9_-]*)
    echo "PGACC_TEST_ID may only contain a-z, 0-9, '_' and '-'" >&2
    exit 2
    ;;
esac
. tests/postgres-version.sh
PGACC_TEST_SUFFIX=${test_id:+-$test_id}$PGACC_TEST_PG_TAG
export PGACC_TEST_SUFFIX

lane_tag=php$(echo "$lane" | tr -d .)
case "$lane" in
  5.6) lane_files="-f compose.test.yaml -f compose.php56.yaml"; base_image=pgacc-test-application-php56 ;;
  7.4) lane_files="-f compose.test.yaml"; base_image=pgacc-test-application ;;
  8.0) lane_files="-f compose.test.yaml -f compose.php80.yaml"; base_image=pgacc-test-application-php80 ;;
  8.5) lane_files="-f compose.test.yaml -f compose.php85.yaml"; base_image=pgacc-test-application-php85 ;;
esac
project="pgacc-reference${PGACC_TEST_SUFFIX}-$lane_tag"

PGACC_REFERENCE_BASE_IMAGE=$base_image$PGACC_TEST_SUFFIX
PGACC_REFERENCE_IMAGE=pgacc-test-reference-$lane_tag$PGACC_TEST_SUFFIX
# mcrypt only on the recording lane: production PHP 5 has it; PHP 7.2+ cannot.
PGACC_REFERENCE_MCRYPT=0
if [ "$lane" = 5.6 ]; then
  PGACC_REFERENCE_MCRYPT=1
fi
PGACC_REFERENCE_MODE=$mode
PGACC_REFERENCE_LANE=$lane
PGACC_REFERENCE_OWNER=$(id -u):$(id -g)
if [ "$mode" = record ]; then
  PGACC_REFERENCE_OUT=$repo_root/tests/reference/php5
else
  PGACC_REFERENCE_OUT=$repo_root/build/reference/$lane_tag
fi
export PGACC_REFERENCE_BASE_IMAGE PGACC_REFERENCE_IMAGE PGACC_REFERENCE_MCRYPT \
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
  lane_compose -f tests/reference/compose.yaml "$@"
}
cleanup()
{
  compose down --volumes --remove-orphans
}
trap cleanup EXIT INT TERM

echo "PHP 5 reference: $mode on PHP $lane with PostgreSQL $PGACC_TEST_PG"
# The lane's own image first; the reference image is built on top of it.
lane_compose build application
compose build application
compose up --detach --wait database
compose run --rm database-test
compose up --detach --wait application
compose run --rm reference-test
