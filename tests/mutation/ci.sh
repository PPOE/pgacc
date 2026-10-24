#!/bin/sh
set -eu

# Mutation step of the CI lanes (.github/workflows/tests.yaml), also usable
# locally:
#
#   sh tests/mutation/ci.sh <7.4|8.5> [--changed-since=<base>]
#
# Runs the runner self-test, then every target of the lane's list below
# (with --changed-since: only the mutations whose search region overlaps a
# changed line, see tests/mutation/run.sh). Every target is attempted; the
# exit status is nonzero if any failed. PGACC_TEST_ID and PGACC_TEST_PG work
# as for tests/mutation/run.sh. PHP 5.3 has no mutation step (the runner
# needs PHP's built-in web server, PHP 5.4+).
#
# Targets per lane: those whose unmutated baseline passes there. On PHP 8.5
# the other targets' baselines fail on PHP 8.5 diagnostics that have no
# baseline entry (implicit-connection deprecations; tests/COMPATIBILITY_PLAN.md).

usage()
{
  echo "Usage: sh tests/mutation/ci.sh <7.4|8.5> [--changed-since=<base>]" >&2
  exit 2
}

[ $# -ge 1 ] || usage
lane=$1
shift
case "$lane" in
  7.4)
    targets='rights2orgasql null-arguments login-logout redirects rights-checks receipt-crypto
      sql-injection read-only-rights import-checks listing-columns report-prevcond donations'
    ;;
  8.5)
    targets='rights2orgasql null-arguments'
    ;;
  *) usage ;;
esac
case "${1:-}" in
  ''|--changed-since=?*) ;;
  *) usage ;;
esac

status=0
sh tests/mutation/run.sh "$lane" --self-test || status=1
for target in $targets; do
  echo "== mutation target $target on PHP $lane${1:+ ($1)}"
  sh tests/mutation/run.sh "$lane" "$target" "$@" || status=1
done
exit "$status"
