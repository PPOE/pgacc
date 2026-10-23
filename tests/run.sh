#!/bin/sh
set -eu

php_version=${1:-all}

case "$php_version" in
  all)
    sh tests/php-syntax-matrix.sh
    sh tests/run.sh 7.4
    sh tests/run.sh 5.6
    exit
    ;;
  7.4|5.6) ;;
  *)
    echo "Usage: sh tests/run.sh [all|7.4|5.6]" >&2
    exit 2
    ;;
esac

compose()
{
  if [ "$php_version" = 5.6 ]; then
    docker compose --project-name pgacc-test-php56 \
      -f compose.test.yaml -f compose.php56.yaml "$@"
  else
    docker compose --project-name pgacc-test-php74 -f compose.test.yaml "$@"
  fi
}

cleanup()
{
  compose down --volumes --remove-orphans
}

trap cleanup EXIT INT TERM

echo "Running application tests on PHP $php_version"
compose up --detach --wait database
compose run --rm php-lint
compose run --rm database-test
compose up --build --detach --wait application
compose run --rm application-test
