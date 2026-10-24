# Mutation testing

Two tools, both on the PHP 7.4 and 8.5 lanes (PHP 5.3 has no built-in server,
and no Infection release runs on PHP 5):

- [Infection](https://infection.github.io/) generates mutants for the
  PHPUnit-tested helpers in `functions.php` (through a class wrapper) and
  reports a mutation score. It is a report, not a CI gate yet. Use it for
  broad coverage of helpers that PHPUnit calls directly. See [Infection](#infection).
- `run.sh`, the target-driven runner, applies hand-written, reviewable
  mutations from `targets/` and runs the PHPUnit filters and/or HTTP suites
  that must kill them, against a `php -S` server on the mutated copy. Use it
  for code only the HTTP tests reach (top-level page code, which Infection
  cannot mutate) and for security-relevant, domain-specific mutations that
  Infection does not generate (fail-open returns, `IN` to `NOT IN`, widened ID
  patterns). Fix pull requests use its targets (see
  [Required step for fixes](#required-step-for-fixes)).

## Infection

```sh
sh tests/mutation/infection.sh 7.4    # or 8.5; extra arguments go to Infection
PGACC_TEST_ID=mine sh tests/mutation/infection.sh 8.5 --min-covered-msi=67
PGACC_INFECTION_TARGET=shim sh tests/mutation/infection.sh 7.4   # the wrapper itself
```

The script starts a synthetic database in its own Compose project
(`pgacc-test[-ID]-infection-php74|php85`), builds the lane's application image,
layers the pinned Infection tools on top (`infection.Dockerfile`, image
`pgacc-test-infection-php74|php85[-ID]`) and runs Infection on an internal
network with a read-only root filesystem. Reports go to
`tests/mutation/results/php74|php85[-shim]/` (ignored by Git) or to the
absolute path in `PGACC_INFECTION_RESULTS`: `infection.log` (escaped mutants
with diffs), `summary.log`, `infection.json`, `per-mutator.md`,
`infection.html`. The exit status is nonzero only for a failed initial test
run or a `--min-msi`/`--min-covered-msi` option. CI runs both lanes and both
targets (`functions`, `shim`) on full runs only, with `continue-on-error`, and
uploads the reports.

Run Infection once for a finished `functions.php` helper change, not after
every edit.

### Versions

| Lane | Infection | Installation | Coverage driver | Run time (8 threads) |
|---|---|---|---|---:|
| PHP 7.4.33 | 0.26.6 | locked Composer project in `infection/` | Xdebug 3.1.6 (initial run only) | ~30 s |
| PHP 8.5 | 0.35.8 | PHAR, SHA-256 pinned | PCOV 1.0.12, SHA-256 pinned | ~45 s |

- 0.26.6 is the last release for PHP 7.4. Its published PHARs (0.25.0-0.26.6)
  do not start on PHP 7.4 (`Class 'Symfony\Polyfill\Php80\Php80' not found`),
  so it is installed from `infection/composer.lock`, separate from the
  application's lock. It runs from `/tmp`, because the binary also loads the
  project autoloader from its working directory, whose `nikic/php-parser` 5
  conflicts with Infection 0.26's version 4.
- The 0.35.8 PHAR was checked against its GPG signature
  (`C6D76C329EBADE2FB9C458CFC5095986493B4AA0`) before its SHA-256 was pinned.
- Both accept the locked PHPUnit 9.6.38 and php-code-coverage 9.2.32.
- Infection 0.26 reads only strict JSON, so `infection.json` uses only keys both
  versions know. 0.35 skips uncovered code unless `--with-uncovered`, which the
  script passes on PHP 8.5 so both lanes report the same metrics.
- On PHP 7.4, mutant runs use `xdebug.mode=off` (3.5 minutes became 30 s).

### Global functions and the wrapper

No Infection release (checked up to 0.35.8) mutates functions outside a class,
so `functions.php` alone gives 0 mutants. `infection-shim.php` (command-line
entry for `PgaccInfectionShim` in `InfectionShim.php`) writes a disposable copy
to `/tmp/infection-src/functions.php` in the container:

- each top-level `function name(` becomes `public static function name(` of
  one final class, on the same line;
- after the class, each global name is a one-line delegate,
  `function name(...$arguments) { return Class::name(...$arguments); }`.

Tests keep calling the global names, so defaults, `global`, output and return
values are unchanged, and reported line numbers are `functions.php` lines. The
wrapper refuses what it cannot wrap faithfully: other top-level code,
by-reference parameters or returns, any `static`, and
`__FUNCTION__`/`func_get_args()`-style constructs. `PGACC_FUNCTIONS_FILE` makes
`tests/functions-bootstrap.php` load the copy; `infection/phpunit.xml` mirrors
`phpunit.xml` with coverage on it. `@function_signature` mutators are disabled
(they only break the wrapper). Mutants are reported against the `/tmp` path, so
`--git-diff-lines` does not apply.

`tests/unit/InfectionShimTest.php` (in the normal suite) checks exact output
for small inputs, that every function of `functions.php` and of
`tests/fixtures/infection-shim/functions.php` keeps its line and the result
passes `php -l`, that the wrapped fixture behaves like the original (in
separate `php -n` processes), each refusal message, and the command-line entry.
Its own coverage (100% of lines and branches):

```sh
docker run --rm --network none -v "$PWD/tests:/app/tests:ro" \
  pgacc-test-application sh -c 'cd /app && XDEBUG_MODE=coverage \
  php -d memory_limit=-1 vendor/bin/phpunit \
  --configuration tests/mutation/infection/shim/phpunit.xml \
  --path-coverage --coverage-text'
```

`PGACC_INFECTION_TARGET=shim` mutates the wrapper against these tests
(`@function_signature` enabled; Infection 0.26 needs the class file as
`bootstrap`). Last result (2026-10-23): every mutant killed on both lanes.

### Results

Latest recorded run on `functions.php` (2026-10-23, after the mcrypt
replacement and its review fixes):

| Lane | Mutants | Killed | Escaped | Not covered | Timed out |
|---|---:|---:|---:|---:|---:|
| PHP 7.4, Infection 0.26.6 | 686 | 431 | 245 | 9 | |
| PHP 8.5, Infection 0.35.8 | 723 | 494 | 220 | 7 | 2 |

Rerun after `functions.php` changes and compare with this table. No mutant
escapes on recently changed lines except the equivalent `setBlockLength(257)`
(phpseclib rounds it to the same 256-bit block); the
[`receipt-crypto` target](#targets) covers the call sites. Infection has no
operator that removes a null guard; the `null-arguments` target covers that.

Escaped mutants by function (first run, PHP 7.4 / PHP 8.5):

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

Most escaped mutants are real test gaps (222 / 198); 14 are equivalent and 8
unobservable on each lane.

Equivalent: the first `.=` in `getfilter` (line 37, `$filter` is empty); the
three mutants of the malformed regex on line 73; reordering or dropping the
separator space in `ascii2hex` (line 16, spaces are removed afterwards);
removing the empty `default:` in `getsort`; `substr($sortn, -1, 2)` in
`getoppsort`; `continue` to `break`, `pg_free_result()` removal and
`while (false)` in the two database helpers (at most one row); `$date != null ||`
and the removed `$` after `.*` in `format_date` line 250;
`csv_download_link($i = -2)`.

Unobservable: `checklogin('rights', true)` in `acc_header`/`acc_footer` (the
test stub ignores the redirect flag) and the `die()` messages (a failing query
ends the PHPUnit process).

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

### Infection versus the runner

Infection cannot mutate code reached only through HTTP tests: it maps mutants
to tests through the PHPUnit process's coverage, its include interceptor
replaces files only in that process, and page scripts are top-level code.

On `rights2orgasql()` Infection generates 20 (PHP 7.4) and 24 (PHP 8.5)
mutants, all killed, but only about 6 overlap the runner's target. It does not
change string literals or invent domain-specific alternatives, so it misses
fail-open returns (`" AND false "` to `""`), `IN` to `OR`/`NOT IN`, changed
delimiters, privilege exemptions, substring or case-insensitive role matching,
widened ID patterns, keeping only the last organization and removing the array
initialization. Keep the runner's `rights2orgasql` target for this helper.

### Threshold plan

- Keep the CI job non-blocking until the real gaps above are closed. Then
  (1) gate on no new escaped mutants against a committed baseline of escaped
  mutant IDs (mutator, line, diff) with reasons for the equivalents, rather
  than on a percentage that hides regressions behind new kills; (2) add
  `minCoveredMsi`/`minMsi` floors just below the current values and raise them
  as tests improve; (3) require no escaped mutants on changed lines except
  listed equivalents. Gate on PHP 8.5, which reports missing-key warnings; keep
  PHP 7.4 as a report.
- Feed the runner generated mutants: Infection's JSON diffs for helpers and a
  small `nikic/php-parser` generator for top-level page code, selecting HTTP
  checks per mutant from server-process coverage.
- Longer term, moving the helpers into a class (with thin global wrappers)
  would remove the wrapper and allow `--git-diff-lines`.

## Target-driven runner

`run.sh` mutation-tests code against the existing suites on PHP 7.4 and 8.5
(either PostgreSQL server, `PGACC_TEST_PG`). Each target is a reviewable file
of hand-written mutations: a targeted check of assertion strength, not a
generated score.

```sh
PGACC_TEST_ID=mine sh tests/mutation/run.sh 7.4 login-logout
PGACC_TEST_ID=mine PGACC_TEST_PG=8.4 sh tests/mutation/run.sh 7.4 rights2orgasql
PGACC_TEST_ID=mine sh tests/mutation/run.sh 7.4 login-logout --changed-since=origin/master
PGACC_TEST_ID=mine sh tests/mutation/run.sh 7.4 login-logout --only=login-target-ignored,logout-redirect-removed
PGACC_TEST_ID=mine sh tests/mutation/run.sh 7.4 login-logout --check   # definitions only
PGACC_TEST_ID=mine sh tests/mutation/run.sh 7.4 --self-test            # runner unit tests
```

The target is a file name in `targets/`. Results go to
`build/mutation/<target>-php<lane>/` (`-pg84`/`-pg18` suffix for a non-default
`PGACC_TEST_PG`; override with `PGACC_MUTATION_HOST_DIR`): `results.json`,
`summary.txt`, and a test log and server log per mutant. `PGACC_TEST_ID`
isolates concurrent runs; remove the tagged image with `docker rmi` afterwards.

Exit status: 0 when every valid mutant is killed, timed out, or an accepted
equivalent; 1 for any unaccepted survivor, invalid mutant, or stale
equivalent; 2 for a definition error, a setup error, or a failing baseline.

### Iterating versus readiness

- **While iterating** (developing a test, fixing one review item), select
  mutants with `--only=id,id`: the new ones and the existing ones whose
  behaviour the change can affect. Each invocation still runs the baseline.
  Never report such a selection as a full-target result. Use `--check` for
  definition problems and the focused test (`tests/README.md`, "Focused
  tests") for fixture or setup problems, not repeated target runs.
- **At readiness**, run each affected target once with
  `--changed-since=<real PR base>` (the branch the PR targets, not `HEAD~1`).
  If the change touched the target definition, this selects the whole target
  and can take minutes; that is expected and is the run that counts.
- A later fix invalidates only the affected evidence: rerun its focused test
  and affected target, not every target. Full sweeps run in CI.
- Report the selected IDs, killed/timeouts/survivors/equivalents, the exit
  code and the source and base. A timeout is not an assertion kill. No
  selected mutants means no mutation evidence for the change, even with exit 0.

### Changed lines only (`--changed-since`)

`--changed-since=<base>` runs only the mutations whose **search region**
overlaps a line changed since `git merge-base <base> HEAD` in the working tree
(uncommitted and untracked files included). The search region is the
mutation's `region` (start to end marker) if it has one, else the lines of the
matched `search`. A pure deletion counts as a change of the lines on both
sides. A changed target file selects all its mutations; changes to test files
alone select none. With nothing selected the target passes without running
its baseline. `run.sh` takes the diff on the host
(`build/mutation/<target>-.../changed.diff`) and passes it to
`runner.php --changed-diff=`; the selection is `pgacc_mutation_parse_diff()`,
`pgacc_mutation_lines_changed()` and `pgacc_mutation_select_changed()` in
`lib.php`, unit-tested in `tests/LibTest.php`.

### CI (`ci.sh`)

`sh tests/mutation/ci.sh <7.4|8.5> [--changed-since=<base>]` runs the
self-test and the lane's target list, attempting every target: all 12 on PHP
7.4 (either server), `rights2orgasql` and `null-arguments` on PHP 8.5, where
the other baselines fail on PHP 8.5 diagnostics. CI passes `--changed-since`
on per-push runs and runs every target on full runs (weekly, manual, `full-ci`
pull requests), also on PHP 7.4 / PostgreSQL 8.4.

### How a run works

`run.sh` builds the lane's application image and starts its PostgreSQL and
membership-stub services in their own Compose project (`pgacc-mutation[-ID]-php74|php85`, without
`compose.coverage.yaml`), then runs `runner.php` in a one-off container of the
application service (same image, environment, internal network, read-only
root, `/tmp` tmpfs). `runner.php`:

1. validates the target and applies every mutation in memory; any definition
   error aborts before anything runs;
2. copies `/app` to `/tmp` once (`vendor/` linked) and creates
   `accounting_mutation_template` from the same fixtures, in the same order, as
   `tests/database-smoke.sh`;
3. runs the unmutated baseline like a mutant; if it fails, the run stops with
   exit 2 and prints its log;
4. for each mutant: copies the tree to a disposable directory with the mutated
   file (the image and repository are never modified); runs `php -l` (a syntax
   error makes it **invalid**); recreates `accounting` from the template
   (`DROP DATABASE ... WITH (FORCE)` from PostgreSQL 13, else after
   terminating the other backends); for HTTP tests starts a fresh
   `php -S 127.0.0.1:<port>` with the application service's settings
   (`output_buffering=4096`, the diagnostics prepend; checked against
   `compose.test.yaml` by the self-tests) and a fresh diagnostics directory;
   runs the target's tests in order, stopping at the first failure (the
   `output_buffering=0` server of `http-login.php` serves the mutant too);
   classifies the result and cleans up.

Every command runs in its own process group (`setsid`); on timeout, or when a
command leaves children behind, the group is killed, so a hung mutant cannot
hold a port. The runner passes its served root as `PGACC_TEST_APP_ROOT`, and
`tests/http-diagnostics.php` strips it before matching displayed diagnostics.

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
            // 'equivalent' => array('reason' => '...', 'php' => array('7.4')),
        ),
    ),
);
```

Pick only the HTTP suites that reach the code (`tests/http-smoke.php`,
`tests/http-login.php`, `tests/write-workflows.php`, or any script using
`tests/http-test-helpers.php`); a mutant takes about one to three seconds with
one suite.

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

Changing a target definition makes `--changed-since` select the whole target
(see [Iterating versus readiness](#iterating-versus-readiness)).

### Runner tests

`lib.php` holds target validation, mutation application, exact-match
enforcement, outcome classification, summaries and the process runner;
`runner.php` is the orchestration. `sh tests/mutation/run.sh 7.4 --self-test`
runs their PHPUnit 9.6 tests (`tests/`), reports line and branch coverage of
`lib.php` on PHP 7.4 and fails on anything uncovered, and loads every committed
target and applies all its mutations to the current sources, so an application
change that breaks a definition fails there first. `runner.php` is exercised
end to end by the target runs.

### Targets

All targets run on PHP 7.4 (`ci.sh`); `rights2orgasql` and `null-arguments`
also on PHP 8.5. The target files hold each mutation's rationale.

| Target | Code | Tests |
|---|---|---|
| `rights2orgasql` | `rights2orgasql()` in `functions.php` | PHPUnit `testRights2orgasql` |
| `null-arguments` | null guards in `tag()`, `acc_header()`, `acc_footer()`, `rights2orgasql()` | PHPUnit |
| `login-logout` | `login()` and the early logout/login blocks in `index.php`, `login.php`, the `page_new_buchung()` signature | `http-login.php` |
| `redirects` | login check and member search redirect in `index.php`, `ob_start()` for edit/merge, `relocate()` in `edit.php` and its callers, `search.php` | `http-redirects.php`, `write-workflows.php` |
| `rights-checks` | organization scope and rights checks of voucher creation, revision, approval, approval reset, merge, the role checks and new users; the removed finalization (`finalization-write-restored`) | `write-workflows.php` |
| `sql-injection` | request values in SQL: voucher ID checks, escaped old approvals, date pattern, listing filter | `write-workflows.php` |
| `import-checks` | date and organization checks of every bank CSV line (`import.php`), the PDF import's voucher match (`import2.php`), the receipt upload's row check (`new.php`) | `ReceiptImportTest`, `ImportMemberMatchTest`, `http-import.php` |
| `read-only-rights` | `write_rights()` and its checks for read-only `Rbgf` | `write-workflows.php`, PHPUnit |
| `listing-columns` | column preferences in `closed.php` (`listing_column_hidden()`, `emptytag()`) | `http-listing-prefs.php` |
| `report-prevcond` | `$prevcond` initialization in `page_report()` | `http-smoke.php` |
| `donations` | `page_donations()` initializations, disabled publication, captcha | `http-donations.php` |
| `receipt-crypto` | mcrypt-compatible helpers, receipt storage and migration, their call sites in `file.php`, `new.php`, `import2.php`, `getusers.php`, `mbapi.php` | `CryptoTest`, `GetUsersTest`, `ReceiptImportTest`, `ReceiptStorageTest`, `ReceiptMigrationTest`, `http-receipts.php` |

Accepted equivalents (reasons in the target files):

- `login-logout`: `logout-cookie-expiry-in-future` (`setcookie()` ignores the
  expiry for an empty value); `page-new-buchung-default-restored` on PHP 7.4
  only (a default before required parameters is silent before PHP 8.0).
- `null-arguments`: the guard removals on PHP 7.4 (null is accepted silently
  before PHP 8.1); PHP 8.5 kills them.
- `redirects`: `search-requires-login` (`page_search()` replaces the login
  `Location`).
- `rights-checks`: `ack-visibility-check-counts-deleted` (the receipt check
  skips deleted rows too).
- `import-checks`: `upload-row-check-removed`, `upload-update-unscoped`,
  `upload-update-any-voucher` (the scoped row check before the finalized check
  already refuses those rows), `upload-update-ignores-previous-file` and
  `import2-update-ignores-file` (differ only under a concurrent change).
- `report-prevcond`: `prevcond-passes-prev-cond` (`unit_report()` never reads
  its first parameter).

Deliberately not mutated: an unprotected import page (`redirects`) and the bank
import check (`read-only-rights`), because the page would call the external
membership system. On PHP 8.5 every target except `rights2orgasql` and
`null-arguments` fails its baseline (exit 2) on the implicit-connection
deprecations (see `tests/COMPATIBILITY_PLAN.md`).

### Required step for fixes

Fix pull requests that change code reached by a target report red and green
mutation evidence on PHP 7.4: the new test kills the relevant mutants, which
survive on the old code (or the old code fails the baseline). While developing
use the focused test and `--only`; at readiness run each affected target once
with `--changed-since=<real PR base>` and report the actual selection,
including the full-target fallback when a definition changed (see
[Iterating versus readiness](#iterating-versus-readiness)). A new behaviour
needs a mutation in the relevant target (or a new target), and the change must
not introduce survivors. CI runs `tests/mutation/ci.sh` on the PHP 7.4 and 8.5
lanes: changed lines on per-push runs, every target on full runs (weekly,
manual, `full-ci` pull requests; also on PHP 7.4 / PostgreSQL 8.4). The PHP 8.5
list is `rights2orgasql` and `null-arguments` (see `tests/COMPATIBILITY_PLAN.md`);
the PHP 8.5 job is non-blocking. The `import-checks` target needs the
membership stub of `compose.test.yaml`, which the runner starts with the
database.
