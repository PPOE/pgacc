#!/bin/sh
set -eu

# Infection mutation testing for functions.php on the PHP 7.4 or PHP 8.5 lane.
# Uses a fresh synthetic PostgreSQL database because some helpers query it.
# Extra arguments are passed to Infection, e.g. --min-msi=90 --threads=1.
# Reports are written to tests/mutation/results/<lane> (or
# $PGACC_INFECTION_RESULTS, which must be an absolute path).
# PGACC_INFECTION_TARGET=shim mutates the class wrapper (InfectionShim.php)
# against its own tests instead; its reports go to results/<lane>-shim.

test_id=${PGACC_TEST_ID:-}
case "$test_id" in
  *[!a-z0-9_-]*)
    echo "PGACC_TEST_ID may only contain a-z, 0-9, '_' and '-'" >&2
    exit 2
    ;;
esac
PGACC_TEST_SUFFIX=${test_id:+-$test_id}
export PGACC_TEST_SUFFIX

php_version=${1:-7.4}
[ "$#" -gt 0 ] && shift
case "$php_version" in
  7.4)
    lane=php74
    compose_files="-f compose.test.yaml"
    base_image="pgacc-test-application${PGACC_TEST_SUFFIX}"
    lane_options=""
    ;;
  8.5)
    lane=php85
    compose_files="-f compose.test.yaml -f compose.php85.yaml"
    base_image="pgacc-test-application-php85${PGACC_TEST_SUFFIX}"
    # Infection 0.35 skips uncovered code by default; 0.26 always reports it.
    lane_options="--with-uncovered"
    ;;
  *)
    echo "Usage: sh tests/mutation/infection.sh [7.4|8.5] [infection options]" >&2
    exit 2
    ;;
esac

case "${PGACC_INFECTION_TARGET:-functions}" in
  functions)
    config=/app/tests/mutation/infection.json
    results_name=$lane
    ;;
  shim)
    config=/app/tests/mutation/infection-shim.json
    results_name=$lane-shim
    ;;
  *)
    echo "PGACC_INFECTION_TARGET must be 'functions' or 'shim'" >&2
    exit 2
    ;;
esac

project="pgacc-test${PGACC_TEST_SUFFIX}-infection-${lane}"
image="pgacc-test-infection-${lane}${PGACC_TEST_SUFFIX}"
results=${PGACC_INFECTION_RESULTS:-$(pwd -P)/tests/mutation/results/$results_name}

compose()
{
  # shellcheck disable=SC2086
  docker compose --project-name "$project" $compose_files "$@"
}

cleanup()
{
  compose down --volumes --remove-orphans
}

trap cleanup EXIT INT TERM

compose up --detach --wait database
compose run --rm database-test
compose build application
docker build \
  --file tests/mutation/infection.Dockerfile \
  --build-arg BASE_IMAGE="$base_image" \
  --build-arg INFECTION_LANE="$lane" \
  --tag "$image" \
  tests/mutation/infection

mkdir -p "$results"
rm -f "$results"/infection.log "$results"/summary.log "$results"/infection.json \
  "$results"/per-mutator.md "$results"/infection.html

docker run --rm \
  --network "${project}_test" \
  --read-only \
  --tmpfs /tmp:rw,exec,mode=1777 \
  --user "$(id -u):$(id -g)" \
  --env HOME=/tmp \
  --env PGHOST=database \
  --env PGPORT=5432 \
  --env PGDATABASE=accounting \
  --env PGUSER=pgacc_test \
  --env PGPASSWORD=pgacc_test \
  --env TZ=UTC \
  --volume "$results:/results" \
  --env PGACC_FUNCTIONS_FILE=/tmp/infection-src/functions.php \
  --env PGACC_INFECTION_CONFIG="$config" \
  --workdir /tmp \
  "$image" \
  sh -c 'php -d xdebug.mode=off /app/tests/mutation/infection-shim.php \
      /app/functions.php /tmp/infection-src \
    && exec php -d memory_limit=1G "$(command -v infection)" --configuration="$PGACC_INFECTION_CONFIG" "$@"' \
  infection --threads="$(nproc)" --no-progress --show-mutations $lane_options "$@"
