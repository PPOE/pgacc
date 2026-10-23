# Fetch locked application dependencies and the legacy runner using modern TLS.
FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS dependencies
WORKDIR /dependencies
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-dev --no-interaction --no-progress --no-scripts --prefer-dist
RUN curl --fail --silent --show-error --location --retry 3 \
      https://phar.phpunit.de/phpunit-5.7.27.phar \
      --output /tmp/phpunit-5.7.27.phar \
    && echo 'f0300a13f2653bee10b0ce24dbc8d94e65dc9be8966d822b9e455b6b294be16e  /tmp/phpunit-5.7.27.phar' | sha256sum --check -

FROM php:7.4.33-cli-bullseye@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5 AS archive-keys

FROM php:5.6-cli@sha256:6ce95208609dc66df163ab936c970b3b34cd901b85c747102c5999f08ade9143
# Archived Release files also carry newer signatures absent from the old image.
COPY --from=archive-keys /usr/share/keyrings/debian-archive-keyring.gpg /usr/share/keyrings/debian-archive-keyring.gpg
RUN printf 'deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] http://archive.debian.org/debian stretch main\ndeb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] http://archive.debian.org/debian-security stretch/updates main\n' >/etc/apt/sources.list \
    && printf 'Acquire::Check-Valid-Until "false";\n' >/etc/apt/apt.conf.d/99archive \
    && apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install --yes --no-install-recommends libpq-dev=9.6.24-0+deb9u1 \
    && docker-php-ext-install pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . ./
COPY --from=dependencies /dependencies/vendor ./vendor
COPY --from=dependencies /tmp/phpunit-5.7.27.phar /opt/test-tools/phpunit-5.7.27.phar
COPY --from=dependencies /usr/bin/composer /usr/local/bin/composer
COPY tests/runtime/test-constants.php ./constants.php
RUN printf 'date.timezone=UTC\n' >/usr/local/etc/php/conf.d/test-timezone.ini \
    && COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev \
    && mkdir -p files
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]
