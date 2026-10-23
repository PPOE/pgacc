#!/bin/sh
# PHP 5.3 lane tests. This runs in the PHP 5.6 client image; the application
# under test is the PHP 5.3 Lighttpd/PHP-CGI server (compose.php53.yaml).
set -eu

php --version
php tests/php53/http-cgi.php
php tests/http-smoke.php
php tests/http-login.php
php tests/http-listing-prefs.php
php tests/http-redirects.php
php tests/http-donations.php
php tests/write-workflows.php
