#!/bin/sh
set -eu

# Try every group while investigating PHP 8 compatibility, and keep failures
# visible through the final exit status. Coverage remains on the PHP 7.4 lane.
test_status=0
php --version
php tests/http-smoke.php || test_status=1
php tests/http-login.php || test_status=1
php tests/http-listing-prefs.php || test_status=1
php tests/http-redirects.php || test_status=1
php tests/http-donations.php || test_status=1
XDEBUG_MODE=off vendor/bin/phpunit --configuration phpunit.xml \
  --do-not-cache-result --no-coverage || test_status=1
php tests/write-workflows.php || test_status=1
exit "$test_status"
