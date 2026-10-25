#!/bin/sh
set -eu

# Files in order; pgacc_mutation_fixture_files() in tests/mutation/lib.php
# builds the mutation template from the same list (checked by
# tests/mutation/tests/LibTest.php).
set -- \
  --file=tests/fixtures/pgcrypto.sql \
  --file=tests/fixtures/schema.sql \
  --file=tests/fixtures/data.sql \
  --file=tests/database-characterization.sql

# Include every file from one script: they run in one transaction, with
# file:line error locations.
script=/tmp/database-smoke.sql
printf 'SET client_min_messages = warning;\n' >"$script"
for file; do
  printf '\\i %s\n' "${file#*=}" >>"$script"
done

psql \
  --set=ON_ERROR_STOP=1 \
  --single-transaction \
  --file "$script" \
  >/dev/null

echo "PostgreSQL $(psql --tuples-only --no-align --command='SHOW server_version') schema and characterization tests passed"
