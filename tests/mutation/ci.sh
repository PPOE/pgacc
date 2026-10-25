#!/bin/sh
set -eu

# Mutation step of the CI lanes (.github/workflows/tests.yaml), also usable
# locally:
#
#   sh tests/mutation/ci.sh 8.5 [--changed-since=<base>]
#
# Runs the runner self-test, then every target of the list below (with
# --changed-since: only the mutations whose search region overlaps a changed
# line, see tests/mutation/run.sh). Every target is attempted; the exit status
# is nonzero if any failed. PGACC_TEST_ID works as for tests/mutation/run.sh.
#
# Every committed target is listed.

usage()
{
  echo "Usage: sh tests/mutation/ci.sh 8.5 [--changed-since=<base>]" >&2
  exit 2
}

[ $# -ge 1 ] || usage
lane=$1
shift
case "$lane" in
  8.5)
    targets='rights2orgasql null-arguments login-logout redirects rights-checks receipt-crypto
      sql-injection read-only-rights import-checks listing-columns report-prevcond donations amount-parsing
      output-escaping amount-cents csrf login-session'
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
