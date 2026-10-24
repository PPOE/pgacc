# Reference (golden master) runtime: the lane's application image plus
# libfaketime (a fixed "now" for the application server). The PHP 5.3 image
# already has production's php5-mcrypt package.
# See tests/README.md, "PHP 5 reference recordings".
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
# Pinned per lane distribution by tests/reference/run.sh (squeeze, for the
# PHP 5.3 lane, calls the package "faketime").
ARG PGACC_REFERENCE_FAKETIME
ARG PGACC_REFERENCE_FAKETIME_PACKAGE=libfaketime
RUN apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install --yes --no-install-recommends "$PGACC_REFERENCE_FAKETIME_PACKAGE=$PGACC_REFERENCE_FAKETIME" \
    && ln -s "$(dpkg -L "$PGACC_REFERENCE_FAKETIME_PACKAGE" | grep '/libfaketime\.so\.1$')" /usr/local/lib/pgacc-libfaketime.so.1 \
    && rm -rf /var/lib/apt/lists/*
COPY tests/reference/runtime/reference-constants.php /app/constants.php
