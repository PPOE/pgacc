# Infection mutation-testing tools layered on an already built application
# test image (BASE_IMAGE). Built by tests/mutation/infection.sh with
# tests/mutation/infection as the build context; the lane runtime Dockerfiles
# are not changed.
#
# PHP 7.4: Infection 0.26.6 (the last release allowing PHP 7.4) from a locked
#   Composer project. Its published PHARs (0.25.0-0.26.6) fail on PHP 7.4 with
#   "Class 'Symfony\Polyfill\Php80\Php80' not found". Coverage: Xdebug 3.1.6
#   from the base image, enabled only for the initial run (infection.json);
#   mutant runs use xdebug.mode=off, which makes them several times faster.
# PHP 8.5: Infection 0.35.8 PHAR (requires PHP ^8.3), pinned by SHA-256 after
#   checking its GPG signature (key C6D76C329EBADE2FB9C458CFC5095986493B4AA0).
#   Coverage: PCOV 1.0.12, pinned by SHA-256; the base image has no driver.
ARG BASE_IMAGE=pgacc-test-application
ARG INFECTION_LANE=php74

FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS composer-tools
WORKDIR /opt/test-tools/infection
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-dev --no-interaction --no-progress --no-scripts --no-plugins --prefer-dist

FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS downloads
RUN curl --fail --silent --show-error --location --retry 3 \
      https://github.com/infection/infection/releases/download/0.35.8/infection.phar \
      --output /tmp/infection-0.35.8.phar \
    && echo 'b2049ae1bff43d9dc82831c2833005005eb62d5180cd518892af93fc8e23ddc5  /tmp/infection-0.35.8.phar' | sha256sum -c - \
    && curl --fail --silent --show-error --location --retry 3 \
      https://pecl.php.net/get/pcov-1.0.12.tgz --output /tmp/pcov-1.0.12.tgz \
    && echo '23255c8c9335a9636ccb743f5302436a97a582a0bbde9869485be911bbc15da8  /tmp/pcov-1.0.12.tgz' | sha256sum -c -

FROM ${BASE_IMAGE} AS lane-php74
COPY --from=composer-tools /opt/test-tools/infection /opt/test-tools/infection
RUN cd /opt/test-tools/infection \
    && COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev \
    && ln -s /opt/test-tools/infection/vendor/bin/infection /usr/local/bin/infection \
    && printf 'xdebug.mode=off\n' >/usr/local/etc/php/conf.d/zz-infection-xdebug.ini

FROM ${BASE_IMAGE} AS lane-php85
COPY --from=downloads /tmp/pcov-1.0.12.tgz /tmp/pcov-1.0.12.tgz
COPY --from=downloads /tmp/infection-0.35.8.phar /opt/test-tools/infection-0.35.8.phar
RUN pecl install /tmp/pcov-1.0.12.tgz \
    && docker-php-ext-enable pcov \
    && rm /tmp/pcov-1.0.12.tgz \
    && chmod 0755 /opt/test-tools/infection-0.35.8.phar \
    && ln -s /opt/test-tools/infection-0.35.8.phar /usr/local/bin/infection

FROM lane-${INFECTION_LANE}
WORKDIR /app
