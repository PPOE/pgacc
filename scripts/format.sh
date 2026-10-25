#!/bin/sh
# Formatting: PER Coding Style 3.0, whitespace rules only
# (.php-cs-fixer.dist.php), with PHP-CS-Fixer 3.95.27 pinned in
# tests/style/composer.lock, always on PHP 8.5 with short_open_tag=0. The
# single entry point for CI, the Claude Code hook
# (scripts/claude-format-hook.sh), the opt-in pre-commit hook
# (.githooks/pre-commit) and manual runs.
#
#   sh scripts/format.sh [files...]          format the files (default: the tree)
#   sh scripts/format.sh --check [files...]  dry run: print the diff; exit 8
#                                            (PHP-CS-Fixer) if a file needs it
#   sh scripts/format.sh --stop              stop the warm container (below)
#
# Options before the files:
#   --ci          one-off "docker run --rm" (also when CI=true); used by CI
#   --root=<dir>  a directory inside the repository that holds its own
#                 .php-cs-fixer.dist.php and the files at their repository
#                 paths (the pre-commit hook checks staged blobs this way)
#
# Runtime, the first that applies:
#   1. a host PHP 8.5 (php8.5, or php if it is 8.5+) with the fixer installed
#      by "composer install -d tests/style";
#   2. a warm container, pgacc-style-<hash of the repository path>, started
#      once (docker run -d ... sleep infinity) and reused with docker exec;
#      sh scripts/format.sh --stop removes it;
#   3. with --ci or CI=true: a one-off docker run --rm.
# Containers have no network, a read-only root, no capabilities and
# no-new-privileges (where the Docker host allows it); the repository's .git
# is mounted read-only. The fixer cache lives in build/php-cs-fixer/.
#
# Files outside the config's scope (vendor/, tests/fixtures/, the reference
# recordings, the canaries, see .php-cs-fixer.dist.php) are skipped
# (--path-mode=intersection). Exit 2: usage or infrastructure error.
set -eu

usage() {
  echo "Usage: sh scripts/format.sh [--check] [--ci] [--root=<dir>] [files...] | --stop" >&2
  exit 2
}

repo_dir=$(cd "$(dirname "$0")/.." && pwd -P)
mode=fix
ci=${CI:-false}
root=$repo_dir
while [ $# -gt 0 ]; do
  case "$1" in
    --check) mode=check ;;
    --ci) ci=true ;;
    --stop) mode=stop ;;
    --root=*)
      root=$(cd "${1#--root=}" 2>/dev/null && pwd -P) || usage
      case "$root/" in
        "$repo_dir"/*) ;;
        *) usage ;;
      esac
      ;;
    --) shift; break ;;
    -*) usage ;;
    *) break ;;
  esac
  shift
done
[ -f "$root/.php-cs-fixer.dist.php" ] || {
  echo "format.sh: no .php-cs-fixer.dist.php in $root" >&2
  exit 2
}

repo_hash=$(printf '%s' "$repo_dir" | sha256sum | cut -c1-10)
container=pgacc-style-$repo_hash
if [ "$mode" = stop ]; then
  docker rm -f "$container" >/dev/null 2>&1 || true
  exit 0
fi

# The files as paths relative to $root (only those are validated).
relative=
for file; do
  case "$file" in
    /*) absolute=$file ;;
    *) absolute=$(pwd -P)/$file ;;
  esac
  directory=$(cd "$(dirname "$absolute")" 2>/dev/null && pwd -P) || {
    echo "format.sh: no such file: $file" >&2
    exit 2
  }
  absolute=$directory/$(basename "$absolute")
  case "$absolute" in
    "$root"/*) path=${absolute#"$root"/} ;;
    *)
      echo "format.sh: not inside $root: $file" >&2
      exit 2
      ;;
  esac
  case "$path" in
    *[!A-Za-z0-9_./-]*|-*)
      echo "format.sh: unsupported file name: $path" >&2
      exit 2
      ;;
  esac
  relative="$relative $path"
done

mkdir -p "$repo_dir/build/php-cs-fixer"

# Runtime: host, CI (one-off container) or the warm container.
host_php=
if command -v php8.5 >/dev/null 2>&1; then
  host_php=php8.5
elif command -v php >/dev/null 2>&1 && php -n -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);' 2>/dev/null; then
  host_php=php
fi
if [ "$ci" != true ] && [ -n "$host_php" ] && [ -f "$repo_dir/tests/style/vendor/bin/php-cs-fixer" ]; then
  runtime=host
  prefix=$repo_dir
elif [ "$ci" = true ]; then
  runtime=ci
  prefix=/app
else
  runtime=warm
  prefix=/app
fi

# The fixer arguments, as the positional parameters.
inner_root=$prefix${root#"$repo_dir"}
if [ "$mode" = check ]; then
  set -- check --diff
else
  set -- fix
fi
set -- "$@" "--config=$inner_root/.php-cs-fixer.dist.php" --show-progress=none --no-interaction
if [ "$runtime" = ci ] || [ "$root" != "$repo_dir" ]; then
  set -- "$@" --using-cache=no
else
  set -- "$@" --using-cache=yes "--cache-file=$prefix/build/php-cs-fixer/$mode.cache"
fi
if [ -n "$relative" ]; then
  set -- "$@" --path-mode=intersection
  for path in $relative; do
    set -- "$@" "$inner_root/$path"
  done
fi

# 1. Host PHP 8.5 with the fixer from composer install -d tests/style.
if [ "$runtime" = host ]; then
  exec "$host_php" -d short_open_tag=0 -d memory_limit=1G "$repo_dir/tests/style/vendor/bin/php-cs-fixer" "$@"
fi

# The image, built once per Dockerfile/lock content.
hash=$(cat "$repo_dir/tests/style/Dockerfile" "$repo_dir/tests/style/composer.json" \
  "$repo_dir/tests/style/composer.lock" | sha256sum | cut -c1-12)
image=pgacc-style:$hash
if ! docker image inspect "$image" >/dev/null 2>&1; then
  docker build --quiet --tag "$image" --file "$repo_dir/tests/style/Dockerfile" \
    "$repo_dir/tests/style" >/dev/null || exit 2
fi
# no-new-privileges, unless this Docker host cannot exec under it (probed
# once per image).
nnp_marker=$repo_dir/build/php-cs-fixer/no-new-privileges-$hash
if [ ! -f "$nnp_marker" ]; then
  if docker run --rm --network none --security-opt no-new-privileges "$image" true >/dev/null 2>&1; then
    echo yes >"$nnp_marker"
  else
    echo no >"$nnp_marker"
  fi
fi
security="--cap-drop ALL"
if [ "$(cat "$nnp_marker")" = yes ]; then
  security="$security --security-opt no-new-privileges"
fi
repo_mount="type=bind,src=$repo_dir,dst=/app"
git_mount="type=bind,src=$repo_dir/.git,dst=/app/.git,readonly"
[ -e "$repo_dir/.git" ] || git_mount="type=tmpfs,dst=/app/.git"

# 3. CI: a one-off container; the repository is read-only in check mode.
if [ "$runtime" = ci ]; then
  [ "$mode" = check ] && repo_mount="$repo_mount,readonly"
  # shellcheck disable=SC2086 # $security: plain words
  exec docker run --rm --network none --read-only --tmpfs /tmp:exec,mode=1777 $security \
    --user "$(id -u):$(id -g)" --mount "$repo_mount" --mount "$git_mount" "$image" \
    php /tools/vendor/bin/php-cs-fixer "$@"
fi

# 2. The warm container (recreated when the image changed or it stopped).
if [ "$(docker inspect -f '{{.Config.Image}} {{.State.Running}}' "$container" 2>/dev/null || true)" != "$image true" ]; then
  docker rm -f "$container" >/dev/null 2>&1 || true
  # shellcheck disable=SC2086 # $security: plain words
  docker run -d --name "$container" --network none --read-only --tmpfs /tmp:exec,mode=1777 $security \
    --user "$(id -u):$(id -g)" --mount "$repo_mount" --mount "$git_mount" \
    --label "pgacc.style.repository=$repo_dir" "$image" sleep infinity >/dev/null || exit 2
fi
exec docker exec "$container" php /tools/vendor/bin/php-cs-fixer "$@"
