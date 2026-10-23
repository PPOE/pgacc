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
lane fails CI; it does not cancel the other lanes.

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
   reviewed `tests/known-diagnostics.php` baseline. The PHP 7.4 lane additionally collects
   and checks `functions.php` line coverage.

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
`auto_prepend_file`. It installs an error handler for all diagnostic types and
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
Coverage is collected only on PHP 7.4, for `functions.php`, with Xdebug 3.1.6.
The suite exercises the utility and rendering helpers plus the
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
PHP 5.6 and PHP 7.4 both pass 115 PHPUnit test cases and 138 assertions. PHP 5.6 has an
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
- [ ] Evaluate a mutation testing framework, such as Infection, to replace the
  handpicked mutation runner with broader generated mutations and reporting.
  Check compatibility with the legacy PHP lanes, establish a passing baseline,
  and review surviving/equivalent mutants before choosing a CI threshold.
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
  `functions.php` report in CI. The existing HTTP checks run in a separate
  server process and are not included in this report. Add remote-process
  coverage and a CI artifact when coverage is extended to those checks.
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
