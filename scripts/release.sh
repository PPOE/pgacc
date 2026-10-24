#!/bin/sh
# Build the pgacc install archive for a release: the committed code at HEAD
# (git archive, .gitattributes export-ignore) plus the locked vendor/, because
# production cannot reach GitHub or Composer. See README.md, "Releases".
#
#   sh scripts/release.sh [--skip-check-php53] <version>
#
# This is the build step of .github/workflows/release.yml, which alone creates
# releases (a draft, on a tag push). Running it locally is for testing only.
#
# Output in build/release/: pgacc-<version>.tar.gz (top directory
# pgacc-<version>/), pgacc-<version>.tar.gz.sha256, notes-<version>.md (the
# CHANGELOG.md section "## [<version>] - YYYY-MM-DD ...", which must exist and
# be dated) and title-<version>.txt (the release title). The built vendor/ is
# loaded on PHP 5.3 (tests/php53/vendor-check.php in the
# tests/php53/runtime.Dockerfile image) unless --skip-check-php53 is given.
set -eu

usage() {
  echo "Usage: sh scripts/release.sh [--skip-check-php53] <version>" >&2
  exit 2
}

check_php53=true
version=
version_set=false
for arg in "$@"; do
  case "$arg" in
    --skip-check-php53) check_php53=false ;;
    -*) usage ;;
    *)
      [ "$version_set" = false ] || usage
      version=$arg
      version_set=true
      ;;
  esac
done
# Character check first: this also rejects newlines, which grep would split.
case "$version" in
  '' | *[!0-9A-Za-z.-]*)
    echo "Invalid version (allowed: 0-9 A-Z a-z . -)" >&2
    exit 2
    ;;
esac
if ! printf '%s\n' "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'; then
  echo "Version must look like 1.2.3 or 1.2.3-rc.1 (without the leading v): $version" >&2
  exit 2
fi

repo_dir=$(pwd -P)
if [ ! -f "$repo_dir/scripts/release.sh" ] || [ ! -f "$repo_dir/composer.lock" ]; then
  echo "Run from the repository root" >&2
  exit 2
fi
if [ -n "$(git -C "$repo_dir" status --porcelain)" ]; then
  echo "The working tree is not clean; commit or remove the changes first" >&2
  exit 1
fi
if ! tar --version 2>/dev/null | grep -q 'GNU tar'; then
  echo "GNU tar is required (reproducible archive: --sort=name, --mtime)" >&2
  exit 1
fi
if command -v sha256sum >/dev/null 2>&1; then
  sha256() { sha256sum "$@"; }
elif command -v shasum >/dev/null 2>&1; then
  sha256() { shasum -a 256 "$@"; }
else
  echo "sha256sum or shasum is required" >&2
  exit 1
fi

name="pgacc-$version"
out_dir="$repo_dir/build/release"
stage_root="$out_dir/stage"
stage="$stage_root/$name"
archive="$out_dir/$name.tar.gz"
notes="$out_dir/notes-$version.md"
title_file="$out_dir/title-$version.txt"

# Release notes: the dated CHANGELOG.md section of this version, from HEAD. The
# section ends at the next "## " heading or at the link definitions.
changelog=$(git -C "$repo_dir" show HEAD:CHANGELOG.md 2>/dev/null) || {
  echo "CHANGELOG.md is missing at HEAD" >&2
  exit 1
}
heading=$(printf '%s\n' "$changelog" | awk -v prefix="## [$version]" \
  'index($0, prefix) == 1 { print; exit }')
if [ -z "$heading" ]; then
  echo "CHANGELOG.md has no section \"## [$version] - YYYY-MM-DD\"" >&2
  exit 1
fi
if ! printf '%s\n' "$heading" | grep -Eq '^## \[[^]]*\] - [0-9]{4}-[0-9]{2}-[0-9]{2}( .*)?$'; then
  echo "The CHANGELOG.md section of $version is not dated (\"## [$version] - YYYY-MM-DD\"): $heading" >&2
  exit 1
fi
mkdir -p "$out_dir"
printf '%s\n' "$changelog" | awk -v prefix="## [$version]" '
  index($0, prefix) == 1 { found = 1; next }
  found && (/^## / || /^\[.*\]: /) { exit }
  found { print }
' | sed -e '/./,$!d' >"$notes"
if ! grep -q '[^[:space:]]' "$notes"; then
  rm -f "$notes"
  echo "The CHANGELOG.md section of $version is empty" >&2
  exit 1
fi
# Title: "v1.0.0", plus a trailing "(...)" of the heading, e.g. "(php5-last)".
title_suffix=$(printf '%s\n' "$heading" | sed -n 's/.*\(([^)]*)\) *$/\1/p')
printf '%s\n' "v$version${title_suffix:+ $title_suffix}" >"$title_file"

build_archive() {
  # Same pinned Composer 2.2 LTS image as the PHP 5.3 lane (2.2 generates an
  # autoloader that runs on PHP 5.3); tests/php53/runtime.Dockerfile is the
  # single source of the pin.
  composer_image=$(sed -n 's/^FROM \(composer:[^ ]*@sha256:[0-9a-f]\{64\}\) AS dependencies$/\1/p' \
    "$repo_dir/tests/php53/runtime.Dockerfile")
  if [ -z "$composer_image" ]; then
    echo "No pinned composer image in tests/php53/runtime.Dockerfile" >&2
    exit 1
  fi

  rm -rf "$stage_root" "$archive" "$archive.sha256"
  trap 'rm -rf "$stage_root"' EXIT
  trap 'exit 1' HUP INT TERM
  mkdir -p "$stage"

  # Code at HEAD, without the export-ignore'd development files.
  git -C "$repo_dir" archive --format=tar HEAD | tar -x -C "$stage"
  if [ -e "$stage/constants.php" ] || [ -e "$stage/tests/run.sh" ]; then
    echo "The archive contains development or configuration files; check .gitattributes" >&2
    exit 1
  fi

  # Locked runtime dependencies (no dev packages), as the host user. The
  # autoloader class suffix is random by default; the lock's content-hash
  # keeps it stable (global config, so the shipped composer.json stays
  # unchanged).
  suffix=$(sed -n 's/^ *"content-hash": "\([0-9a-f]*\)".*/\1/p' "$stage/composer.lock")
  if [ -z "$suffix" ]; then
    echo "composer.lock has no content-hash" >&2
    exit 1
  fi
  docker run --rm \
    --user "$(id -u):$(id -g)" \
    --env COMPOSER_HOME=/tmp/composer \
    --env PGACC_AUTOLOADER_SUFFIX="$suffix" \
    --volume "$stage:/app" \
    --workdir /app \
    "$composer_image" \
    sh -c 'mkdir -p "$COMPOSER_HOME" \
      && printf "{\"config\":{\"autoloader-suffix\":\"%s\"}}\n" "$PGACC_AUTOLOADER_SUFFIX" >"$COMPOSER_HOME/config.json" \
      && composer install --no-dev --no-interaction --no-progress --prefer-dist \
        --no-scripts --classmap-authoritative'
  if [ ! -f "$stage/vendor/autoload.php" ]; then
    echo "composer install did not produce vendor/autoload.php" >&2
    exit 1
  fi

  if [ "$check_php53" = true ]; then
    # Production-like PHP 5.3 image from this checkout; the release vendor/
    # is mounted over the image's own copy.
    check_image="pgacc-release-php53-check${PGACC_TEST_ID:+-$PGACC_TEST_ID}"
    docker build --quiet --file tests/php53/runtime.Dockerfile --tag "$check_image" "$repo_dir" >/dev/null
    status=0
    docker run --rm --volume "$stage/vendor:/app/vendor:ro" "$check_image" \
      php tests/php53/vendor-check.php || status=$?
    docker rmi "$check_image" >/dev/null || true
    if [ "$status" -ne 0 ]; then
      echo "The release vendor/ failed the PHP 5.3 check" >&2
      exit 1
    fi
  else
    echo "Skipped the PHP 5.3 check of vendor/ (--skip-check-php53)"
  fi

  # Reproducible archive: commit date as mtime, numeric root owner, sorted
  # entries, no gzip timestamp.
  mtime=$(git -C "$repo_dir" log -1 --format=%ct HEAD)
  tar -C "$stage_root" --format=gnu --sort=name --mtime="@$mtime" \
    --owner=0 --group=0 --numeric-owner --mode='u+rwX,go+rX,go-w' \
    -cf - "$name" | gzip -n -9 >"$archive"
  (cd "$out_dir" && sha256 "$name.tar.gz" >"$name.tar.gz.sha256")
  rm -rf "$stage_root"

  echo "Archive: build/release/$name.tar.gz ($(wc -c <"$archive" | tr -d ' ') bytes)"
  echo "Checksum: $(cat "$archive.sha256")"
  echo "Notes: build/release/notes-$version.md ($(wc -l <"$notes" | tr -d ' ') lines)"
  echo "Title: $(cat "$title_file")"
}

build_archive
