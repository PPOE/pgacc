# Reference (golden master) runtime: the lane's application image plus
# libfaketime (a fixed "now" for the application server).
# See tests/README.md, "PHP 5 reference recordings".
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
# Pinned by tests/reference/run.sh for the lane image's Debian release.
ARG PGACC_REFERENCE_FAKETIME
RUN apt-get -o Acquire::Retries=3 update \
    && apt-get -o Acquire::Retries=3 install --yes --no-install-recommends "libfaketime=$PGACC_REFERENCE_FAKETIME" \
    && ln -s "$(dpkg -L libfaketime | grep '/libfaketime\.so\.1$')" /usr/local/lib/pgacc-libfaketime.so.1 \
    && rm -rf /var/lib/apt/lists/*
COPY tests/reference/runtime/reference-constants.php /app/constants.php
