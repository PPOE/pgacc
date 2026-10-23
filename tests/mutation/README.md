# Targeted mutation test: rights2orgasql

`rights2orgasql.php` runs the existing `testRights2orgasql` data provider against
31 deliberately chosen changes to `rights2orgasql()`. It copies the current
source and test files into a disposable workspace, syntax-checks every mutant,
and invokes PHPUnit separately for each one. The baseline must pass first;
existing runtime errors cannot be mistaken for mutation kills.

The mutations cover privilege checks, exact role matching, numeric validation,
single-organization handling, SQL restrictions, and fail-open behavior. They
are a targeted set, not an exhaustive Infection-generated mutation score.

TODO: evaluate a mutation testing framework such as Infection to generate
broader mutations and replace this handpicked runner. The main test plan tracks
runtime compatibility, baseline requirements, and CI integration for that work.

## Results

| Test set | Rights cases | Killed | Survived | Invalid syntax |
|---|---:|---:|---:|---:|
| Original PHP 7.4 tests | 4 | 13 | 17 | 0 |
| Expanded PHP 7.4 tests | 24 | 30 | 0 | 0 |
| Candidate initialization fix on PHP 8.5, without warning suppression | 24 | 30 | 0 | 0 |
| Applied initialization fix on PHP 7.4, without warning suppression | 24 | 31 | 0 | 0 |
| Applied initialization fix on PHP 8.5, without warning suppression | 24 | 31 | 0 | 0 |

The original cases missed privilege substrings, mixed privilege tokens, a
newly granted root exemption, single IDs, empty rights, numeric prefixes and
suffixes, negative/decimal IDs, zero IDs, and mixed valid/invalid tokens. The
expanded data provider kills the same previously surviving mutants.

The array initialization fix is now applied, and the rights assertions no longer
use `@`. All 24 cases pass without warning suppression on PHP 7.4 and PHP 8.5.
The runner now also mutates away the initialization: both runtimes kill that
regression, along with the original 30 mutations. The earlier candidate result
was measured in a disposable copy before the production fix was applied.

## Run

Build the existing PHP 7.4 image and verify the full baseline first:

```sh
sh tests/run.sh 7.4
```

Then run the targeted mutations without database or network access:

```sh
repo_dir=$(pwd -P)
mkdir -p /tmp/pgacc-rights-mutations
docker run --rm --network none --read-only --tmpfs /tmp \
  --env XDEBUG_MODE=off \
  --volume "$repo_dir:/workspace:ro" \
  --volume /tmp/pgacc-rights-mutations:/results \
  pgacc-test-application \
  php /workspace/tests/mutation/rights2orgasql.php \
    /workspace /results /app/vendor/bin/phpunit
```

`results.json` records the source SHA-256, runtime, exact mutations, and outcome.
Each mutation has a PHPUnit log. The runner exits nonzero if the baseline fails,
any mutant has invalid syntax, or any valid mutant survives.

To run the same mutations under PHP 8.5, use the already-built
`pgacc-test-application-php85` image and a different output directory. The
mutation runner always changes only its copies, never the repository.
