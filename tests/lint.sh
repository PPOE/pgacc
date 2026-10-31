#!/bin/sh
set -eu

# Top-level files, the entry points in public/ and the library files in src/.
php_files="$({ find . -maxdepth 1 -type f -name '*.php' -print; find ./public ./src -maxdepth 1 -type f -name '*.php' -print; } | sort)"

if [ -z "$php_files" ]; then
  echo "No top-level PHP files found" >&2
  exit 1
fi

linted=0
for php_file in $php_files; do
  if ! php -l "$php_file" >/dev/null; then
    php -l "$php_file"
    exit 1
  fi
  linted=$((linted + 1))
done

echo "PHP syntax check passed for $linted files"
