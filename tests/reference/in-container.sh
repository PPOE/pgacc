#!/bin/sh
set -eu

# Runs inside the reference-test container (tests/reference/compose.yaml).
out=/reference-out
if [ -n "${PGACC_REFERENCE_OWNER:-}" ]; then
  # Hand the bind-mounted output back to the user who started the run.
  trap 'chown -R "$PGACC_REFERENCE_OWNER" "$out" || true' EXIT
fi
# PGACC_REFERENCE_PARTS: "all" (default); on the PHP 5.3 lane "helpers" runs
# helpers.php on the PHP 5.3 image and "scenario" the HTTP scenario (and the
# comparison) from the PHP 5.6 client image (tests/reference/compose.php53.yaml).
parts=${PGACC_REFERENCE_PARTS:-all}
recording=$out
if [ "$PGACC_REFERENCE_MODE" != record ]; then
  recording=$out/actual
  mkdir -p "$recording"
fi
php --version | head -n 1
if [ "$parts" != helpers ]; then
  php tests/reference/selftest.php
fi
if [ "$parts" != scenario ]; then
  php tests/reference/helpers.php "$recording"
fi
if [ "$parts" = helpers ]; then
  exit 0
fi
php tests/reference/scenario.php "$recording"
if [ "$PGACC_REFERENCE_MODE" = record ]; then
  echo "Reference recorded in tests/reference/php5/ on PHP $PGACC_REFERENCE_LANE"
else
  php tests/reference/compare.php tests/reference/php5 "$out/actual" "$out"
fi
