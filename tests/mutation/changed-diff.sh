#!/bin/sh
# The changed lines for tests/mutation/run.sh --changed-since=<base>: a
# "git diff --unified=0" of the working tree against the merge base of
# <base> and HEAD, untracked files included (as additions). Files that
# tests/coverage/token-equivalence.sh proves token-equivalent (whitespace-only
# changes) are left out, as for the changed-line coverage gate; if that check
# cannot run, nothing is left out.
#
#   sh tests/mutation/changed-diff.sh <base> >changed.diff
set -eu

[ $# -eq 1 ] || {
  echo "Usage: sh tests/mutation/changed-diff.sh <base>" >&2
  exit 2
}
coverage_dir=$(cd "$(dirname "$0")/../coverage" && pwd -P)
cd "$(git rev-parse --show-toplevel)"
merge_base=$(git merge-base "$1" HEAD)
if ! excludes=$(sh "$coverage_dir/token-equivalence.sh" --exclude-pathspecs "$merge_base"); then
  echo "warning: token-equivalence check unavailable: every changed line selects" >&2
  excludes=
fi
# shellcheck disable=SC2086 # $excludes: one plain pathspec per line
git diff --no-color --no-ext-diff --unified=0 "$merge_base" -- ':(top)' $excludes
git ls-files --others --exclude-standard | while IFS= read -r file; do
  git diff --no-color --no-ext-diff --unified=0 --no-index /dev/null "$file" || true
done
