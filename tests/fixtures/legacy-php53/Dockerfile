# PHP 5.3.3 with mcrypt and php-cgi (Debian 6 packages, i386); same base
# image and package pins as tests/php53/runtime.Dockerfile at the tag
# php5-last. Used only by generate.sh.
FROM --platform=linux/386 debian/eol:squeeze@sha256:4a306675b658d01a453bb89404daa87367f894b6ee62ea8f8980484099ca9bea
RUN printf 'deb http://archive.debian.org/debian squeeze main\ndeb http://archive.debian.org/debian-security squeeze/updates main\n' >/etc/apt/sources.list \
    && printf 'Acquire::Check-Valid-Until "false";\n' >/etc/apt/apt.conf.d/99archive \
    && apt-get -o Acquire::Retries=3 update \
    && DEBIAN_FRONTEND=noninteractive apt-get -o Acquire::Retries=3 install --yes --no-install-recommends \
      php5-common=5.3.3-7+squeeze19 php5-cgi=5.3.3-7+squeeze19 php5-cli=5.3.3-7+squeeze19 \
      php5-pgsql=5.3.3-7+squeeze19 php5-mcrypt=5.3.3-7+squeeze19 \
    && rm -rf /var/lib/apt/lists/*
