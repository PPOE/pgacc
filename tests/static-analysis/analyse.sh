#!/bin/sh
# Runs inside the static-analysis image; start it with tests/static-analysis.sh.
#
#   analyse.sh check FILE...             canary self-test, then fail on any
#                                        finding not in, or missing from, the
#                                        reviewed baselines
#   analyse.sh report FILE...            print every finding, baselines ignored
#   analyse.sh update-baselines FILE...  rewrite both baselines from the
#                                        current findings (review the diff!)
#
# FILE... are the tracked top-level application PHP files. The repository is
# mounted read-only at /app; analysis runs on a copy in /tmp/workspace.
set -eu

mode=${1:-}
shift || true

source_dir=/app
config_source=$source_dir/tests/static-analysis
work=/tmp/workspace
config=tests/static-analysis
phpcs=/tools/vendor/bin/phpcs
phpstan=/tools/vendor/bin/phpstan
findings="php $config/findings.php"
results=/tmp/results

case "$mode" in
  check|report|update-baselines) ;;
  *)
    echo "Usage: analyse.sh check|report|update-baselines FILE..." >&2
    exit 2
    ;;
esac

if [ "$#" -eq 0 ]; then
  echo "No application files given" >&2
  exit 2
fi

rm -rf "$work" "$results"
mkdir -p "$work/tests" "$results"
for file in "$@"; do
  case "$file" in
    */*|constants.php|*[!A-Za-z0-9_.-]*)
      echo "Unexpected application file: $file" >&2
      exit 2
      ;;
  esac
  cp "$source_dir/$file" "$work/$file"
done
cp -R "$config_source" "$work/$config"
# Present only so require("constants.php") and require("vendor/autoload.php")
# resolve; neither is analysed. The constants are the synthetic test fixture.
cp "$source_dir/tests/runtime/test-constants.php" "$work/constants.php"
ln -s /dependencies/vendor "$work/vendor"
cd "$work"

# PHP_CodeSniffer exit status: bits 1/2 mean findings; anything else is a
# tool failure that must not be mistaken for a clean or baselined result.
run_phpcs()
{
  phpcs_status=0
  "$phpcs" --standard="$config/phpcs.xml" --no-cache "$@" || phpcs_status=$?
  if [ $((phpcs_status & ~3)) -ne 0 ]; then
    echo "PHP_CodeSniffer failed with exit status $phpcs_status" >&2
    exit 2
  fi
}

# PHPStan exit status 1 means findings; anything higher is a tool failure.
run_phpstan()
{
  phpstan_status=0
  "$phpstan" analyse --no-progress --memory-limit=1G "$@" || phpstan_status=$?
  if [ "$phpstan_status" -gt 1 ]; then
    echo "PHPStan failed with exit status $phpstan_status" >&2
    exit 2
  fi
  return "$phpstan_status"
}

canary_check()
{
  echo "== Canary self-test (known bug classes must still be detected)"
  run_phpcs --report=json --report-file="$results/canary-phpcs.json" "$config/canaries"
  run_phpstan --error-format=json -c "$config/phpstan-common.neon" "$config/canaries" \
    >"$results/canary-phpstan.json" || true
  $findings canaries "$config/canaries/expected.txt" \
    "$results/canary-phpcs.json" "$results/canary-phpstan.json" "$work/"
}

echo "Static analysis of $# application files (PHP_CodeSniffer $("$phpcs" --version | sed -n 's/^PHP_CodeSniffer version \([^ ]*\).*/\1/p'), $("$phpstan" --version --no-ansi))"

case "$mode" in
  report)
    echo "== PHPCompatibility (testVersion 5.3-8.5) and short open tags"
    run_phpcs -s "$@"
    echo "== PHPStan (baseline not applied)"
    run_phpstan -c "$config/phpstan-application.neon" || true
    ;;

  update-baselines)
    output=/baseline-output
    if [ ! -w "$output" ]; then
      echo "update-baselines needs a writable $output mount" >&2
      exit 2
    fi
    run_phpcs --report=json --report-file="$results/phpcs.json" "$@"
    {
      echo "# PHP_CodeSniffer baseline: reviewed existing findings, one per line."
      echo "# file<TAB>sniff<TAB>message; duplicates are separate findings."
      echo "# A finding missing here fails CI; an entry no longer reported fails"
      echo "# CI until it is removed. Regenerate with:"
      echo "#   sh tests/static-analysis.sh --update-baselines"
      $findings phpcs-list "$results/phpcs.json" "$work/"
    } >"$output/phpcs-baseline.txt"
    # Generated inside the workspace so its paths stay relative (../../x.php).
    run_phpstan -c "$config/phpstan-application.neon" \
      --generate-baseline="$config/phpstan-baseline.neon"
    cp "$config/phpstan-baseline.neon" "$output/phpstan-baseline.neon"
    echo "Baselines rewritten; review every added or removed entry before committing."
    ;;

  check)
    status=0
    canary_check || status=1
    echo "== PHPCompatibility (testVersion 5.3-8.5) and short open tags"
    run_phpcs --report=json --report-file="$results/phpcs.json" "$@"
    $findings phpcs-list "$results/phpcs.json" "$work/" >"$results/phpcs.txt"
    if ! $findings compare "$config/phpcs-baseline.txt" "$results/phpcs.txt"; then
      status=1
      echo "Full PHP_CodeSniffer report:"
      run_phpcs -s "$@"
    fi
    echo "== PHPStan (level and baseline: $config/phpstan.neon)"
    run_phpstan -c "$config/phpstan.neon" || status=1
    exit "$status"
    ;;
esac
