# PostgreSQL 8.4.20 test server (PGACC_TEST_PG=8.4), built like the PHP 5.3
# lane: Debian squeeze i386 from the archive, with the packages pinned to
# production's 8.4.20-0squeeze1 (squeeze/updates). Configured like production
# (UTF8 databases in en_US.UTF-8, DateStyle ISO, MDY, UTC, pgcrypto from
# contrib, MD5 authentication); see tests/README.md, "PostgreSQL versions".
# Built by tests/postgres-version.sh (pgacc_test_pg_build); entrypoint.sh
# mimics the official postgres images' POSTGRES_* initialisation.

# debian/eol:squeeze, linux/386 manifest (same base as tests/php53/runtime.Dockerfile).
FROM --platform=linux/386 debian/eol:squeeze@sha256:4a306675b658d01a453bb89404daa87367f894b6ee62ea8f8980484099ca9bea

# squeeze-lts carries 8.4.22lts*; it may only supply packages squeeze lacks.
RUN printf 'deb http://archive.debian.org/debian squeeze main\ndeb http://archive.debian.org/debian-security squeeze/updates main\ndeb http://archive.debian.org/debian squeeze-lts main\n' >/etc/apt/sources.list \
    && printf 'Acquire::Check-Valid-Until "false";\n' >/etc/apt/apt.conf.d/99archive \
    && printf 'Package: *\nPin: release a=squeeze-lts\nPin-Priority: 100\n' >/etc/apt/preferences.d/squeeze-lts \
    && apt-get -o Acquire::Retries=3 update \
    && DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=3 install --yes --no-install-recommends locales \
    && printf 'en_US.UTF-8 UTF-8\n' >/etc/locale.gen \
    && locale-gen \
    && DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=3 install --yes --no-install-recommends \
      postgresql-8.4=8.4.20-0squeeze1 \
      postgresql-client-8.4=8.4.20-0squeeze1 \
      postgresql-contrib-8.4=8.4.20-0squeeze1 \
      libpq5=8.4.20-0squeeze1 \
    && (pg_dropcluster --stop 8.4 main || true) \
    && rm -rf /var/lib/apt/lists/* /var/lib/postgresql/8.4 \
    && dpkg-query --show --showformat='${Package} ${Version} ${Architecture}\n' >/opt/pgacc-postgres84-packages.txt \
    && test -f /usr/share/postgresql/8.4/contrib/pgcrypto.sql

# PGDATA: a subdirectory of the tmpfs that compose.test.yaml mounts at
# /var/lib/postgresql/data (PGACC_TEST_PG_DATA).
ENV PATH=/usr/lib/postgresql/8.4/bin:$PATH \
    PGDATA=/var/lib/postgresql/data/pgdata \
    PGACC_BAKED_USER=pgacc_test \
    PGACC_BAKED_CLUSTER=/var/lib/postgresql/baked \
    LANG=en_US.UTF-8
COPY entrypoint.sh /usr/local/bin/pgacc-postgres-entrypoint
# pg_isready (9.3+) for the healthcheck in compose.test.yaml.
COPY pg_isready.sh /usr/local/bin/pg_isready
RUN chmod 0755 /usr/local/bin/pgacc-postgres-entrypoint /usr/local/bin/pg_isready \
    && mkdir -p /docker-entrypoint-initdb.d
USER postgres
RUN pgacc-postgres-entrypoint --bake
ENTRYPOINT ["/usr/local/bin/pgacc-postgres-entrypoint"]
EXPOSE 5432
CMD ["postgres"]
