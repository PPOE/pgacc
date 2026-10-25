#!/bin/sh
# Git-level tests of tests/coverage/ast-equivalence.sh, in a throw-away
# repository. Run by sh tests/coverage/ast-equivalence.sh --self-test.
set -eu

coverage_dir=$(cd "$(dirname "$0")" && pwd -P)
repo=$(mktemp -d)
trap 'rm -rf "$repo"' EXIT
failures=0
check() {
  if [ "$2" = 0 ]; then
    echo "ok   $1"
  else
    echo "FAIL $1"
    failures=$((failures + 1))
  fi
}
# has <text> <line>: whether <text> has exactly that line.
has() {
  printf '%s\n' "$1" | grep -qxF -- "$2"
}

cd "$repo"
git init -q
mkdir -p sub/dir
page='<?php\n$a = array(1, array(2));\nlist($b, $c) = $a;\n?>\n<p>\n  html\n</p>\n'
for name in arrays string html operator order line moved deleted sub/dir/nested; do
  printf "$page" >"$name.php"
done
git add -A
git -c user.name=test -c user.email=test@example.invalid -c commit.gpgsign=false commit -q -m base
short='<?php\n$a = [1, [2]];\n[$b, $c] = $a;\n?>\n<p>\n  html\n</p>\n'
printf "$short" >arrays.php
printf "$short" >sub/dir/nested.php
printf '<?php\n$a = [1, [2]];\n[$b, $c] = $a;\necho "x";\n?>\n<p>\n  html\n</p>\n' >string.php
printf '<?php\n$a = [1, [2]];\n[$b, $c] = $a;\n?>\n<p>\n    html\n</p>\n' >html.php
printf '<?php\n$a = [1, [2]];\n[$b, $c] = $a + 1;\n?>\n<p>\n  html\n</p>\n' >operator.php
printf '<?php\n[$b, $c] = $a;\n$a = [1, [2]];\n?>\n<p>\n  html\n</p>\n' >order.php
printf '<?php\n$a = [1, [__LINE__]];\n[$b, $c] = $a;\n?>\n<p>\n  html\n</p>\n' >line.php
git mv moved.php renamed.php
printf "$short" >renamed.php
git rm -q deleted.php
printf "$short" >added.php
git add added.php
printf "$short" >untracked.php

equivalent=$(sh "$coverage_dir/ast-equivalence.sh" --list-equivalent HEAD)
r=0
[ "$equivalent" = "$(printf 'arrays.php\nsub/dir/nested.php')" ] || r=1
check '--list-equivalent: in-place array/list spelling changes only, root-relative paths' $r

equivalent=$(cd sub && sh "$coverage_dir/ast-equivalence.sh" --list-equivalent HEAD)
r=0
[ "$equivalent" = "$(printf 'arrays.php\nsub/dir/nested.php')" ] || r=1
check '--list-equivalent from a subdirectory: root-relative paths' $r

status=0
verdicts=$(sh "$coverage_dir/ast-equivalence.sh" --check HEAD) || status=$?
r=1
if [ "$status" = 1 ] && has "$verdicts" 'arrays.php: equivalent' \
  && printf '%s\n' "$verdicts" | grep -q '^string.php: AST change: ' \
  && printf '%s\n' "$verdicts" | grep -q '^html.php: AST change: ' \
  && printf '%s\n' "$verdicts" | grep -q '^operator.php: AST change: ' \
  && printf '%s\n' "$verdicts" | grep -q '^order.php: AST change: ' \
  && printf '%s\n' "$verdicts" | grep -q '^line.php: not provable: the new file uses __LINE__' \
  && ! printf '%s\n' "$verdicts" | grep -q 'renamed\|moved\|deleted\|added\|untracked'; then
  r=0
fi
check '--check: string, inline HTML, operator, order and __LINE__ changes fail; moves, deletions, additions are not equivalent' $r

printf '<?php\n$a = [;\n' >string.php
status=0
sh "$coverage_dir/ast-equivalence.sh" --list-equivalent HEAD >/dev/null 2>&1 || status=$?
r=1
[ "$status" != 2 ] || r=0
check 'a parse error is exit 2' $r
status=0
sh "$coverage_dir/ast-equivalence.sh" --check no-such-revision >/dev/null 2>&1 || status=$?
r=1
[ "$status" != 2 ] || r=0
check 'an unknown base is exit 2' $r
status=0
sh "$coverage_dir/ast-equivalence.sh" --check >/dev/null 2>&1 || status=$?
r=1
[ "$status" != 2 ] || r=0
check 'a usage error is exit 2' $r

if [ "$failures" -gt 0 ]; then
  echo "$failures Git-level ast-equivalence test(s) failed"
  exit 1
fi
echo "Git-level ast-equivalence tests passed"
