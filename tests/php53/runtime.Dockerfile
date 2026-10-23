# PHP 5.3 lane: a production-like server image (see tests/README.md, "PHP 5.3 lane").
# Production: Debian 6.0.9 squeeze i686, PHP 5.3.3-7+squeeze19 (Suhosin patch),
# Lighttpd + PHP-CGI, PostgreSQL 8.4 client library.

# Composer cannot install on squeeze (OpenSSL 0.9.8 has no TLS 1.2), so the
# locked vendor/ is installed here and copied in; PHPUnit 4.8 is the last
# PHPUnit for PHP 5.3 (checksum and Sebastian Bergmann's signature verified).
FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS dependencies
WORKDIR /dependencies
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-dev --no-interaction --no-progress --no-scripts --prefer-dist
RUN curl --fail --silent --show-error --location --retry 3 \
      https://phar.phpunit.de/phpunit-4.8.36.phar \
      --output /tmp/phpunit-4.8.36.phar \
    && echo 'ab8bc3017a64cf75a2112f5e089a1d5b0c4cc2116556d60c1af5e3f584e85c5e  /tmp/phpunit-4.8.36.phar' | sha256sum -c -

# debian/eol:squeeze, linux/386 manifest (32-bit like production). Manifest
# list: sha256:80ed3d6f933f102b35efaa1a9a7513e5ed9937c5a93c289dd6e2e1db51d6f331.
FROM --platform=linux/386 debian/eol:squeeze@sha256:4a306675b658d01a453bb89404daa87367f894b6ee62ea8f8980484099ca9bea

# The image already lists archive.debian.org squeeze, squeeze/updates and
# squeeze-lts. squeeze-lts (after production's last update) only supplies
# packages that squeeze itself lacks; the PHP stack is pinned to the
# production versions, which squeeze and squeeze/updates still carry.
RUN printf 'deb http://archive.debian.org/debian squeeze main\ndeb http://archive.debian.org/debian-security squeeze/updates main\ndeb http://archive.debian.org/debian squeeze-lts main\n' >/etc/apt/sources.list \
    && printf 'Acquire::Check-Valid-Until "false";\n' >/etc/apt/apt.conf.d/99archive \
    && printf 'Package: *\nPin: release a=squeeze-lts\nPin-Priority: 100\n' >/etc/apt/preferences.d/squeeze-lts \
    && apt-get -o Acquire::Retries=3 update \
    && DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=3 install --yes --no-install-recommends \
      php5-common=5.3.3-7+squeeze19 \
      php5-cgi=5.3.3-7+squeeze19 \
      php5-cli=5.3.3-7+squeeze19 \
      php5-pgsql=5.3.3-7+squeeze19 \
      php5-mcrypt=5.3.3-7+squeeze19 \
      lighttpd=1.4.28-2+squeeze1.6 \
      libpq5=8.4.20-0squeeze1 \
    && rm -rf /var/lib/apt/lists/* \
    && dpkg-query --show --showformat='${Package} ${Version} ${Architecture}\n' >/opt/pgacc-php53-packages.txt

COPY tests/php53/lighttpd.conf /etc/lighttpd/lighttpd.conf
# Test-only addition to the stock CGI php.ini: the diagnostics recorder.
RUN printf 'auto_prepend_file=/app/tests/runtime/diagnostics-prepend.php\n' \
      >/etc/php5/cgi/conf.d/zz-pgacc-test.ini

WORKDIR /app
COPY . ./
COPY --from=dependencies /dependencies/vendor ./vendor
COPY --from=dependencies /tmp/phpunit-4.8.36.phar /opt/test-tools/phpunit-4.8.36.phar
COPY --from=dependencies /usr/bin/composer /opt/test-tools/composer
COPY tests/runtime/test-constants.php ./constants.php
RUN mkdir -p files \
    && linux32 php-cgi -q tests/php53/ini-check.php \
    && php tests/php53/vendor-check.php \
    && COMPOSER_ALLOW_SUPERUSER=1 php /opt/test-tools/composer check-platform-reqs --no-dev \
    && lighttpd -t -f /etc/lighttpd/lighttpd.conf
EXPOSE 8080
CMD ["sh", "/app/tests/php53/start-server.sh"]
