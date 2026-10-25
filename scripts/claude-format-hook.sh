#!/bin/sh
# Claude Code PostToolUse hook (.claude/settings.json, matcher Edit|Write):
# formats the edited file with scripts/format.sh if it is a PHP file inside
# this repository. format.sh itself skips files outside the formatter's scope
# (vendor/, fixtures, recordings; --path-mode=intersection). Reads the hook
# JSON from stdin (.tool_input.file_path, via jq).
#
# Never blocks the agent: always exits 0, is silent on success and prints at
# most one short line when something goes wrong.

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

if ! sh "$repo_dir/scripts/format.sh" "$file" >/dev/null 2>&1; then
  echo "format hook: scripts/format.sh failed for ${file#"$repo_dir"/} (run it manually)"
fi
exit 0
