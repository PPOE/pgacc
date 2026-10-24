ARG PHP_IMAGE=php:7.4.33-cli-bullseye@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5

FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS composer

FROM ${PHP_IMAGE}

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN sed -i \
      -e 's|deb.debian.org/debian|archive.debian.org/debian|g' \
      -e 's|security.debian.org/debian-security|archive.debian.org/debian-security|g' \
      /etc/apt/sources.list \
    && printf 'Acquire::Check-Valid-Until "false";\n' \
      >/etc/apt/apt.conf.d/99archive \
    && apt-get update \
    && apt-get install --yes --no-install-recommends \
      libpq-dev=13.23-0+deb11u4 \
      unzip=6.0-26+deb11u2 \
    && docker-php-ext-install pgsql \
    && pecl install https://pecl.php.net/get/xdebug-3.1.6.tgz \
    && docker-php-ext-enable xdebug \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY composer.json composer.lock ./

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-interaction \
      --no-progress \
      --no-scripts \
      --prefer-dist

COPY . ./
COPY tests/runtime/test-constants.php ./constants.php

RUN mkdir -p files

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
