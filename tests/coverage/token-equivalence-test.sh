#!/bin/sh
# Git-level tests of tests/coverage/token-equivalence.sh, in a throw-away
# repository. Run by sh tests/coverage/token-equivalence.sh
# --self-test.
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
page='<?php\nif ($a) {\n  f();\n}\n?>\n<p>\n  html\n</p>\n'
for name in whitespace code html same moved deleted sub/dir/nested; do
  printf "$page" >"$name.php"
done
printf '<?php\r\nif ($a) {\r\n  f();\r\n}\r\n?>\r\n<p>\r\n</p>\r\n' >crlf.php
git add -A
git -c user.name=test -c user.email=test@example.invalid -c commit.gpgsign=false commit -q -m base
reindented='<?php\nif ($a) {\n    f();\n}\n?>\n<p>\n  html\n</p>\n'
printf "$reindented" >whitespace.php
printf "$reindented" >sub/dir/nested.php
printf '<?php\nif ($a) {\n  g();\n}\n?>\n<p>\n  html\n</p>\n' >code.php
printf '<?php\nif ($a) {\n  f();\n}\n?>\n<p>\n    html\n</p>\n' >html.php
printf '<?php\nif ($a) {\n    f();\n}\n?>\n<p>\n</p>\n' >crlf.php
git mv moved.php renamed.php
printf "$reindented" >renamed.php
git rm -q deleted.php
printf "$reindented" >added.php
git add added.php
printf "$reindented" >untracked.php

equivalent=$(sh "$coverage_dir/token-equivalence.sh" --list-equivalent HEAD 2>/dev/null)
r=0
[ "$equivalent" = "$(printf 'sub/dir/nested.php\nwhitespace.php')" ] || r=1
check '--list-equivalent: in-place whitespace changes only, one root-relative path per line' $r
equivalent=$(sh "$coverage_dir/token-equivalence.sh" --allow-crlf-to-lf --list-equivalent HEAD 2>/dev/null)
r=0
has "$equivalent" crlf.php || r=1
check 'CRLF -> LF in inline HTML is equivalent only with --allow-crlf-to-lf' $r

equivalent=$(cd sub && sh "$coverage_dir/token-equivalence.sh" --list-equivalent HEAD 2>/dev/null)
r=0
[ "$equivalent" = "$(printf 'sub/dir/nested.php\nwhitespace.php')" ] || r=1
check '--list-equivalent from a subdirectory: root-relative paths' $r

status=0
verdicts=$(sh "$coverage_dir/token-equivalence.sh" --check HEAD 2>/dev/null) || status=$?
r=1
if [ "$status" = 1 ] && has "$verdicts" 'whitespace.php: equivalent' \
  && printf '%s\n' "$verdicts" | grep -q '^code.php: token change: ' \
  && printf '%s\n' "$verdicts" | grep -q '^html.php: token change: ' \
  && printf '%s\n' "$verdicts" | grep -q '^crlf.php: token change: ' \
  && ! printf '%s\n' "$verdicts" | grep -q 'renamed\|moved\|deleted\|added\|untracked'; then
  r=0
fi
check '--check: real, inline HTML and newline changes fail; moves, deletions, additions are not checked' $r

status=0
sh "$coverage_dir/token-equivalence.sh" --check no-such-revision >/dev/null 2>&1 || status=$?
r=1
[ "$status" != 2 ] || r=0
check 'an unknown base is exit 2' $r
status=0
sh "$coverage_dir/token-equivalence.sh" --check >/dev/null 2>&1 || status=$?
r=1
[ "$status" != 2 ] || r=0
check 'a usage error is exit 2' $r

if [ "$failures" -gt 0 ]; then
  echo "$failures Git-level token-equivalence test(s) failed"
  exit 1
fi
echo "Git-level token-equivalence tests passed"
