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

FROM php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37
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
RUN printf 'date.timezone=UTC\n' >/usr/local/etc/php/conf.d/test-timezone.ini \
    && mkdir -p files
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
