#!/bin/sh
# PHP 5.3 lane server: Lighttpd with PHP-CGI over FastCGI (tests/php53/lighttpd.conf)
# on port 8080, and a second instance on port 8081 whose PHP-CGI runs with
# output_buffering=0 (PGACC_TEST_UNBUFFERED_BASE_URL, see start_unbuffered_server()
# in tests/http-test-helpers.php). The root filesystem is read-only;
# /var/run and /var/cache/lighttpd are tmpfs.
set -eu

mkdir -p /var/run/lighttpd /var/cache/lighttpd/compress /var/cache/lighttpd/uploads
chown www-data:www-data /var/run/lighttpd /var/cache/lighttpd/compress /var/cache/lighttpd/uploads

sed -e 's|^server.port .*|server.port                 = 8081|' \
    -e 's|/var/run/lighttpd.pid|/var/run/lighttpd-unbuffered.pid|' \
    -e 's|/var/run/lighttpd/php.socket|/var/run/lighttpd/php-unbuffered.socket|' \
    -e 's|"/usr/bin/php-cgi"|"/usr/bin/php-cgi -d output_buffering=0"|' \
    /etc/lighttpd/lighttpd.conf >/var/run/lighttpd-unbuffered.conf
grep -q 'output_buffering=0' /var/run/lighttpd-unbuffered.conf

# linux32: uname reports i686 like the 32-bit production host.
linux32 /usr/sbin/lighttpd -f /var/run/lighttpd-unbuffered.conf
exec linux32 /usr/sbin/lighttpd -D -f /etc/lighttpd/lighttpd.conf
