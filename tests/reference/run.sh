#!/bin/sh
set -eu

# Reference recordings (golden master); see tests/README.md.
#
#   sh tests/reference/run.sh record [8.5]    record the scenario on the PHP 8.5 /
#                                             PostgreSQL 18 lane; replaces
#                                             tests/reference/php85/ only if
#                                             the run succeeds (review the
#                                             diff before committing it)
#   sh tests/reference/run.sh compare [8.5]   run the scenario on the PHP 8.5 /
#                                             PostgreSQL 18 lane and compare it
#                                             with tests/reference/php85/
#
# The comparison runs on the same runtime as the recording, so every
# difference fails unless a reviewed tests/reference/allowlist.php entry
# accepts it (none today). PGACC_TEST_ID works
# as for tests/run.sh. Compare results go to build/reference/php85/
# (report.txt, diffs/ and the actual recording).

usage()
{
  echo "Usage: [PGACC_TEST_ID=<id>] sh tests/reference/run.sh record|compare [8.5]" >&2
  exit 2
}

mode=${1:-}
case "$mode" in
  record|compare) lane=${2:-8.5} ;;
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
reference_dir=$repo_root/tests/reference/$lane_tag
if [ "$mode" = record ]; then
  # Recorded per PGACC_TEST_ID outside the tree; tests/reference/php85/ is
  # replaced only after the whole run succeeded (see the end of this script).
  PGACC_REFERENCE_OUT=$repo_root/build/reference/$lane_tag-record$PGACC_TEST_SUFFIX
else
  PGACC_REFERENCE_OUT=$repo_root/build/reference/$lane_tag
fi
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

echo "Reference: $mode on PHP $lane with PostgreSQL 18"
# The lane's own image first; the reference image is built on top of it.
lane_compose build application
compose build application
compose up --detach --wait database
compose run --rm database-test
compose up --detach --wait application
compose run --rm reference-test

if [ "$mode" = record ]; then
  # Move the new recording into place: one rename on the same file system
  # for the new directory, the old one is renamed aside first and removed.
  staged=$reference_dir.new.$$
  previous=$reference_dir.old.$$
  rm -rf "$staged" "$previous"
  mv "$PGACC_REFERENCE_OUT" "$staged"
  if [ -e "$reference_dir" ]; then
    mv "$reference_dir" "$previous"
  fi
  mv "$staged" "$reference_dir"
  rm -rf "$previous"
  echo "Reference recorded in tests/reference/$lane_tag/; review the diff before committing it."
fi
