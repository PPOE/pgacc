#!/bin/sh
# Entrypoint of the PostgreSQL 8.4 test image (tests/postgres84/Dockerfile),
# a small subset of the official postgres images' docker-entrypoint.sh:
# POSTGRES_USER (superuser), POSTGRES_PASSWORD, POSTGRES_DB, and *.sql/*.sh
# files in /docker-entrypoint-initdb.d (*.sh files are sourced, with PGDATA
# set). TCP connections always authenticate with MD5 (as on production; 8.4
# has no SCRAM), the local socket uses trust as in the official images.
# Synthetic test data only.
#
#   pgacc-postgres-entrypoint --bake      (image build) initialise the cluster
#                                         in $PGACC_BAKED_CLUSTER
#   pgacc-postgres-entrypoint postgres [server options]
#
# initdb takes about a minute on a busy host, so the image carries a cluster
# initialised at build time for $PGACC_BAKED_USER; a container start copies it
# into the (tmpfs) data directory. Another POSTGRES_USER runs initdb instead.
set -eu
PATH=/usr/lib/postgresql/8.4/bin:$PATH
export PATH

# initdb plus the production configuration (read-only facts from the
# production server; ssl is off here) and pgcrypto from contrib in template1,
# so that every database created later (POSTGRES_DB, the mutation runner's
# template) has crypt() and gen_salt() as production's database has.
init_cluster()
{
  data=$1
  user=$2
  mkdir -p "$data"
  chmod 0700 "$data"
  pwfile=$(mktemp)
  # Replaced at every start (ALTER ROLE below).
  printf 'placeholder\n' >"$pwfile"
  # Production: every database UTF8 with collation and ctype en_US.UTF-8.
  initdb --pgdata="$data" --username="$user" --pwfile="$pwfile" \
    --encoding=UTF8 --locale=en_US.UTF-8 --auth=md5 >/dev/null
  rm -f "$pwfile"
  # standard_conforming_strings stays at 8.4's default (off), stated here.
  cat >>"$data/postgresql.conf" <<CONF

# pgacc test server (tests/postgres84/entrypoint.sh), production settings
listen_addresses = '*'
max_connections = 100
shared_buffers = 3584
datestyle = 'iso, mdy'
timezone = 'UTC'
log_timezone = 'UTC'
lc_messages = 'en_US.UTF-8'
lc_monetary = 'en_US.UTF-8'
lc_numeric = 'en_US.UTF-8'
lc_time = 'en_US.UTF-8'
default_text_search_config = 'pg_catalog.english'
standard_conforming_strings = off
ssl = off
CONF
  cat >"$data/pg_hba.conf" <<HBA
local all all trust
host all all 0.0.0.0/0 md5
host all all ::/0 md5
HBA
  temp_start "$data" "$user"
  temp_psql "$user" --dbname=template1 --file=/usr/share/postgresql/8.4/contrib/pgcrypto.sql >/dev/null
  temp_stop "$data" "$user"
}

# Temporary server on a private socket only: the healthcheck (pg_isready on
# /var/run/postgresql) fails until the real server starts. pg_ctl -w waits by
# connecting through PGHOST/PGUSER.
temp_start()
{
  temp_socket=$(mktemp -d)
  PGHOST=$temp_socket PGUSER=$2 pg_ctl -D "$1" -w -s \
    -o "-c listen_addresses='' -k $temp_socket" start >/dev/null
}
temp_psql()
{
  user=$1
  shift
  psql --no-psqlrc --set=ON_ERROR_STOP=1 --quiet --host="$temp_socket" --username="$user" "$@"
}
temp_stop()
{
  PGHOST=$temp_socket PGUSER=$2 pg_ctl -D "$1" -w -s -m fast stop >/dev/null
  rmdir "$temp_socket"
}

if [ "${1:-}" = --bake ]; then
  init_cluster "$PGACC_BAKED_CLUSTER" "$PGACC_BAKED_USER"
  exit 0
fi
if [ "${1:-}" != postgres ]; then
  exec "$@"
fi
shift

: "${POSTGRES_USER:=postgres}"
: "${POSTGRES_DB:=$POSTGRES_USER}"
: "${POSTGRES_PASSWORD:?POSTGRES_PASSWORD must be set}"

# PGDATA is a subdirectory of the data mount (a root-owned tmpfs in
# compose.test.yaml); the image runs as the postgres user.
if [ ! -s "$PGDATA/PG_VERSION" ]; then
  if [ "$POSTGRES_USER" = "$PGACC_BAKED_USER" ]; then
    mkdir -p "$PGDATA"
    chmod 0700 "$PGDATA"
    cp -a "$PGACC_BAKED_CLUSTER/." "$PGDATA/"
  else
    init_cluster "$PGDATA" "$POSTGRES_USER"
  fi
  temp_start "$PGDATA" "$POSTGRES_USER"
  # password_encryption is on (MD5) by default on 8.4.
  password=$(printf '%s' "$POSTGRES_PASSWORD" | sed "s/'/''/g")
  temp_psql "$POSTGRES_USER" --dbname=postgres \
    --command="ALTER ROLE \"$POSTGRES_USER\" PASSWORD '$password'" >/dev/null
  if [ "$POSTGRES_DB" != postgres ]; then
    temp_psql "$POSTGRES_USER" --dbname=postgres --command="CREATE DATABASE \"$POSTGRES_DB\"" >/dev/null
  fi
  for file in /docker-entrypoint-initdb.d/*; do
    case "$file" in
      *.sql) temp_psql "$POSTGRES_USER" --dbname="$POSTGRES_DB" --file="$file" ;;
      *.sh) . "$file" ;;
    esac
  done
  temp_stop "$PGDATA" "$POSTGRES_USER"
fi

exec postgres -D "$PGDATA" "$@"
