#!/bin/sh
set -eu

repo_dir=$(pwd -P)

check_syntax()
{
  image=$1
  docker run --rm \
    --volume "$repo_dir:/app:ro" \
    --workdir /app \
    "$image" sh tests/lint.sh
  echo "Compatibility syntax pass: $image"
}

check_syntax 'php:5.5-cli@sha256:15008d2bf3fbdf421b133e085dafb8f8640e8b20be4068d55ebf01dc211868b8'
check_syntax 'php:5.6-cli@sha256:6ce95208609dc66df163ab936c970b3b34cd901b85c747102c5999f08ade9143'
check_syntax 'php:7.4.33-cli-bullseye@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5'
check_syntax 'php:8.0-cli@sha256:0569e384b9064c04dec55dc6e41be41b494a878dfbb6577a7d76bd50cfd5bc00'
check_syntax 'php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37'
