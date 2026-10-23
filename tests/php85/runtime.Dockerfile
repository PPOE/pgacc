FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS dependencies
WORKDIR /dependencies
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-interaction --no-progress --no-scripts --prefer-dist

FROM php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37
RUN apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install --yes --no-install-recommends \
      libpq-dev=17.11-0+deb13u1 \
    && docker-php-ext-install pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . ./
COPY --from=dependencies /dependencies/vendor ./vendor
COPY tests/runtime/test-constants.php ./constants.php
RUN printf 'date.timezone=UTC\n' >/usr/local/etc/php/conf.d/test-timezone.ini \
    && mkdir -p files
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
