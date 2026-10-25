# Mechanical syntax modernization only (tests/rector/run.sh): pinned Rector
# on PHP 8.5. This image never serves the application.
# Build context: tests/rector (vendor/ is ignored, .dockerignore).

FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS tools
WORKDIR /tools
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-interaction --no-progress --prefer-dist --classmap-authoritative

FROM php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37
COPY --from=tools /tools /tools
RUN printf 'memory_limit=1G\nshort_open_tag=0\n' >/usr/local/etc/php/conf.d/rector.ini \
    && /tools/vendor/bin/rector --version | grep -q ' 2\.7\.0'
WORKDIR /app
