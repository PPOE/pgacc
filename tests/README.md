# Test harness

This directory contains an isolated characterization-test environment for the
legacy pgacc application. It does not use production credentials, production
data, or the production attachment directory.

Run the current harness with:

```bash
sh tests/run.sh
```

The runner currently performs four checks:

1. It syntax-checks every top-level PHP application file in pinned PHP 5.5,
   5.6, and 7.4 containers. It also checks PHP 8.0 for the known failure at
   `functions.php:14` and reports that incompatibility as an expected result.
2. It syntax-checks every top-level PHP application file under the regular
   PHP 7.4 lint container.
3. It installs a data-free copy of the pgacc schema and synthetic fixtures in
   PostgreSQL, then runs database characterization assertions with `psql`.
4. It starts the unchanged application in a disposable PHP 7.4 runtime with
   the PostgreSQL extension and exercises real HTTP routes against the fixture
   database, then runs PHPUnit and prints `functions.php` coverage.

PHP 7.4 is used only as a temporary characterization bridge because the
unchanged application contains syntax removed in PHP 8. It is not a supported
deployment target. The database uses PostgreSQL 17 as the initial replacement
platform target.

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

`write-workflows.php` exercises real HTTP mutations and verifies their database
effects: voucher creation, organization-scoped approval, distinct first and
second reviewers, finalization, the finalized-entry edit lock, revision history,
and root-only recovery. Receipt encryption/upload is deliberately excluded
because the unchanged application requires the removed `mcrypt` extension;
the tests use a synthetic receipt reference instead.

`tests/unit/FunctionsTest.php` runs PHPUnit 9.6 on the PHP 7.4 test runtime and
collects coverage only for `functions.php`. Xdebug 3.1.6 is enabled in that
test-only image. The suite exercises the utility and rendering helpers plus the
two PostgreSQL helpers against the synthetic database. PHPUnit's text report is
printed in CI, and a Clover report is written to `/tmp/functions-clover.xml`
inside the disposable test container. The current suite covers 333 of 334
executable lines (99.70%). The remaining statement is `functions.php:74`,
behind a malformed regular expression on line 73 (`^*...`) that cannot match
any input. A test records that current malformed-regex behavior; reaching the
following statement requires a production fix, which is outside this test-only
change. PHPUnit 9 and Xdebug 3.1 are pinned as a legacy-runtime bridge; neither
is a production dependency.

The PHP 7.4 package repository is archived, so `php-runtime.Dockerfile` pins
the base images and installs `pgsql` from the archived Bullseye repository.
This runtime is only a characterization bridge, not a deployment target.

## Test improvement plan

The current shell and HTTP checks remain useful characterization tests.
PHPUnit 9.6 is pinned for the PHP 7.4 test runtime because newer PHPUnit releases
require PHP 8. PHPUnit 9 is end of life and is a temporary bridge while the
application's PHP 8 syntax blocker remains. The initial PHPUnit suite covers
`functions.php`; the existing shell and HTTP checks have not been migrated.

### Planned slices

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
- [x] Initial syntax probe: all 29 top-level application PHP files pass on PHP
  7.4. On PHP 8.0, parsing first fails in `functions.php:14` because curly
  braces for string offsets were removed. This establishes PHP 8.0 as the
  first failing major version checked; versions before 7.4 have not been
  checked. Higher PHP versions will hit this same syntax blocker until it is
  addressed.
- [x] Older syntax probes: the same files also pass `php -l` on PHP 5.5 and
  5.6. PHP 5.4 and 5.3 were not testable with the available Docker Hub images,
  which use a manifest format rejected by the current Docker engine. This is
  syntax-only evidence and does not establish runtime compatibility on PHP 5.
- [x] Run these syntax checks from `tests/run.sh`, which is also the command
  used by the GitHub Actions Docker harness. The PHP 8.0 incompatibility is an
  expected result until the legacy syntax is addressed, rather than a failing
  CI job.
- [ ] After the syntax blocker is addressed, run the isolated database and
  HTTP suite on successive PHP versions and record runtime failures separately
  from syntax failures. Make the matrix repeatable in CI once the viable range
  is known. A successful lint alone does not prove extensions, database calls,
  or application behavior work on that PHP version.

All new fixtures, keys, and service responses must be synthetic. Tests must
remain isolated from production credentials, production data, and live external
services.
