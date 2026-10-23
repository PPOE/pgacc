#!/bin/sh
set -eu

compose_file="compose.test.yaml"

cleanup()
{
  docker compose -f "$compose_file" down --volumes --remove-orphans
}

trap cleanup EXIT INT TERM

docker compose -f "$compose_file" up --detach --wait database
docker compose -f "$compose_file" run --rm php-lint
docker compose -f "$compose_file" run --rm database-test
docker compose -f "$compose_file" up --build --detach --wait application
docker compose -f "$compose_file" run --rm application-test
