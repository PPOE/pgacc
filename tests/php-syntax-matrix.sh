#!/bin/sh
set -eu

repo_dir=$(pwd -P)

check_syntax()
{
  image=$1
  expected=$2
  logfile=$(mktemp)

  if docker run --rm \
    --volume "$repo_dir:/app:ro" \
    --workdir /app \
    "$image" sh tests/lint.sh >"$logfile" 2>&1; then
    result=0
  else
    result=$?
  fi

  if [ "$expected" = pass ] && [ "$result" -ne 0 ]; then
    cat "$logfile"
    rm -f "$logfile"
    echo "Expected syntax pass for $image" >&2
    exit 1
  fi

  if [ "$expected" = php8-legacy-failure ]; then
    if [ "$result" -eq 0 ] || ! grep -Fq 'Array and string offset access syntax with curly braces is no longer supported' "$logfile"; then
      cat "$logfile"
      rm -f "$logfile"
      echo "Expected the known functions.php PHP 8 syntax failure for $image" >&2
      exit 1
    fi
  fi

  cat "$logfile"
  rm -f "$logfile"
  if [ "$expected" = pass ]; then
    echo "Compatibility syntax pass: $image"
  else
    echo "Known PHP 8 syntax incompatibility confirmed: $image"
  fi
}

# PHP 8.0 is intentionally an expected failure while functions.php uses the
# removed curly-brace string-offset syntax. Update this expectation when that
# legacy syntax is addressed.
check_syntax 'php:5.5-cli@sha256:15008d2bf3fbdf421b133e085dafb8f8640e8b20be4068d55ebf01dc211868b8' pass
check_syntax 'php:5.6-cli@sha256:6ce95208609dc66df163ab936c970b3b34cd901b85c747102c5999f08ade9143' pass
check_syntax 'php:7.4.33-cli-bullseye@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5' pass
check_syntax 'php:8.0-cli@sha256:0569e384b9064c04dec55dc6e41be41b494a878dfbb6577a7d76bd50cfd5bc00' php8-legacy-failure
