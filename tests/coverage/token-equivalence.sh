#!/bin/sh
# Token-equivalence check of the PHP files changed since a Git base
# (tests/coverage/token-equivalence.php), in the repository of the current
# directory.
#
#   sh tests/coverage/token-equivalence.sh [--allow-crlf-to-lf] --check <base>
#       one verdict per modified PHP file; exit 1 if any differs
#   sh tests/coverage/token-equivalence.sh [--allow-crlf-to-lf] --list-equivalent <base>
#       only the equivalent paths, relative to the repository root
#   sh tests/coverage/token-equivalence.sh [--allow-crlf-to-lf] --exclude-pathspecs <base>
#       the same as ":(top,exclude,literal)<path>" pathspecs, for the
#       changed-line gates (changed-lines.sh, tests/mutation/changed-diff.sh)
#   sh tests/coverage/token-equivalence.sh --self-test
#       token-equivalence-test.php and token-equivalence-test.sh
#
# Compares the merge base of <base> and HEAD with the working tree. Only
# files modified in place can be equivalent: added, deleted, renamed or
# copied files (a move changes __DIR__, __FILE__, include paths and URLs) and
# files with names other than [A-Za-z0-9_./-] are never listed. Exit 2 when
# the check cannot run (no merge base, no PHP 8.5 runtime, any setup error):
# callers then gate every changed file.
#
# Each file is tokenized twice, with short_open_tag=0 and =1 (the
# application enables short tags in .htaccess), and must be equivalent in
# both. Runtime: a host PHP 8.5 with the tokenizer under -n (php8.5, or php
# if it is 8.5+; -d extension=tokenizer where it is a shared extension),
# otherwise the pinned php:8.5-cli image (docker run, no network, read-only
# mounts, no capabilities, no-new-privileges where the Docker host allows it).
#
# --allow-crlf-to-lf (also set by PGACC_TOKEN_EQUIVALENCE_ALLOW_CRLF=1): a
# CRLF in a string or inline HTML of the old file may be an LF in the new one.
# Used once, by the CI runs of the formatting pull request that converted
# the CRLF files (.github/workflows/tests.yaml); remove it from there after
# that merge. Without it any newline change in output is a token change.
set -eu

image=php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37
tool_dir=$(cd "$(dirname "$0")" && pwd -P) || exit 2
# Work directories live in this checkout's build/ (ignored by Git), where a
# Docker daemon can mount them.
make_work() {
  mkdir -p "$tool_dir/../../build" && mktemp -d "$tool_dir/../../build/token-equivalence.XXXXXX"
}

# A host PHP 8.5 that has token_get_all() under -n, with the flags it needs.
host_php=
host_flags=
probe='exit(PHP_VERSION_ID >= 80500 && function_exists("token_get_all") ? 0 : 1);'
for candidate in php8.5 php; do
  command -v "$candidate" >/dev/null 2>&1 || continue
  if "$candidate" -n -d short_open_tag=0 -r "$probe" 2>/dev/null; then
    host_php=$candidate
    break
  fi
  if "$candidate" -n -d extension=tokenizer -d short_open_tag=0 -r "$probe" 2>/dev/null; then
    host_php=$candidate
    host_flags='-d extension=tokenizer'
    break
  fi
done

# run_php <work dir> <short_open_tag> <file> <arguments>: PHP file <file> of
# tests/coverage/ with <work dir> readable; arguments equal to <work dir>
# become its path in the container.
run_php() {
  work=$1
  short=$2
  file=$3
  shift 3
  if [ -n "$host_php" ]; then
    # shellcheck disable=SC2086 # $host_flags: empty or plain words
    "$host_php" -n $host_flags -d "short_open_tag=$short" "$tool_dir/$file" "$@"
    return
  fi
  if ! command -v docker >/dev/null 2>&1; then
    echo "token-equivalence: no PHP 8.5 with the tokenizer on the host and no docker" >&2
    return 2
  fi
  translated=
  for argument; do
    case "$argument" in
      "$work") argument=/work ;;
    esac
    translated="$translated $argument"
  done
  set -- --rm --network none --read-only --tmpfs /tmp:mode=1777 --cap-drop ALL --user "$(id -u):$(id -g)" \
    --mount "type=bind,src=$tool_dir,dst=/tool,readonly" \
    --mount "type=bind,src=$work,dst=/work,readonly"
  # shellcheck disable=SC2086 # $translated: plain words (flags and /work)
  if docker run "$@" --security-opt no-new-privileges "$image" \
    php -n -d "short_open_tag=$short" "/tool/$file" $translated 2>"$work.err"; then
    rm -f "$work.err"
    return 0
  else
    docker_status=$?
  fi
  # Some Docker hosts cannot exec under no-new-privileges ("operation not
  # permitted" before PHP starts): retry without it, with a warning.
  if [ "$docker_status" -ge 125 ] && grep -q 'operation not permitted' "$work.err"; then
    rm -f "$work.err"
    echo "token-equivalence: this Docker host refuses no-new-privileges; running without it" >&2
    # shellcheck disable=SC2086
    docker run "$@" "$image" php -n -d "short_open_tag=$short" "/tool/$file" $translated
    return
  fi
  cat "$work.err" >&2
  rm -f "$work.err"
  return "$docker_status"
}

allow=
if [ "${PGACC_TOKEN_EQUIVALENCE_ALLOW_CRLF:-}" = 1 ]; then
  allow=--allow-crlf-to-lf
fi
if [ "${1:-}" = --allow-crlf-to-lf ]; then
  allow=--allow-crlf-to-lf
  shift
fi

if [ "${1:-}" = --self-test ]; then
  work=$(make_work) || exit 2
  trap 'rm -rf "$work"' EXIT
  status=0
  run_php "$work" 0 token-equivalence-test.php || status=$?
  [ "$status" -eq 0 ] || exit "$status"
  sh "$tool_dir/token-equivalence-test.sh"
  exit
fi

case "${1:-}" in
  --check|--list-equivalent|--exclude-pathspecs) mode=$1 ;;
  *)
    echo "Usage: sh tests/coverage/token-equivalence.sh [--allow-crlf-to-lf] --check|--list-equivalent|--exclude-pathspecs <base> | --self-test" >&2
    exit 2
    ;;
esac
if [ $# -ne 2 ]; then
  echo "token-equivalence: missing <base>" >&2
  exit 2
fi
merge_base=$(git merge-base "$2" HEAD) || exit 2
top=$(git rev-parse --show-toplevel) || exit 2

work=$(make_work) || exit 2
trap 'rm -rf "$work"' EXIT
mkdir "$work/old" "$work/new" || exit 2
: >"$work/list" || exit 2
: >"$work/skipped" || exit 2
# Modifications in place only: no rename detection, so a move is a deletion
# plus an addition, and neither is listed.
git -C "$top" diff --no-color --no-ext-diff --no-renames --name-only --diff-filter=M "$merge_base" -- '*.php' \
  >"$work/changed" || exit 2
while IFS= read -r path; do
  case "$path" in
    *[!A-Za-z0-9_./-]*|-*)
      echo "$path: not checked (unsupported file name)" >>"$work/skipped" || exit 2
      continue
      ;;
  esac
  mkdir -p "$work/old/$(dirname "$path")" "$work/new/$(dirname "$path")" || exit 2
  git -C "$top" cat-file blob "$merge_base:$path" >"$work/old/$path" || exit 2
  cp "$top/$path" "$work/new/$path" || exit 2
  echo "$path" >>"$work/list" || exit 2
done <"$work/changed"

# Both short_open_tag settings; a file is equivalent only if it is in both.
: >"$work/verdicts" || exit 2
[ -s "$work/list" ] && for short in 0 1; do
  status=0
  # shellcheck disable=SC2086 # $allow: empty or one flag
  run_php "$work" "$short" token-equivalence.php "--short-open-tag=$short" $allow --dir "$work" \
    >"$work/verdicts-$short" || status=$?
  if [ "$status" -ge 2 ]; then
    exit 2
  fi
done
# "path: verdict" of the first setting, unless the second one disagrees.
[ ! -s "$work/list" ] || awk -F': ' '
  FNR == NR { first[$1] = $0; order[++n] = $1; next }
  {
    second = substr($0, length($1) + 3)
    if (second !~ /^equivalent/ && substr(first[$1], length($1) + 3) ~ /^equivalent/) {
      first[$1] = $1 ": " second " (short_open_tag=1)"
    }
  }
  END { for (i = 1; i <= n; i++) print first[order[i]] }
' "$work/verdicts-0" "$work/verdicts-1" >"$work/verdicts" || exit 2

case "$mode" in
  --check)
    cat "$work/verdicts" "$work/skipped" || exit 2
    if [ -s "$work/skipped" ] || grep -qv ': equivalent' "$work/verdicts"; then
      exit 1
    fi
    ;;
  --list-equivalent)
    sed -n 's/: equivalent\( (CRLF -> LF in output tokens)\)\{0,1\}$//p' "$work/verdicts" || exit 2
    ;;
  --exclude-pathspecs)
    sed -n 's/^\(.*\): equivalent\( (CRLF -> LF in output tokens)\)\{0,1\}$/:(top,exclude,literal)\1/p' \
      "$work/verdicts" || exit 2
    ;;
esac
