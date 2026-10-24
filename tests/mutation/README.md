# Mutation testing

Two mutation tools cover this repository:

- [Infection](https://infection.github.io/) generates mutants for the
  PHPUnit-tested helpers in `functions.php` (through a class wrapper) and
  reports a mutation score. It runs on the PHP 7.4 and PHP 8.5 lanes. It is a
  report, not a required CI gate yet. See [Infection](#infection).
- `run.sh`, the target-driven runner, applies hand-written, reviewable
  mutations from `targets/` and runs the test suites that must kill them:
  PHPUnit filters and/or the HTTP suites against a `php -S` server started
  from the mutated copy. It runs on the PHP 7.4 and 8.5 lanes (PHP 5.6/8.0
support was removed with those lanes; PHP 5.3 has no built-in server). It replaces
  the former hand-picked `rights2orgasql.php` runner; its `rights2orgasql`
  target keeps the same 31 mutations, plus one for null rights. See
  [Target-driven runner](#target-driven-runner).

Which tool when: use Infection for broad, generated coverage of helper
functions that PHPUnit tests call directly, and to find unasserted behavior.
Use the target-driven runner for code that only the HTTP tests reach
(top-level page code such as `index.php`, which Infection cannot mutate), for
security-relevant, domain-specific mutations that Infection's operators do not
generate (fail-open returns, `IN` to `NOT IN`, widened ID patterns). Fix pull requests use the runner's
targets (see [Required step for fixes](#required-step-for-fixes)).

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
(`phpunit.xml`), so it runs on the PHP 7.4 and 8.5 lanes. Its 40 cases check:

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

Rerun on 2026-10-23 after the null-argument guards in `tag()`, `acc_header()`,
`acc_footer()` and `rights2orgasql()` (lines 102, 179, 228, 230, 259, 262) and
their six tests (before: the rerun above, with identical numbers):

| Lane | Mutants | Killed | Timed out | Escaped | Not covered | MSI | Covered MSI |
|---|---:|---:|---:|---:|---:|---:|---:|
| PHP 7.4, Infection 0.26.6 | 655 | 401 | 1 | 244 | 9 | 61.37% | 62.23% |
| PHP 8.5, Infection 0.35.8 | 682 | 454 | 1 | 220 | 7 | 66.72% | 67.41% |

All 12 (PHP 7.4) and 6 (PHP 8.5) new mutants are killed; no mutant escapes on
a changed line, before or after. Infection has no operator that removes a
null guard; the [`null-arguments` target](#null-arguments-phpunit-functionsphp)
covers that.

Rerun on 2026-10-23 after the mcrypt replacement (`rijndael256_*`,
`receipt_*` and `membership_*` helpers, lines 499-560) with
`tests/unit/CryptoTest.php` added to `infection/phpunit.xml` (before: the
rerun above):

| Lane | Mutants | Killed | Timed out | Escaped | Not covered | MSI | Covered MSI |
|---|---:|---:|---:|---:|---:|---:|---:|
| PHP 7.4, Infection 0.26.6 | 692 | 437 | 1 | 245 | 9 | 63% | 64% |
| PHP 8.5, Infection 0.35.8 | 727 | 498 | 1 | 221 | 7 | 68% | 69% |

After the review fixes (shared `*_cipher_params()` helpers): 686 mutants,
431 killed, 245 escaped, 9 not covered on PHP 7.4; 723 mutants, 494 killed,
220 escaped, 7 not covered, 2 timeouts on PHP 8.5. The only escaped mutant
on the changed lines is still the equivalent `setBlockLength(257)`.

The one new escaped mutant (both lanes) is equivalent: `setBlockLength(257)`,
which phpseclib rounds down to the same 256-bit block (`$length >> 5`). The
[`receipt-crypto` target](#receipt-crypto-phpunit-and-http) covers the call
sites, which Infection cannot mutate.

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
initialization. Keep the runner's `rights2orgasql` target for this
security-relevant helper.

### HTTP-tested code

Infection cannot mutate code that is exercised only through the HTTP tests:

- it maps mutants to tests through PHPUnit coverage of the PHPUnit process,
  while HTTP tests reach a separate `php -S` server process;
- its include interceptor replaces the mutated file only in the PHPUnit
  process, not in the server;
- the page scripts are top-level code, which Infection never mutates, and the
  wrapper above cannot convert.

The [target-driven runner](#target-driven-runner) covers this code instead.

### Recommendations

- Done: `rights2orgasql.php` was generalized into the
  [target-driven runner](#target-driven-runner) (per-target mutation lists on
  a disposable application copy, PHPUnit or HTTP test commands, a fresh
  database per mutant, timeouts, baseline, lint, and JSON reports), which runs
  on PHP 5.6 as well as 7.4-8.5.
- Feed the runner generated mutants: use Infection's JSON diffs for helper functions,
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

## Target-driven runner

`run.sh` mutation-tests code against the existing test suites on the PHP 7.4
and 8.5 lanes (on either PostgreSQL server, `PGACC_TEST_PG`). It works for code that only the HTTP tests reach, such as
the top-level page code in `index.php`, `edit.php` or `new.php`, which
Infection cannot mutate. Each target is a reviewable file of hand-written
mutations; this is a targeted check of assertion strength, not a generated
mutation score.

```sh
PGACC_TEST_ID=mine sh tests/mutation/run.sh 7.4 login-logout
PGACC_TEST_ID=mine PGACC_TEST_PG=8.4 sh tests/mutation/run.sh 7.4 rights2orgasql
PGACC_TEST_ID=mine sh tests/mutation/run.sh 7.4 login-logout --changed-since=origin/master
PGACC_TEST_ID=mine sh tests/mutation/run.sh 7.4 login-logout --only=login-target-ignored,logout-redirect-removed
PGACC_TEST_ID=mine sh tests/mutation/run.sh 7.4 login-logout --check   # definitions only
PGACC_TEST_ID=mine sh tests/mutation/run.sh 7.4 --self-test            # runner unit tests
```

The lane is `7.4` or `8.5`; the target is the name of a file in
`targets/`. Results go to `build/mutation/<target>-php<lane>/` (with a
`-pgNN` suffix for a non-default `PGACC_TEST_PG`; override with
`PGACC_MUTATION_HOST_DIR`): `results.json`, `summary.txt`, and one test log and
one server log per mutant. `PGACC_TEST_ID` isolates concurrent runs as for
`tests/run.sh`; remove the tagged image with `docker rmi` afterwards.

Exit status: 0 when every valid mutant is killed, timed out, or an accepted
equivalent; 1 for any unaccepted survivor, invalid mutant, or stale
equivalent; 2 for a definition error, a setup error, or a failing baseline.

### Changed lines only (`--changed-since`)

`--changed-since=<base>` runs only the mutations whose **search region**
overlaps a line changed since `git merge-base <base> HEAD` in the working tree
(uncommitted and untracked files included). The search region is the
mutation's `region` (from its start marker to its end marker) when it has one,
otherwise the lines of the matched `search` text. A pure deletion counts as a
change of the lines on both sides of it. A changed target file selects all of
its mutations; changes to test files alone select none (the full runs cover
them). With nothing selected the target passes without running its baseline.
`run.sh` takes the diff on the host (`build/mutation/<target>-.../changed.diff`)
and passes it to `runner.php --changed-diff=`; the selection is
`pgacc_mutation_parse_diff()`, `pgacc_mutation_lines_changed()` and
`pgacc_mutation_select_changed()` in `lib.php`, unit-tested in
`tests/LibTest.php`.

`sh tests/mutation/ci.sh <7.4|8.5> [--changed-since=<base>]` runs the
self-test and the lane's target list: all 11 targets on PHP 7.4 (on either
server); `rights2orgasql` and `null-arguments` on PHP 8.5, where the other
baselines fail on PHP 8.5 diagnostics (`donations`, `listing-columns` and
`report-prevcond` checked on 2026-10-24). CI uses it with `--changed-since`
on every push and without it weekly and on pull requests.

### How a run works

`run.sh` builds the lane's application image and starts the lane's isolated
PostgreSQL service in its own Compose project (`pgacc-mutation[-ID]-phpXX`,
from `compose.test.yaml` plus the lane file, without `compose.coverage.yaml`).
It then runs `runner.php` in a one-off container of the application service:
same image, environment, internal network, read-only root, and `/tmp` tmpfs.

`runner.php`:

1. Validates the target and applies every mutation to the source in memory.
   Any definition error aborts before anything runs.
2. Copies the image's `/app` to `/tmp` once (vendor/ linked, not copied), and
   creates the database `accounting_mutation_template` from the same fixture
   files, in the same order, as `tests/database-smoke.sh`.
3. Runs the unmutated baseline exactly like a mutant. If it does not pass, the
   run stops with exit 2 and prints the baseline log. No mutant is tested.
4. For each mutant:
   - copies the pristine tree to a disposable directory and writes the mutated
     file there (the image's `/app` and the repository are never modified);
   - runs `php -l` on it: a syntax error makes the mutant **invalid**, not
     killed;
   - recreates `accounting` from the template (`DROP DATABASE ... WITH (FORCE)`
     and `CREATE DATABASE ... TEMPLATE`): `index.php` connects to the fixed
     name `accounting`, so the name is reused rather than varied. Servers
     older than PostgreSQL 13 (`PGACC_TEST_PG=9.4`) have no `WITH (FORCE)`;
     there the runner first terminates the database's other backends with
     `pg_terminate_backend()` and then drops it;
   - for HTTP tests, starts a fresh `php -S 127.0.0.1:<port>` on the copy with
     the application service's settings (`output_buffering=4096` and the
     diagnostics recorder as `auto_prepend_file`, checked against
     `compose.test.yaml` by the self-tests) and a fresh diagnostics directory;
   - runs the target's test commands from the copy in order, stopping at the
     first failure. The second, `output_buffering=0` server that
     `tests/http-login.php` starts therefore also serves the mutant;
   - classifies the result, stops the server, and deletes the copy.

Every command runs in its own process group with `setsid`; on timeout (or when
a command exits and leaves children behind) the whole group is killed, so a
hung mutant cannot leave a server holding a port for the next one.

Outcomes:

| Status | Meaning |
|---|---|
| `killed` | a test command exited nonzero. The HTTP suites fail on any PHP diagnostic outside `tests/known-diagnostics.php`, so a mutant that only raises a new diagnostic is killed too. |
| `timeout` | the test commands exceeded the target's per-mutant `timeout`; counted as detected. |
| `survived` | every test command passed. Fails the run. |
| `equivalent` | survived, and the mutation is listed as an accepted equivalent for this PHP version. |
| `stale-equivalent` | listed as equivalent, but killed or timed out. Fails the run, so the list stays accurate. |
| `invalid` | `php -l` failed on the mutant. Fails the run (fix the definition). |

### Target format

A target is a PHP file in `targets/` that returns an array:

```php
return array(
    'description' => 'index.php login() and logout branch',
    'file' => 'index.php',                    // default for every mutation
    'region' => array('start' => '...', 'end' => '...'),   // optional default
    'tests' => array(                         // run in order, stop at first failure
        array('type' => 'http', 'script' => 'tests/http-login.php'),
        array('type' => 'phpunit', 'filter' => 'testRights2orgasql'),  // 'file' defaults to tests/unit/FunctionsTest.php
    ),
    'timeout' => 60,                          // seconds per mutant, all commands together (default 120)
    'mutations' => array(
        array(
            'id' => 'login-target-ignored',   // letters, digits, '-', '_'; unique
            'region' => array('start' => 'function login($user, $pass)', 'end' => 'require("constants.php");'),
            'search' => 'header("Location: " . hex2ascii($_POST[\'target\']));',
            'replace' => 'header("Location: index.php");',
            'rationale' => 'A login with a target returns to that page.',
            // Optional, only after review:
            // 'equivalent' => array('reason' => '...', 'php' => array('5.6')),
        ),
    ),
);
```

Pick the HTTP suites that reach the code: `tests/http-smoke.php`,
`tests/http-login.php`, `tests/write-workflows.php`, or any other script that
uses `tests/http-test-helpers.php`. Selecting only the relevant scripts keeps a
target fast; a mutant takes about one to three seconds with one suite.

Matching rules, enforced for every mutation before anything runs:

- `search` must occur **exactly once** in the file, or inside `region` when
  one is given. Zero or several matches are an error: add surrounding context
  or a region instead of relying on the first match.
- Region `start` and `end` markers must each occur exactly once in the file,
  `end` after `start`. The mutation must lie between them. A target-level
  region applies to mutations of the target-level `file` only.
- Equivalent spellings (for example `array()` and `[]`) are tolerated only when
  listed: `search` may be a list, and exactly one listed spelling may match.
  `replace` is then a string, or a list with one replacement per spelling.
- Write strings with `"\n"`. In CRLF files (most application files) they match
  CRLF line endings, and the mutant keeps CRLF, so only the mutated bytes
  differ. Files with mixed line endings are rejected.
- `replace` must keep the number of lines. Shifted lines would move
  diagnostics that `tests/known-diagnostics.php` lists by `file:line`, and the
  mutant would be "killed" by the baseline check rather than by the tests.
  Remove a statement by replacing it with `true;` or an empty line.
- `equivalent` needs a `reason` and is optionally limited to PHP minor
  versions. Only list a mutant after confirming that no test could distinguish
  it; a survivor that a test should catch is a missing test.

The self-tests also load every committed target and apply all of its
mutations to the current sources, so an application change that breaks a
definition fails there first.

### Runner tests

`lib.php` holds target validation, mutation application, exact-match
enforcement, outcome classification, the summaries, and the process runner;
`runner.php` is the orchestration (copies, database, servers). The PHPUnit
tests in `tests/` run on PHPUnit 5.7 (PHP 5.6) and 9.6 (PHP 7.4-8.5):

```sh
sh tests/mutation/run.sh 7.4 --self-test
```

On PHP 7.4 they also report line and branch coverage of `lib.php` and fail on
anything uncovered: all executable lines and branches are covered. `runner.php` itself is exercised end
to end by the target runs below, not by unit tests.

### Results

#### `rights2orgasql` (PHPUnit, `functions.php`)

Ported from the former hand-picked runner `rights2orgasql.php` with the same
31 mutations. Two search strings needed extra context because they occurred
twice in the function (the old runner silently mutated the first occurrence),
and removing the initialization now leaves an empty line instead of deleting
it; the mutants are otherwise identical.

| Lane | Killed | Equivalent | Survived | Invalid | Seconds per mutant |
|---|---:|---:|---:|---:|---:|
| PHP 5.6.40 | 30 | 1 | 0 | 0 | 0.5-2.7 |
| PHP 7.4.33 | 31 | 0 | 0 | 0 | 0.5-1.0 |
| PHP 8.0.30 | 31 | 0 | 0 | 0 | 0.3-0.6 |
| PHP 8.5.12 | 31 | 0 | 0 | 0 | 0.2-0.3 |

On PHP 5.6 `array-initialization-removed` is an accepted equivalent: `count()`
of the undefined variable returns 0 with only an `E_NOTICE`, hidden by the
default `error_reporting`, so the function still returns `" AND false "`. PHP
7.2+ adds a warning that the tests catch. The runner calls PHPUnit with
`--no-configuration`, so the `error_reporting=-1` setting of
`tests/php56/phpunit.xml` does not apply to its runs.

The former `rights2orgasql.php` runner did not start on PHP 5.6 (it used the
`redirect` descriptor of `proc_open()`, PHPUnit 9's `--do-not-cache-result`,
and the PHPUnit 9 bootstrap). In a manually adapted copy, PHPUnit 5.7.27 on
PHP 5.6.40 killed 30 of 31, with the same survivor; with
`php -d error_reporting=-1` all 31 were killed (13 of the 24 cases fail on the
notice). The new runner starts on PHP 5.6 unchanged and reports 30 killed and
1 accepted equivalent.

Earlier history: the original four rights cases killed 13 of the first 30
mutants; the expanded 24 cases kill all 30, and the initialization-removal
mutant was added with the initialization fix.

After the null-argument guards (2026-10-23) the target has 32 mutations: the
two delimiter mutations also accept the guarded `explode()` spelling, and
`null-rights-fail-open` returns `""` for null rights. All 32 are killed on PHP
5.6, 7.4, 8.0 and 8.5. `array-initialization-removed` is no longer an accepted
equivalent on PHP 5.6: the null-rights test captures every diagnostic with its
own error handler, which also receives the notice that the default
`error_reporting` hides.

#### `null-arguments` (PHPUnit, `functions.php`)

The null guards that keep PHP 8.1+ from reporting "Passing null to parameter"
in `tag()` (CSV mode), `acc_header()`, `acc_footer()` and both `explode()`
calls of `rights2orgasql()`. For each of the six guards: removed, inverted
(`!== null`), and null replaced by a non-empty value (which shows admin
links, hides the login link, grants `bgf` or an organization, or fills a
CSV cell).

| Lane | Killed | Equivalent | Survived | Invalid |
|---|---:|---:|---:|---:|
| PHP 5.6.40 | 12 | 6 | 0 | 0 |
| PHP 7.4.33 | 12 | 6 | 0 | 0 |
| PHP 8.0.30 | 12 | 6 | 0 | 0 |
| PHP 8.5.12 | 18 | 0 | 0 | 0 |

The six guard removals are accepted equivalents on PHP 5.6, 7.4 and 8.0:
before PHP 8.1 the internal functions accept null silently with the
empty-string result, so only the deprecation differs. PHP 8.5 kills them.

#### `login-logout` (HTTP, `index.php`, `login.php`, `new.php`)

`login()` (`index.php:13-39`), the logout and `login_redirect()` blocks that
`index.php` answers before `acc_header()` (lines 196-208; 175-187 before the early redirects of the [`redirects`](#redirects-http-indexphp-editphp-searchphp) target moved them), `login_redirect()`
and `page_login()` in `login.php`, and the `page_new_buchung()` signature
(`new.php:450`, compiled on every request), tested with `tests/http-login.php`
only. Since the login/logout header fix the target has 38 mutations: the 15
earlier ones (the logout ones moved to the new logout block) plus the
meaningful mutants of the former manual list `login-headers.json` (see
[History](#history-of-the-login-logout-target)).

| Mutant | Line | 5.6 | 7.4 | 8.0 | Killed by |
|---|---:|---|---|---|---|
| `login-password-check-inverted` | 22 | killed | killed | killed | valid login not 302; wrong password accepted |
| `login-password-check-removed` | 22 | killed | killed | killed | wrong password accepted |
| `login-session-not-stored` | 26 | killed | killed | killed | cookies do not authenticate; database state |
| `login-cookie-renamed` | 27 | killed | killed | killed | missing `pp_pgacc_login` |
| `login-cookie-value` | 27 | killed | killed | killed | cookie differs from `users.cookie` |
| `login-cookie-expires-immediately` | 27 | killed | killed | killed | cookie attributes (expiry) |
| `login-cookie-expiry` | 27 | killed | killed | killed | cookie attributes (`Max-Age=86400`) |
| `login-id-cookie-removed` | 28 | killed | killed | killed | missing `pp_pgacc_id` |
| `login-id-cookie-renamed` | 28 | killed | killed | killed | missing `pp_pgacc_id` |
| `login-id-cookie-value` | 28 | killed | killed | killed | wrong user id |
| `login-id-cookie-path` | 28 | killed | killed | killed | cookie attributes (`path`) |
| `login-target-ignored` | 31 | killed | killed | killed | wrong `Location` for target |
| `login-target-not-decoded` | 31 | killed | killed | killed | hex target not decoded |
| `login-default-redirect-removed` | 32 | killed | killed | killed | 200 instead of 302 |
| `login-default-redirect-target-changed` | 32 | killed | killed | killed | wrong `Location` |
| `login-username-not-escaped` | 15 | killed | killed | killed | quote in user name logs in (SQL injection) |
| `login-password-not-escaped` | 16 | killed | killed | killed | quote in password: SQL error, no form |
| `login-raises-hidden-notice` | 25 | killed | killed | killed | new hidden `E_NOTICE` (diagnostics check) |
| `logout-login-cookie-kept` | 198 | killed | killed | killed | `pp_pgacc_login` not cleared |
| `logout-login-cookie-renamed` | 198 | killed | killed | killed | `pp_pgacc_login` not cleared |
| `logout-id-cookie-kept` | 199 | killed | killed | killed | `pp_pgacc_id` not cleared |
| `logout-id-cookie-not-cleared` | 199 | killed | killed | killed | `pp_pgacc_id` set to `0` |
| `logout-redirect-removed` | 200 | killed | killed | killed | 200 instead of 302 |
| `logout-redirect-target-changed` | 200 | killed | killed | killed | wrong `Location` |
| `logout-continues` | 201 | killed | killed | killed | redirect body not empty |
| `logout-cookie-expiry-in-future` | 198 | equivalent | equivalent | equivalent | `setcookie()` ignores the expiry for an empty value |
| `logout-after-output` | 196 | killed | killed | killed | body not empty; unbuffered logout 200 without cookies |
| `login-continues` | 206 | killed | killed | killed | redirect body not empty |
| `login-after-output` | 204 | killed | killed | killed | body not empty; unbuffered login 200 without cookies |
| `logged-in-guard-removed` | `login.php:7` | killed | killed | killed | logged-in target redirect |
| `logged-in-target-check-removed` | `login.php:9` | killed | killed | killed | logged-in page without target |
| `logged-in-target-not-decoded` | `login.php:11` | killed | killed | killed | hex target not decoded |
| `logged-in-target-continues` | `login.php:12` | killed | killed | killed | redirect body not empty |
| `login-submit-check-negated` | `login.php:19` | killed | killed | killed | valid login not 302, no cookies |
| `login-success-continues` | `login.php:21` | killed | killed | killed | redirect body not empty |
| `rejected-login-ends-request` | `login.php:21` | killed | killed | killed | rejected login renders no form |
| `page-login-guard-removed` | `login.php:28` | killed | killed | killed | logged-in user sees the form |
| `page-new-buchung-default-restored` | `new.php:450` | equivalent | equivalent | killed | PHP 8.0 deprecation (diagnostics check) |

PHP 5.6 and 7.4: 36 killed, 2 accepted equivalents; PHP 8.0: 37 killed, 1
accepted equivalent. Every run exits 0. `page-new-buchung-default-restored` is
an equivalent on PHP 5.6 and 7.4 only (`'php' => array('5.6', '7.4')`): before
PHP 8.0 a default before required parameters is accepted silently and never
used, because the only caller passes all four arguments. On PHP 8.0 it is
killed by the deprecation, and listing it as equivalent there would make it a
stale equivalent.

On PHP 8.5 the runner refuses the target, as intended: the baseline fails (exit
2) because the implicit-connection deprecations in `login()`/`checklogin()` are
displayed before the login redirect, so its body is not empty and the
unbuffered login returns 200 without cookies (see
`tests/COMPATIBILITY_PLAN.md`).

A mutant takes 0.6-0.8 s on PHP 7.4, 0.5-1.7 s on PHP 5.6, and 0.6-1.2 s on
PHP 8.0 (several seconds on a busy host). A whole run with an already built
image, including the database start, takes 30-40 s on each of these lanes, so
CI runs it on PHP 5.6, 7.4 and 8.0.

#### History of the `login-logout` target

Before this runner existed, the login/logout header fix was mutation-tested
with a manual list (`login-headers.json`, now replaced by this target): 26
mutants, each applied by hand (mutated files bind-mounted over `/app`, a fresh
synthetic database per run) and run against `tests/http-login.php` on the PHP
7.4 image, the signature mutant on PHP 8.0. "Before" means the original code
at 20b5911 with the test-first `http-login.php` (the unbuffered login/logout
checks still recorded as known failures); mutants that exist only in the
fixed structure had no "before" form.

| Code | Driver | Tests | Mutants | Killed | Survived |
|---|---|---|---:|---:|---:|
| Original (before) | manual | `http-login.php` test-first version, with the unbuffered known failures | 19 | 18 | 1 |
| Fixed (after) | manual | final `http-login.php` | 26 | 25 | 1 |
| Fixed (after) | this runner, PHP 5.6 / 7.4 | final `http-login.php` | 38 | 36 | 0 (2 equivalent) |
| Fixed (after) | this runner, PHP 8.0 | final `http-login.php` | 38 | 37 | 0 (1 equivalent) |

The manual survivor in both runs was `logout-expiry-in-future`, here
`logout-cookie-expiry-in-future`, the accepted equivalent. With the
`http-login.php` at 20b5911 and the first version of this target (15
mutants), `login-cookie-expires-immediately` and `login-username-not-escaped`
survived on PHP 5.6 and 7.4; the cookie attribute checks and the rejected
logins with quotes in the user name and password kill them now. All 26 manual
mutants are in the target, some under the earlier target's ID
(`login-database-update-removed` is `login-session-not-stored`,
`logout-location` is `logout-redirect-target-changed`,
`logout-return-removed` is `logout-continues`, `login-return-removed` is
`login-continues`, `login-default-location` is
`login-default-redirect-target-changed`, `login-cookie-path` is
`login-id-cookie-path`, `login-id-cookie-name` is `login-id-cookie-renamed`,
`logout-login-cookie-name` is `logout-login-cookie-renamed`); the removal and
move mutants keep the line count (`true;` placeholders, and one search that
spans the moved block and `acc_header()`).

#### `listing-columns` (HTTP, `closed.php`)

The listing column preferences: `listing_column_hidden()`, its calls in
`gentab()`, the filter row and `page_listing_line()`, and `emptytag()`.
Tested with `tests/http-listing-prefs.php` only. 50 mutants: the helper's key
and value checks (5), `emptytag()` (4, including the original one-argument
`tag()` call), the CSV and HTML header checks (3, including the original
direct `$user_prefs_hide` read in CSV), a non-incremented column counter in
the filter row and in a voucher row (2), and one inverted check per column for
the filter row (18) and the voucher rows (18).

| Lane | Killed | Equivalent | Survived | Invalid | Seconds per mutant |
|---|---:|---:|---:|---:|---:|
| PHP 5.6.40 | 50 | 0 | 0 | 0 | 0.6-3.0 |
| PHP 7.4.33 | 50 | 0 | 0 | 0 | 1.5-9.7 |

The inverted check for column 12 (`commentgf`, empty in every fixture row)
gives the same `<td></td>` in HTML whether shown or hidden; it is killed by the
CSV comparison, where a hidden column has no field.

Before the fix: run on a copy of the original application (`git archive
14e81a6`) with only the new fixtures, test and drivers added, the target's
baseline fails, so no mutant runs (exit 2): on PHP 7.4 the first hidden column
ends the page with `ArgumentCountError` (`closed.php:158`); on PHP 5.6 every
hidden cell displays "Missing argument 2 for tag()". The new test therefore
detects the original defect on both lanes.

#### `report-prevcond` (HTTP, `report.php`)

The `$prevcond = null;` initialization in `page_report()`, tested with
`tests/http-smoke.php`.

| Mutant | PHP 5.6 | PHP 7.4 | Notes |
|---|---|---|---|
| `prevcond-initialization-removed` | killed | killed | the undefined-variable notice is no longer a known diagnostic |
| `prevcond-passes-prev-cond` | equivalent | equivalent | `unit_report()` never reads its first parameter |

Before the fix (same original copy, with the original
`tests/known-diagnostics.php`): a line-preserving form of the fix
(`$precond = "";` replaced by `$prevcond = null;`) and passing `$prev_cond`
at the first call both survived on PHP 5.6 and 7.4. With `KD-REPORT-PREVCOND`
in the baseline the tests could not see whether the variable was defined; after
removing the entry, reintroducing the undefined variable is killed.

#### `redirects` (HTTP, `index.php`, `edit.php`, `search.php`)

The redirects sent before any output: the login check of the protected pages
and the member search redirect that `index.php` answers before the CSV block
and `acc_header()` (lines 129-143), the `ob_start()` for edit and merge
requests (line 191), `relocate()` (`edit.php:2-10`) and the targets of its
callers, tested with `tests/http-redirects.php` and `tests/write-workflows.php`.
29 mutations: 18 listed in the target and one `unprotected-<page>` mutant per
protected page except `import` (an unprotected import page would call the
external membership system; the test requests it only after every other
page has passed).

| Mutant | Line | 5.6 | 7.4 | Killed by |
|---|---:|---|---|---|
| `login-check-removed` | 132 | killed | killed | `http-redirects.php` |
| `login-check-inverted` | 132 | killed | killed | `http-redirects.php` |
| `login-redirect-continues` | 134 | killed | killed | `http-redirects.php` |
| `login-redirect-after-csv-bom` | 132 | killed | killed | `http-redirects.php` |
| `search-block-removed` | 137 | killed | killed | `http-redirects.php` |
| `search-continues` | 141 | killed | killed | `http-redirects.php` |
| `search-member-ignored` | `search.php:5` | killed | killed | `http-redirects.php` |
| `edit-merge-buffer-removed` | 191 | killed | killed | `write-workflows.php` |
| `edit-buffer-missing` | 191 | killed | killed | `write-workflows.php` |
| `merge-buffer-missing` | 191 | killed | killed | `write-workflows.php` |
| `relocate-page-kept` | `edit.php:6` | killed | killed | `write-workflows.php` |
| `relocate-continues` | `edit.php:9` | killed | killed | `write-workflows.php` |
| `relocate-redirect-removed` | `edit.php:8` | killed | killed | `write-workflows.php` |
| `merge-target` | `edit.php:77` | killed | killed | `write-workflows.php` |
| `approval-target` | `edit.php:194` | killed | killed | `write-workflows.php` |
| `revision-target` | `edit.php:276` | killed | killed | `write-workflows.php` |
| `search-requires-login` | 139 | equivalent | equivalent | `page_search()` replaces the login `Location` |
| `unprotected-<page>` (11: all, mb, open, merge, edit, new, closed, deleted, recover, accounts, import2) | 132 | killed | killed | `http-redirects.php` |

PHP 5.6 and 7.4: 28 killed (27 since the finalization removal dropped `finalization-target`), 1 accepted equivalent; both runs exit 0 (a mutant
takes 2-20 s, a run about 4 minutes on PHP 7.4).

Before the fix, an uncommitted counterpart of the target for the code at the
test-first commit (`4544994`, with the known failures still in place) had 20
mutations: the four relocate targets, the `relocate()` redirect and
`pg_close()`, the `checklogin()` login redirect, the search target, the search
equivalent, and `checklogin('rights',false)` in each of the 11 dispatch
branches. On PHP 7.4: 5 killed (the relocate targets and redirect, by the 302
and `Location` assertions of `write-workflows.php`), 1 equivalent, 14 survived:
the login redirect, the search target, every dispatch branch, and
`pg_close()` in `relocate()`; the checks that would catch them were known
failures. On PHP 5.6 that baseline did not pass in the runner: the old
`relocate()` displays its closed-connection warnings (then
`KD-EDIT-RELOCATE-CLOSED-LINK`), and the body cross-check of
`http-diagnostics.php` only strips `/app/` from displayed paths, so under the
runner's `/tmp/pgacc-mutation/...` copy they did not match the logged
diagnostics. After the fix no redirect displays anything, so the baseline
passes on both lanes. The `login-logout` target gives the same result before
(at `5fe1c62`) and after the fix: 36 killed, 2 accepted equivalents on PHP 5.6
and 7.4.

#### `rights-checks` (HTTP, `new.php`, `edit.php`, `accounts.php`)

The organization scope and rights checks of voucher writes, tested with
`tests/write-workflows.php` (users of organizations 1 and 2, `bgf`, admin,
empty rights and `Rbgf`; vouchers with rows in two organizations): the rights
that creation (`page_new_save()`) and revision pass to `get_voucher()`, its
default organization, the check of posted organizations, the rights SQL and
the visibility check of approval, the exact `bgf`/`root` checks of
approval reset and merge, the removed finalization, and the rights of new users.

| Mutant | Line | 5.6 | 7.4 | Killed by |
|---|---:|---|---|---|
| `new-save-rights-dropped` | `new.php:182` | killed | killed | voucher of an organization user saved in organization 10 |
| `new-save-rights-null` | `new.php:210` | killed | killed | same |
| `default-organization-fixed` | `new.php:43` | killed | killed | creation and revision by an organization user |
| `default-organization-for-any-rights` | `new.php:41` | killed | killed | `bgf` voucher saved in organization 0 |
| `posted-organization-ignored` | `new.php:45` | killed | killed | posted organization not saved |
| `new-posted-organization-unchecked` | `new.php:194` | killed | killed | voucher saved in a foreign organization |
| `revision-posted-organization-unchecked` | `edit.php:256` | killed | killed | revision saved in a foreign organization |
| `posted-organization-bgf-exemption-removed` | `new.php:69` | killed | killed | `bgf` voucher in organization 1 refused |
| `posted-organization-first-part-only` | `new.php:72` | killed | killed | foreign second part saved |
| `posted-organization-loose-comparison` | `new.php:75` | killed | killed | organization `01` accepted |
| `posted-organization-default-checked` | `new.php:75` | killed | killed | voucher without organization refused |
| `new-account-creator-rights` | `accounts.php:21` | killed | killed | new user gets the creator's rights |
| `new-account-rights-unescaped` | `accounts.php:18` | killed | killed | quoted rights fail the insert |
| `ack-visibility-check-removed` | `edit.php:146` | killed | killed | foreign user told "bereits finalisiert" |
| `ack-visibility-check-unscoped` | `edit.php:144` | killed | killed | same |
| `ack-finalized-check-removed` | `edit.php:155` | killed | killed | finalized voucher approved |
| `ack-finalized-check-scoped` | `edit.php:155` | killed | killed | own rows of a partly finalized voucher approved |
| `ack-receipt-check-unscoped` | `edit.php:165` | killed | killed | approved row without receipt approved again |
| `ack1-update-unscoped` | `edit.php:178` | killed | killed | first approval of the other organization's row |
| `ack2-update-unscoped` | `edit.php:189` | killed | killed | second approval of the other organization's row |
| `ack2-same-user` | `edit.php:189` | killed | killed | duplicate approval by the first approver |
| `merge-root-check-removed` | `edit.php:64` | killed | killed | merge by an organization user |
| `merge-root-substring` | `edit.php:64` | killed | killed | merge by `bgf` without `root` |
| `finalization-write-restored` | `index.php:253` | - | killed | a POST with `beleg` finalizes |
| `drop-acks-bgf-check-removed` | `edit.php:100` | killed | killed | reset by a user without rights |
| `drop-acks-bgf-check-fail-closed-for-bgf` | `edit.php:100` | killed | killed | bgf reset refused |
| `drop-acks-bgf-substring` | `edit.php:100` | killed | killed | reset by `Rbgf` |
| `drop-acks-reset-removed` | `edit.php:111` | killed | killed | bgf reset leaves the approvals |

PHP 5.6 and 7.4: 30 killed, 1 accepted equivalent; both runs exit 0. Since the
finalization removal the four `finalize-*` mutants are gone and
`finalization-write-restored` is new (killed on PHP 7.4; full run in CI).
The review fixes add, all killed on PHP 7.4 (full runs in CI):
`ack-visibility-check-counts-deleted` (an accepted equivalent since the receipt
check skips deleted rows as well); `organization-bgf-exemption-removed`,
`organization-root-exemption-removed`, `organization-loose-comparison` and
`default-organization-unchecked` (replacing the earlier bgf-exemption,
loose-comparison and default mutants of `posted_organizations_allowed()`);
`revision-visibility-check-removed`, `-unscoped`, `-counts-deleted`,
`revision-finalized-check-scoped`, `revision-approved-check-scoped`; and one
`strpos()` mutant per converted role check (`accounts-*-root-substring` (8),
`recover-root-substring`, `member-fees-bgf-substring`,
`merge-link-root-substring`). `sql-injection` adds the `view-*-raw` (4),
`old-ack*-filter-wrong-value` (2, replacing the three escape mutants) and
`member-name-unescaped` mutants; the new `import-checks` target has 4.

Before the security fixes (`d564754`) the target had the first five and the
`ack-*`, `finalize-unscoped` and `drop-acks-reset-removed` mutants: 12 killed
on PHP 5.6 and 7.4.

Before the fix of `page_new_save()` (the test-first commit, `9d0e753`), the
11 mutations that apply to that code (all but `new-save-rights-dropped`) gave
10 killed and 1 survivor on PHP 5.6 and 7.4: `new-save-rights-null` survived,
because creation passed no rights to `get_voucher()` at all (the undefined
variable is `null`).

#### `sql-injection` (HTTP, `edit.php`, `new.php`, `functions.php`)

Request values in SQL, tested with `tests/write-workflows.php`: the voucher ID
check of approval, approval reset, revision and receipt upload
(injected conditions, quotes, a final newline, a sign, an array, no ID), the
escaped old approval names of a revision, the grouped date pattern, and the
escaped old-approval listing filter.

| Mutants | Lines | 5.6 | 7.4 |
|---|---|---|---|
| `<action>-id-unchecked`, `<action>-id-trailing-newline` for drop-acks, ack, save; `ack-id-array` | `edit.php` (one per handler) | 9 killed | 9 killed |
| `upload-id-unchecked`, `upload-id-trailing-newline` | `new.php:226` at the fix | 2 killed | 2 killed |
| `old-ack1-unescaped`, `old-ack2-unescaped`, `date-pattern-ungrouped` | `new.php:158/159/37` at the fix | 3 killed | 3 killed |
| `old-ack-filter-unescaped`, `old-ack2-filter-unescaped`, `old-ack-filter-unset` | `functions.php:88` at the fix | 3 killed | 3 killed |

PHP 5.6 and 7.4: 17 killed (15 since the finalization removal); both runs exit 0. The target is new with the fix;
before it, the tests failed on the unfixed code (the first injected approval
got the approval instead of "Buchung nicht gefunden").

#### `read-only-rights` (HTTP and PHPUnit, `edit.php`, `new.php`, `accounts.php`, `index.php`, `functions.php`)

Read-only `Rbgf`, tested with `tests/write-workflows.php` (users
`synthetic-read-only` and `synthetic-read-only-two`) and the `write_rights()`
and menu PHPUnit tests: the `write_rights()` checks of voucher creation,
receipt upload, revision and approval (`<path>-read-only-allowed`), the write
rights of `Rbgf,2` for approval and creation (`<path>-read-only-rights-kept`),
the user page, the receipt import, `write_rights()` itself, and the hidden
menu links and form buttons. The bank import check is not mutated: without it
the page would call the external membership system.

PHP 5.6 and 7.4: 13 killed; both runs exit 0. New with the change; before it,
the tests failed on the unfixed code (the voucher form showed the save button
to `Rbgf`; voucher creation by `Rbgf` succeeded).

#### `donations` (HTTP, `donations.php`)

The array initializations in `page_donations()`, the disabled donor
publication and the captcha, tested with `tests/http-donations.php`.

| Mutant | PHP 5.6 | PHP 7.4 | Killed by |
|---|---|---|---|
| `donations-initialization-removed` | killed | killed | undefined `$donations` and `foreach` warnings; displayed inside the table |
| `sort-sum-initialization-removed` | killed | killed | `array_multisort()` argument #1 warning |
| `sort-name-initialization-removed` | killed | killed | `array_multisort()` argument #3 warning |
| `publication-re-enabled` | killed | killed | a donor row appears in the 2024 list |
| `captcha-check-bypassed` | killed | killed | a wrong answer shows the list |
| `captcha-offset-changed` | killed | killed | the correct answer shows the captcha again |
| `captcha-hidden-value-changed` | killed | killed | hidden value is not `A + B - 37` |

7 of 7 killed on both lanes. Before the fix (original `donations.php` with
the new test, and the four mutations that apply to it), the target's baseline
fails on PHP 5.6 and 7.4 (exit 2, no mutant runs): the 2024 list reads the
undefined `$donations`, and the warnings land between the header row and
`</table>`. The test therefore detects the original defect on both lanes.

#### `receipt-crypto` (PHPUnit and HTTP)

The mcrypt replacement in `functions.php` and its five call sites (`file.php`,
`new.php`, `import2.php`, `getusers.php`, `mbapi.php`): block length (128,
192), ECB mode, phpseclib padding, missing IV, the zero padding (empty data,
rounding, block size, pad byte, padding of ciphertext), receipt and membership
key/IV derivation, and at each call site the voucher ID, the encryption
itself, `rtrim()`, base64 and the SHA-1 framing. Tests: `CryptoTest`,
`GetUsersTest`, `ReceiptImportTest` and `tests/http-receipts.php`.

| Lane | Killed | Equivalent | Survived | Invalid |
|---|---:|---:|---:|---:|
| PHP 5.6.40 | 30 | 0 | 0 | 0 |
| PHP 7.4.33 | 30 | 0 | 0 | 0 |

30 mutations since the review fixes: key and IV now come from
`receipt_cipher_params()`/`membership_cipher_params()` (swapped key/IV
arguments in a helper are mutated too), and the call sites pass `''`
explicitly (the configured test key instead of `''` is killed at each of
the three sites). The first version had 25, all killed.

The first run left `members-padding-kept` (no `rtrim()`) alive: the
`getUsers()` export was a whole number of blocks, so it had no padding. The
vector now ends 5 bytes short of a block. On PHP 5.6 the baseline first failed
because displayed warnings name the runner's copy (`/tmp/pgacc-mutation/...`)
instead of `/app`; the runner now passes its served root as
`PGACC_TEST_APP_ROOT`, and `tests/http-diagnostics.php` strips it (or `/app`)
before matching the logged diagnostic exactly.

### Required step for fixes

Fix pull requests that change code reached by a target run that target on PHP
7.4 before and after the change and report both results; locally
`--changed-since=<base>` limits the run to the mutations on the changed lines.
A new behavior needs a mutation in the relevant target (or a new target), and
the change must not introduce survivors. CI runs `tests/mutation/ci.sh` on the
PHP 7.4 and 8.5 lanes: on every push for the changed lines only, weekly and
on pull requests to `master` for every target (also on PHP 7.4 / PostgreSQL
8.4). The PHP 8.5 list is `rights2orgasql` and `null-arguments`; the other
targets' baselines fail there on PHP 8.5 diagnostics without baseline entries
(see `tests/COMPATIBILITY_PLAN.md`).
The `import-checks` target (`tests/http-import.php`) needs the membership stub
of `compose.test.yaml`, which the runner starts with the database.
