#!/bin/sh
set -eu

psql \
  --set=ON_ERROR_STOP=1 \
  --single-transaction \
  --file=tests/fixtures/schema.sql \
  --file=tests/fixtures/data.sql \
  --file=tests/database-characterization.sql \
  >/dev/null

echo "PostgreSQL schema and characterization tests passed"
