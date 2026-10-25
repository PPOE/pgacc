#!/bin/sh
set -eu

# Local development loop (tests/README.md, "Development loop"): a
# long-running PHP 8.5 x PostgreSQL 18 stack with the checkout bind-mounted
# read-only (compose.dev.yaml), so edits are live without a rebuild. No
# coverage; tests/run.sh and CI stay the authoritative checks.
#
#   sh tests/dev.sh up                  start or update the stack
#   sh tests/dev.sh test [name...]      run HTTP test files, e.g. http-import
#                                       (default: all, in the lane's order)
#   sh tests/dev.sh unit [phpunit args] PHPUnit without Xdebug, e.g.
#                                       --filter ReceiptStorageTest
#   sh tests/dev.sh down                stop it and remove its volumes
#   sh tests/dev.sh clean               down, and remove its image
#
# Before each test file (and before PHPUnit) the database is recreated from a
# template built from the fixtures, and the receipt files and diagnostics are
# removed (tests/dev/reset.php). The image is rebuilt only when
# tests/php-runtime.Dockerfile or the composer files change.
#
# PGACC_TEST_ID (default: the user name) isolates the stack and its image:
# project pgacc-dev-<id>, image pgacc-test-application-dev-<id>.
test_id=${PGACC_TEST_ID:-$(id -un | tr 'A-Z' 'a-z' | tr -c 'a-z0-9_\n-' '-')}
case "$test_id" in
  '' | *[!a-z0-9_-]*)
    echo "PGACC_TEST_ID may only contain a-z, 0-9, '_' and '-'" >&2
    exit 2
    ;;
esac
PGACC_TEST_SUFFIX=-dev-$test_id
export PGACC_TEST_SUFFIX
image=pgacc-test-application$PGACC_TEST_SUFFIX

repo_root=$(cd "$(dirname "$0")/.." && pwd)
cd "$repo_root"

usage()
{
  echo "Usage: sh tests/dev.sh up | test [name...] | unit [phpunit args] | down | clean" >&2
  exit 2
}

compose()
{
  docker compose --project-name "pgacc-dev-$test_id" \
    -f compose.test.yaml -f compose.dev.yaml "$@"
}

# The image inputs that the bind mount does not replace.
image_inputs()
{
  cat tests/php-runtime.Dockerfile composer.json composer.lock | sha256sum | cut -d ' ' -f 1
}

image_label()
{
  docker image inspect --format '{{index .Config.Labels "org.pgacc.dev-inputs"}}' "$image" 2>/dev/null || true
}

in_tester()
{
  compose exec -T application-test "$@"
}

require_stack()
{
  if [ -z "$(compose ps --status running --quiet application-test 2>/dev/null)" ]; then
    echo "The development stack is not running: sh tests/dev.sh up" >&2
    exit 2
  fi
  if [ "$(image_label)" != "$(image_inputs)" ]; then
    echo "Warning: the Dockerfile or composer files changed; run sh tests/dev.sh up" >&2
  fi
}

reset_state()
{
  in_tester php tests/dev/reset.php "$@"
}

[ $# -ge 1 ] || usage
command=$1
shift

case "$command" in
  up)
    [ $# -eq 0 ] || usage
    inputs=$(image_inputs)
    if [ "$(image_label)" != "$inputs" ]; then
      docker build --file tests/php-runtime.Dockerfile --label "org.pgacc.dev-inputs=$inputs" \
        --tag "$image" .
    fi
    # Mount points of the nested mounts (compose.dev.yaml); both directories
    # are ignored by Git and by the image build.
    mkdir -p vendor files
    compose up --detach --wait application application-test
    reset_state
    echo "Development stack pgacc-dev-$test_id is up"
    ;;
  test)
    require_stack
    if [ $# -eq 0 ]; then
      # The HTTP files of tests/application-tests.sh, in its order.
      # shellcheck disable=SC2046 # one word per file name
      set -- $(sed -n 's|^php tests/\([a-z-]*\)\.php .*|\1|p' tests/application-tests.sh \
        | grep -E '^(http-.*|write-workflows)$')
    fi
    for name; do
      name=${name#tests/}
      name=${name%.php}
      case "$name" in
        http-test-helpers | http-diagnostics) usage ;;
        http-[a-z]* | write-workflows) ;;
        *) usage ;;
      esac
      case "$name" in
        *[!a-z-]*) usage ;;
      esac
      [ -f "tests/$name.php" ] || {
        echo "No test file tests/$name.php" >&2
        exit 2
      }
    done
    failed=
    for name; do
      name=${name#tests/}
      name=${name%.php}
      started=$(date +%s)
      # /tmp: logs that the HTTP files append to (connection-failure server).
      reset_state /app/files /diagnostics /tmp
      if in_tester php "tests/$name.php"; then
        echo "PASS $name ($(($(date +%s) - started))s)"
      else
        echo "FAIL $name ($(($(date +%s) - started))s)"
        failed="$failed $name"
      fi
    done
    if [ -n "$failed" ]; then
      echo "Failed:$failed" >&2
      exit 1
    fi
    ;;
  unit)
    require_stack
    reset_state
    in_tester env XDEBUG_MODE=off php vendor/bin/phpunit --configuration phpunit.xml \
      --do-not-cache-result --no-coverage "$@"
    ;;
  down)
    [ $# -eq 0 ] || usage
    compose down --volumes --remove-orphans
    ;;
  clean)
    [ $# -eq 0 ] || usage
    compose down --volumes --remove-orphans
    docker rmi "$image" 2>/dev/null || true
    ;;
  *) usage ;;
esac
