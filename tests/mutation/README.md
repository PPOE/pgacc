# Mutation testing

Two mutation tools cover the PHPUnit-tested helpers in `functions.php`:

- [Infection](https://infection.github.io/) generates mutants for the whole
  file and reports a mutation score. It runs on the PHP 7.4 and PHP 8.5 lanes.
  It is a report, not a required CI gate yet.
- `rights2orgasql.php` applies 31 hand-picked, security-focused mutations to
  `rights2orgasql()`. It is described below. It runs on PHP 7.4 and 8.5;
  PHP 5.6 needs the changes listed under its results.

## Infection

```sh
sh tests/mutation/infection.sh 7.4    # or 8.5; extra arguments go to Infection
PGACC_TEST_ID=mine sh tests/mutation/infection.sh 8.5 --min-covered-msi=67
```

The script starts a fresh synthetic PostgreSQL database in its own Compose
project (`pgacc-test[-ID]-infection-php74|php85`), loads the fixtures, builds
the lane's application image, layers the pinned Infection tools on top of it
(`infection.Dockerfile`, image `pgacc-test-infection-php74|php85[-ID]`), and
runs Infection on an internal network with a read-only root filesystem. Reports
are written to `tests/mutation/results/<lane>/` (ignored by Git), or to the
absolute path in `PGACC_INFECTION_RESULTS`: `infection.log` (escaped mutants
with diffs), `summary.log`, `infection.json`, `per-mutator.md`, and
`infection.html`. The script returns Infection's exit status, which is nonzero
only for a failed initial test run or a `--min-msi`/`--min-covered-msi` option.

GitHub Actions runs both lanes, for `functions.php` and for the wrapper, in a
`mutation` job with `continue-on-error` and uploads the reports as artifacts.

### Versions

| Lane | Infection | Installation | Coverage driver | Run time (8 threads) |
|---|---|---|---|---:|
| PHP 7.4.33 | 0.26.6 | locked Composer project in `infection/` | Xdebug 3.1.6 (initial run only) | ~30 s |
| PHP 8.5 | 0.35.8 | PHAR, SHA-256 pinned | PCOV 1.0.12, SHA-256 pinned | ~45 s |
| PHP 8.0 | not set up; 0.26.19 is the last release allowing PHP 8.0 | | | |
| PHP 5.6 | none: every release requires PHP 7.4.7 or newer | | | |

- Infection 0.26.6 is the last release that allows PHP 7.4 (0.26.7 and later
  require PHP 8.0, 0.26.20 8.1, 0.29.10 8.2, 0.32.7 8.3). The published PHARs
  for 0.25.0 to 0.26.6 do not start on PHP 7.4: they fail with
  `Class 'Symfony\Polyfill\Php80\Php80' not found`. The PHP 7.4 lane therefore
  installs `infection/infection` 0.26.6 from `infection/composer.lock`, kept
  separate from the application's `composer.lock`. It is run from `/tmp`,
  because the Composer-installed binary also loads the project autoloader from
  its working directory, whose `nikic/php-parser` 5 conflicts with
  Infection 0.26's version 4.
- The 0.35.8 PHAR was checked against its GPG signature (Infection PHP,
  `C6D76C329EBADE2FB9C458CFC5095986493B4AA0`) before its SHA-256 was pinned.
- Both versions accept the locked PHPUnit 9.6.38 and php-code-coverage 9.2.32.
- Infection 0.26 reads only strict JSON, so the configuration is
  `infection.json` (not JSON5) and uses only keys known to both versions.
  0.35 skips uncovered code unless `--with-uncovered` is given, which the
  script passes on PHP 8.5 so that both lanes report the same metrics.
- On PHP 7.4, Xdebug runs in coverage mode for the initial test run only;
  mutant runs use `xdebug.mode=off` (3.5 minutes became 30 seconds with
  identical results).

### Global functions and the wrapper

No Infection release (checked up to 0.35.8) mutates functions declared outside
a class: its reflection visitor marks every node outside a class as ineligible,
so running Infection on `functions.php` generates 0 mutants. Application files
are not changed for this evaluation. Instead, `infection-shim.php` (a two-line
command-line entry for the `PgaccInfectionShim` class in `InfectionShim.php`)
writes a disposable copy to `/tmp/infection-src/functions.php` in the container:

- each top-level `function name(` becomes `public static function name(` of
  one final class, on the same line as in `functions.php`;
- after the class, each global name is redefined as a one-line delegate,
  `function name(...$arguments) { return Class::name(...$arguments); }`.

Tests and helpers keep calling the global names, so defaults, `global`
statements, output, and return values are unchanged, and reported line numbers
are `functions.php` line numbers. The wrapper refuses input it cannot wrap
faithfully: other top-level code, by-reference parameters or returns, any
`static` (variables, `static::`), and `__FUNCTION__`/`func_get_args()`-style constructs.
`PGACC_FUNCTIONS_FILE` makes `tests/functions-bootstrap.php` load the wrapped
copy, and `infection/phpunit.xml` mirrors `phpunit.xml` with coverage on that
copy. Visibility mutators (`@function_signature`) are disabled because they
would only break the wrapper. Mutants are reported against the wrapper's
`/tmp/infection-src/functions.php` path, so Git diff filters
(`--git-diff-lines`) do not apply.

### Testing the wrapper

`tests/unit/InfectionShimTest.php` is part of the normal PHPUnit suite
(`phpunit.xml` and `tests/php56/phpunit.xml`), so it runs on all four lanes,
including PHPUnit 5.7 on PHP 5.6. Its 40 cases check:

- exact output for small inputs (opening tag with `\n` or `\r\n`, closing tag,
  comments, `{$x}`/`${x}` string interpolation, whitespace before `<?php`);
- that every function of `functions.php` and of the fixture
  `tests/fixtures/infection-shim/functions.php` keeps its line, that every
  other line is unchanged, and that the wrapped `functions.php` passes `php -l`;
- that the wrapped fixture behaves like the original (defaults, `global`,
  output, return values, closures with `use (&$x)`), run in separate
  `php -n` processes because both define the same global names;
- each refusal (other top-level code, inline HTML, a second PHP block,
  by-reference parameters and returns, `static`, `__FUNCTION__`,
  `__METHOD__`, `__CLASS__`, `func_get_args()`, `func_get_arg()`,
  `func_num_args()`, and incomplete input) with its exact message;
- the command-line entry and `main()`: output, new and existing output
  directories, exit status, wrong argument counts, unreadable input, and
  output directories that cannot be created or written.

Line coverage is measured separately, because the merged report covers only
application code. `infection/shim/phpunit.xml` runs these tests with coverage
on `InfectionShim.php` and `infection-shim.php`:

```sh
docker run --rm --network none -v "$PWD/tests:/app/tests:ro" \
  pgacc-test-application sh -c 'cd /app && XDEBUG_MODE=coverage \
  php -d memory_limit=-1 vendor/bin/phpunit \
  --configuration tests/mutation/infection/shim/phpunit.xml \
  --path-coverage --coverage-text'
```

Result: 100% of lines (70/70) and branches (82/82).

Because the wrapper is a class, Infection can mutate it directly against
these tests (`@function_signature` mutators enabled):

```sh
PGACC_INFECTION_TARGET=shim sh tests/mutation/infection.sh 7.4   # or 8.5
```

Reports go to `results/php74-shim` or `results/php85-shim`. Result on
2026-10-23: every mutant killed, 133/133 on PHP 7.4 (Infection 0.26.6) and
161/161 on PHP 8.5 (Infection 0.35.8), MSI 100%. The first run left three
escaped mutants on PHP 8.5: a changed `mkdir()` mode (now asserted) and two
equivalent conditions (a `trim()` after `?>`, where only `<?php` can follow,
and a `$depth > 0` check for `static`, whose top-level use is refused
earlier), which were removed from the code. Infection 0.26 needs the class
file as its `bootstrap` to find the class.

### Results

Run on 2026-10-23 against `functions.php` at commit 54e9a23, with the 115
existing tests:

| Lane | Mutants | Killed | Timed out | Escaped | Not covered | MSI | Covered MSI |
|---|---:|---:|---:|---:|---:|---:|---:|
| PHP 7.4, Infection 0.26.6 | 643 | 377 | 2 | 255 | 9 | 58.94% | 59.78% |
| PHP 8.5, Infection 0.35.8 | 676 | 448 | 1 | 220 | 7 | 66.42% | 67.12% |

Rerun on 2026-10-23 on the unchanged `functions.php` (commit 20b5911), with
`error_reporting=-1` in `infection/phpunit.xml`:

| Lane | Mutants | Killed | Timed out | Escaped | Not covered | MSI | Covered MSI |
|---|---:|---:|---:|---:|---:|---:|---:|
| PHP 7.4, Infection 0.26.6 | 643 | 389 | 1 | 244 | 9 | 60.65% | 61.51% |
| PHP 8.5, Infection 0.35.8 | 676 | 448 | 1 | 220 | 7 | 66.42% | 67.12% |

The 11 PHP 7.4 mutants of the setup gap below are now killed; PHP 8.5 is
unchanged because it already reported the warnings. The tables below describe
the first run.

Escaped mutants by function (PHP 7.4 / PHP 8.5):

| Function | Mutants | Escaped | Notes |
|---|---:|---:|---|
| `acc_header` | 166 / 148 | 156 / 132 | Only titles and three links are asserted |
| `getfilter` | 250 / 266 | 66 / 54 | Plus 7 not covered on line 74 (unreachable) |
| `percent_of_bookings` | 64 / 64 | 14 / 14 | Year range and `die()` messages |
| `format_date` | 27 / 30 | 5 / 5 | Regex anchors |
| `vouchers_reset_ack` | 6 / 6 | 5 / 5 | `die()` message, empty result loop |
| `ascii2hex` | 18 / 18 | 3 / 3 | Zero padding order |
| `tag` | 14 / 18 | 2 / 2 | Euro regex anchors |
| `csv_download_link` | 7 / 9 | 1 / 2 | Count of 0 |
| `acc_footer`, `getsort`, `getoppsort` | 26 / 30 | 3 / 3 | Equivalent or unobservable |
| `hex2ascii`, `block_start`, `block_end`, `rights2orgasql`, `konto2lo`, `eyes`, `whitelist` | 65 / 87 | 0 / 0 | |

Classification of the escaped mutants:

| Class | PHP 7.4 | PHP 8.5 |
|---|---:|---:|
| Real test gaps | 222 | 198 |
| Gaps caused by the PHP 7.4 PHPUnit setup (notices not reported) | 11 | 0 |
| Equivalent mutants | 14 | 14 |
| Unobservable in the PHPUnit process (`die()` message, `checklogin()` stub) | 8 | 8 |

Equivalent: the first `.=` in `getfilter` (line 37, `$filter` is empty); the
three mutants of the malformed regex on line 73 (it still cannot match);
reordering or dropping the separator space in `ascii2hex` (line 16, spaces are
removed afterwards); removing the empty `default:` in `getsort`;
`substr($sortn, -1, 2)` in `getoppsort`; `continue` to `break`,
`pg_free_result()` removal, and `while (false)` in the two database helpers
(each query returns at most one row, or none); `$date != null ||` and the
removed `$` after `.*` in `format_date` line 250; `csv_download_link($i = -2)`.

Unobservable: `checklogin('rights', true)` in `acc_header`/`acc_footer`,
because the test stub ignores the redirect flag (only HTTP tests can see the
redirect); the `die()` message mutants, because a failing query ends the
PHPUnit process.

PHP 7.4 setup gap (in the run above, now fixed): with no `php.ini`, PHP 7.4
does not report `E_NOTICE`, and `phpunit.xml` did not set `error_reporting`.
Mutants that replace `isset(...) && preg_match(...)` with `||` in `getfilter`
read a missing `$_GET` key; PHP 8.5 reports that as a warning and PHPUnit
fails the test, PHP 7.4 silently continued. `phpunit.xml`,
`tests/php56/phpunit.xml`, and `infection/phpunit.xml` now set
`<ini name="error_reporting" value="-1"/>`; see the rerun below.

### Suggested tests for the real gaps

1. `acc_header`: exact-output characterization (one expected HTML fixture per
   case) for the default and each named page, `deleted`, `accounts`, and
   `vreport`, with rights `''`, `'1'`, `'bgf'`, and `'root'`; assert the
   `class="selected"` link, `filter_date=<year>` in every year link, the
   default year 2015 when `$year` is omitted, the `root`-only deleted link,
   and the `percent_of_bookings()` text inside the notice. About 130 mutants.
2. `getfilter`: one case with every valid filter at once, to check that each
   branch appends (`.=` versus `=`, 14 mutants); prefix and suffix variants
   that must be rejected for each anchored pattern, such as `x34`/`34x` for
   `filter_bid`, `x2024`, `2024x`, `x2024-02`, `2024-02-29x` for
   `filter_date`, `8x`/`x8` for `filter_type` and `filter_lo`, and a leading
   or trailing `%` for each text filter (about 34 mutants); an upper-case
   `filter_gk` value (`/i` flag); and `'< -12.50'` to cover the `.` removal
   in the decimal amount branch.
3. `percent_of_bookings`: insert rows for 2011 and 2012 and for the current
   year + 1 and + 2 inside a rolled-back transaction; assert that 2012 and
   next year are listed and 2011 and the year after next are not (9 mutants).
4. `format_date`: `x01.02.2024`, `01.02.2024x`, and `x2024-01-01 10:00` stay
   unchanged.
5. `tag`: in CSV mode, `x12.5€` and `12.5€x` keep their decimal point.
6. `ascii2hex`: `"\n"` must give `0A`, not `A0`.
7. `csv_download_link(0)` prints `0 Umsätze gefunden`.

### Infection and the custom runner on rights2orgasql

Infection generates 20 mutants for `rights2orgasql()` on PHP 7.4 and 24 on
PHP 8.5; all are killed. Only about 6 match one of the 31 hand-picked
mutations: `&&` to `||`, removing the `^` or `$` anchor, inverting the
`preg_match` result, the `count()` condition, and removing the quotes around
IDs. Infection adds negations of each `in_array()`, an empty `foreach`,
`preg_match(...) == 2`, `count(...) >= 0`, and reordered or removed
concatenation operands.

Infection does not change string literal contents and does not invent
domain-specific alternatives, so it misses the runner's most important
mutations: fail-open returns (`" AND false "` to `""`), `IN` to `OR`/`NOT IN`,
changed delimiters, removed or added privilege exemptions, substring or
case-insensitive role matching, widened ID patterns (`\d*`, negative,
decimal), `> 1`, keeping only the last organization, and removing the array
initialization. Keep the targeted runner for security-relevant helpers.

### HTTP-tested code

Infection cannot mutate code that is exercised only through the HTTP tests:

- it maps mutants to tests through PHPUnit coverage of the PHPUnit process,
  while HTTP tests reach a separate `php -S` server process;
- its include interceptor replaces the mutated file only in the PHPUnit
  process, not in the server;
- the page scripts are top-level code, which Infection never mutates, and the
  wrapper above cannot convert.

### Recommendations

- Generalize `rights2orgasql.php` into a target-driven runner that stays
  PHP 5.6-compatible: a mutation list per target (file, region, name, from,
  to), applied to a disposable copy of the whole application; a per-target
  test command (a PHPUnit filter, or the HTTP suites against a `php -S`
  server started from the mutated copy); a fresh database per mutant (for
  example `CREATE DATABASE ... TEMPLATE` from the fixture database, because
  the write workflows change data); per-mutant timeouts; and the existing
  baseline, lint, and JSON reporting. Run it on PHP 5.6 as well as 7.4/8.5.
- Feed it generated mutants: use Infection's JSON diffs for helper functions,
  and a small `nikic/php-parser` generator (run on PHP 7.4 or 8.5; the parser
  reads PHP 5.6 syntax) for top-level page code. Select the HTTP checks per
  mutant from server-process coverage once that is available.
- Threshold strategy: keep the job non-blocking until the real gaps above are
  closed. Then (1) gate on no new escaped mutants against a committed
  baseline of escaped mutant IDs (mutator, line, diff), listing the
  equivalent ones with a reason, rather than on a percentage alone, which
  hides regressions behind new kills; (2) add `minCoveredMsi`/`minMsi` floors
  just below the current values (59/58 on PHP 7.4, 67/66 on PHP 8.5) and
  raise them whenever tests improve; (3) for the owner's rule that every
  change is mutation tested, require that changed lines have no escaped
  mutants except listed equivalents. Use the PHP 8.5 lane as the gate, because
  it reports missing-key warnings, and keep PHP 7.4 as a report.
- Longer term, moving the helpers into a class (keeping thin global wrappers)
  would remove the need for the wrapper and allow Infection's `--git-diff-lines`.

## Targeted mutation test: rights2orgasql

`rights2orgasql.php` runs the existing `testRights2orgasql` data provider against
31 deliberately chosen changes to `rights2orgasql()`. It copies the current
source and test files into a disposable workspace, syntax-checks every mutant,
and invokes PHPUnit separately for each one. The baseline must pass first;
existing runtime errors cannot be mistaken for mutation kills.

The mutations cover privilege checks, exact role matching, numeric validation,
single-organization handling, SQL restrictions, and fail-open behavior. They
are a targeted set, not an exhaustive generated mutation score. They remain
useful next to Infection: most of them are domain-specific changes that
Infection's operators do not generate (see the comparison above), and the
runner, unlike Infection, can be made to run on PHP 5.6 (see below).

### Results

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

PHP 5.6 (checked 2026-10-23): the runner does not start there unchanged. It
uses the `redirect` descriptor of `proc_open()` (PHP 7.4+), PHPUnit 9's
`--do-not-cache-result` option, and the PHPUnit 9 bootstrap
(`FunctionsTestCase` with `: void`). It also passes `--no-configuration`, so
the `error_reporting` setting in `phpunit.xml` does not reach it. In a
disposable copy with a shell redirect, without that option, and with
`tests/php56/bootstrap.php`, PHPUnit 5.7.27 on PHP 5.6.40 killed 30 of 31:
`array-initialization-removed` survived, because PHP 5.6's built-in default
`error_reporting` (no `php.ini`) hides the `Undefined variable: rights2`
notice. With `php -d error_reporting=-1` for each PHPUnit process as well, all
31 were killed (13 of the 24 cases fail on that notice). Making the runner
itself PHP 5.6-compatible and passing `-d error_reporting=-1` is a follow-up.

### Run

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
