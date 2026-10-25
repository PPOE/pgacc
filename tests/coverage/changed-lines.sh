#!/bin/sh
# Prints the lines added or modified since a Git base, one "file:start-end"
# range per line, for tests/coverage/check-changed-lines.php.
#
# Usage: sh tests/coverage/changed-lines.sh <base> [<pathspec>...]
#   <base>      commit to compare against (merge base is used), e.g. origin/main
#   <pathspec>  relative to the repository root; defaults to '*.php'
#               (without .php-cs-fixer.dist.php)
#
# Compares the merge base with the working tree, so uncommitted changes count.
# Untracked files matching the pathspec count as entirely new. Pure deletions
# add no range.
#
# PGACC_SYNTAX_ONLY=1 (set by CI for the short-array pull request only, to
# be removed after its merge): files modified in place whose syntax tree is
# unchanged (tests/coverage/ast-equivalence.sh: array/list spelling,
# positions, ordinary comments) add no range; every other change is listed
# in full. If that check cannot run, every changed file is listed, with a
# warning. Unset, every changed line counts.
set -eu

if [ $# -lt 1 ]; then
  echo "Usage: sh tests/coverage/changed-lines.sh <base> [<pathspec>...]" >&2
  exit 2
fi

base=$1
shift
if [ $# -eq 0 ]; then
  # The formatter configuration is tooling, not application code (it is
  # export-ignored, see .gitattributes).
  set -- '*.php' ':(exclude).php-cs-fixer.dist.php'
fi

script_dir=$(cd "$(dirname "$0")" && pwd -P)
# Paths and pathspecs are relative to the repository root, from any directory.
cd "$(git rev-parse --show-toplevel)"
merge_base=$(git merge-base "$base" HEAD)

excludes=
if [ "${PGACC_SYNTAX_ONLY:-}" = 1 ]; then
  if excludes=$(sh "$script_dir/ast-equivalence.sh" --exclude-pathspecs "$merge_base"); then
    for path in $excludes; do
      echo "AST-equivalent, not gated (PGACC_SYNTAX_ONLY=1): ${path#:(top,exclude,literal)}" >&2
    done
  else
    echo "warning: AST-equivalence check unavailable: every changed file is gated" >&2
    excludes=
  fi
fi

# shellcheck disable=SC2086 # $excludes: one plain pathspec per line
git diff --no-color --no-ext-diff --unified=0 --diff-filter=AMR "$merge_base" -- "$@" $excludes |
  awk '
    /^\+\+\+ / {
      file = substr($0, 5)
      sub(/^b\//, "", file)
      next
    }
    /^@@ / {
      # @@ -a[,b] +c[,d] @@
      split($3, added, ",")
      start = substr(added[1], 2) + 0
      count = (2 in added) ? added[2] + 0 : 1
      if (count > 0 && file != "/dev/null") {
        printf "%s:%d-%d\n", file, start, start + count - 1
      }
    }
  '

git ls-files --others --exclude-standard -- "$@" |
  while IFS= read -r file; do
    lines=$(awk 'END { print NR }' "$file")
    if [ "$lines" -gt 0 ]; then
      printf '%s:1-%d\n' "$file" "$lines"
    fi
  done
