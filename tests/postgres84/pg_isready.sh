#!/bin/sh
# Minimal pg_isready for the PostgreSQL 8.4 image (the tool exists from 9.3).
# Arguments are ignored: it checks that the server initialised by
# entrypoint.sh accepts connections on the default socket, as the
# compose.test.yaml healthcheck expects. During initialisation the temporary
# server listens on another socket only, so this fails until the real start.
exec psql --no-psqlrc --quiet --tuples-only --no-align \
  --host=/var/run/postgresql --username="${POSTGRES_USER:-postgres}" \
  --dbname="${POSTGRES_DB:-${POSTGRES_USER:-postgres}}" --command='SELECT 1' >/dev/null 2>&1
