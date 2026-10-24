# Sourced by tests/run.sh, tests/reference/run.sh and tests/mutation/run.sh
# (POSIX sh).
#
# PGACC_TEST_PG selects the PostgreSQL server of the synthetic test database:
#   8.4  production's 8.4.20, built locally from Debian squeeze i386
#        (tests/postgres84/), like the PHP 5.3 lane
#   18   postgres:18-bookworm
# The caller sets pgacc_test_pg_default first: 8.4 for the PHP 5.3 lane (the
# production pair), 18 for every other lane. See tests/README.md,
# "PostgreSQL versions".
#
# Exports for compose.test.yaml / compose.php53.yaml:
#   PGACC_TEST_PG_IMAGE           server image (also the psql client)
#   PGACC_TEST_PG_DATA            tmpfs mount for the data directory
#   PGACC_TEST_PG_MD5_ENCRYPTION  password_encryption value giving MD5 hashes
# and sets PGACC_TEST_PG_TAG ("" for the lane's default server, else "-pg84"
# or "-pg18"), which callers append to PGACC_TEST_SUFFIX so that concurrent
# runs on different servers use different Compose projects and image tags.
# Callers run pgacc_test_pg_build before starting the database.

PGACC_TEST_PG=${PGACC_TEST_PG:-${pgacc_test_pg_default:-18}}
case "$PGACC_TEST_PG" in
  8.4)
    # Built by pgacc_test_pg_build (tagged with PGACC_TEST_ID). Before
    # PostgreSQL 10, password_encryption is a boolean; "on" stores MD5
    # hashes. 8.4 has no SCRAM: every lane authenticates with MD5.
    PGACC_TEST_PG_IMAGE=pgacc-test-postgres84${PGACC_TEST_ID:+-$PGACC_TEST_ID}
    PGACC_TEST_PG_DATA=/var/lib/postgresql/data
    PGACC_TEST_PG_MD5_ENCRYPTION=on
    ;;
  18)
    # Keep in sync with the defaults in compose.test.yaml. The 18+ images keep
    # PGDATA in /var/lib/postgresql/18/docker and refuse to start when
    # /var/lib/postgresql/data is a mount point.
    PGACC_TEST_PG_IMAGE=postgres:18-bookworm@sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc
    PGACC_TEST_PG_DATA=/var/lib/postgresql
    PGACC_TEST_PG_MD5_ENCRYPTION=md5
    ;;
  *)
    echo "PGACC_TEST_PG must be 8.4 or 18" >&2
    exit 2
    ;;
esac

if [ "$PGACC_TEST_PG" = "${pgacc_test_pg_default:-18}" ]; then
  PGACC_TEST_PG_TAG=
else
  PGACC_TEST_PG_TAG=-pg$(echo "$PGACC_TEST_PG" | tr -d .)
fi
export PGACC_TEST_PG PGACC_TEST_PG_IMAGE PGACC_TEST_PG_DATA PGACC_TEST_PG_MD5_ENCRYPTION

# Builds the 8.4 server image (a no-op for 18, which is pulled). Run from the
# repository root.
pgacc_test_pg_build()
{
  if [ "$PGACC_TEST_PG" = 8.4 ]; then
    docker build --quiet --platform linux/386 --tag "$PGACC_TEST_PG_IMAGE" tests/postgres84 >/dev/null
  fi
}
