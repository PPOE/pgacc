# Static-analysis tools only. This image never serves the application and is
# separate from the PHP 8.5 runtime image.
# Build context: repository root (see tests/static-analysis.sh).

FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS tools
WORKDIR /tools
COPY tests/static-analysis/composer.json tests/static-analysis/composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-interaction --no-progress --prefer-dist --classmap-authoritative

# The application's locked runtime dependencies (smalot/pdfparser, phpseclib), so PHPStan
# can resolve their classes. Never executed: PHPStan reads them statically.
FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS application-dependencies
WORKDIR /dependencies
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-interaction --no-progress --no-scripts --prefer-dist --no-dev

FROM php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37
COPY --from=tools /tools /tools
COPY --from=application-dependencies /dependencies/vendor /dependencies/vendor
# PHPStan ships as a PHAR inside its Composer package; the lock file pins the
# package commit and this digest pins the executable itself.
RUN echo '0ded687c44b1f9d0346f7f00fa505212b018efe532b2b18e3a72f6e16feb32be  /tools/vendor/phpstan/phpstan/phpstan.phar' \
      | sha256sum -c - \
    && printf 'memory_limit=1G\ndate.timezone=UTC\n' \
      >/usr/local/etc/php/conf.d/static-analysis.ini \
    && /tools/vendor/bin/phpcs --version \
    && /tools/vendor/bin/phpcs -i | grep -q PHPCompatibility \
    && /tools/vendor/bin/phpstan --version
WORKDIR /app
