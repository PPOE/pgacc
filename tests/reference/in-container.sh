#!/bin/sh
set -eu

# Runs inside the reference-test container (tests/reference/compose.yaml).
out=/reference-out
if [ -n "${PGACC_REFERENCE_OWNER:-}" ]; then
  # Hand the bind-mounted output back to the user who started the run.
  trap 'chown -R "$PGACC_REFERENCE_OWNER" "$out" || true' EXIT
fi
php --version | head -n 1
php tests/reference/selftest.php
if [ "$PGACC_REFERENCE_MODE" = record ]; then
  php tests/reference/helpers.php "$out"
  php tests/reference/scenario.php "$out"
  echo "Reference recorded in tests/reference/php5/"
else
  mkdir -p "$out/actual"
  php tests/reference/helpers.php "$out/actual"
  php tests/reference/scenario.php "$out/actual"
  php tests/reference/compare.php tests/reference/php5 "$out/actual" "$out"
fi
