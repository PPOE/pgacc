#!/bin/sh
set -eu

# PHP 5 reference recordings (golden master); see tests/README.md.
#
#   sh tests/reference/run.sh record          record on the production pair (PHP 5.3,
#                                             PostgreSQL 8.4) into tests/reference/php5/
#   sh tests/reference/run.sh compare 7.4     run the same scenario on a lane and compare
#   sh tests/reference/run.sh compare 5.3     reproducibility check of the recording
#
# PGACC_TEST_ID and PGACC_TEST_PG work as for tests/run.sh (default: 8.4 for
# PHP 5.3, 18 otherwise). Compare results go to build/reference/php<lane>/
# (report.txt, diffs/ and the actual recording).

usage()
{
  echo "Usage: [PGACC_TEST_ID=<id>] sh tests/reference/run.sh record [5.3] | compare 5.3|7.4|8.5" >&2
  exit 2
}

mode=${1:-}
case "$mode" in
  record) lane=${2:-5.3} ;;
  compare) lane=${2:-} ;;
  *) usage ;;
esac
case "$lane" in
  5.3) pgacc_test_pg_default=8.4 ;;
  7.4|8.5) pgacc_test_pg_default=18 ;;
  *) usage ;;
esac
# Production runs PHP 5.3 with PostgreSQL 8.4 (owner decision: the reference
# is recorded on that pair).
if [ "$mode" = record ] && { [ "$lane" != 5.3 ] || [ "${PGACC_TEST_PG:-8.4}" != 8.4 ]; }; then
  echo "The reference is recorded on PHP 5.3 with PostgreSQL 8.4 only." >&2
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
# libfaketime of the lane image's Debian release (squeeze, bullseye, trixie).
PGACC_REFERENCE_FAKETIME_PACKAGE=libfaketime
reference_files="-f tests/reference/compose.yaml"
case "$lane" in
  # Production-like PHP 5.3 server (squeeze i386); see tests/reference/compose.php53.yaml.
  5.3) lane_files="-f compose.test.yaml -f compose.php53.yaml"; base_image=pgacc-test-application-php53
    PGACC_REFERENCE_FAKETIME=0.8-1 PGACC_REFERENCE_FAKETIME_PACKAGE=faketime
    reference_files="$reference_files -f tests/reference/compose.php53.yaml" ;;
  7.4) lane_files="-f compose.test.yaml"; base_image=pgacc-test-application
    PGACC_REFERENCE_FAKETIME=0.9.8-9 ;;
  8.5) lane_files="-f compose.test.yaml -f compose.php85.yaml"; base_image=pgacc-test-application-php85
    PGACC_REFERENCE_FAKETIME=0.9.10+2024-06-05+gba9ed5b2-0.6 ;;
esac
project="pgacc-reference${PGACC_TEST_SUFFIX}-$lane_tag"

PGACC_REFERENCE_BASE_IMAGE=$base_image$PGACC_TEST_SUFFIX
PGACC_REFERENCE_IMAGE=pgacc-test-reference-$lane_tag$PGACC_TEST_SUFFIX
# PHP 5.3 lane only: the lane's PHP 5.6 client image as the scenario client.
PGACC_REFERENCE_CLIENT_BASE_IMAGE=pgacc-test-client-php56$PGACC_TEST_SUFFIX
PGACC_REFERENCE_CLIENT_IMAGE=pgacc-test-reference-php53-client$PGACC_TEST_SUFFIX
# PHP 5.3 lane only: tag its libpq 8.4 connections through PGOPTIONS, which
# only 9.0+ servers accept (tests/reference/runtime/prepend.php).
PGACC_REFERENCE_PGOPTIONS=0
if [ "$PGACC_TEST_PG" != 8.4 ]; then
  PGACC_REFERENCE_PGOPTIONS=1
fi
PGACC_REFERENCE_MODE=$mode
PGACC_REFERENCE_LANE=$lane
PGACC_REFERENCE_OWNER=$(id -u):$(id -g)
if [ "$mode" = record ]; then
  PGACC_REFERENCE_OUT=$repo_root/tests/reference/php5
else
  PGACC_REFERENCE_OUT=$repo_root/build/reference/$lane_tag
fi
export PGACC_REFERENCE_BASE_IMAGE PGACC_REFERENCE_IMAGE PGACC_REFERENCE_PGOPTIONS PGACC_REFERENCE_FAKETIME \
  PGACC_REFERENCE_FAKETIME_PACKAGE PGACC_REFERENCE_CLIENT_BASE_IMAGE PGACC_REFERENCE_CLIENT_IMAGE \
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

echo "PHP 5 reference: $mode on PHP $lane with PostgreSQL $PGACC_TEST_PG"
# The lane's own image first; the reference image is built on top of it.
lane_compose build application
compose build application
if [ "$lane" = 5.3 ]; then
  # The PHP 5.6 client image and the scenario client image built on it.
  lane_compose build application-test
  compose build reference-test
fi
pgacc_test_pg_build
compose up --detach --wait database
compose run --rm database-test
compose up --detach --wait application
if [ "$lane" = 5.3 ]; then
  compose run --rm reference-helpers
fi
compose run --rm reference-test
