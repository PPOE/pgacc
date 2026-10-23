#!/bin/sh
set -eu

php --version
php tests/http-smoke.php
php tests/http-login.php
php tests/http-listing-prefs.php
php tests/http-redirects.php
php tests/http-donations.php
php /opt/test-tools/phpunit-5.7.27.phar --configuration tests/php56/phpunit.xml
php tests/write-workflows.php
php tests/http-receipts.php
