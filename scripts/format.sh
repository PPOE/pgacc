#!/bin/sh
# Formatting: PER Coding Style 3.0, whitespace rules only
# (.php-cs-fixer.dist.php), with PHP-CS-Fixer pinned in
# tests/style/composer.lock, always on PHP 8.5 with short_open_tag=0. The
# single entry point for CI, the Claude Code hook
# (scripts/claude-format-hook.sh), the opt-in pre-commit hook
# (.githooks/pre-commit) and manual runs.
#
#   sh scripts/format.sh [files...]          format the files (default: the tree)
#   sh scripts/format.sh --check [files...]  dry run: print the diff
#   sh scripts/format.sh --stop              stop this checkout's containers
#   sh scripts/format.sh --stop-all          stop every pgacc-style-* container
#
# Options before the files:
#   --ci          a one-off "docker run --rm" (also CI=true or CI=1); takes
#                 precedence over the other runtimes; used by CI
#   --root=<dir>  a directory inside the repository that holds its own
#                 .php-cs-fixer.dist.php and the files at their repository
#                 paths (the pre-commit hook checks staged blobs this way)
#   --no-build    exit 3 instead of building the image (the Claude hook)
#
# Exit status: PHP-CS-Fixer's bitmask (0 clean; 8 a file needs formatting;
# 4 a file has a syntax error; 1, 16, 32, 64 fixer errors), 2 usage or
# infrastructure error, 3 image missing with --no-build.
#
# Runtime, the first that applies:
#   1. --ci or CI=true/1: a one-off container;
#   2. a host PHP 8.5.x with PHP-CS-Fixer from "composer install -d
#      tests/style" at the version of tests/style/composer.lock;
#   3. warm containers, started once (docker run -d ... sleep infinity) and
#      reused with docker exec: pgacc-style-<checkout hash>-<image hash>-ro
#      for --check (repository read-only) and ...-rw for formatting. A lock
#      (build/php-cs-fixer/start.lock) serializes their start; a running
#      container is never removed; one whose mounts or image do not match is
#      not used (a one-off container runs instead). --stop removes them.
# Containers have no network, a read-only root, no capabilities and
# no-new-privileges (where the Docker host allows it). In fix mode the
# repository is mounted read-write (the fixer writes the files) and .git
# read-only; the residual risk is a fixer that writes other files of the
# checkout. The fixer cache lives in build/php-cs-fixer/.
#
# Files outside the config's scope (vendor/, tests/fixtures/, the reference
# recordings, the canaries, see .php-cs-fixer.dist.php) are skipped
# (--path-mode=intersection).
set -eu

usage() {
  echo "Usage: sh scripts/format.sh [--check] [--ci] [--root=<dir>] [--no-build] [files...] | --stop | --stop-all" >&2
  exit 2
}

repo_dir=$(cd "$(dirname "$0")/.." && pwd -P) || exit 2
mode=fix
case "${CI:-}" in
  true|1) ci=true ;;
  *) ci=false ;;
esac
root=$repo_dir
build=true
while [ $# -gt 0 ]; do
  case "$1" in
    --check) mode=check ;;
    --ci) ci=true ;;
    --no-build) build=false ;;
    --stop) mode=stop ;;
    --stop-all) mode=stop-all ;;
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

repo_hash=$(printf '%s' "$repo_dir" | sha256sum | cut -c1-10)
if [ "$mode" = stop ] || [ "$mode" = stop-all ]; then
  if [ "$mode" = stop ]; then
    filter="name=pgacc-style-$repo_hash-"
  else
    filter=name=pgacc-style-
  fi
  for name in $(docker ps -aq --filter "$filter" 2>/dev/null); do
    docker rm -f "$name" >/dev/null 2>&1 || true
  done
  exit 0
fi
[ -f "$root/.php-cs-fixer.dist.php" ] || {
  echo "format.sh: no .php-cs-fixer.dist.php in $root" >&2
  exit 2
}

state=$repo_dir/build/php-cs-fixer
mkdir -p "$state" || exit 2

# Runtime.
host_php=
fixer_version=$(sed -n '/"name": "friendsofphp\/php-cs-fixer"/{n;s/.*"version": "\(.*\)".*/\1/p;}' \
  "$repo_dir/tests/style/composer.lock")
installed=$repo_dir/tests/style/vendor/composer/installed.json
if [ "$ci" != true ] && [ -f "$installed" ] \
  && grep -A1 '"name": "friendsofphp/php-cs-fixer"' "$installed" | grep -q "\"version\": \"$fixer_version\""; then
  for candidate in php8.5 php; do
    if command -v "$candidate" >/dev/null 2>&1 \
      && "$candidate" -r 'exit(PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600 ? 0 : 1);' 2>/dev/null; then
      host_php=$candidate
      break
    fi
  done
fi
if [ "$ci" = true ]; then
  runtime=ci
elif [ -n "$host_php" ]; then
  runtime=host
else
  runtime=warm
fi
if [ "$runtime" = host ]; then
  prefix=$repo_dir
else
  prefix=/app
fi
inner_root=$prefix${root#"$repo_dir"}

# The files, as fixer paths (only the part relative to $root is checked).
count=$#
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
    -*|*"
"*)
      echo "format.sh: unsupported file name: $path" >&2
      exit 2
      ;;
  esac
  set -- "$@" "$inner_root/$path"
done
shift "$count"
[ $# -eq 0 ] || set -- --path-mode=intersection "$@"
if [ "$runtime" = ci ] || [ "$root" != "$repo_dir" ]; then
  set -- --using-cache=no "$@"
else
  set -- --using-cache=yes "--cache-file=$prefix/build/php-cs-fixer/$mode.cache" "$@"
fi
set -- "--config=$inner_root/.php-cs-fixer.dist.php" --show-progress=none --no-interaction "$@"
if [ "$mode" = check ]; then
  set -- check --diff "$@"
else
  set -- fix "$@"
fi

if [ "$runtime" = host ]; then
  # OPcache's file cache keeps the fixer's compiled code between runs
  # (about 0.3 s instead of 0.7 s per file); ignored where OPcache is missing.
  opcache_dir="$repo_dir/build/opcache-cli"
  mkdir -p "$opcache_dir"
  exec "$host_php" -d short_open_tag=0 -d memory_limit=1G \
    -d opcache.enable_cli=1 -d opcache.file_cache="$opcache_dir" -d opcache.file_cache_only=1 \
    "$repo_dir/tests/style/vendor/bin/php-cs-fixer" "$@"
fi

# The image, built once per Dockerfile/lock content.
hash=$(cat "$repo_dir/tests/style/Dockerfile" "$repo_dir/tests/style/composer.json" \
  "$repo_dir/tests/style/composer.lock" | sha256sum | cut -c1-12)
image=pgacc-style:$hash
if ! docker image inspect "$image" >/dev/null 2>&1; then
  if [ "$build" = false ]; then
    echo "format.sh: the formatter image is not built yet; run sh scripts/format.sh once" >&2
    exit 3
  fi
  docker build --quiet --tag "$image" --file "$repo_dir/tests/style/Dockerfile" \
    "$repo_dir/tests/style" >/dev/null || exit 2
fi

# no-new-privileges, unless this Docker host refuses to exec under it
# ("operation not permitted", exit >= 125). The verdict is cached per image;
# other failures are not cached (no-new-privileges is then kept).
nnp_marker=$state/no-new-privileges-$hash
if [ ! -f "$nnp_marker" ]; then
  probe_status=0
  docker run --rm --network none --security-opt no-new-privileges "$image" true \
    >/dev/null 2>"$state/nnp.err.$$" || probe_status=$?
  verdict=
  if [ "$probe_status" -eq 0 ]; then
    verdict=yes
  elif [ "$probe_status" -ge 125 ] && grep -q 'operation not permitted' "$state/nnp.err.$$"; then
    verdict=no
    echo "format.sh: this Docker host refuses no-new-privileges; running without it" >&2
  fi
  rm -f "$state/nnp.err.$$"
  if [ -n "$verdict" ]; then
    echo "$verdict" >"$nnp_marker.$$" && mv "$nnp_marker.$$" "$nnp_marker"
  fi
fi
security="--cap-drop ALL"
if [ "$(cat "$nnp_marker" 2>/dev/null || echo yes)" = yes ]; then
  security="$security --security-opt no-new-privileges"
fi

# Mounts: the repository read-only for --check (with the cache directory
# writable), read-write for formatting; .git always read-only.
if [ -e "$repo_dir/.git" ]; then
  git_mount="type=bind,src=$repo_dir/.git,dst=/app/.git,readonly"
else
  git_mount="type=tmpfs,dst=/app/.git"
fi
if [ "$mode" = check ]; then
  access=ro
  repo_mount="type=bind,src=$repo_dir,dst=/app,readonly"
  cache_mount="type=bind,src=$state,dst=/app/build/php-cs-fixer"
else
  access=rw
  repo_mount="type=bind,src=$repo_dir,dst=/app"
  # build/php-cs-fixer is part of the writable repository mount; a second,
  # harmless mount keeps the docker run lines identical.
  cache_mount="type=tmpfs,dst=/tmp/unused-cache-mount"
fi
one_off() {
  # shellcheck disable=SC2086 # $security: plain words
  exec docker run --rm --network none --read-only --tmpfs /tmp:exec,mode=1777 $security \
    --user "$(id -u):$(id -g)" --mount "$repo_mount" --mount "$cache_mount" --mount "$git_mount" \
    "$image" php /tools/vendor/bin/php-cs-fixer "$@"
}
if [ "$runtime" = ci ]; then
  one_off "$@"
fi

# The warm container for this checkout, image and access.
container=pgacc-style-$repo_hash-$hash-$access
expected_mounts="$repo_dir:/app:$([ "$access" = rw ] && echo true || echo false)"
inspect() {
  docker inspect -f '{{.Config.Image}} {{.State.Running}} {{range .Mounts}}{{.Source}}:{{.Destination}}:{{.RW}} {{end}}' \
    "$container" 2>/dev/null || true
}
usable() {
  case "$1" in
    "$image true "*" $expected_mounts "*|"$image true $expected_mounts "*) return 0 ;;
  esac
  return 1
}
current=$(inspect)
if ! usable "$current"; then
  # Serialize the start with a lock directory; a lock older than two
  # minutes is a leftover of an interrupted run.
  lock=$state/start.lock
  waited=0
  until mkdir "$lock" 2>/dev/null; do
    if [ -n "$(find "$lock" -maxdepth 0 -mmin +2 2>/dev/null)" ]; then
      rmdir "$lock" 2>/dev/null || true
      continue
    fi
    waited=$((waited + 1))
    if [ "$waited" -gt 600 ]; then
      echo "format.sh: timed out waiting for $lock" >&2
      exit 2
    fi
    sleep 0.1 2>/dev/null || sleep 1
  done
  trap 'rmdir "$lock" 2>/dev/null || true' EXIT
  current=$(inspect)
  if ! usable "$current"; then
    case "$current" in
      '') ;;
      *" false "*)
        # Stopped: safe to replace.
        docker rm -f "$container" >/dev/null 2>&1 || true
        current=
        ;;
    esac
    if [ -z "$current" ]; then
      # shellcheck disable=SC2086 # $security: plain words
      if ! docker run -d --name "$container" --network none --read-only --tmpfs /tmp:exec,mode=1777 $security \
        --user "$(id -u):$(id -g)" --mount "$repo_mount" --mount "$cache_mount" --mount "$git_mount" \
        --label "pgacc.style.repository=$repo_dir" "$image" sleep infinity >/dev/null 2>&1; then
        : # A name conflict: someone else started it; inspect again below.
      fi
      current=$(inspect)
    fi
  fi
  rmdir "$lock" 2>/dev/null || true
  trap - EXIT
  if ! usable "$current"; then
    # Running, but with other mounts or another image: never removed here
    # (sh scripts/format.sh --stop cleans up); use a one-off container.
    echo "format.sh: $container does not match this checkout; using a one-off container" >&2
    one_off "$@"
  fi
fi
exec docker exec "$container" php /tools/vendor/bin/php-cs-fixer "$@"
