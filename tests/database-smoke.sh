#!/bin/sh
set -eu

psql --set=ON_ERROR_STOP=1 --file=tests/fixtures/harness.sql >/dev/null

probe="$(
  psql \
    --tuples-only \
    --no-align \
    --set=ON_ERROR_STOP=1 \
    --command='SELECT id || E'\''\t'\'' || label FROM harness_probe ORDER BY id'
)"

if [ "$probe" != "1	synthetic test data" ]; then
  echo "Unexpected synthetic database result: $probe" >&2
  exit 1
fi

echo "PostgreSQL smoke test passed"
