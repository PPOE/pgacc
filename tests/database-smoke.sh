#!/bin/sh
set -eu

# Files in order, by server version; pgacc_mutation_fixture_files() in
# tests/mutation/lib.php builds the mutation template from the same list
# (checked by tests/mutation/tests/LibTest.php).
server_version_num=$(psql --tuples-only --no-align --command='SHOW server_version_num')
set --
if [ "$server_version_num" -ge 90100 ]; then
  # CREATE EXTENSION (9.1+); the 8.4 server has pgcrypto from contrib in template1.
  set -- "$@" --file=tests/fixtures/pgcrypto.sql
fi
set -- "$@" --file=tests/fixtures/schema.sql --file=tests/fixtures/data.sql
if [ "$server_version_num" -lt 90000 ]; then
  # plpgsql is built in from 9.0; on 8.4 it exists only for the characterization.
  set -- "$@" --file=tests/fixtures/plpgsql-create.sql
fi
set -- "$@" --file=tests/database-characterization.sql
if [ "$server_version_num" -lt 90000 ]; then
  set -- "$@" --file=tests/fixtures/plpgsql-drop.sql
fi

# psql runs the server's version (PGACC_TEST_PG). Before 9.6 it ignores all but
# the last --file, so include every file from one script instead: all versions
# then run the same files in one transaction, with file:line error locations.
script=/tmp/database-smoke.sql
# 8.4 reports every implicit sequence and index as a NOTICE.
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
