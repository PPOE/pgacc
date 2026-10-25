#!/bin/sh
set -eu

# Runs inside the reference-test container (tests/reference/compose.yaml).
out=/reference-out
if [ -n "${PGACC_REFERENCE_OWNER:-}" ]; then
  # Hand the bind-mounted output back to the user who started the run.
  trap 'chown -R "$PGACC_REFERENCE_OWNER" "$out" || true' EXIT
fi
recording=$out/actual
mkdir -p "$recording"
php --version | head -n 1
php tests/reference/selftest.php
php tests/reference/helpers.php "$recording"
php tests/reference/scenario.php "$recording"
php tests/reference/compare.php tests/reference/php5 "$out/actual" "$out"
