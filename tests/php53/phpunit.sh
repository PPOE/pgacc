#!/bin/sh
# PHPUnit 4.8 on the PHP 5.3 CLI of the production-like image (stock squeeze
# /etc/php5/cli/php.ini). See tests/php53/phpunit.xml.
set -eu

php --version
php /opt/test-tools/phpunit-4.8.36.phar --configuration tests/php53/phpunit.xml
