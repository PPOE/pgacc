#!/bin/sh
set -eu
# Records the PHP 5 reference (golden master) on the PHP 5.6 lane into
# tests/reference/php5/. Review the diff before committing it.
# See tests/README.md, "PHP 5 reference recordings".
exec sh "$(dirname "$0")/run.sh" record "$@"
