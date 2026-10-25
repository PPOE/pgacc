#!/bin/sh
# AST-equivalence check of the PHP files changed since a Git base
# (tests/coverage/ast-equivalence.php), in the repository of the current
# directory. A review tool for mechanical syntax changes: it proves that a
# diff (such as array() -> []) leaves every syntax tree unchanged apart from
# positions, ordinary comments and the array/list spelling. No gate uses it;
# the changed-line coverage gate and the mutation selection count every
# changed line (tests/README.md, "Coverage").
#
#   sh tests/coverage/ast-equivalence.sh --check <base>
#       one verdict per modified PHP file; exit 1 if any differs
#   sh tests/coverage/ast-equivalence.sh --list-equivalent <base>
#       only the equivalent paths, relative to the repository root
#   sh tests/coverage/ast-equivalence.sh --self-test
#       ast-equivalence-test.php and ast-equivalence-test.sh
#
# Compares the merge base of <base> and HEAD with the working tree. Only
# files modified in place can be equivalent: added, deleted, renamed or
# copied files (a move changes __DIR__, __FILE__, include paths and URLs) and
# files with names other than [A-Za-z0-9_./-] are never listed (--check
# reports the latter as not proven). Exit 2 when the check cannot run (no
# merge base, no runtime, a parse error, any setup error).
#
# Each file is parsed twice, with short_open_tag=0 and =1 (the application
# enables short tags in .htaccess), and must be equivalent in both.
# Runtime, with nikic/php-parser at the version of tests/ast/composer.lock:
# a host PHP 8.5 (php8.5, or php if it is 8.5+) with "composer install -d
# tests/ast" at that version, otherwise the image built from
# tests/ast/Dockerfile (docker run, no network, read-only mounts and root,
# no capabilities, no-new-privileges where the Docker host allows it).
set -eu

tool_dir=$(cd "$(dirname "$0")" && pwd -P) || exit 2
ast_dir=$(cd "$tool_dir/../ast" && pwd -P) || exit 2
# Work directories live in this checkout's build/ (ignored by Git), where a
# Docker daemon can mount them.
make_work() {
  mkdir -p "$tool_dir/../../build" && mktemp -d "$tool_dir/../../build/ast-equivalence.XXXXXX"
}

parser_version=$(sed -n '/"name": "nikic\/php-parser"/{n;s/.*"version": "\(.*\)".*/\1/p;}' "$ast_dir/composer.lock")
if [ -z "$parser_version" ]; then
  echo "ast-equivalence: no nikic/php-parser version in tests/ast/composer.lock" >&2
  exit 2
fi

# A host PHP 8.5 with tests/ast/vendor at the locked version.
host_php=
installed=$ast_dir/vendor/composer/installed.json
if [ -f "$installed" ] \
  && grep -A1 '"name": "nikic/php-parser"' "$installed" | grep -q "\"version\": \"$parser_version\""; then
  for candidate in php8.5 php; do
    command -v "$candidate" >/dev/null 2>&1 || continue
    if "$candidate" -n -r 'exit(PHP_VERSION_ID >= 80500 && class_exists("PhpToken") ? 0 : 1);' 2>/dev/null; then
      host_php=$candidate
      break
    fi
  done
fi

image=
# The image, built once per Dockerfile/lock content.
ensure_image() {
  [ -z "$image" ] || return 0
  if ! command -v docker >/dev/null 2>&1; then
    echo "ast-equivalence: no host PHP 8.5 with tests/ast/vendor and no docker" >&2
    return 2
  fi
  hash=$(cat "$ast_dir/Dockerfile" "$ast_dir/composer.json" "$ast_dir/composer.lock" | sha256sum | cut -c1-12)
  image=pgacc-ast:$hash
  if ! docker image inspect "$image" >/dev/null 2>&1; then
    docker build --quiet --tag "$image" --file "$ast_dir/Dockerfile" "$ast_dir" >/dev/null || {
      echo "ast-equivalence: cannot build $image" >&2
      return 2
    }
  fi
}

# run_php <work dir> <short_open_tag> <file> <arguments>: PHP file <file> of
# tests/coverage/ with <work dir> readable and the parser options first;
# arguments equal to <work dir> become its path in the container.
run_php() {
  work=$1
  short=$2
  file=$3
  shift 3
  if [ -n "$host_php" ]; then
    "$host_php" -n -d "short_open_tag=$short" "$tool_dir/$file" "--short-open-tag=$short" \
      "--autoload=$ast_dir/vendor/autoload.php" "--parser-version=$parser_version" "$@"
    return
  fi
  ensure_image || return 2
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
  set -- "$@" "$image" php -n -d "short_open_tag=$short" "/tool/$file" "--short-open-tag=$short" \
    --autoload=/tools/vendor/autoload.php "--parser-version=$parser_version"
  # shellcheck disable=SC2086 # $translated: plain words (flags and /work)
  if docker run --security-opt no-new-privileges "$@" $translated 2>"$work.err"; then
    cat "$work.err" >&2
    rm -f "$work.err"
    return 0
  else
    docker_status=$?
  fi
  # Some Docker hosts cannot exec under no-new-privileges ("operation not
  # permitted" before PHP starts): retry without it, with a warning.
  if [ "$docker_status" -ge 125 ] && grep -q 'operation not permitted' "$work.err"; then
    rm -f "$work.err"
    echo "ast-equivalence: this Docker host refuses no-new-privileges; running without it" >&2
    # shellcheck disable=SC2086
    docker run "$@" $translated
    return
  fi
  cat "$work.err" >&2
  rm -f "$work.err"
  return "$docker_status"
}

if [ "${1:-}" = --self-test ]; then
  [ $# -eq 1 ] || exit 2
  work=$(make_work) || exit 2
  trap 'rm -rf "$work" "$work.err"' EXIT
  for short in 0 1; do
    status=0
    run_php "$work" "$short" ast-equivalence-test.php || status=$?
    [ "$status" -eq 0 ] || exit "$status"
  done
  sh "$tool_dir/ast-equivalence-test.sh"
  exit
fi

case "${1:-}" in
  --check|--list-equivalent) mode=$1 ;;
  *)
    echo "Usage: sh tests/coverage/ast-equivalence.sh --check|--list-equivalent <base> | --self-test" >&2
    exit 2
    ;;
esac
if [ $# -ne 2 ]; then
  echo "ast-equivalence: missing <base>" >&2
  exit 2
fi
merge_base=$(git merge-base "$2" HEAD) || exit 2
top=$(git rev-parse --show-toplevel) || exit 2

work=$(make_work) || exit 2
trap 'rm -rf "$work" "$work.err"' EXIT
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
if [ -s "$work/list" ]; then
  for short in 0 1; do
    status=0
    run_php "$work" "$short" ast-equivalence.php --dir "$work" >"$work/verdicts-$short" || status=$?
    if [ "$status" -ge 2 ]; then
      exit 2
    fi
  done
  # Every listed file needs a verdict under both settings.
  for short in 0 1; do
    [ "$(wc -l <"$work/verdicts-$short")" -eq "$(wc -l <"$work/list")" ] || exit 2
  done
  # "path: verdict" of the first setting, unless the second one disagrees.
  awk -F': ' '
    FNR == NR { first[$1] = $0; order[++n] = $1; next }
    {
      second = substr($0, length($1) + 3)
      if (second != "equivalent" && substr(first[$1], length($1) + 3) == "equivalent") {
        first[$1] = $1 ": " second " (short_open_tag=1)"
      }
    }
    END { for (i = 1; i <= n; i++) print first[order[i]] }
  ' "$work/verdicts-0" "$work/verdicts-1" >"$work/verdicts" || exit 2
fi

case "$mode" in
  --check)
    cat "$work/verdicts" "$work/skipped" || exit 2
    if [ -s "$work/skipped" ] || grep -qv ': equivalent$' "$work/verdicts"; then
      exit 1
    fi
    ;;
  --list-equivalent)
    sed -n 's/: equivalent$//p' "$work/verdicts" || exit 2
    ;;
esac
