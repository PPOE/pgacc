# Reference (golden master) runtime: the lane's application image plus
# libfaketime (a fixed "now" for the application server) and, on the PHP 5.6
# recording lane only, the mcrypt extension that production PHP 5 has and the
# membership import, receipt upload/download and mbapi.php need.
# See tests/README.md, "PHP 5 reference recordings".
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
ARG PGACC_REFERENCE_MCRYPT=0
# Pinned per lane distribution by tests/reference/run.sh (squeeze, for the
# PHP 5.3 lane, calls the package "faketime").
ARG PGACC_REFERENCE_FAKETIME
ARG PGACC_REFERENCE_FAKETIME_PACKAGE=libfaketime
RUN apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install --yes --no-install-recommends "$PGACC_REFERENCE_FAKETIME_PACKAGE=$PGACC_REFERENCE_FAKETIME" \
    && if [ "$PGACC_REFERENCE_MCRYPT" = 1 ]; then \
         apt-get -o Acquire::Retries=3 install --yes --no-install-recommends libmcrypt-dev=2.5.8-3.3 \
         && docker-php-ext-install mcrypt; \
       fi \
    && ln -s "$(dpkg -L "$PGACC_REFERENCE_FAKETIME_PACKAGE" | grep '/libfaketime\.so\.1$')" /usr/local/lib/pgacc-libfaketime.so.1 \
    && rm -rf /var/lib/apt/lists/*
COPY tests/reference/runtime/reference-constants.php /app/constants.php
