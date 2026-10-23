#!/bin/sh
set -eu

# Files in order; tests/mutation/lib.php builds its template from the same list.
set -- \
  --file=tests/fixtures/schema.sql \
  --file=tests/fixtures/data.sql \
  --file=tests/database-characterization.sql

# psql runs the server's version (PGACC_TEST_PG). Before 9.6 it ignores all but
# the last --file, so include every file from one script instead: all versions
# then run the same files in one transaction, with file:line error locations.
script=/tmp/database-smoke.sql
: >"$script"
for file; do
  printf '\\i %s\n' "${file#*=}" >>"$script"
done

psql \
  --set=ON_ERROR_STOP=1 \
  --single-transaction \
  --file "$script" \
  >/dev/null

echo "PostgreSQL $(psql --tuples-only --no-align --command='SHOW server_version') schema and characterization tests passed"
