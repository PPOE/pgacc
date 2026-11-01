# Application test image of the PHP 8.5 lane (the only lane after php5-last;
# tests/README.md). Xdebug is installed for the coverage run only: it is off
# by default (xdebug.mode=off) and compose.coverage.yaml enables coverage mode.
FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS dependencies
WORKDIR /dependencies
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-interaction --no-progress --no-scripts --prefer-dist

FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS downloads
# Xdebug 3.5.3 (PHP 8.0-8.5) from xdebug.org, pinned by SHA-256.
RUN curl --fail --silent --show-error --location --retry 3 \
      https://xdebug.org/files/xdebug-3.5.3.tgz --output /tmp/xdebug-3.5.3.tgz \
    && echo 'f073de91bea046106abf4d6071c963ea71e58571df6ce58948ceca89d121cb2d  /tmp/xdebug-3.5.3.tgz' | sha256sum -c -

# Synthetic TLS for the membership stub (compose.test.yaml): a test CA and a
# server certificate for mitglieder.piratenpartei.at, generated per build
# (plus a self-signed and a wrong-name certificate for the failure tests). The
# CA key never leaves this stage. notBefore lies in the past because the
# reference runs fake the application's clock (tests/reference/compose.yaml).
FROM php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37 AS membership-tls
WORKDIR /tls
RUN openssl req -x509 -newkey rsa:2048 -nodes -sha256 -subj '/CN=pgacc synthetic test CA' \
      -not_before 20200101000000Z -not_after 20991231235959Z \
      -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
      -keyout ca.key -out ca.pem \
    && openssl req -newkey rsa:2048 -nodes -subj '/CN=mitglieder.piratenpartei.at' \
      -keyout server.key -out server.csr \
    && printf 'basicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:mitglieder.piratenpartei.at\n' >server.ext \
    && openssl x509 -req -sha256 -in server.csr -CA ca.pem -CAkey ca.key -set_serial 1 \
      -not_before 20200101000000Z -not_after 20991231235959Z -extfile server.ext -out server.pem \
    && openssl verify -CAfile ca.pem server.pem \
    && cat server.pem server.key >membership.pem \
    && openssl req -x509 -newkey rsa:2048 -nodes -sha256 -subj '/CN=mitglieder.piratenpartei.at' \
      -not_before 20200101000000Z -not_after 20991231235959Z \
      -addext 'subjectAltName=DNS:mitglieder.piratenpartei.at' -keyout selfsigned.key -out selfsigned.crt \
    && cat selfsigned.crt selfsigned.key >selfsigned.pem \
    && openssl req -newkey rsa:2048 -nodes -subj '/CN=wrong.invalid' -keyout wrongname.key -out wrongname.csr \
    && printf 'basicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:wrong.invalid\n' >wrongname.ext \
    && openssl x509 -req -sha256 -in wrongname.csr -CA ca.pem -CAkey ca.key -set_serial 2 \
      -not_before 20200101000000Z -not_after 20991231235959Z -extfile wrongname.ext -out wrongname.crt \
    && cat wrongname.crt wrongname.key >wrongname.pem

FROM php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37
# The test CA is the members_cafile of tests/runtime/test-sync-secrets.php.
# It is also trusted system-wide, so the tests can show that getusers.php
# trusts members_cafile only (GetUsersTest).
COPY --from=membership-tls /tls/ca.pem /usr/local/share/ca-certificates/pgacc-synthetic-test-ca.crt
# The stub's certificates: the valid one, and for the verification tests a
# self-signed one and a test-CA one for another host name.
COPY --from=membership-tls /tls/membership.pem /tls/selfsigned.pem /tls/wrongname.pem /etc/pgacc-test-tls/
RUN update-ca-certificates
COPY --from=downloads /tmp/xdebug-3.5.3.tgz /tmp/xdebug-3.5.3.tgz
RUN apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install --yes --no-install-recommends \
      libpq-dev=17.11-0+deb13u1 \
    && docker-php-ext-install pgsql \
    && pecl install /tmp/xdebug-3.5.3.tgz \
    && docker-php-ext-enable xdebug \
    && printf 'xdebug.mode=off\n' >/usr/local/etc/php/conf.d/zz-xdebug-mode.ini \
    && rm -rf /var/lib/apt/lists/* /tmp/xdebug-3.5.3.tgz

WORKDIR /app
COPY . ./
COPY --from=dependencies /dependencies/vendor ./vendor
COPY tests/runtime/test-constants.php ./constants.php
COPY tests/runtime/test-sync-secrets.php ./sync-secrets.php
RUN printf 'date.timezone=UTC\n' >/usr/local/etc/php/conf.d/test-timezone.ini \
    && mkdir -p files
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/tests/runtime/router.php"]
