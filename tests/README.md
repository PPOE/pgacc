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

GitHub Actions runs the four application lanes as parallel matrix jobs
on PostgreSQL 17, plus PHP 7.4 on PostgreSQL 9.4 and 18 (see
[PostgreSQL versions](#postgresql-versions)),
alongside a separate syntax compatibility job. A failure in any application
lane fails CI; it does not cancel the other lanes. A separate job compares every
lane with the [PHP 5 reference recordings](#php-5-reference-recordings). A separate, non-blocking `mutation`
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
PostgreSQL 17 as the initial replacement platform target by default; see
[PostgreSQL versions](#postgresql-versions) for the 9.4, 14, and 18 servers.

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
The test server enables `output_buffering=4096`. No redirect depends on it:
login, logout, the login redirect of protected pages, the member search and the
voucher write redirects are all sent before any output (see `http-login.php`,
`http-redirects.php` and `write-workflows.php`).

`http-login.php` checks login and logout responses directly: a valid login
returns 302 with an empty body and `Location: index.php` (or the hex-decoded
`target`, including spaced/lowercase hex and the empty target the real form
posts), numeric `pp_pgacc_login` and `pp_pgacc_id` cookies for the right user
with a 24-hour expiry and no other attributes, the same cookie value in
`users.cookie` with a fresh `users.login`, and cookies that authenticate a
follow-up request. An already logged-in user gets "Login erfolgreich!", or a
redirect to a posted target, without a new login. Rejected logins (wrong
password, unknown user, quotes in user name or password) return 200 with the
form (carrying the URL `target`), no `Location`, no cookies, and no database
change; logout returns 302 with an empty body to `index.php` and clears both
cookies. It then starts a second
built-in server inside the test container with `output_buffering=0` and repeats
the redirects there, so they cannot regress to depending on the buffer.

`http-listing-prefs.php` covers the ledger listing (`?action=all`, rendered by
`closed.php`) with hidden columns. The fixture users `synthetic-hide-odd` and
`synthetic-hide-even` store hidden odd and even columns, so each of the 18
columns is hidden once and shown once. Against the output for
`synthetic-admin` (no preferences), the header, filter row, every voucher row
and the CSV export must show exactly the expected cells: an add icon instead
of the column header, no filter input, an empty cell in HTML, and no field in
CSV. `?hide=-3` and `?hide=1` then show and hide a column for the request and
in the stored preference. Before the fix, the first hidden column crashed the
page with `ArgumentCountError` on PHP 7.1+ and displayed a warning per hidden
cell on PHP 5.6.

`http-redirects.php` checks the other redirects that must precede any output,
on the normal and on a second `output_buffering=0` server: every protected page
(including CSV listings and `?hide=`) answers visitors who are not logged in
with exactly a 302 to `index.php?action=login&target=<hex of the request URI>`
and an empty body, and the member search answers with a 302 to the filtered
ledger, logged in, as CSV, and not logged in. The import page is requested only
after every other protected page has passed, so a missing login check never
makes the test reach its call to the external membership system.

`http-donations.php` covers the public donation list (`?action=donations`,
`donations.php`). It reads the captcha operands and the hidden value from the
form and answers like a browser, so the application's captcha check runs
unchanged; a wrong or missing answer shows a new captcha. With the correct
answer, 2024 (a donor above the 100 € threshold) and 2025 (below it) show the
heading, year links, notice and an empty table: donor publication is disabled
for legal reasons, so no donor name (plain or base64-encoded), row or sum may
appear. Years before 2012 show 2013 without a captcha. Before the fix, the 2024
list read the undefined `$donations` (displayed warnings on PHP 5.6 and 7.4, a
`TypeError` in `array_multisort()` on PHP 8). Requests without `year` are not
tested: `index.php` reads `$_GET["year"]` without `isset()` (see
`COMPATIBILITY_PLAN.md`).

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

`http-smoke.php`, `http-login.php`, `http-redirects.php`, and `write-workflows.php` print every
diagnostic they saw (type, file:line, message, requests) and fail on any that is
not listed in `tests/known-diagnostics.php`. That list is a reviewed baseline:
each entry matches an exact PHP minor version, type, file, line set, message
pattern, and whether it was reported or hidden, with an ID and reason. It
currently contains only issues observed on PHP 5.6 and 7.4, the lanes that
passed before diagnostics were checked, so those lanes keep passing while the
issues stay visible. PHP 8.0 and 8.5 have no entries, so every diagnostic there
fails the lane, including on the unbuffered server. Remove entries when the
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
merges (including one by a user of another organization), and root-only
recovery. The approval, finalization, revision and merge redirects must be a
302 to the voucher's edit page with the exact hint and an empty body, also on
an `output_buffering=0` server. Visitors who are not logged in posting a new
voucher, an approval, a finalization, a revision, or a merge get only the
login redirect, and nothing changes in the database. The fixture users
`synthetic-unit-one` (organization 1), `synthetic-unit-two` (organization 2)
and `synthetic-no-rights` (empty rights) cover the organization scope: the
default organization of vouchers created or revised without one, approvals
and finalization of a voucher with rows in two organizations, approvals of
finalized vouchers, and the approval reset. It uses a synthetic receipt reference;
`tests/http-receipts.php` covers receipt upload, PDF import (PHP 5.6 only:
the locked `smalot/pdfparser` does not load on 7.2+), download and the
`mbapi.php` export against the mcrypt contract vectors in
`tests/fixtures/mcrypt/` (see `tests/unit/CryptoTest.php`).

`tests/unit/FunctionsTest.php` runs under PHPUnit 9.6 on PHP 7.4, PHP 8.0, and
PHP 8.5, and PHPUnit 5.7.27 on PHP 5.6.40. The assertions and data providers are shared; only the
runner bootstrap, lifecycle signature, and two string assertion aliases differ.
Coverage is collected only on PHP 7.4, with Xdebug 3.1.6; the PHPUnit
coverage is configured for `functions.php`, `getusers.php` and `import2.php`
and merged with the HTTP coverage
(see [Coverage](#coverage)). All PHPUnit configurations set
`error_reporting` to `-1` (E_ALL), so notices fail tests on every lane, also
where the runtime's built-in default hides them (PHP 5.6 and 7.4 without
`php.ini`). The same suite runs `tests/unit/InfectionShimTest.php`, the tests
of the Infection class wrapper (see [mutation notes](mutation/README.md)),
and the mcrypt contract tests: `CryptoTest.php` (vectors from
`tests/fixtures/mcrypt/vectors.json`), `GetUsersTest.php` (`getUsers()` with a
stubbed `http://` stream wrapper, rolled back) and `ReceiptImportTest.php`
(the receipt file `import2.php` writes). The suite exercises the utility and rendering helpers plus the
two PostgreSQL helpers against the synthetic database. PHPUnit's text report is
printed in CI, and a Clover report is written to `/tmp/functions-clover.xml`
inside the disposable test container. The current suite covers 349 of 350
executable lines (99.71%). The remaining statement is `functions.php:74`,
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
PHP 5.6 and PHP 7.4 both pass 161 PHPUnit test cases and 256 assertions (121 and
157 of them for `functions.php`, the rest for the Infection wrapper). PHP 5.6 has an
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

All four runtimes pass the 121 `functions.php` PHPUnit tests and 157 assertions
after explicitly initializing the rights helper's organization array and
guarding the helpers against null arguments. The rights assertions no
longer suppress warnings. Tests that pass null (the anonymous `checklogin()`
result, nullable columns) capture every diagnostic with their own error
handler, because PHPUnit 9 only prints deprecations; they fail on PHP 8.1+ if
a helper passes null to an internal function. On both PHP 8 runtimes, the HTTP
and write tests fail on diagnostics that have no known-diagnostics entry:
undefined `$rights`/`$rightssql` (the closed-link error after `relocate()` is
fixed). On PHP 8.0 `http-login.php` and `http-redirects.php` pass and the
write-workflow assertions pass up to the diagnostics check. PHP 8.5 adds
implicit PostgreSQL connection deprecations; displayed before the headers, they
still break the login redirect without output buffering (and are expected to
break the other unbuffered redirects the same way). See
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

`tests/mutation/run.sh <lane> <target>` is a target-driven mutation runner for
any lane, including PHP 5.6. Targets in `tests/mutation/targets/` list exact,
reviewable mutations and the tests that must kill them: PHPUnit filters and/or
HTTP suites. HTTP mutants each get a disposable copy of the application, a
fresh `php -S` with the application service's settings, and a fresh database
from a template, so top-level page code that Infection cannot mutate is
covered too. The `login-logout` target mutates login/logout
handling in `index.php` and `login.php` and the `page_new_buchung()` signature
in `new.php`; on PHP 5.6 and 7.4 it kills 36 of 38 mutants and accepts two
reviewed equivalents.
CI runs the runner's self-tests and the `rights2orgasql` and `null-arguments`
targets on every lane,
and the `login-logout` target on PHP 5.6, 7.4 and 8.0.
See [the runner documentation](mutation/README.md).

## PostgreSQL versions

The PostgreSQL server is a second test axis, selected with `PGACC_TEST_PG`
(default `17`, unchanged behaviour). It combines with every PHP lane and with
`PGACC_TEST_ID`:

```bash
PGACC_TEST_ID=pg94 PGACC_TEST_PG=9.4 sh tests/run.sh 7.4
PGACC_TEST_ID=pg18 PGACC_TEST_PG=18 sh tests/run.sh 7.4
PGACC_TEST_PG=9.4 sh tests/run.sh 5.6
PGACC_TEST_PG=9.4 sh tests/mutation/run.sh 7.4 login-logout
```

| `PGACC_TEST_PG` | Image (pinned by digest in `tests/postgres-version.sh`) | Notes |
|---|---|---|
| `9.4` | `postgres:9.4` (9.4.26, last official build) | Oldest pullable stand-in for production 8.4.20. MD5 authentication only (no SCRAM); `password_encryption` is a boolean (`on`). |
| `14` | `postgres:14-bookworm` | Last major that accepts `8AND` (see `KD-REPORT-SQL-PG15` in the compatibility plan). |
| `17` | `postgres:17-bookworm` | Default; the digest is also the default in `compose.test.yaml`. |
| `18` | `postgres:18-bookworm` | Data directory is `/var/lib/postgresql/18/docker`; the tmpfs moves to `/var/lib/postgresql`. Records NOT NULL as `pg_constraint` rows. |

`postgres:8.4` and `postgres:9.0` images use a manifest format that current
Docker engines no longer pull, so 8.4 itself cannot be tested here.

`tests/postgres-version.sh` maps the version to the image, the data tmpfs and
the MD5 `password_encryption` value, which `compose.test.yaml` and
`compose.php56.yaml` read as `PGACC_TEST_PG_*` variables (their defaults are
the 17 settings, so plain `docker compose` calls are unchanged). The
`database-test` service uses the same image, so `psql` always matches the
server. A non-default version appends `-pg94`, `-pg14` or `-pg18` to the
Compose project names, the locally built image tags, and the mutation result
directory, so several versions (and IDs) can run concurrently; for example
`PGACC_TEST_ID=pg94 PGACC_TEST_PG=9.4 sh tests/run.sh 7.4` uses the project
`pgacc-test-pg94-pg94-php74`. Remove the tagged images with `docker rmi` when
no longer needed. Authentication per lane: PHP 7.4/8.0/8.5 use the server
default (MD5 on 9.4, SCRAM on 14+); PHP 5.6 always uses MD5.

Test infrastructure adaptations, all valid on 9.4 through 18:

- `database-smoke.sh` includes the three SQL files from one generated `\i`
  script, because `psql` before 9.6 silently runs only the last `--file`.
- `database-characterization.sql` looks up the `public` namespace in
  `pg_namespace` instead of `'public'::regnamespace` (9.5+), ignores
  PostgreSQL 18's NOT NULL constraint rows (`contype = 'n'`) and checks NOT
  NULL columns through `pg_attribute.attnotnull` instead, which is what 8.4
  uses as well. Its comparisons use `IS DISTINCT FROM`: with `<>`, an empty
  result (`NULL`) passed silently, which is how the 9.4 `--file` problem first
  went unnoticed in the table check.
- The mutation runner uses `DROP DATABASE ... WITH (FORCE)` only on 13+; on
  older servers it terminates the database's other backends with
  `pg_terminate_backend()` first.
- `pgcrypto` (`crypt()`, `gen_salt('md5')`) is available as an extension in
  all four images; production 8.4 installs it from contrib, with the same
  MD5-crypt hashes.

Coverage is collected only on PHP 7.4 with PostgreSQL 17; other PostgreSQL
versions run the 7.4 lane without `compose.coverage.yaml`.
`tests/known-diagnostics.php` has no PostgreSQL-specific entries: every lane
produced the same diagnostics on 9.4, 14, 17 and 18 (see
[COMPATIBILITY_PLAN.md](COMPATIBILITY_PLAN.md#postgresql-version-matrix)). If
a version-specific diagnostic appears, add a `pg` key to that entry in the style
of the `php` key and teach `tests/http-diagnostics.php` to match it, with tests.

**Limit: 9.4 is not 8.4.** Differences that the 9.4 server cannot reproduce
include `standard_conforming_strings` (off by default on 8.4, on from 9.1:
backslashes in string literals built with `pg_escape_string()`), the 8.4
planner, and `bytea_output` (hex from 9.0), and
`DO`/`CREATE EXTENSION` (9.0/9.1; the characterization script itself needs
9.0+). Read-only checks on production that could close this gap, to be run
only by the owner, are proposed in the compatibility plan; nothing has been
run against production.

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
`http-login.php`, `http-redirects.php` and `write-workflows.php` start inside the
test container only load the diagnostics recorder; their requests are not
included in the coverage.

Limitations: coverage is measured on PHP 7.4 only; code that behaves
differently on other runtimes is not measured there. Line coverage shows that a
line ran, not that its result was asserted; mutation testing remains the check
for assertion strength.

## PHP 5 reference recordings

PHP 5 support ends with the PHP 5.6 lane (new floor: 7.4). Before that lane
goes, `tests/reference/` pins the current PHP 5 behaviour as committed
reference data (a golden master) recorded on PHP 5.6, and compares the other
lanes against it:

```bash
sh tests/reference/record.sh                      # PHP 5.6: rewrite tests/reference/php5/
PGACC_TEST_ID=ref sh tests/reference/run.sh compare 7.4   # or 5.6, 8.0, 8.5
```

`run.sh` builds the lane's image, adds a reference image on top
(`runtime.Dockerfile`: libfaketime, and on PHP 5.6 the `mcrypt` extension that
production PHP 5 has), and starts the lane's services plus a local membership
stub (`tests/reference/compose.yaml`). `PGACC_TEST_ID` and `PGACC_TEST_PG`
work as for `tests/run.sh`; remove the `pgacc-test-reference-*` images
afterwards. A recording has:

- `http/<step>.txt`: status, headers (without Date, Host, Connection,
  X-Powered-By) and body of each of the ~300 requests in `scenario.php`:
  every page and action, public report years, listings with every filter and
  sort, CSV exports, edit views, captcha posts and the voucher, approval,
  merge, recovery and account workflows, as visitor and as seven synthetic
  users with different rights. Binary bodies are stored as base64.
- `sql/<step>.sql`: the request's SQL statements and SQL errors, from
  PostgreSQL's CSV statement log (`log_statement=all`). The application server
  sets `application_name=ref:<request id>` per request through `PGAPPNAME`
  (`runtime/prepend.php`, which chains the diagnostics recorder).
- `db/<step>.txt`: row changes (`-`/`+`) of every table that the request's
  statements wrote, plus sequences; `db/initial.txt` and `db/final.txt` are
  full sorted dumps.
- `helpers/*.txt`: the helper golden master (`helpers.php`): the helpers of
  `functions.php` and page files, and probes of value-handling idioms copied
  from the application (amount parsing, money formatting, review
  percentages, loose comparisons, sorting), over a generated deterministic
  corpus of numbers, numeric strings, `''`, `null`, `'1e3'`, `' 12'`, UTF-8 and
  ISO-8859 bytes, floats and 64-bit limits.
- The imports: synthetic BAWAG, ISO-8859-15, Handkassa, Sparkasse and PayPal
  CSV files (`import.php`) and generated PDF bank statements (`import2.php`,
  `fixtures/make-pdf.php`), receipt upload and download (`new.php`, `file.php`),
  `mbapi.php` and the member-fee page. `getusers()` fetches the member list
  from `mitglieder.piratenpartei.at`, which the Compose network alias resolves
  to the stub (`membership/router.php`); `membership-stub.txt` records what it
  received. The stub serves `membership/adm_names.b64`, synthetic members
  encrypted the way `getusers.php` decrypts them, with the synthetic key in
  `runtime/reference-constants.php` (`membership/generate.php` regenerates it).
- `diagnostics/*.txt`: PHP diagnostics per request (informational).

Nondeterminism is removed explicitly (details in `tests/reference/lib.php`):
the application server runs with a fixed "now" (2026-06-15 10:00 UTC,
libfaketime), Xdebug off and `display_errors=0`; login cookie values, cookie
expiry times of day, captcha numbers (after checking the hidden answer), the
impressum's PHP/PostgreSQL versions, PostgreSQL's own timestamps
(`users.login`, `vouchers.save_date`) and md5-crypt salts are replaced by
placeholders. `fixtures/extra.sql` adds reference-only synthetic rows and
resets the sequences. A second PHP 5.6 run reproduces the recording byte for
byte (`compare 5.6`).

`compare.php` fails on every difference unless each changed line is accepted
by a reviewed entry in `tests/reference/allowlist.php` (ID, PHP versions, file
glob, line pattern, reason), in the style of `known-diagnostics.php`; unused
entries fail as stale. `diagnostics/` differences are reported but do not fail,
because the lanes already gate diagnostics. The report and per-file diffs go
to `build/reference/php<lane>/`. `selftest.php` tests the allowlist logic and
the normalizations before every run. The CI job `PHP 5 reference comparison`
runs `compare` on all four lanes and uploads the reports.

Re-record (and review the diff) when an intended behaviour change lands; the
reference was re-recorded after the rights and donations fixes and is to be
re-recorded after the mcrypt change. Production runs PHP 5.3.3, which cannot be tested here; 5.6 is the
oldest available stand-in.

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
  5.3-8.5 (production runs PHP 5.3.3; the oldest runtime lane is 5.6) plus
  `Generic.PHP.DisallowShortOpenTag`. No formatting rules.
- **PHPStan** level 5 (`phpstan-common.neon`): undefined and possibly undefined
  variables (levels 0/1), unknown functions, argument counts and types, invalid
  regular expressions. `phpVersion` is 7.1-8.5 because PHPStan cannot model PHP
  below 7.1; PHPCompatibility covers the 5.3 floor. PHPStan reads the locked
  `smalot/pdfparser` and `phpseclib` sources.

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
parameter and a short open tag. A short-array `[]` (PHP 5.4) and a `??`
(PHP 7.0) canary guard the 5.3 floor of `testVersion`. `canaries/expected.txt` names the finding each tool must
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
  the target-driven runner covers that lane. A non-blocking CI job uploads the
  reports.
- [ ] Close the real Infection test gaps listed in the mutation notes (mainly
  `acc_header` output and `getfilter` validation), then gate CI on no new
  escaped mutants.
- [x] Report notices in PHPUnit on PHP 5.6 and 7.4 (`error_reporting=-1`).
- [x] Test the Infection class wrapper (100% lines and branches; mutated with
  `PGACC_INFECTION_TARGET=shim`).
- [x] Generalize the hand-picked `rights2orgasql` runner into a target-driven
  runner (`tests/mutation/run.sh`) that also mutates code reached only through
  the HTTP tests, on every lane including PHP 5.6.
- [x] Close the `login-logout` survivors: assert login cookie expiry and add a
  rejected login with a user name that needs SQL escaping.
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
  isolated test network and must not contact the live service. Done for
  PHPUnit: `tests/unit/GetUsersTest.php` replaces the `http://` stream wrapper
  and decrypts a synthetic export with the mcrypt-compatible helpers; the HTTP
  import pages still need a stub inside the test network.
- [x] Add a contract test for `mbapi.php` using synthetic PostgreSQL rows and
  test-only encryption keys. Assert the checksum and response framing, decrypt
  the payload, and cover the amount threshold, date cutoff, acknowledgement,
  deleted-voucher filtering, and per-member totals. Done in
  `tests/http-receipts.php` (the test `$datakey` is empty; the mcrypt vectors
  use a synthetic key).
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
- [x] Send the login/logout cookies and redirects before rendering and turn
  the `output_buffering=0` known failures into regular checks.
- [x] Characterize the remaining redirects sent after output (protected
  pages for visitors, member search, voucher write redirects) with
  `output_buffering=0` known failures, then send them before any output and
  turn the known failures into regular checks (`http-redirects.php`,
  `write-workflows.php`).
- [x] Read HTTP response headers with `http_get_last_response_headers()` when
  available, falling back to `$http_response_header` on PHP 5.6-8.3.
- [ ] Apply the same strictness to the PHPUnit process: PHP 8.5 currently
  prints implicit-connection deprecations from `functions.php:434` and `:494`
  during the PHPUnit run without failing it.
- [ ] Shrink `tests/known-diagnostics.php` to empty as the application issues
  are fixed, and then report unused entries as failures. `KD-PREFS-HIDE`,
  `KD-REPORT-PREVCOND` and `KD-EDIT-RELOCATE-CLOSED-LINK` are fixed and removed;
  see `COMPATIBILITY_PLAN.md`.
- [x] Test listings with hidden columns (`http-listing-prefs.php`) and fix the
  `emptytag()` `ArgumentCountError` on PHP 7.1+ and the undefined
  `$user_prefs_hide` offsets, with output unchanged for users without hide
  preferences.
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
