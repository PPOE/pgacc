# Test harness

This directory contains an isolated characterization-test environment for the
legacy pgacc application. It does not use production credentials, production
data, or the production attachment directory.

Run the current harness with:

```bash
sh tests/run.sh
```

This runs the syntax matrix, then independent PHP 7.4, PHP 5.6, PHP 8.0, and
PHP 8.5 application lanes. Each lane gets a fresh synthetic PostgreSQL database.
Every lane is attempted, and the runner returns nonzero if any lane fails. To
run one lane:

```bash
sh tests/run.sh 7.4
sh tests/run.sh 5.6
sh tests/run.sh 8.0
sh tests/run.sh 8.5
```

To run several harnesses at the same time on one Docker host, for example from
separate Git worktrees, give each run its own ID:

```bash
PGACC_TEST_ID=worktree-a sh tests/run.sh 7.4
PGACC_TEST_ID=worktree-b sh tests/run.sh 7.4
```

The ID (lowercase letters, digits, `_` and `-`) is appended to the Compose
project names and the locally built application image tags, so concurrent runs
do not share containers, networks, databases, or images. Without an ID the
names are unchanged (`pgacc-test-php74`, `pgacc-test-application`, ...). Runs
with an ID leave their tagged images behind; remove them with `docker rmi` when
no longer needed.

GitHub Actions runs the four application lanes as parallel matrix jobs,
alongside a separate syntax compatibility job. A failure in any application
lane fails CI; it does not cancel the other lanes. A separate, non-blocking `mutation`
job runs Infection on PHP 7.4 and PHP 8.5 and uploads its reports.

The runner currently performs four checks:

1. It syntax-checks every top-level PHP application file in pinned PHP 5.5,
   5.6, 7.4, 8.0, and 8.5 containers. Every version must pass; the old PHP 8 expected
   failure has been removed after replacing `$ascii{$i}` with `$ascii[$i]`.
2. Each application lane syntax-checks every top-level PHP application file
   under its own PHP runtime.
3. It installs a data-free copy of the pgacc schema and synthetic fixtures in
   PostgreSQL, then runs database characterization assertions with `psql`.
4. Each lane starts the application with the PostgreSQL extension,
   exercises real HTTP routes and login/logout responses, runs the shared
   PHPUnit helper tests, and exercises voucher write workflows. The HTTP checks
   fail on any PHP diagnostic the application raises that is not in the
   reviewed `tests/known-diagnostics.php` baseline. The PHP 7.4 lane additionally
   collects coverage of the PHPUnit run and of the application server during the
   HTTP and write-workflow tests, and merges both into one report (see
   [Coverage](#coverage)).

PHP 5.6, 7.4, and 8.0 are historical characterization runtimes, not supported
deployment targets. PHP 8.5.12 is also tested as an upgrade target. Passing syntax
checks alone does not establish behavioral compatibility. The database uses
PostgreSQL 17 as the initial replacement platform target.

`fixtures/schema.sql` was reconstructed from a read-only inspection of the
deployed PostgreSQL 8.4.20 catalog. It preserves the observed tables, columns,
defaults, sequences, and constraints while excluding the dated backup table,
owners, permissions, credentials, and all production rows. `fixtures/data.sql`
contains only visibly synthetic records.

`database-characterization.sql` covers password hashing, open and closed
voucher states, attachment propagation, public transactions and spendings,
donation totals, report type mapping, and review-progress counts using SQL
contracts from the current PHP application.

`http-smoke.php` covers the public report, transactions, spendings, rejected
login, successful login, and an authenticated ledger request. The runtime uses
test-only constants and the locked Composer dependencies. It has no published
host port, cannot reach external networks, and receives only synthetic data.
The test server enables `output_buffering=4096` because the current login flow
sends redirect and cookie headers after page rendering has started.

`http-login.php` checks login and logout responses directly: a valid login
returns 302 with `Location: index.php` (or the hex-decoded `target`), numeric
`pp_pgacc_login` and `pp_pgacc_id` cookies for the right user, and cookies that
authenticate a follow-up request; rejected logins (wrong password, unknown
user) return 200 with the form, no `Location`, and no cookies; logout returns
302 to `index.php` and clears both cookies. It then starts a second built-in
server inside the test container with `output_buffering=0` and repeats the
valid login and logout there. Those two checks are **known failures**
(`KF-LOGIN-UNBUFFERED`, `KF-LOGOUT-UNBUFFERED`): they document that the current
redirects only work because of the 4,096-byte buffer. The script fails if a
known failure starts passing, so the fix that moves header-setting actions
before rendering must also move these checks to the regular assertions.

### PHP diagnostics are failures

Every HTTP test server loads `tests/runtime/diagnostics-prepend.php` with
`auto_prepend_file` (on the PHP 7.4 lane the main server's coverage prepend
includes it; see [Coverage](#coverage)). It installs an error handler for all diagnostic types and
a shutdown check for fatal errors, writes each one to a per-request JSON-lines
file on a tmpfs volume shared with the test container
(`PGACC_TEST_DIAGNOSTICS_DIR`), and then returns `false`, so PHP's own handling
continues unchanged. The runtime's `error_reporting`, `display_errors`, and
output buffering are not modified: response bodies and header timing are the
same as without the recorder. Diagnostics that PHP 5.6/7.4 hide by default
(notices, deprecations) or that `@` silences are still recorded and marked
"hidden". Each request carries an `X-Pgacc-Test-Request` ID; the client fails if
the recorder did not reach its completion marker for that request, and also
cross-checks the body for displayed diagnostics missing from the log.

`http-smoke.php`, `http-login.php`, and `write-workflows.php` print every
diagnostic they saw (type, file:line, message, requests) and fail on any that is
not listed in `tests/known-diagnostics.php`. That list is a reviewed baseline:
each entry matches an exact PHP minor version, type, file, line set, message
pattern, and whether it was reported or hidden, with an ID and reason. It
currently contains only issues observed on PHP 5.6 and 7.4, the lanes that
passed before diagnostics were checked, so those lanes keep passing while the
issues stay visible. PHP 8.0 and 8.5 have no entries, so every diagnostic there
fails the lane. Requests made inside a known-failure check (the unbuffered
server) are attributed to that known failure instead. Remove entries when the
application is fixed; do not add entries to make a lane green without review.
On PHP 8.5, OPcache is active in the built-in server, so compile-time
deprecations appear only on the first request that compiles a file.

The test client itself also fails on its own unsilenced diagnostics. It reads
response headers with `http_get_last_response_headers()` when available (PHP
8.4+) and otherwise falls back to `$http_response_header` in
`tests/http-legacy-headers.php`, which is only included on older runtimes
because PHP 8.5 deprecates that variable at compile time.

`write-workflows.php` exercises real HTTP mutations and verifies their database
effects: voucher creation, organization-scoped approval, distinct first and
second reviewers, finalization, the finalized-entry edit lock, revision history,
and root-only recovery. Receipt encryption/upload is deliberately excluded
because the unchanged application requires the removed `mcrypt` extension;
the tests use a synthetic receipt reference instead.

`tests/unit/FunctionsTest.php` runs under PHPUnit 9.6 on PHP 7.4, PHP 8.0, and
PHP 8.5, and PHPUnit 5.7.27 on PHP 5.6.40. The assertions and data providers are shared; only the
runner bootstrap, lifecycle signature, and two string assertion aliases differ.
Coverage is collected only on PHP 7.4, with Xdebug 3.1.6; the PHPUnit
coverage is configured for `functions.php` and merged with the HTTP coverage
(see [Coverage](#coverage)). All PHPUnit configurations set
`error_reporting` to `-1` (E_ALL), so notices fail tests on every lane, also
where the runtime's built-in default hides them (PHP 5.6 and 7.4 without
`php.ini`). The same suite runs `tests/unit/InfectionShimTest.php`, the tests
of the Infection class wrapper (see [mutation notes](mutation/README.md)). The suite exercises the utility and rendering helpers plus the
two PostgreSQL helpers against the synthetic database. PHPUnit's text report is
printed in CI, and a Clover report is written to `/tmp/functions-clover.xml`
inside the disposable test container. The current suite covers 334 of 335
executable lines (99.70%). The remaining statement is `functions.php:74`,
behind a malformed regular expression on line 73 (`^*...`) that cannot match
any input. A test records that current malformed-regex behavior; reaching the
following statement requires a separate production fix. PHPUnit 9 and Xdebug
3.1 are pinned as a legacy-runtime bridge; neither
is a production dependency.

The PHP 5.6 image installs only the locked application dependencies, checks their
platform requirements against the actual PHP 5.6 runtime, and runs a pinned
PHPUnit PHAR outside the application document root. Its download is checked with
a pinned SHA-256 digest. It uses the same public/authenticated HTTP scenarios and
voucher creation, approval, finalization, revision, and recovery tests as PHP 7.4.
PHP 5.6 and PHP 7.4 both pass 155 PHPUnit test cases and 237 assertions (115 and
138 of them for `functions.php`, the rest for the Infection wrapper). PHP 5.6 has an
explicit UTC PHP timezone setting, matching the fixture environment.

`compose.php56.yaml` configures MD5 password authentication only for that lane's
isolated synthetic PostgreSQL database. Its archived libpq 9.6 client predates
SCRAM authentication. The PHP 7.4 database keeps its existing authentication
settings, as do PHP 8.0 and PHP 8.5. All lanes have internal networks and no published
application port.

The Dockerfiles pin base images and libpq development packages and compile
`pgsql`: archived Bullseye for PHP 7.4/8.0, archived Stretch for PHP 5.6, and
Trixie for PHP 8.5.

The PHP 8 driver attempts the HTTP, PHPUnit, and write-workflow groups even if
one fails, then returns a failing exit status if any group failed. It does not
change the shared assertions to accept PHP 8 incompatibilities. Coverage remains
on PHP 7.4.

All four runtimes pass the 115 PHPUnit tests and 138 assertions after explicitly
initializing the rights helper's organization array. The rights assertions no
longer suppress warnings. On both PHP 8 runtimes, the HTTP and write tests fail:
login and logout return 200 instead of 302 (no `Location`, no cookies), and
every request reports diagnostics that have no known-diagnostics entry — the
`new.php:450` parameter-order deprecation, undefined `$prevcond`, the
PostgreSQL 15+ report query error, and "headers already sent" warnings; PHP 8.5
adds implicit PostgreSQL connection and `strlen(null)` deprecations. See
`COMPATIBILITY_PLAN.md`. These failures remain visible in CI; they are not
allowed failures. The test helper no longer uses `$http_response_header` on
PHP 8.5.

The rights helper has 24 authorization cases, strengthened using targeted
mutations. The original four cases killed 13 mutants; the expanded cases kill
all original 30. With the initialization fix applied, PHP 7.4 and PHP 8.5 also
kill a new mutation that removes the initialization: 31/31 valid mutations
killed. See [mutation results and reproduction steps](mutation/README.md).
The suite also passes in random order, as Infection runs it: the
acknowledgement-reset test no longer closes the shared default PostgreSQL
connection that later database helper tests use.

## Coverage

The PHP 7.4 lane measures coverage of all 29 top-level application files, not
only the PHPUnit-tested `functions.php`. `tests/run.sh 7.4` adds
`compose.coverage.yaml`, which only exists for that lane; the other lanes are
unchanged.

- The application server starts with
  `auto_prepend_file=tests/coverage/prepend.php` and `XDEBUG_MODE=develop,coverage`
  (`develop` is the Xdebug default the server already used, so error output is
  unchanged). For each request the prepend starts Xdebug line and branch
  coverage, and after the application and its own shutdown functions have run
  it writes the raw data to a project-scoped Docker volume. It prints nothing,
  sends no headers, and defines no global variables. Writing the data happens
  after the application finished and lifts `memory_limit` for that step only.
- PHPUnit additionally writes its coverage in php-code-coverage's serialized
  format, with branch data (`--path-coverage`, run with an unlimited
  `memory_limit` in the PHPUnit process only).
- `tests/coverage/merge.php` merges PHPUnit and all request data with the
  already locked php-code-coverage 9.2 (no new dependency), prints a per-file
  line/branch table in the lane log, and writes the report to
  `build/coverage/` in the checkout (override with `PGACC_COVERAGE_HOST_DIR`):
  `summary.txt`, `clover.xml`, `html/index.html`, `coverage.cov`, and
  `uncovered-branches.txt`. Files that no test reaches are included with zero
  coverage; they are statically analysed, never executed. The test-only
  `constants.php` is excluded.
- CI publishes the summary on the job page and uploads `build/coverage` as the
  `coverage-php74` artifact.

Branch coverage makes each request several seconds slower, because Xdebug
analyses every path of the large legacy functions. Run
`PGACC_COVERAGE_BRANCHES=0 sh tests/run.sh 7.4` for faster line-only coverage.
Path coverage is discarded: the legacy functions have too many paths for it to
be meaningful (8341 in `functions.php` alone). The branch totals in the summary
count branches inside functions only; script-level code outside functions is
covered by the line numbers and listed in `uncovered-branches.txt` as
`{main}`.

### Proving coverage of changed lines

Application changes must cover every changed executable line. Run the lane
with a base revision:

```bash
PGACC_TEST_ID=mine PGACC_COVERAGE_BASE=origin/main sh tests/run.sh 7.4
```

`tests/coverage/changed-lines.sh <base>` lists the lines added or changed since
the merge base with `<base>`, including uncommitted and untracked `*.php`
files, as `file:start-end` ranges. After the merged report is written,
`tests/coverage/check-changed-lines.php` fails the lane if any of those lines is
executable and was not executed by any test. Non-executable lines (comments,
blank lines, inline HTML, declarations) are ignored, and paths outside the
coverage scope (`tests/`, non-PHP files) are skipped. A changed top-level PHP
file missing from the report is an error. Uncovered branches that overlap a
changed line are printed as warnings; `PGACC_COVERAGE_STRICT_BRANCHES=1` makes
them fail too. On pull requests, CI runs the check against the base branch.

The checker also runs on its own (PHP 5.6 or newer with SimpleXML) against an
existing report, with any list of ranges:

```bash
sh tests/coverage/changed-lines.sh origin/main >changes.txt
php tests/coverage/check-changed-lines.php \
  --branches=build/coverage/uncovered-branches.txt \
  build/coverage/clover.xml changes.txt
printf 'functions.php:12-40\nindex.php:27,31-33\n' |
  php tests/coverage/check-changed-lines.php build/coverage/clover.xml -
```

Only one `auto_prepend_file` is possible. `compose.coverage.yaml` therefore
replaces the diagnostics recorder in the application command with the coverage
prepend and names the recorder in `PGACC_COVERAGE_CHAINED_PREPEND`; the coverage
prepend includes it after starting coverage, and its shutdown functions
(including the request-complete marker) run before coverage is written, so the
diagnostics checks work unchanged. Test files under `tests/` are excluded from
coverage. `compose.coverage.yaml` replaces the whole application command, so
keep it identical to the command in `compose.test.yaml` apart from the
`auto_prepend_file`. The second, `output_buffering=0` server that
`http-login.php` starts inside the test container only loads the diagnostics
recorder; its known-failure requests are not included in the coverage.

Limitations: coverage is measured on PHP 7.4 only; code that behaves
differently on other runtimes is not measured there. Line coverage shows that a
line ran, not that its result was asserted; mutation testing remains the check
for assertion strength.

## Static analysis

```bash
sh tests/static-analysis.sh                     # CI check against the baselines
sh tests/static-analysis.sh --report            # every finding, baselines ignored
sh tests/static-analysis.sh --update-baselines  # rewrite both baselines
```

This runs in its own image (`tests/static-analysis/Dockerfile`), separate from the
legacy runtime images: pinned `php:8.5-cli` with tools installed from
`tests/static-analysis/composer.lock` (PHP_CodeSniffer 4.0.4, PHPCompatibility
10.0.0-alpha2, PHPCSUtils 1.2.3, PHPStan 2.3.1; the PHPStan PHAR is also checked
against a SHA-256 digest). Network access is needed only to build the image. The
analysis container has no network, a read-only repository mount and a read-only
root filesystem. It analyses a temporary copy of the tracked top-level application
PHP files (currently 29), excluding `vendor/`, `tests/`, `files/` and `proxy/`.
The copy also contains the synthetic `tests/runtime/test-constants.php` as
`constants.php` and the locked application `vendor/` directory, so `require()` paths resolve.
`PGACC_TEST_ID` suffixes the image name, as for `tests/run.sh`.

- **PHPCompatibility** through PHP_CodeSniffer (`phpcs.xml`): `testVersion`
  5.6-8.5 plus `Generic.PHP.DisallowShortOpenTag`. No formatting rules.
- **PHPStan** level 5 (`phpstan-common.neon`): undefined and possibly undefined
  variables (levels 0/1), unknown functions, argument counts and types, invalid
  regular expressions. `phpVersion` is 7.1-8.5 because PHPStan cannot model PHP
  below 7.1; PHPCompatibility covers the 5.6 floor. `stubs.php` declares the
  removed `mcrypt` functions and constants.

The check fails on any finding that is not in a baseline. It also fails on a
baseline entry that is no longer reported or occurs a different number of times.
A fix therefore has to remove its entry, and the finding cannot come back
unnoticed later. `phpcs-baseline.txt` lists one line per finding: file, sniff and message.
`phpstan-baseline.neon` is PHPStan's own format: message, identifier, count and
path. Neither uses line numbers, so unrelated edits do not churn them. Every
entry is listed and explained in
[COMPATIBILITY_PLAN.md](COMPATIBILITY_PLAN.md#static-analysis-findings). Review
the diff of any `--update-baselines` run like a code change. Some findings are
also `tests/known-diagnostics.php` entries; fixing one of those removes both
entries (see [PHP diagnostics are failures](#php-diagnostics-are-failures)).

A canary self-test runs first. `tests/static-analysis/canaries/` holds minimal
synthetic copies of the bug classes already found: `$ascii{$i}`, the uninitialized
`$rights2` array, the result-loop arrays at `edit.php:345`/`donations.php:116`,
a function reading `$key` without `global`, the optional-before-required
parameter and a short open tag. A PHP 7-only `??` canary guards the 5.6 floor
of `testVersion`. `canaries/expected.txt` names the finding each tool must
report for them. A weakened configuration (for example, a lower PHPStan
level or a narrower `testVersion`) fails this step even if the baselines are
regenerated.

Against the pre-fix `functions.php` (commit `8bbeb63`), PHPCompatibility reports
`$ascii{$i}` (`RemovedCurlyBraceArrayAccess`) and PHPStan reports
`Variable $rights2 might not be defined` (level 1 and above). Each tool catches
only one of the two: PHPStan's parser accepts the curly-brace offset, and
PHP_CodeSniffer has no data-flow analysis. The missing-upload `count()` crashes at
`import.php:27` and `import2.php:24` are only reported at PHPStan level 9, as
`count(mixed)`, among about 700 findings. The HTTP tests remain the check for
those. Neither tool reports PHP 8.5's implicit PostgreSQL connection deprecation.

## Test improvement plan

The [compatibility findings and fix list](COMPATIBILITY_PLAN.md) records completed
changes, reproduced array/input crashes, the login-header diagnosis, short-tag
risks, and proposed PHPStan/PHPCompatibility checks.

The current shell and HTTP checks remain useful characterization tests.
PHPUnit 9.6 is pinned for the PHP 7.4, PHP 8.0, and PHP 8.5 test runtimes so they can run
the same suite during migration. PHP 5.6 uses PHPUnit 5.7 for the
same reason, with a separate runner configuration. The initial suite covers
`functions.php`; the existing shell and HTTP checks have not been migrated.

### Planned slices

- [ ] Evaluate [Rector](https://github.com/rectorphp/rector) for PHP-version
  upgrade assistance, starting with selected rules and dry runs whose proposed
  changes are checked against the supported runtime matrix.
- [x] Evaluate Infection for broader generated mutations and reporting.
  `sh tests/mutation/infection.sh 7.4|8.5` runs Infection 0.26.6 (PHP 7.4)
  or 0.35.8 (PHP 8.5) on `functions.php` through a class wrapper, because
  Infection never mutates global functions. Baseline: MSI 58.94% (PHP 7.4)
  and 66.42% (PHP 8.5); escaped mutants are classified in
  [the mutation notes](mutation/README.md). PHP 5.6 cannot run Infection, so
  the targeted runner stays. A non-blocking CI job uploads the reports.
- [ ] Close the real Infection test gaps listed in the mutation notes (mainly
  `acc_header` output and `getfilter` validation), then gate CI on no new
  escaped mutants.
- [x] Report notices in PHPUnit on PHP 5.6 and 7.4 (`error_reporting=-1`).
- [x] Test the Infection class wrapper (100% lines and branches; mutated with
  `PGACC_INFECTION_TARGET=shim`).
- [ ] Generalize the targeted mutation runner to HTTP-tested code and PHP 5.6.
- [x] Add PHPUnit to the isolated test environment and begin PHPUnit coverage
  with `functions.php`. Keep `tests/run.sh` as the single entry point for
  syntax, database, and application checks; existing shell and HTTP checks
  remain in place.
- [ ] Add a BAWAG import integration test through the real multipart HTTP
  upload route. Use synthetic semicolon-delimited CSV fixtures and assert
  voucher fields, import-line deduplication, duplicate re-upload behavior,
  malformed rows, and unknown account handling.
- [ ] Stub the membership lookup locally for import tests. The application
  fetches `http://mitglieder.piratenpartei.at/adm_api/adm_names.php` before
  processing imports, so tests must serve a synthetic response inside the
  isolated test network and must not contact the live service. The current
  decryption path depends on `mcrypt`; assess a pinned, test-only extension in
  the PHP runtime so tests exercise the real code path with a synthetic key.
- [ ] Add a contract test for `mbapi.php` using synthetic PostgreSQL rows and
  test-only encryption keys. Assert the checksum and response framing, decrypt
  the payload, and cover the amount threshold, date cutoff, acknowledgement,
  deleted-voucher filtering, and per-member totals.
- [ ] Document the `mbapi.php` output contract and verify it against the
  membership system's consumer implementation or an agreed sample. A local
  test can characterize this repository's output, but cannot by itself prove
  compatibility with the external consumer.
- [x] Add Xdebug coverage reporting for the PHPUnit process and print the
  `functions.php` report in CI.
- [x] Measure application-server coverage during the HTTP and write-workflow
  tests on PHP 7.4, merge it with the PHPUnit coverage, print a per-file
  line/branch summary, upload the HTML/Clover report as a CI artifact, and
  check that changed executable lines are covered (see [Coverage](#coverage)).
- [x] Record every PHP diagnostic raised while serving HTTP test requests
  (including ones hidden by the PHP 5.6/7.4 default `error_reporting` or `@`)
  without changing runtime error settings, and fail on any diagnostic outside
  the reviewed `tests/known-diagnostics.php` baseline.
- [x] Add login/logout response regressions (302, `Location`, both cookies,
  rejected logins without cookies) and `output_buffering=0` known failures
  that the header-ordering fix must flip.
- [x] Read HTTP response headers with `http_get_last_response_headers()` when
  available, falling back to `$http_response_header` on PHP 5.6-8.3.
- [ ] Apply the same strictness to the PHPUnit process: PHP 8.5 currently
  prints implicit-connection deprecations from `functions.php:434` and `:494`
  during the PHPUnit run without failing it.
- [ ] Shrink `tests/known-diagnostics.php` to empty as the application issues
  are fixed, and then report unused entries as failures.
- [x] Replace the removed curly-brace string-offset syntax with square brackets.
  All 29 top-level application PHP files now pass syntax checks on PHP 5.5,
  5.6, 7.4, 8.0, and 8.5. The fix preserves compatibility with the older runtimes.
- [x] Older syntax probes: the same files also pass `php -l` on PHP 5.5 and
  5.6. PHP 5.4 and 5.3 were not testable with the available Docker Hub images,
  which use a manifest format rejected by the current Docker engine. This is
  syntax-only evidence for PHP 5.5; PHP 5.6 also has the behavioral lane below.
- [x] Run the shared PHPUnit tests and HTTP/database workflows on PHP 5.6.40,
  using PHPUnit 5.7.27 and an isolated PostgreSQL database. Run the PHP 5.6 and
  7.4 lanes as independent GitHub Actions matrix jobs.
- [x] Run syntax checks from the default `tests/run.sh` entry point and the
  dedicated GitHub Actions syntax job; every checked version must pass.
- [x] Add a PHP 8.0 runtime lane for the same PHPUnit and HTTP/database tests,
  using the parameterized test Dockerfile and a separate synthetic database.
- [x] Add a PHP 8.5.12 runtime lane for the same tests, using a pinned Trixie
  image and separate synthetic database.
- [x] Mutation-test `rights2orgasql()` and expand authorization cases to kill
  all 30 original mutants. Apply the array-initialization fix, remove warning
  suppression, and kill the new initialization-removal mutant on PHP 7.4 and
  PHP 8.5 (31/31).
- [ ] Resolve PHP 8 behavioral failures exposed by the shared tests, then
  extend the matrix to newer PHP 8 releases. A successful lint alone does not
  prove extensions, database calls, or application behavior work on that version.

All new fixtures, keys, and service responses must be synthetic. Tests must
remain isolated from production credentials, production data, and live external
services.
