# Infection mutation-testing tools layered on an already built application
# test image (BASE_IMAGE). Built by tests/mutation/infection.sh with
# tests/mutation/infection as the build context; the lane runtime Dockerfile
# is not changed.
#
# PHP 8.5: Infection 0.35.8 PHAR (requires PHP ^8.3), pinned by SHA-256 after
#   checking its GPG signature (key C6D76C329EBADE2FB9C458CFC5095986493B4AA0).
#   Coverage: PCOV 1.0.12, pinned by SHA-256 (the base image's Xdebug stays
#   off, xdebug.mode=off; PHPUnit prefers PCOV).
ARG BASE_IMAGE=pgacc-test-application

FROM composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c AS downloads
RUN curl --fail --silent --show-error --location --retry 3 \
      https://github.com/infection/infection/releases/download/0.35.8/infection.phar \
      --output /tmp/infection-0.35.8.phar \
    && echo 'b2049ae1bff43d9dc82831c2833005005eb62d5180cd518892af93fc8e23ddc5  /tmp/infection-0.35.8.phar' | sha256sum -c - \
    && curl --fail --silent --show-error --location --retry 3 \
      https://pecl.php.net/get/pcov-1.0.12.tgz --output /tmp/pcov-1.0.12.tgz \
    && echo '23255c8c9335a9636ccb743f5302436a97a582a0bbde9869485be911bbc15da8  /tmp/pcov-1.0.12.tgz' | sha256sum -c -

FROM ${BASE_IMAGE}
COPY --from=downloads /tmp/pcov-1.0.12.tgz /tmp/pcov-1.0.12.tgz
COPY --from=downloads /tmp/infection-0.35.8.phar /opt/test-tools/infection-0.35.8.phar
RUN pecl install /tmp/pcov-1.0.12.tgz \
    && docker-php-ext-enable pcov \
    && rm /tmp/pcov-1.0.12.tgz \
    && chmod 0755 /opt/test-tools/infection-0.35.8.phar \
    && ln -s /opt/test-tools/infection-0.35.8.phar /usr/local/bin/infection

WORKDIR /app
