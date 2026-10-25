#!/bin/sh
# Claude Code PostToolUse hook (.claude/settings.json, matcher Edit|Write):
# formats the edited file with scripts/format.sh if it is a PHP file inside
# this repository. format.sh itself skips files outside the formatter's scope
# (vendor/, fixtures, recordings; --path-mode=intersection). Reads the hook
# JSON from stdin (.tool_input.file_path, via jq).
#
# Never blocks the agent (PostToolUse cannot block): silent with exit 0 on
# success and for files it does not format; exit 2 with one line on stderr
# (shown to the agent) when formatting fails; one line and exit 0 when jq is
# missing or the formatter image is not built yet (no image build inside a
# hook).

repo_dir=$(cd "$(dirname "$0")/.." 2>/dev/null && pwd -P) || exit 0
if ! command -v jq >/dev/null 2>&1; then
  echo "format hook: jq is missing, PHP files are not formatted (run sh scripts/format.sh)"
  exit 0
fi

file=$(jq -r '.tool_input.file_path // empty' 2>/dev/null) || exit 0
case "$file" in
  *.php) ;;
  *) exit 0 ;;
esac
case "$file" in
  /*) ;;
  *) file=$(pwd -P)/$file ;;
esac
directory=$(cd "$(dirname "$file")" 2>/dev/null && pwd -P) || exit 0
file=$directory/$(basename "$file")
[ -f "$file" ] || exit 0
case "$file" in
  "$repo_dir"/*) ;;
  *) exit 0 ;;
esac

status=0
sh "$repo_dir/scripts/format.sh" --no-build "$file" >/dev/null 2>&1 || status=$?
case "$status" in
  0) exit 0 ;;
  3)
    echo "format hook: the formatter image is not built; run sh scripts/format.sh once"
    exit 0
    ;;
  *)
    echo "format hook: sh scripts/format.sh ${file#"$repo_dir"/} failed (exit $status); run it to see why" >&2
    exit 2
    ;;
esac
