#!/bin/sh
# Build style.css from css/app.css with the Tailwind CSS standalone CLI (a
# single binary, no Node.js). style.css is committed and served as is, so a
# release needs no build step; css/ is a development source (.gitattributes
# export-ignore).
#
#   sh scripts/build-css.sh           rebuild style.css
#   sh scripts/build-css.sh --check   fail if style.css differs from a rebuild (CI)
#
# The CLI version is pinned below with the SHA-256 of each platform binary
# (from the release's sha256sums.txt). The binary is downloaded once from the
# GitHub release, verified and cached in build/tailwindcss/<version>/. To
# update: change TAILWIND_VERSION and the checksums, rebuild, review the diff
# of style.css (tests/http-smoke.php and tests/mutation/targets/amount-cents.php
# search its td rule) and note the update in CHANGELOG.md.
set -eu

TAILWIND_VERSION=4.3.3

usage() {
  echo "Usage: sh scripts/build-css.sh [--check]" >&2
  exit 2
}

check=false
case "${1-}" in
  '') ;;
  --check) check=true ;;
  *) usage ;;
esac
[ "$#" -le 1 ] || usage

repo_dir=$(pwd -P)
if [ ! -f "$repo_dir/scripts/build-css.sh" ] || [ ! -f "$repo_dir/css/app.css" ]; then
  echo "Run from the repository root" >&2
  exit 2
fi

case "$(uname -s)-$(uname -m)" in
  Linux-x86_64 | Linux-amd64) asset=tailwindcss-linux-x64 ;;
  Linux-aarch64 | Linux-arm64) asset=tailwindcss-linux-arm64 ;;
  Darwin-x86_64) asset=tailwindcss-macos-x64 ;;
  Darwin-arm64) asset=tailwindcss-macos-arm64 ;;
  *)
    echo "No pinned Tailwind CSS binary for $(uname -s) $(uname -m)" >&2
    exit 2
    ;;
esac
# musl-based Linux (Alpine) needs the musl build.
if [ "$(uname -s)" = Linux ] && ldd --version 2>&1 | grep -qi musl; then
  asset=$asset-musl
fi
case "$asset" in
  tailwindcss-linux-x64) sha=dc61b3ac6b8c9ca874c0cc4c57b2409791a64c5540404ca5f5367360babc313a ;;
  tailwindcss-linux-x64-musl) sha=a04d34ceacc8f52cbe8920ad846cdeb61d3d0021dba32db0d1f77c9d9fad7a6c ;;
  tailwindcss-linux-arm64) sha=55fd0b241214eff3de1e8ee4f22796662f2d2e7a49bcfca7477cfd0bac398195 ;;
  tailwindcss-linux-arm64-musl) sha=71ea4be79c9de9827545682df3e040053fb535d37c71ed2cfdedf9385a0868e0 ;;
  tailwindcss-macos-x64) sha=7922e0953f2110c05976e3bf58f14e643d90427575e766b7d433f5f80cbee7e1 ;;
  tailwindcss-macos-arm64) sha=cdf646702987a743464dff4d9c60fd4480d1c1e73dd819a9a67f1078815dce9d ;;
esac

if command -v sha256sum >/dev/null 2>&1; then
  sha256() { sha256sum "$1" | cut -d' ' -f1; }
elif command -v shasum >/dev/null 2>&1; then
  sha256() { shasum -a 256 "$1" | cut -d' ' -f1; }
else
  echo "sha256sum or shasum is required" >&2
  exit 1
fi

cache_dir="$repo_dir/build/tailwindcss/$TAILWIND_VERSION"
bin="$cache_dir/$asset"
mkdir -p "$cache_dir"
if [ ! -x "$bin" ] || [ "$(sha256 "$bin")" != "$sha" ]; then
  url="https://github.com/tailwindlabs/tailwindcss/releases/download/v$TAILWIND_VERSION/$asset"
  echo "Downloading $url" >&2
  curl -fsSL --retry 3 -o "$bin.part" "$url"
  actual=$(sha256 "$bin.part")
  if [ "$actual" != "$sha" ]; then
    rm -f "$bin.part"
    echo "Checksum mismatch for $asset: expected $sha, got $actual" >&2
    exit 1
  fi
  chmod +x "$bin.part"
  mv "$bin.part" "$bin"
fi

if [ "$check" = true ]; then
  out="$repo_dir/build/tailwindcss/style.check.css"
else
  out="$repo_dir/style.css"
fi
# No content scanning (source(none) in css/app.css): the output depends only
# on css/app.css and the CLI version. --optimize flattens the nesting and adds
# the vendor prefixes for the supported browsers; the output stays unminified.
"$bin" --input "$repo_dir/css/app.css" --output "$out" --optimize 2>"$repo_dir/build/tailwindcss/build.log" || {
  cat "$repo_dir/build/tailwindcss/build.log" >&2
  exit 1
}

if [ "$check" = true ]; then
  if ! cmp -s "$out" "$repo_dir/style.css"; then
    diff -u "$repo_dir/style.css" "$out" | head -n 60 >&2 || true
    echo "style.css is not the build of css/app.css; run sh scripts/build-css.sh and commit style.css" >&2
    exit 1
  fi
  echo "style.css matches css/app.css (Tailwind CSS $TAILWIND_VERSION)"
fi
