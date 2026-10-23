#!/bin/sh
set -eu

php tests/http-smoke.php
php tests/http-login.php
XDEBUG_MODE=coverage vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --coverage-text
php tests/show-uncovered-functions.php /tmp/functions-clover.xml
php tests/write-workflows.php
