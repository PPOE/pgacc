# AST-equivalence check only (tests/coverage/ast-equivalence.sh): pinned
# nikic/php-parser on PHP 8.5. This image never serves the application.
# Build context: tests/ast (vendor/ is ignored, .dockerignore).

FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS tools
WORKDIR /tools
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-interaction --no-progress --prefer-dist --classmap-authoritative

FROM php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37
COPY --from=tools /tools /tools
RUN php -n -r 'require "/tools/vendor/autoload.php"; exit(Composer\InstalledVersions::getPrettyVersion("nikic/php-parser") === "v5.9.0" ? 0 : 1);'
WORKDIR /work
