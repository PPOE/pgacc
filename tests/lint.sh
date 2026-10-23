#!/bin/sh
set -eu

php_files="$(find . -maxdepth 1 -type f -name '*.php' -print | sort)"

if [ -z "$php_files" ]; then
  echo "No top-level PHP files found" >&2
  exit 1
fi

linted=0
for php_file in $php_files; do
  php -l "$php_file" >/dev/null
  linted=$((linted + 1))
done

echo "PHP syntax check passed for $linted files"
