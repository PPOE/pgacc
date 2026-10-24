# Test harness

An isolated characterization-test environment for the legacy pgacc
application. It uses no production credentials, data or attachment directory.
The change workflow and the verification order are in
[AGENTS.md](../AGENTS.md#verification-cadence): focused tests while editing,
one readiness batch for the finished branch, the rest in CI.

## Running the harness

```bash
sh tests/run.sh                       # the PHP 8.5 / PostgreSQL 18 lane (same as: sh tests/run.sh 8.5)
PGACC_TEST_ID=worktree-a sh tests/run.sh
```

The lane gets a fresh synthetic PostgreSQL database. After the 1.0.0 release
(tag `php5-last`) the application supports and tests only PHP 8.5 and
PostgreSQL 18 (owner decision, 2026-10-25):

| Lane | Role |
|---|---|
| PHP 8.5 / PostgreSQL 18 (`compose.test.yaml` + `compose.coverage.yaml`) | The only lane: coverage and the changed-line gate, mutation runner, Infection, PHP 5 reference comparison. See [PHP 8.5 lane](#php-85-lane). |

The PHP 5.3 / PostgreSQL 8.4 production-pair lane, the PHP 7.4 lanes (on 18
and 8.4), the PHP 5.6 client image and the PostgreSQL 8.4 image were removed
after 1.0.0; a 1.0.x hotfix needs the harness of the `php5-last` tag. Static
analysis (PHPCompatibility 8.5-) and the lane's `php -l` of every top-level
file replace a separate syntax matrix.

`PGACC_TEST_ID` (lowercase letters, digits, `_`, `-`) is appended to the
Compose project names and image tags, so concurrent runs (worktrees, agents)
share no containers, networks, databases or images. Without it the names are
`pgacc-test-php85`, `pgacc-test-application`, ... Tagged images stay behind;
remove them with `docker rmi`.

The lane:

1. runs `php -l` on every top-level application file under PHP 8.5;
2. loads the data-free schema and synthetic fixtures and runs
   `database-characterization.sql` with `psql`;
3. starts the application and runs the HTTP tests, the PHPUnit suite and the
   write workflows. HTTP checks fail on any PHP diagnostic that is not in
   `tests/known-diagnostics.php`. Every group is attempted; the lane fails
   if any failed. It also collects [coverage](#coverage).

### Focused tests

While developing a red test or a fix, run only the affected PHPUnit method or
HTTP script against a warm setup instead of a whole lane. Example on the
PHP 8.5 / PostgreSQL 18 configuration (pick your own ID and test):

```sh
export PGACC_TEST_ID=focus-receipt
export PGACC_TEST_SUFFIX=-${PGACC_TEST_ID}
p="--project-name pgacc-focus-${PGACC_TEST_ID} -f compose.test.yaml"

docker compose $p up --detach --wait database
docker compose $p run --rm database-test          # load (or reset) the fixtures
docker compose $p build application               # after EVERY source or test edit
docker compose $p run --rm --no-deps application env XDEBUG_MODE=off \
  php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result \
  --filter ReceiptStorageTest::testConfiguredKeyIsTheValidGlobalKey
docker compose $p down --volumes --remove-orphans # when done
```

- The source is baked into the image. **Rebuild `application` after each
  edit**; a running container still tests the old code.
- Rerun `database-test` between scenarios that change the database, not
  between tests that roll back their own transactions.
- For an HTTP script: rebuild, start or recreate `application`, then run the
  script through `application-test` (e.g.
  `docker compose $p run --rm application-test php tests/http-login.php`).
  The HTTP scripts have no filter option; do not invent one.
- A red test must fail on an assertion. A broken fixture, connection, parse
  error or fatal setup error is not red evidence.
- The full lane stays the authoritative check. Remove only your own image
  tags afterwards.

## What the tests cover

`fixtures/schema.sql` was reconstructed from a read-only inspection of the
production PostgreSQL 8.4.20 catalog (tables, columns, defaults, sequences,
constraints; no backup table, owners, permissions, credentials or rows).
`fixtures/data.sql` holds only visibly synthetic records.

- `database-characterization.sql`: password hashing, open and closed voucher
  states, attachment propagation, public transactions and spendings, donation
  totals, report type mapping and review-progress counts.
- `http-smoke.php`: public report, transactions, spendings, rejected and
  successful login, an authenticated ledger request.
- `http-login.php`: a valid login returns 302 with an empty body and
  `Location: index.php` (or the hex-decoded `target` if it is a page of the
  application; other targets, also absolute URLs, `//`, backslashes and line
  breaks, lead to `index.php`), an HMAC `pp_pgacc_login` token and the numeric
  `pp_pgacc_id` (24-hour expiry, `secure`, `HttpOnly`, `SameSite=Lax`), a
  random nonce in `users.cookie` with a fresh `users.login`, and cookies that
  authenticate a follow-up request; wrong, old-format, other users' and
  expired tokens are rejected. A logged-in user gets "Login erfolgreich!" or
  the posted target. Rejected logins (wrong password, unknown user, quotes in
  user name or password) return 200 with the form, no `Location`, no cookies
  and no database change. Logout (POST with the form token) returns an empty
  302 to `index.php`, clears both cookies and ends the session on the server;
  a GET request or a request without a session ends nothing.
- `http-session-lifecycle.php`: session invalidation (logout, also a token
  computed for the cleared nonce; a new login replaces the form token and
  logout ends it; own and root password changes end that user's sessions,
  rights-only changes keep them). `php
  tests/http-session-lifecycle.php logout|password` runs one scenario for
  focused red evidence; reset the fixtures after a red failure.
- `http-redirects.php`: every protected page (also CSV listings and `?hide=`)
  answers visitors with exactly a 302 to
  `index.php?action=login&target=<hex of the request URI>` and an empty body;
  the member search redirects to the filtered ledger. The import page is
  requested last.
- `http-import.php`: the bank CSV import (`import.php`) with the synthetic CSV
  files of `tests/reference/fixtures/import/`. Lines whose date is not a date
  or whose organization the user may not use are reported ("Ungültige
  Buchungszeile", line number) and skipped; the other lines and files are
  imported. The `/g` regular-expression warning of every imported line is the
  known failure `KF-IMPORT-REGEX-G`.
- `http-listing-prefs.php`: the ledger (`?action=all`, `closed.php`) with hidden
  columns. `synthetic-hide-odd`/`synthetic-hide-even` hide each of the 18
  columns once; header, filter row, voucher rows and CSV must match the output
  for `synthetic-admin`. The column buttons (POST `hide=-3`/`hide=1` with the
  form token) change the stored preference; the old GET `?hide=` does not.
- `http-csrf.php` (last in every lane): form tokens. For every write action
  (voucher creation, revision, approval, approval reset, receipt upload,
  merge, recovery, accounts, both imports, column preferences, logout) a
  missing, a wrong and another session's token return 200 with "FEHLER:
  Ungültiges Formular-Token" and change no table or sequence; GET merge,
  recovery, `?hide=` and logout write nothing; the forms carry the token of
  their session; the valid token works; a new login replaces the token. The
  HTTP helper adds the session's token to every POST with session cookies
  (`session_csrf_token()`, read from the user list) unless a test sets
  `csrf_token` itself (`null`: none).
- `http-donations.php`: the donation list answers the captcha like a browser.
  2024 (above the 100 € threshold) and 2025 show heading, year links, notice
  and an empty table; no donor name (plain or base64), row or sum may appear
  (publication is disabled for legal reasons). Years before 2012 show 2013.
  Requests without `year` are not tested (see `COMPATIBILITY_PLAN.md`).
- `write-workflows.php`: voucher creation, organization-scoped approval,
  distinct first and second reviewers, the removed finalization (a POST with
  `beleg` changes nothing), the finalized-entry edit lock, the approval reset,
  revisions, merges (also by a user of another organization) and root-only
  recovery, with their database effects. Approval, revision and merge
  redirects must be an empty 302 to the voucher's edit page with the exact
  hint; visitors get only the login redirect and change nothing. `synthetic-unit-one`,
  `synthetic-unit-two` and `synthetic-no-rights` cover the organization scope.
- `http-receipts.php`: receipt upload, PDF import (skipped: the locked
  `smalot/pdfparser` does not load on PHP 7.2+; it ran on the former PHP 5
  lanes), download of `<n>.v2.aes`
  and legacy `<n>.aes` (headers, legacy fallback, error cases, merges), the
  migration script's 404 and the `mbapi.php` export. It reads and writes the
  server's `files/` through a volume shared with the client.

The membership system never leaves the test network: `compose.test.yaml` runs
the reference stub (`tests/reference/membership/router.php`) as the service
`membership` under the alias `mitglieder.piratenpartei.at`, and
`tests/runtime/test-constants.php` sets the synthetic `$datakey` of its member
list. The application service waits for it.

All test servers run with `output_buffering=4096`, but no redirect depends on
it: `http-login.php`, `http-redirects.php` and `write-workflows.php` repeat
their redirects on a second server with `output_buffering=0` started inside
the test container.

### PHPUnit

`tests/unit/` runs on PHPUnit 9.6 (PHP 8.5). Some tests still avoid
`finally` and other PHP 5.4+ constructs from the time they also ran on PHPUnit
4.8 / PHP 5.3; that is not a requirement any more. Every configuration sets
`error_reporting=-1`, so notices fail tests. The suite covers `functions.php` (utility,
rendering and the two PostgreSQL helpers), `InfectionShimTest.php` (the
Infection wrapper, see [mutation notes](mutation/README.md)), `CryptoTest.php`
(vectors in `tests/fixtures/mcrypt/vectors.json`), `GetUsersTest.php`
(stubbed `http://` stream wrapper), `ImportMemberMatchTest.php`,
`ReceiptImportTest.php` (the import's `<n>.v2.aes`, refusals without a valid
key), `ReceiptStorageTest.php` (v2 format against `tests/fixtures/receipt-v2/`,
short reads via `ShortReadStream.php`, and the 20 MB memory check
`tests/receipt-memory.php`), `ReceiptMigrationTest.php` and
`ReceiptMigrationCommandTest.php` (`migrate-receipt-key.php` on synthetic
files) and `ShortOpenTagTest.php`. Tests that pass null capture
every diagnostic with their own error handler, because PHPUnit 9 only prints
deprecations. The suite also passes in random order, as Infection runs it.

`functions.php:74` is unreachable: the regular expression on line 73 (`^*...`)
is malformed and cannot match; a test records that behaviour. PHPUnit 9 and Xdebug are test tools, not
production dependencies.

`http-output-escaping.php` runs last and covers the output encoding: it
adds synthetic organizations, types, a user and vouchers whose text columns
contain markup, quotes, `&` and umlauts, then checks that the ledger
listing, the voucher form, the user list, the public report, campaign,
transaction, spending and virtual-account pages, the account report, the
membership-fee page and the import reports show them as text
(`&lt;s&gt;...`), that ordinary values and umlauts are unchanged, that
reflected filter values, sort links and the CSV link are encoded, and that
the CSV export keeps the raw text, prefixes formula and quoted cells (`=`,
`+`, `-`, `@`, `"`, also after spaces) with `'` and keeps tabs and line
breaks from splitting cells. Forms shown again from posted values keep
quotes and backslashes as entered, and saving stores them unchanged. It removes its rows afterwards. Pages
with known diagnostics outside its scope are attributed to the known
failures `KF-AMOUNT-FILTER-REGEX`, `KF-WK-UNDEFINED-YEAR`, `KF-NEW-UNDEFINED-BGFANDACK`, `KF-ACCOUNTS-QUERY-ERROR` (the warning of the deliberately failing user query) and
`KF-REPORT-UNDEFINED-UNIT` (see `COMPATIBILITY_PLAN.md`), which must still
occur.

### PHP diagnostics are failures

Every HTTP test server loads `tests/runtime/diagnostics-prepend.php` with
`auto_prepend_file` (through the coverage prepend on the lane). It records every
diagnostic, including fatal errors, notices and deprecations that the runtime
hides and `@`-silenced ones (marked "hidden"), to a per-request JSON-lines file
on a shared tmpfs (`PGACC_TEST_DIAGNOSTICS_DIR`; the server's `files/` is
shared the same way for `http-receipts.php`), then returns `false` so PHP's
handling continues. `error_reporting`, `display_errors` and output buffering
are unchanged. Each request carries an `X-Pgacc-Test-Request` ID; the client
fails if the recorder did not complete for it, and cross-checks the body for
displayed diagnostics missing from the log.

The HTTP scripts print every diagnostic they saw and fail on any that is not
in `tests/known-diagnostics.php`. That reviewed baseline matches an exact PHP
minor version, type, file, line set, message pattern and reported/hidden, with
an ID and reason. It is currently empty, and PHP 8.5 never gets entries. Remove
entries when the application is fixed; never add one without review. On PHP
8.5, OPcache reports compile-time deprecations only on the first request that
compiles a file.

The test client also fails on its own unsilenced diagnostics. It reads response
headers with `http_get_last_response_headers()` (PHP 8.4+), else with
`$http_response_header` in `tests/http-legacy-headers.php`, which is not
included on PHP 8.5 (deprecated at compile time).

### PHP 8.5 lane

`tests/php-runtime.Dockerfile`: `php:8.5-cli` (Trixie, digest-pinned) with
`pgsql` and Xdebug 3.5.3 (SHA-256 pinned, `xdebug.mode=off` unless
`compose.coverage.yaml` sets `XDEBUG_MODE=coverage`). `tests/application-tests.sh`
runs the HTTP, PHPUnit and write-workflow groups even if one fails, and fails
if any group failed. Assertions are not relaxed for PHP 8. The lane passes and
the CI job is blocking. The PDF import is still skipped (locked
`smalot/pdfparser` 0.9.23; see `COMPATIBILITY_PLAN.md`, "PHP 8.5-only
migration", step b).

### Mutation testing

`tests/mutation/run.sh 8.5 <target>` mutation-tests code against the
PHPUnit and HTTP suites, also top-level page code that Infection cannot
mutate; `tests/mutation/infection.sh` runs Infection on `functions.php`. See
the [mutation README](mutation/README.md).

## PostgreSQL

The lane runs `postgres:18-bookworm` (digest in `compose.test.yaml`), the only
supported server; `PGACC_TEST_PG` and the PostgreSQL 8.4 image
(`tests/postgres84/`) were removed after 1.0.0. `tests/database-smoke.sh`,
`database-characterization.sql`, the mutation runner (`tests/mutation/lib.php`)
and the reference statement log still carry inert pre-9.x branches selected by
`server_version_num`; removing them is a later cleanup.

## Continuous integration

`.github/workflows/tests.yaml` follows the owner's cost policy. A `plan` job
chooses a per-push or full run and the comparison base: `github.event.before`
for pushes and pull request updates, else `origin/<default branch>` or
`origin/<PR base>`; a force-pushed `before` that no longer exists falls back
to the default branch. Only pushes to `master` trigger push runs; other
branches run through their pull request, so each push starts one run. A pull
request gets a full run only while it carries the `full-ci` label (add it once
before merging to `master`); with the `mutation` label it also gets the
changed-line mutation job. Without a label a pull request run is the tests,
the coverage gate, the reference comparison and static analysis.

| Job (name in GitHub) | Every push / pull request update | Weekly (Mon 03:17 UTC), manual, `full-ci` pull requests |
|---|---|---|
| `Plan (full or per-push run)` | yes | yes |
| `Static analysis (PHPCompatibility, PHPStan)` | yes | yes |
| `PHP 8.5 / PostgreSQL 18 characterization harness` (coverage gate on changed lines) | yes | yes |
| `Mutation tests (PHP 8.5 / PostgreSQL 18)` (`tests/mutation/ci.sh`, blocking) | only with the `mutation` label: changed lines (`--changed-since=<base>`); skipped otherwise | every target |
| `PHP 5 reference comparison (PHP 8.5)` | yes | yes |
| `Infection mutation report (functions\|shim, PHP 8.5, non-blocking)` | no | yes |

The coverage gate is off only on scheduled and manual runs, which have no
base. CI keeps the default branch coverage (`PGACC_COVERAGE_BRANCHES=1`).

## Coverage

`tests/run.sh` adds `compose.coverage.yaml` and measures all 30 top-level
application files:

- The application server runs with `auto_prepend_file=tests/coverage/prepend.php`
  and `XDEBUG_MODE=coverage` (Xdebug 3.5.3). Per request the prepend starts Xdebug
  line and branch coverage and, after the application and its shutdown
  functions, writes the raw data to a project-scoped volume. It prints
  nothing, sends no headers and defines no globals.
- PHPUnit writes its coverage (with branch data, `--path-coverage`) in
  php-code-coverage's serialized format.
- `tests/coverage/merge.php` merges both with the locked php-code-coverage
  9.2, prints a per-file line/branch table, and writes `build/coverage/`
  (override with `PGACC_COVERAGE_HOST_DIR`): `summary.txt`, `clover.xml`,
  `html/index.html`, `coverage.cov` and `uncovered-branches.txt`. Unreached
  files count with zero coverage; the test-only `constants.php` and `tests/`
  are excluded.
- CI publishes the summary on the job page and uploads the `coverage-php85`
  artifact.

Branch coverage makes each request several seconds slower.
`PGACC_COVERAGE_BRANCHES=0` keeps line coverage and the changed-line gate but
skips branch data; use it for local readiness runs. Path coverage is discarded
(8341 paths in `functions.php` alone). Branch totals count branches inside
functions only; script-level code is covered by lines and listed in
`uncovered-branches.txt` as `{main}`.

Only one `auto_prepend_file` is possible, so the coverage prepend chains the
diagnostics recorder (`PGACC_COVERAGE_CHAINED_PREPEND`), whose shutdown
functions run before coverage is written. `compose.coverage.yaml` replaces the
whole application command: keep it identical to `compose.test.yaml` apart from
`auto_prepend_file`. Requests to the second `output_buffering=0` servers are
not covered.

Coverage is measured on the PHP 8.5 lane. A covered line ran; mutation testing
checks that its result was asserted.

### Proving coverage of changed lines

Application changes must cover every changed executable line:

```bash
PGACC_TEST_ID=mine PGACC_COVERAGE_BRANCHES=0 PGACC_COVERAGE_BASE=<real PR base> sh tests/run.sh
```

Use the branch the pull request targets as the base (e.g.
`origin/test/php56-runtime`), not just `HEAD~1`, which proves only the last
commit. `tests/coverage/changed-lines.sh <base>` lists the lines changed since
the merge base, including uncommitted and untracked `*.php` files, as
`file:start-end` ranges. `tests/coverage/check-changed-lines.php` then fails
the lane if any of them is executable and unexecuted. Non-executable lines are
ignored and paths outside the scope (`tests/`, non-PHP files) skipped; a
changed top-level PHP file missing from the report is an error. Uncovered
branches on changed lines are warnings (`PGACC_COVERAGE_STRICT_BRANCHES=1`
makes them fail).

Check the log: each changed file must print
`<file>: N changed executable lines, all covered`. An exit status of 0 without
that line means the gate did not run on your change.

The checker also runs on its own (PHP 5.6+ with SimpleXML) on any ranges:

```bash
sh tests/coverage/changed-lines.sh origin/main >changes.txt
php tests/coverage/check-changed-lines.php \
  --branches=build/coverage/uncovered-branches.txt \
  build/coverage/clover.xml changes.txt
printf 'functions.php:12-40\nindex.php:27,31-33\n' |
  php tests/coverage/check-changed-lines.php build/coverage/clover.xml -
```

## PHP 5 reference recordings

`tests/reference/php5/` pins PHP 5 behaviour as a golden master. It was
recorded on the production pair, PHP 5.3 with PostgreSQL 8.4, before the
`php5-last` tag and is kept as the **historical reference**: that lane no
longer exists, so it cannot be re-recorded (`record.sh` was removed). The PHP
8.5 / PostgreSQL 18 lane is compared against it with the existing allowlist:

```bash
PGACC_TEST_ID=ref sh tests/reference/run.sh compare 8.5
```

`run.sh` builds the lane's image plus a reference image (`runtime.Dockerfile`,
libfaketime) and starts the lane's services and a membership stub
(`tests/reference/compose.yaml`). `PGACC_TEST_ID` works as
for `tests/run.sh`; remove the `pgacc-test-reference-*` images afterwards. A
recording has:

- `http/<step>.txt`: status, headers (without transport details: Date, Host,
  Connection, X-Powered-By, Server, a Content-Length equal to the body length)
  and body of the ~300 requests in `scenario.php`: every page and action,
  report years, listings with every filter and sort, CSV exports, captchas and
  the voucher, approval, merge, recovery and account workflows, as visitor and
  seven synthetic users. Binary bodies are base64.
- `sql/<step>.sql`: the request's SQL statements and errors from PostgreSQL's
  CSV statement log. The server sets `application_name=ref:<request id>`
  through `PGAPPNAME` (`runtime/prepend.php`). PostgreSQL 8.4 has no
  `application_name`: there the recorder attributes statements by connection
  from the application server (one request at a time).
- `db/<step>.txt`: row changes (`-`/`+`) of every written table and sequences;
  `db/initial.txt` and `db/final.txt` are full sorted dumps.
- `helpers/*.txt`: `helpers.php` runs the `functions.php` and page helpers and
  copied value-handling idioms (amounts, money format, percentages, loose
  comparisons, sorting) over a deterministic corpus (numeric strings, `''`,
  `null`, `'1e3'`, `' 12'`, UTF-8 and ISO-8859 bytes, floats, 64-bit limits).
- Imports: synthetic BAWAG, ISO-8859-15, Handkassa, Sparkasse and PayPal CSV
  (`import.php`), generated PDF statements (`import2.php`,
  `fixtures/make-pdf.php`), receipt upload/download, `mbapi.php` and the
  member-fee page. `mitglieder.piratenpartei.at` resolves to the stub
  (`membership/router.php`) inside the network; it serves
  `membership/adm_names.b64` (synthetic members, key in
  `runtime/reference-constants.php`, regenerated by `membership/generate.php`)
  and `membership-stub.txt` records what it received.
- `diagnostics/*.txt`: PHP diagnostics per request (informational).

Nondeterminism is removed explicitly (`tests/reference/lib.php`): a fixed
"now" (2026-06-15 10:00 UTC, libfaketime), Xdebug off, `display_errors=0`, and
placeholders for cookie values and expiry times, captcha numbers, the
impressum's versions, PostgreSQL timestamps (`users.login`,
`vouchers.save_date`) and md5-crypt salts. `fixtures/extra.sql` adds
reference-only rows. While the PHP 5.3 lane existed, a second run reproduced
the recording byte for byte.

`compare.php` fails on every difference unless a reviewed entry in
`tests/reference/allowlist.php` (ID, PHP versions, optional `pg`, file glob,
line pattern, reason, category) accepts it; unused entries fail as stale.
`accepted` is an expected environment difference (`default_charset`, cookie
`Max-Age`/expiry format, 32- vs 64-bit integers); `todo` is a defect to fix
later (PDF import on 7.2+, PHP 8 TypeErrors, exponent and hex numeric
strings). `todo` entries come first so they take precedence over the broad
32-bit entry. `diagnostics/` differences are reported, not failed. Reports go
to `build/reference/php<lane>/`; `selftest.php` tests the allowlist and
normalizations first. CI compares PHP 8.5 (PostgreSQL 18). Entries that
list `7.4` besides `8.5` are kept as reviewed; only the `8.5` part applies now.

Re-baselining the reference on PHP 8.5 is a later, separate step, once every
difference from `php5/` is explained (`COMPATIBILITY_PLAN.md`, "PHP 8.5-only
migration", step d).

## Static analysis

```bash
sh tests/static-analysis.sh                     # check against the baselines (CI)
sh tests/static-analysis.sh --report            # every finding, baselines ignored
sh tests/static-analysis.sh --update-baselines  # rewrite both baselines
```

It runs in its own image (`tests/static-analysis/Dockerfile`): pinned
`php:8.5-cli` with tools from `tests/static-analysis/composer.lock`
(PHP_CodeSniffer 4.0.4, PHPCompatibility 10.0.0-alpha2, PHPCSUtils 1.2.3,
PHPStan 2.3.1, its PHAR SHA-256 checked). Only the build needs network; the
analysis has no network, a read-only repository mount and root filesystem. It
analyses a copy of the 30 tracked top-level application files (no `vendor/`,
`tests/`, `files/`, `proxy/`), with `tests/runtime/test-constants.php` as
`constants.php` and the locked `vendor/`, so `require()` paths resolve.
`PGACC_TEST_ID` suffixes the image name.

- **PHPCompatibility** (`phpcs.xml`): `testVersion` 8.5- plus
  `Generic.PHP.DisallowShortOpenTag`. No formatting rules.
- **PHPStan** level 5 (`phpstan-common.neon`): undefined and possibly undefined
  variables, unknown functions, argument counts and types, invalid regular
  expressions. `phpVersion` is 8.5. It reads the locked `smalot/pdfparser` and
  `phpseclib` sources.

The check fails on any finding not in a baseline, and on a baseline entry that
is no longer reported or occurs a different number of times, so a fix must
remove its entry. `phpcs-baseline.txt` lists file, sniff and message per
finding; `phpstan-baseline.neon` is PHPStan's format (message, identifier,
count, path). Neither uses line numbers. Every entry is explained in
[COMPATIBILITY_PLAN.md](COMPATIBILITY_PLAN.md#static-analysis-findings). Review
every `--update-baselines` diff like code. Fixing a finding that is also a
`tests/known-diagnostics.php` entry removes both.

A canary self-test runs first. `tests/static-analysis/canaries/` holds minimal
copies of the bug classes found so far (`$ascii{$i}`, an uninitialized array,
result-loop arrays, `$key` without `global`, optional-before-required
parameters, a short open tag; the `[]`/`??` canaries of the former 5.3
floor were removed with it); `curly-string-offset.php` is checked by
PHPCompatibility only, because with `phpVersion` 8.5 it is a PHPStan parse
error that would leave the canary run incomplete;
`canaries/expected.txt` names the finding each tool must report. A weakened
configuration (lower PHPStan level, narrower `testVersion`) fails here even
with regenerated baselines.

Each tool has blind spots: PHPStan's 8.5 parser rejects `$ascii{$i}` as a parse error, and
PHP_CodeSniffer has no data flow; the missing-upload `count()` crashes in
`import.php`/`import2.php` appear only at PHPStan level 9; neither reports PHP
8.5's implicit PostgreSQL connection deprecation. The HTTP tests cover those.

## Open test work

Findings and the fix list are in [COMPATIBILITY_PLAN.md](COMPATIBILITY_PLAN.md).
Open harness items:

- [ ] Close the real Infection gaps listed in the [mutation notes](mutation/README.md#suggested-tests-for-the-real-gaps)
  (mainly `acc_header` output and `getfilter` validation), then gate CI on no
  new escaped mutants.
- [ ] Extend the bank import test (`http-import.php`) to import-line
  deduplication, duplicate re-uploads and the BAWAG voucher IDs once
  `KF-IMPORT-REGEX-G` is fixed.
- [ ] Document the `mbapi.php` output contract and verify it against the
  membership system's consumer or an agreed sample.
- [ ] Apply the HTTP diagnostics strictness to PHPUnit (PHPUnit 9 only prints
  deprecations; the implicit-connection ones are gone).
- [ ] Report unused `tests/known-diagnostics.php` entries as failures.
- [ ] Resolve the PHP 8 failures, then extend the matrix to newer PHP 8
  releases.
