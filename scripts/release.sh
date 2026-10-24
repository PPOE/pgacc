#!/bin/sh
# Build the pgacc install archive for a release: the committed code at HEAD
# (git archive, .gitattributes export-ignore) plus the locked vendor/, because
# production cannot reach GitHub or Composer. See README.md, "Releases".
#
#   sh scripts/release.sh [--check-php53] [--publish-draft|--publish-only] <version>
#
# Output in build/release/: pgacc-<version>.tar.gz (top directory
# pgacc-<version>/), pgacc-<version>.tar.gz.sha256 and notes-<version>.md (the
# CHANGELOG.md section "## [<version>] ...").
#
#   --check-php53    load the built vendor/ on PHP 5.3 (tests/php53/vendor-check.php
#                    in the tests/php53/runtime.Dockerfile image)
#   --publish-draft  create a DRAFT GitHub release v<version> with the archive
#                    and checksum (gh); tag v<version> must point to HEAD
#                    locally and on origin
#   --publish-only   the same, for an archive already in build/release/ (CI:
#                    built in a job without write access); no rebuild
set -eu

# Same pinned Composer 2.2 LTS image as tests/php53/runtime.Dockerfile (2.2
# generates an autoloader that runs on PHP 5.3).
composer_image='composer:2.2.25@sha256:be23a9c3f73293f966a981be03896f9aaaade6c2cc9415f4cf828d1f39c5005c'

usage() {
  echo "Usage: sh scripts/release.sh [--check-php53] [--publish-draft|--publish-only] <version>" >&2
  exit 2
}

check_php53=false
publish=false
build=true
version=
for arg in "$@"; do
  case "$arg" in
    --check-php53) check_php53=true ;;
    --publish-draft) publish=true ;;
    --publish-only) publish=true; build=false ;;
    -*) usage ;;
    *)
      [ -z "$version" ] || usage
      version=$arg
      ;;
  esac
done
[ -n "$version" ] || usage
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

name="pgacc-$version"
out_dir="$repo_dir/build/release"
stage_root="$out_dir/stage"
stage="$stage_root/$name"
archive="$out_dir/$name.tar.gz"
notes="$out_dir/notes-$version.md"

# Release notes: the CHANGELOG.md section of this version, from HEAD.
changelog=$(git -C "$repo_dir" show HEAD:CHANGELOG.md 2>/dev/null) || {
  echo "CHANGELOG.md is missing at HEAD" >&2
  exit 1
}
mkdir -p "$out_dir"
printf '%s\n' "$changelog" | awk -v version="$version" '
  index($0, "## [" version "]") == 1 { found = 1; next }
  found && /^## \[/ { exit }
  found { print }
' | sed -e '/./,$!d' >"$notes"
if ! grep -q '[^[:space:]]' "$notes"; then
  rm -f "$notes"
  echo "CHANGELOG.md has no section \"## [$version] ...\" (or it is empty)" >&2
  exit 1
fi
# Title: "v1.0.0", plus a trailing "(...)" of the heading, e.g. "(php5-last)".
title_suffix=$(printf '%s\n' "$changelog" | grep -m 1 -F "## [$version]" | sed -n 's/.*\(([^)]*)\) *$/\1/p')
title="v$version${title_suffix:+ $title_suffix}"

# Publishing: tag v<version> must point to HEAD, locally and on origin (gh
# would otherwise create a missing tag from the default branch).
if [ "$publish" = true ]; then
  tag="v$version"
  head_commit=$(git -C "$repo_dir" rev-parse HEAD)
  tag_commit=$(git -C "$repo_dir" rev-parse --verify --quiet "refs/tags/$tag^{commit}") || {
    echo "Tag $tag does not exist locally; create and push it first" >&2
    exit 1
  }
  if [ "$tag_commit" != "$head_commit" ]; then
    echo "Tag $tag ($tag_commit) does not point to HEAD ($head_commit)" >&2
    exit 1
  fi
  # Annotated tags: the peeled "^{}" entry is the commit; lightweight tags
  # have only the plain entry.
  remote_refs=$(git -C "$repo_dir" ls-remote origin "refs/tags/$tag" "refs/tags/$tag^{}") || {
    echo "Cannot list the tags on origin" >&2
    exit 1
  }
  remote_commit=$(printf '%s\n' "$remote_refs" | awk -v ref="refs/tags/$tag^{}" '$2 == ref { print $1 }')
  if [ -z "$remote_commit" ]; then
    remote_commit=$(printf '%s\n' "$remote_refs" | awk -v ref="refs/tags/$tag" '$2 == ref { print $1 }')
  fi
  if [ -z "$remote_commit" ]; then
    echo "Tag $tag is not on origin; push it first (git push origin $tag)" >&2
    exit 1
  fi
  if [ "$remote_commit" != "$head_commit" ]; then
    echo "Tag $tag on origin ($remote_commit) does not point to HEAD ($head_commit)" >&2
    exit 1
  fi
fi

if [ "$build" = false ]; then
  for file in "$archive" "$archive.sha256"; do
    [ -f "$file" ] || {
      echo "--publish-only: $file is missing; build it first" >&2
      exit 1
    }
  done
  (cd "$out_dir" && sha256sum -c "$name.tar.gz.sha256" >/dev/null) || {
    echo "--publish-only: the archive does not match its .sha256" >&2
    exit 1
  }
  if [ "$(gzip -dc "$archive" | tar -t | head -n 1)" != "$name/" ]; then
    echo "--publish-only: $archive is not the pgacc-$version archive" >&2
    exit 1
  fi
else  # Build: up to the matching "fi" after the summary below.

# Code at HEAD, without the export-ignore'd development files.
rm -rf "$stage_root" "$archive" "$archive.sha256"
mkdir -p "$stage"
git -C "$repo_dir" archive --format=tar HEAD | tar -x -C "$stage"
if [ -e "$stage/constants.php" ] || [ -e "$stage/tests/run.sh" ]; then
  echo "The archive contains development or configuration files; check .gitattributes" >&2
  exit 1
fi

# Locked runtime dependencies (no dev packages), as the host user. The
# autoloader class suffix is random by default; the lock's content-hash keeps
# it stable (global config, so the shipped composer.json stays unchanged).
suffix=$(sed -n 's/^ *"content-hash": "\([0-9a-f]*\)".*/\1/p' "$stage/composer.lock")
[ -n "$suffix" ] || {
  echo "composer.lock has no content-hash" >&2
  exit 1
}
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
[ -f "$stage/vendor/autoload.php" ] || {
  echo "composer install did not produce vendor/autoload.php" >&2
  exit 1
}

if [ "$check_php53" = true ]; then
  # Production-like PHP 5.3 image from this checkout; the release vendor/ is
  # mounted over the image's own copy.
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
fi

# Reproducible-ish archive: commit date as mtime, numeric root owner, sorted
# entries with GNU tar, no gzip timestamp.
mtime=$(git -C "$repo_dir" log -1 --format=%ct HEAD)
if tar --version 2>/dev/null | grep -q 'GNU tar'; then
  tar -C "$stage_root" --sort=name --mtime="@$mtime" --owner=0 --group=0 \
    --numeric-owner --mode='u+rwX,go+rX,go-w' -cf - "$name" | gzip -n -9 >"$archive"
else
  find "$stage" -exec touch -h -d "@$mtime" {} + 2>/dev/null || true
  tar -C "$stage_root" -cf - "$name" | gzip -n -9 >"$archive"
fi
(cd "$out_dir" && sha256sum "$name.tar.gz" >"$name.tar.gz.sha256")
rm -rf "$stage_root"

echo "Archive: build/release/$name.tar.gz ($(wc -c <"$archive" | tr -d ' ') bytes)"
echo "Checksum: $(cat "$archive.sha256")"
echo "Notes: build/release/notes-$version.md ($(wc -l <"$notes" | tr -d ' ') lines)"
fi

if [ "$publish" = true ]; then
  # The tag was checked before the build; gh uses the existing tag (no
  # --target), so it creates none.
  gh release create "v$version" --draft --title "$title" --notes-file "$notes" \
    "$archive" "$archive.sha256"
  echo "Draft release v$version created; review and publish it on GitHub"
fi
