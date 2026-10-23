# Sourced by tests/run.sh and tests/mutation/run.sh (POSIX sh).
#
# PGACC_TEST_PG selects the PostgreSQL server of the synthetic test database
# (default 17). Production runs PostgreSQL 8.4.20, whose images no longer
# pull with current Docker engines; 9.4 is the oldest available stand-in.
# See tests/README.md, "PostgreSQL versions".
#
# Exports for compose.test.yaml / compose.php56.yaml:
#   PGACC_TEST_PG_IMAGE           pinned server image (also the psql client)
#   PGACC_TEST_PG_DATA            tmpfs mount for the data directory
#   PGACC_TEST_PG_MD5_ENCRYPTION  password_encryption value giving MD5 hashes
# and sets PGACC_TEST_PG_TAG ("" for the default, else "-pg94", "-pg14", ...),
# which callers append to PGACC_TEST_SUFFIX so that concurrent runs on
# different servers use different Compose projects and image tags.

PGACC_TEST_PG=${PGACC_TEST_PG:-17}
case "$PGACC_TEST_PG" in
  9.4)
    # Last official 9.4 build (9.4.26, Debian stretch). Before PostgreSQL 10,
    # password_encryption is a boolean; "on" stores MD5 hashes. 9.4 has no
    # SCRAM, so every lane authenticates with MD5 here.
    PGACC_TEST_PG_IMAGE=postgres:9.4@sha256:42a7a6a647a602efa9592edd1f56359800d079b93fa52c5d92244c58ac4a2ab9
    PGACC_TEST_PG_DATA=/var/lib/postgresql/data
    PGACC_TEST_PG_MD5_ENCRYPTION=on
    ;;
  14)
    PGACC_TEST_PG_IMAGE=postgres:14-bookworm@sha256:eba39627b02201a04e851363546d34547cc82550a8d7f68c6e9ff6c8b2c6ff33
    PGACC_TEST_PG_DATA=/var/lib/postgresql/data
    PGACC_TEST_PG_MD5_ENCRYPTION=md5
    ;;
  17)
    # Keep in sync with the defaults in compose.test.yaml.
    PGACC_TEST_PG_IMAGE=postgres:17-bookworm@sha256:051f7b7b3abdd564d5d1bd1e8c4b9c1b6e77087d1dd22020ede611c096a272e0
    PGACC_TEST_PG_DATA=/var/lib/postgresql/data
    PGACC_TEST_PG_MD5_ENCRYPTION=md5
    ;;
  18)
    # The 18+ images keep PGDATA in /var/lib/postgresql/18/docker and refuse
    # to start when /var/lib/postgresql/data is a mount point.
    PGACC_TEST_PG_IMAGE=postgres:18-bookworm@sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc
    PGACC_TEST_PG_DATA=/var/lib/postgresql
    PGACC_TEST_PG_MD5_ENCRYPTION=md5
    ;;
  *)
    echo "PGACC_TEST_PG must be one of 9.4, 14, 17 (default), 18" >&2
    exit 2
    ;;
esac

if [ "$PGACC_TEST_PG" = 17 ]; then
  PGACC_TEST_PG_TAG=
else
  PGACC_TEST_PG_TAG=-pg$(echo "$PGACC_TEST_PG" | tr -d .)
fi
export PGACC_TEST_PG PGACC_TEST_PG_IMAGE PGACC_TEST_PG_DATA PGACC_TEST_PG_MD5_ENCRYPTION
