# PHP compatibility findings and follow-up plan

Reviewed against the synthetic Docker harness on 2026-10-23. This list separates
completed changes from reproducible failures and configuration-dependent risks.
Application changes beyond the completed fixes listed below remain follow-up work.

## Completed and verified

- [x] Change `$ascii{$i}` to `$ascii[$i]` in `functions.php`. All 29 tracked
  application PHP files parse on PHP 5.5, 5.6, 7.4, 8.0, and 8.5.
- [x] Initialize `$rights2 = array()` in `rights2orgasql()` before collecting
  organization IDs. Empty/unknown rights still produce `AND false`; privilege
  exemptions and organization restrictions keep their existing behavior.
- [x] Remove `@` from the rights assertions and expand them from four to 24
  cases. The full PHPUnit suite passes 115 tests and 138 assertions on PHP
  5.6.40, 7.4.33, 8.0.30, and 8.5.12 (121 and 157 after the null-argument
  tests).
- [x] Mutation-test the rights helper. The original four cases killed 13/30
  mutants. Expanded cases killed all 30; after the initialization fix, a new
  initialization-removal mutant is also killed (31/31 on PHP 7.4 and 8.5).
- [x] Guard `strlen()`, `explode()` and `str_replace()` in `acc_header()`,
  `acc_footer()`, `rights2orgasql()` and `tag()` against null arguments
  (PHP 8.1+ deprecation), keeping the pre-8.1 results; see
  [Null arguments](#null-arguments-to-internal-functions-php-81).
- [x] Add separate Docker/GitHub Actions runtime lanes. PHP 5.6 and 7.4 pass
  the full HTTP/write harness, with the reviewed diagnostics baseline below.
  PHP 8.0 and 8.5 remain failing on unlisted diagnostics.
  The runner attempts every lane and returns nonzero if any fails.

## Login and redirect failure: confirmed

PHP 8 has not introduced a new rule prohibiting these redirects. The documented
rule is that headers must precede response output, and `Location` normally sets
status 302. See the [PHP header manual](https://www.php.net/manual/en/function.header.php).

Before the fix below (at 20b5911), the application called `acc_header()` at
`index.php:176` before dispatching to `page_login()` at `index.php:284`. Login
set cookies and a redirect at `index.php:27-33`, after rendering had begun, so
it depended on buffering.

The PHP 8.0 reproduction with the normal 4,096-byte buffer showed:

- The synthetic password was valid, and the database cookie/login fields changed.
- The response was HTTP 200, with no `Location` header and no authentication cookies.
- PHP reported headers already sent from `functions.php:170`.
- The response included deprecation output from `new.php:450` before login.
- A separate 16,384-byte-buffer control returned HTTP 302 and both cookies while
  still displaying the deprecations. This was a diagnostic control, not a fix.

PHP 8 defaults to `E_ALL`, including notices and deprecations previously excluded
by default; see the [PHP 8 migration guide](https://www.php.net/manual/en/migration80.incompatible.php).
`page_new_buchung($part = 0, $part_to_remove, $rights, $add)` declares required
parameters after an optional one. PHP documents this as deprecated from 8.0 and
explains that dropping the unused default generally preserves behavior; see
[function parameter rules](https://www.php.net/manual/en/functions.arguments.php).

- [x] Add response-level regressions for valid login, rejected login, both
  cookies, redirect destination, and behavior with buffering disabled/small.
  Before the fix below, `tests/http-login.php` passed on PHP 5.6/7.4 and
  failed on 8.0/8.5 (login, login with target, and logout returned 200 without
  `Location` or cookies). With `output_buffering=0` login and logout failed on
  every lane ("headers already sent by (output started at
  functions.php:118/122)"), recorded as the known failures
  `KF-LOGIN-UNBUFFERED` and `KF-LOGOUT-UNBUFFERED` until the fix below.
- [x] Process login and logout before rendering HTML. `index.php` answers
  logout and `login_redirect()` (successful login, or an already logged-in
  user posting a `target`) before `acc_header()`, then closes the connection
  and returns; rejected logins and the form render as before. Redirect bodies
  are now empty. `KF-LOGIN-UNBUFFERED`/`KF-LOGOUT-UNBUFFERED` are regular
  assertions and pass on PHP 5.6, 7.4, and 8.0. On PHP 8.5 the login redirect
  still needs the buffer because the implicit-connection deprecations in
  `login()`/`checklogin()` (`index.php:15-17/26/78`) are displayed first.
- [x] Send the remaining redirects before any output. Before the fix they
  were sent after `acc_header()` had rendered and depended on output
  buffering: `checklogin()`'s login redirect (`index.php:109`) for every
  protected page, including CSV listings after the byte order mark;
  `relocate()` (`edit.php:6`) after approval, finalization, merge and
  revision saves (it also closed the connection while the request went on,
  `KD-EDIT-RELOCATE-CLOSED-LINK`); and `page_search()` (`search.php:5`).
  Characterized test-first by `tests/http-redirects.php` and
  `tests/write-workflows.php` against the normal and an `output_buffering=0`
  server, with the known failures `KF-REDIRECT-AFTER-OUTPUT` (302 responses
  carrying the page, a CSV byte order mark and header row, or displayed
  warnings) and `KF-REDIRECT-UNBUFFERED` (HTTP 200 without `Location`,
  "headers already sent" at `index.php:109`, `edit.php:6`, `search.php:5`).
  The fix (both are regular checks now; every redirect has an empty body):
  - `index.php` checks the login of the protected pages (all, mb, open,
    merge, edit, new, closed, deleted, recover, accounts, import, import2)
    before the CSV block and `acc_header()`, and returns after
    `checklogin()` has sent the login redirect. Logged-in users are
    unaffected.
  - The member search redirects there too, before any output. Visitors who
    are not logged in keep getting the search redirect, not the login
    redirect: `page_search()`'s `Location` replaced the login one before.
    The dispatch branch for search is removed.
  - `index.php` buffers the edit and merge pages (`ob_start()`), and
    `relocate()` discards that buffer, closes the connection, sends the
    `Location` and exits, so nothing runs on the closed connection.
    Redirect targets and database effects are unchanged.
  Behaviour changes for review:
  - Security: before the fix the page code still ran for visitors who were
    not logged in (behind the login `Location`): a voucher POST to
    `?action=new` created the voucher, a revision POST added a row to an
    existing voucher, and `?action=import` called the membership system
    (`getusers()`, which then drops and rebuilds `ppmembers`). Now nothing
    but the redirect runs. Tested on the protected pages and on the write
    actions (create, approve, finalize, revise, merge).
  - Redirect bodies are empty (they carried the page before).
  - Visitors who are not logged in requesting a CSV listing no longer get
    the CSV headers (`Content-Type: text/csv`, `Content-Disposition`) and
    byte order mark with the login redirect; the member search as CSV no
    longer sends them with its redirect either.
  - `?hide=` on a protected page by a visitor who is not logged in now gets
    the login redirect instead of a failing `UPDATE users` ("Abfrage
    fehlgeschlagen"). On public pages it still fails, and so does `?hide=`
    combined with `?action=search` for a logged-in user (the search now
    redirects before the hide block runs; previously the column was
    hidden, then redirected).
  Not covered: the receipt upload redirect (`new.php:266`, PDF upload on
  the edit page) is only reachable with `mcrypt` (PHP < 7.2) and is not
  tested; `page_edit_drop_acks()` ("Bestätigungen zurücksetzen!") never
  reaches its redirect, because it passes `$rights` instead of the rights
  SQL to `vouchers_reset_ack()` and the query fails ("syntax error at or
  near ..."). [ ] Fix and test the reset separately; it is an existing
  failure, not a header problem. **Owner decision:** the query only works
  for empty (or null) rights, and then has no organization restriction: a
  user with empty rights can reset the approvals of any voucher (tested in
  `tests/write-workflows.php`). Passing `$rightssql` would stop that, but
  would also let every user of an organization reset the approvals of that
  organization's vouchers, which fails for all of them now; the page has no
  `bgf` check (the button is only shown to `bgf` users). The receipt download (`file.php`) and the
  CSV headers were already sent before any output.
- [x] Review every `page_new_buchung()` call and remove the ineffective default.
  The only caller (`new.php:610`) passes all four arguments; the `$part = 0`
  default is dropped and the `new.php:450` deprecation is gone on PHP 8.
- [x] Keep runtime diagnostics visible in tests. A larger buffer or suppressed
  errors alone must not be treated as the application fix. The HTTP tests now
  record every diagnostic per request without changing error settings and fail
  on any outside the reviewed baseline; see "Diagnostics surfaced" below.

## Diagnostics surfaced by the strict HTTP checks

Observed on 2026-10-23 with `tests/runtime/diagnostics-prepend.php`. "Hidden"
means the runtime's default `error_reporting` did not display it.

Reviewed baseline for PHP 5.6 and 7.4 (`tests/known-diagnostics.php`); these
lanes keep passing only through these entries:

| ID | Location | Diagnostic | Follow-up |
|---|---|---|---|
| `KD-REPORT-SQL-PG15` | `report.php:58` | Displayed warning: `pg_query()` fails with "trailing junk after numeric literal at or near "8AND"". `unit_report()` appends `$cond` without a leading space; PostgreSQL 15+ rejects `8AND`, and `or die` ends the 2024 report at its first per-unit income query. The existing smoke assertions still pass on the truncated page. | [x] Fixed: `date_condition()` now returns its `begin = 1` condition with a leading space; the smoke test asserts the report renders through Anlage B. The `begin = 0` branch is only appended after a space, so it is unchanged. Completing the report exposed `$prevcond` at more call sites (`KD-REPORT-PREVCOND`) and required four public person types in the fixtures. |
| `KD-REPORT-PREVCOND` | `report.php:340`, `347`, `374`, `381` | Hidden notice: undefined `$prevcond` (warning on PHP 8). | [x] Fixed: `page_report()` sets `$prevcond = null` before the calls. `unit_report()` has never read its first parameter (in every revision of `report.php` its only occurrence is the parameter list), so it keeps receiving the value it always got, and the report cannot change: the 2012, 2024, 2025, current-year and `print_empty` reports were byte-identical before and after on PHP 7.4. The similarly named `$prev_cond` (`AND date < '<year>-01-01'`) was deliberately not passed; it would be output-identical too, but suggests a use that does not exist. Removing the unused parameter (and the unused `$prev_cond`/`$precond` variables) is left to a later cleanup. Entry and PHPStan baseline entry (8 sites) removed; mutation target `report-prevcond`. |
| `KD-PREFS-HIDE` | `closed.php` 37 lines | Hidden notices: `$user_prefs_hide[$n]` undefined offsets for users without hide preferences (54 distinct line/offset pairs per ledger request). | [x] Fixed together with the `emptytag()` crash (`closed.php:158` under [Static-analysis findings](#static-analysis-findings)): every read goes through `listing_column_hidden($col)`, `isset(...) && ... == 1`, which is what the undefined offset (`null == 1`) evaluated to, so listings without hide preferences are byte-identical (ledger, CSV, open, deleted, search, closed, compared on PHP 7.4). `tests/http-listing-prefs.php` covers stored preferences and `?hide=N`/`?hide=-N` (see the static-analysis row). Mutation target `listing-columns`. |
| `KD-NEW-RIGHTS` | `new.php:184` | Hidden notice: undefined `$rights` passed to `page_save_buchung()`. | [x] Fixed: `page_new_save()` takes the `$rights` that `index.php:264` already passed, as `page_edit_save()` does for revisions. The rights only choose the default organization in `get_voucher()` when the form posts none, which is always the case for a user with a single organization (the form shows the organization field only for rights with several entries). Before: such a user's new voucher was saved in organization 10 (Bund), which the user cannot see or edit. After: it is saved in the user's organization. Unchanged: `bgf`-only, multi-entry and admin rights still default to 10, and a posted organization is still saved as given (it is not checked against the user's rights). **Owner decision:** behaviour change; vouchers created this way so far are in organization 10. Tests in `tests/write-workflows.php`, mutation target `rights-checks`. Entry and PHPStan baseline entries (undefined `$rights`, `page_new_save` invoked with 1 parameter) removed. |
| `KD-EDIT-RIGHTSSQL` | `edit.php:120` | Hidden notice: undefined `$rightssql` in the finalized-voucher check, so that query has no organization restriction. | [ ] **Owner decision, not changed.** The check only refuses ("bereits finalisiert"); every update in `page_edit_ack()` keeps its organization restriction, so no user can approve rows of another organization (tested). Effects of the missing restriction: a finalized row of any organization blocks the approval of the whole voucher (also of another organization's rows of a voucher with rows in two organizations), and a user of another organization learns whether a voucher number is finalized (the answer differs from "Hier fehlt noch der Beleg"). Moving `$rightssql = rights2orgasql($rights);` before the check (as in `page_edit_save()`) would remove the notice and that difference, but would allow the approval of a user's own rows of a partly finalized voucher, which is refused now. Characterized in `tests/write-workflows.php`. |

`KD-EDIT-RIGHTSSQL` (and the fixed `KD-NEW-RIGHTS` and `KD-REPORT-PREVCOND`) are also PHPStan
baseline findings ("Undefined variables in function scope" under
[Static-analysis findings](#static-analysis-findings)). A fix for any of them
must remove both its entry here and its PHPStan baseline entry; the static
check fails until the stale baseline entry is gone.

PHP 8.0 and 8.5 have no baseline entries; every diagnostic fails the lane.
In addition to the PHP 8 forms of the issues above, they report:

- PHP 8.0 and 8.5: undefined `$rightssql` (`edit.php:120`) as a warning
  (`$rights` at `new.php:184` is fixed). The `new.php:450` deprecation (also a former
  PHPCompatibility baseline finding), the login/logout "headers already sent"
  warnings, and the uncaught `Error` "No PostgreSQL link opened yet" at
  `index.php:78` (from `acc_footer()` after `relocate()`) are fixed. A
  targeted PHP 8.0 run after the redirect fix: `http-login.php` and
  `http-redirects.php` pass (also unbuffered), `write-workflows.php` fails
  only on the two warnings above; after the `new.php:184` fix a PHP 8.0 run
  fails only on `edit.php:120`.
- PHP 8.5 only: "headers already sent" at `index.php:27/28/31/33` (login on the
  unbuffered server, output started by the `index.php:15` deprecation). Before
  the redirect fix also at `edit.php:6` (approval redirect); not re-run on
  PHP 8.5 since. The implicit-connection deprecation of the early
  `checklogin()` call (`index.php:78`) is displayed before any redirect, so
  the unbuffered redirect checks are expected to fail on PHP 8.5 until that
  deprecation is fixed.
- PHP 8.5 only: "Automatic fetching of PostgreSQL connection is deprecated"
  for `pg_query()`, `pg_escape_string()`, and `pg_last_error()` in
  `functions.php:434`, `index.php:15-17/26`, `report.php`, `transactions.php`,
  and `spendings.php`. The displayed deprecation at `index.php:15` starts the
  output before the login headers.
- [x] Fixed: `strlen(): Passing null to parameter #1` at
  `functions.php:179/228/230` (see [Null arguments](#null-arguments-to-internal-functions-php-81)).
  Before the fix this displayed deprecation in `acc_header()` started the
  output on PHP 8.5, so logout also returned 200; logout now returns 302 on
  PHP 8.5 (login still fails at `index.php:15`).

## Null arguments to internal functions (PHP 8.1+)

PHP 8.1 deprecates passing null to non-nullable parameters of internal
functions. Every internal call in `functions.php` was reviewed for null input;
reachability was checked with real requests on the PHP 8.5 lane (diagnostics
recorder, synthetic database).

| Location | Null source and reachability | Status |
|---|---|---|
| `functions.php:179` (`acc_header`), `:228`, `:230` (`acc_footer`): `strlen($rights)` | `checklogin('rights', false)` returns null, not `''`, when there is no valid login cookie (it falls through without a return). Every anonymous page, e.g. `GET /index.php?year=2024`; seen in the strict HTTP checks. | [x] Fixed |
| `functions.php:259`, `:262` (`rights2orgasql`): `explode(",", $rights)` | The listing pages get `checklogin('rights')`, null for anonymous requests; the page keeps rendering after the login redirect header. Reproduced with an anonymous `GET /index.php?action=all&filter_date=2024`. | [x] Fixed |
| `functions.php:102` (`tag`, CSV mode): `str_replace(..., $text)` | Listings pass nullable columns (`vouchers.name`, `contra_account`, `account`, `comment`, `street`, ...; no default in the schema). The fixtures set them all, so the HTTP tests do not see it. Reproduced with one synthetic voucher without these columns: public `GET /index.php?action=spendings&format=csv` and authenticated `?action=all&format=csv`. After `str_replace()`, `$text` is a string, so the `preg_match()` on line 103 is not affected. | [x] Fixed |
| `functions.php:4-5` (`hex2ascii`), `:13` (`ascii2hex`) | Only called with `$_POST['target']` after `isset()` (`index.php:31`) and `$_SERVER['REQUEST_URI']`, which the web server always sets. Not reachable with null. | No change |
| `functions.php:190` (`strpos($rights, ...)`) | Only reached when `strlen($rights) > 0`. | No change |
| `functions.php:245`, `:250` (`format_date`) | Guarded by `$date != null`. | No change |
| `getfilter()`, `getsort()`, `getoppsort()`, `eyes()` | `$_GET` values are read only after `isset()`, which is false for null; `getoppsort()` callers pass literals. | No change |

Each fix replaces only null with `''` (`$x === null ? '' : $x`), which is what
these functions did with null before PHP 8.1 (checked on PHP 5.6, 7.4 and
8.0): the return values and output are unchanged for every input, including
non-string ones (a `(string)` cast would have changed arrays and objects). The
PHPUnit tests call the helpers with null, compare with the empty-string
result, and capture all diagnostics; they failed on PHP 8.5 only before the
fix. The `null-arguments` mutation target checks each guard.

## PostgreSQL version matrix

Run on 2026-10-23 with `PGACC_TEST_PG` (see the
[test README](README.md#postgresql-versions)). Production runs 8.4.20, which
cannot be pulled; 9.4.26 is the oldest stand-in.

| Lane | 9.4.26 | 14.24 | 17.11 | 18.6 |
|---|---|---|---|---|
| PHP 7.4 (`run.sh 7.4`) | pass | pass | pass (coverage lane) | pass |
| PHP 5.6 (`run.sh 5.6`) | pass | not run | CI lane (not re-run locally) | not run |

All lanes that ran produced the same PHP diagnostics, PHPUnit results
(155 tests; PHP 5.6 also 237 assertions), HTTP/login and write-workflow results on every server; the
`known-diagnostics.php` baseline therefore needs no per-PostgreSQL entries
(no `pg` key). As an additional manual check, 28 read-only pages (report
2023-2025, ledger, open/closed/deleted lists and their CSV exports, public
transactions/spendings/donations, statistics, `tinyreport`, `vreport`, `wk`,
accounts, edit and recover views) were fetched as `synthetic-admin` from
fresh fixtures on PHP 7.4 against each server. The bodies were byte-identical
apart from random captcha numbers and Xdebug timings in the `mb.php` error
page (it tries to reach the membership API, which the isolated network
blocks). `KD-REPORT-SQL-PG15` was fixed before this run, so 14 and 15+
no longer differ; before the fix, 9.4 and 14 accepted `8AND` and 15+ did not.

Version-specific differences found, all in test infrastructure (adapted, no
application change):

- [x] `psql` before 9.6 runs only the **last** `--file`. On 9.4,
  `database-smoke.sh` therefore ran only the characterization script without
  schema or data. The table/sequence/constraint check still passed, because
  `array_agg()` of no rows is `NULL` and `NULL <> ARRAY[...]` is not true; the
  run then failed at the first `vouchers` reference. Fixed by including the
  files from one `\i` script and by using `IS DISTINCT FROM` in every
  characterization comparison, so empty results now fail on all versions.
- [x] `'public'::regnamespace` needs 9.5+; replaced by a `pg_namespace` lookup.
- [x] PostgreSQL 18 stores NOT NULL as `pg_constraint` rows (`contype = 'n'`,
  e.g. `vouchers_deleted_not_null`), which broke the exact constraint list.
  Those rows are excluded and NOT NULL columns are now checked through
  `pg_attribute.attnotnull` on every version (the 8.4 representation).
- [x] The 18 image keeps its data in `/var/lib/postgresql/18/docker` and
  refuses to start with a mount at `/var/lib/postgresql/data`; the tmpfs path
  is selected per version.
- [x] 9.4's `password_encryption` is a boolean (`on` = MD5) and 9.4 has no
  SCRAM: the PHP 5.6 lane's `password_encryption=md5` is spelled `on` there.
  PHP 7.4 authenticates with MD5 on 9.4 and SCRAM on 14+.
- [x] `DROP DATABASE ... WITH (FORCE)` (mutation runner) needs 13+; older
  servers terminate the other backends first. Unit-tested in
  `tests/mutation/tests/LibTest.php`; exercised against 9.4 by the CI lane's
  mutation step.

No application SQL failed or changed results on 9.4, 14, 17 or 18 within the
covered routes. Not covered: imports (`import.php`, `import2.php`), `merge`,
`accounts` writes, and the membership pages, which need the removed `mcrypt`
or external services.

### 8.4 behaviour that 9.4 cannot show

- [ ] `standard_conforming_strings` is **off** by default on 8.4 (on from
  9.1). `pg_escape_string()` adapts to the connection setting, but literals
  in the application that contain backslashes would change meaning (a
  search of the `pg_query()` lines found none; values pass through
  `pg_escape_string()` in `accounts.php`, `edit.php`, `index.php`,
  `getusers.php`, `import.php` and `new.php`, which follows the setting of the
  connection). A test could
  set `standard_conforming_strings=off` on the 9.4 server to emulate 8.4.
- [ ] `bytea_output` is `escape` on 8.4 (`hex` from 9.0); the schema has no
  `bytea` columns, so this matters only for ad-hoc queries.
- [ ] Planner and sort differences: queries without `ORDER BY` (or with
  ties) can return rows in a different order on 8.4. The synthetic fixtures
  gave identical page output on 9.4-18, but they are small.

Proposed read-only checks on production, for the owner to run; nothing has
been run against production:

1. `SHOW server_version; SHOW standard_conforming_strings; SHOW
   bytea_output; SHOW datestyle; SHOW lc_collate; SHOW client_encoding;`
   and compare with the test servers.
2. `EXPLAIN` (not `EXPLAIN ANALYZE`, which executes the query) of the queries
   generated for `index.php?year=<current>` (report), `action=all`,
   `action=closed` and `action=transactions`, captured from the synthetic
   harness with `log_statement=all`, to compare plans and spot sort or
   hash-aggregate ordering that the pages rely on.
3. `SELECT extname, extversion FROM pg_extension` fails on 8.4 (no
   extensions); instead check `SELECT proname FROM pg_proc WHERE proname IN
   ('crypt', 'gen_salt')` to confirm the contrib pgcrypto functions used by
   login and `accounts.php`.

## Further array/input findings

| Location | Trigger and evidence | Follow-up |
|---|---|---|
| `edit.php:345` | A missing voucher ID leaves `$vouchers` undefined when the query yields no rows. An authenticated scoped-user request reproduced `count(null)` on PHP 8.0. | Initialize the result array; test missing, deleted, and unauthorized voucher IDs while preserving the existing BGF behavior. |
| `import.php:27` | `count($_FILES['uploadcsv']['name'])` assumes a multiple-upload array. Reproduced the missing-upload `TypeError` with the membership lookup stubbed locally. | Validate/normalize upload structure before counting; cover GET, no file, malformed structure, and upload errors. Keep membership traffic synthetic. |
| `import2.php:24` | A normal authenticated GET with no uploaded PDF reproduced `count(null)` on PHP 8.0. | Render the upload form without processing absent files; add malformed-upload tests. |
| `donations.php:116-122` | With a synthetic qualifying donation and captcha submission, `$donations` is undefined and `array_multisort()` receives null. The collection assignment is commented out for legal reasons. Reproduced on PHP 8.0. | [x] Fixed: `page_donations()` initializes `$donations`, `$sort_sum` and `$sort_name` to empty arrays. Donor publication stays disabled (the commented-out assignment is untouched), so the list is the header row only, as before; only the diagnostics disappear (on 5.6/7.4 they were displayed between the header row and `</table>`). New `tests/http-donations.php` (captcha answered from the form, wrong/missing answer, 2024 above and 2025 below the threshold, years before 2012; asserts that no donor name, encoded name, row or sum appears) failed before the fix on 5.6 and 7.4 and passes on 5.6, 7.4 and 8.0. Mutation target `donations`. `kdonations.php` does not share the pattern: its loop body is empty (publication disabled the same way) and it collects nothing. |
| `index.php:293`, `:289` (`?action=donations`/`kdonations` without `year`) | From the source, not run: `intval($_GET["year"])` without `isset()`, so the menu links (`href="donations"`, `href="kdonations"`) raise an undefined-index notice (warning on PHP 8). `tests/http-donations.php` therefore always passes `year`. | Default to 0 with `isset()` (same page: `page_donations()` maps years before 2012 to 2013) and test the menu links; `index.php` is being changed in parallel. |
| `closed.php:222`, `:246` (`?action=closed`) | Found while testing the listing column preferences, not covered by the HTTP tests: `page_closed()` selects neither `vaccount` nor `ack1_old`/`ack2_old`, so `page_listing_line()` reads three undefined indexes per row (hidden notices on 5.6/7.4, warnings on PHP 8) and the closed listing always shows those columns empty. Observed on PHP 7.4 with `synthetic-admin`. | Behavior decision for the owner: selecting the columns changes the closed listing (it would then show virtual accounts and old confirmations, like the ledger); `isset()` defaults keep today's output. Then add `?action=closed` to the HTTP tests. |
| `search.php:4` (`?action=search` without parameters) | Hidden notice "Undefined index: member", observed on PHP 7.4 while comparing listings before and after the fix above; not covered by the HTTP tests. | Review with the search form tests. |

Other direct `count()` sites were reviewed: the local arrays produced by
`explode()` and the guarded regular-expression result arrays do not share the
same uninitialized-variable path. No additional obsolete curly-brace offsets
were found in the tracked application files; PHP 8.5 syntax checks pass.

## Found by the PHP 5 reference

Pinned as current behaviour in `tests/reference/php5/` (PHP 5.6); none is fixed.
Behaviour changes are the owner's decision.

| Location | Finding | Follow-up |
|---|---|---|
| `import.php:252` | When a CSV line without a parsed name matches exactly one member through the `ILIKE` lookup, `break` leaves the `fgetcsv()` loop: that line and all later lines of the file are not imported, yet the page reports "wurde erfolgreich importiert" (`http/import-bawag.txt`). All versions. | Owner decision: the `break` probably meant to end only the lookup. Test a match on a middle line first. |
| `import2.php:103`, `:133` | `page_import2_process_multi_ids()` strips all bytes `\x80-\xFF` before matching `Entgeltf.+r(Kontof.+hrung\|...)`, so "Entgelt für Kontoführung" becomes `EntgeltfrKontofhrung` and can never match (`.+` needs a character between `f` and `r`). All versions. | Match with the umlauts removed (or before stripping); add a PDF fixture for the fee rows. |
| `index.php:283-285` (`?action=transfer`) | Listed in the allowed actions, but `page_transfer()` is defined nowhere: fatal error on every version, also for visitors (`diagnostics/transfer-anon.txt`). | Remove the action or restore the page (owner). |
| `import.php:118`, `donations.php:4-6`, `sprintf('%1.2f', ...)` sums | Numeric strings differ between 5.6 and 7.x: `intval('1e3')` is 1 on 5.6 and 1000 on 7.1+ (an import amount `1e3,2` becomes `PHP_INT_MAX` on 7.4); hex strings such as `'0x1A'` are numeric (26) on 5.6 only (`helpers/idioms-values.txt`, `helpers/page-helpers.txt`). | Validate amount and year formats before `intval()`; the `compare 7.4` diff lists these until they are decided and allowlisted. |
| `functions.php:275` `konto2lo()`, `switch`/`==` idioms | PHP 8 string-number comparison: an account number with a trailing space (`'50110117300 '`) maps to organization 2 on PHP 8 but falls through on 5.6/7.4; `0 == 'abc'` is no longer true; `sort()` already orders mixed values differently on 7.4 than on 5.6 (`helpers/konto2lo.txt`, `helpers/idioms-*.txt`). | Normalize (trim) account numbers and compare strictly where the input is a string; review with the PHP 8 reference comparison. |

## Short tags and other migration debt

- [ ] Replace `<?` with `<?php` at `getusers.php:1` and `file.php:1`. Short tags
  depend on `short_open_tag`; when disabled, PHP can treat these files as text
  instead of defining their functions. A plain `php -l` can then miss the issue.
  Add a check with short tags disabled and assert that source text is never
  emitted. This is a portability risk: source emission was not observed in the
  tested PHP 8.0 default or short-tags-enabled control, and enabling short tags
  did not fix the login response.
- [ ] Replace implicit PostgreSQL connection use incrementally with explicit
  connection arguments; PHP 8.5 emits deprecations from the current helpers.
- [x] Update the HTTP test helper's deprecated `$http_response_header` use on
  PHP 8.5, with feature detection and a fallback for the legacy runtimes.
  The fallback lives in a separate file that PHP 8.4+ never compiles; a
  variable-variable read is not enough, since PHP 8.0 then leaves the
  variable unset.
- [ ] Fix the malformed amount-filter regular expression at `functions.php:73`
  in a separate tested change. Its following statement is currently unreachable.
- [ ] Protect receipt and membership encryption contracts with synthetic fixtures
  before modernizing the `mcrypt` dependency. Do not change ciphertext formats
  without checking consumers.

## Planned security follow-ups

- [ ] CSRF protection, before the `php5-last` tag. A stateless token per login:
  `hash_hmac('sha256', <login cookie value>, $csrf_secret)` with an optional new
  `$csrf_secret` in `constants.php`; when it is not set, fall back to a token
  derived from the login cookie alone, so deploys without the new value keep
  working. Add the token to every form and every link that changes data, and
  verify it centrally in `index.php` before any state-changing action runs.
  Test both modes, plus missing, wrong and other-user tokens with no database
  change. A separate, droppable commit adds `SameSite=Lax` to the login cookies.
- [ ] Later (behavior change, after the CSRF token): perform state-changing
  actions only on POST. Several actions are currently triggered by GET links
  (for example merge); convert those links to small forms and refuse GET for
  them. Until then, those links carry the CSRF token in the query string.

## Dependencies

Audited on 2026-10-23 against `composer.lock` (report only; nothing was
upgraded). The application uses one runtime package: `import2.php:52` calls
`Smalot\PdfParser\Parser::parseContent()` and `Document::getText()` on
uploaded bank statements. That path was exercised outside the HTTP tests:
a script built synthetic one-page PDFs (plain and `FlateDecode` content
streams with the texts `import2.php` searches for), parsed them with the
locked `vendor/` of the PHP 5.6 image and with candidate versions installed
by Composer 2.2.25 in scratch projects, and recorded every diagnostic
(`error_reporting(-1)`) on the official `php:5.6`, `7.4.33`, `8.0`-`8.5` CLI
images (5.6.40, 7.4.33, 8.0.30, 8.1.34, 8.2.35, 8.3.36, 8.4.27, 8.5.12).
Advisories are from the Packagist security-advisories API.

| Package (locked) | PHP constraint | 5.6 | 7.4 / 8.0 | 8.5 | Notes |
|---|---|---|---|---|---|
| `smalot/pdfparser` v0.9.23 (2015) | `>=5.3.0` | works | **fatal**: class `Object` (reserved since 7.2) on 7.4; on 8.0+ the TCPDF parser fails first | **fatal** (`ParseError`, curly-brace offsets in TCPDF) | The PDF import is broken on every runtime from 7.2 on. `composer.json` requires `*`, so any `composer update` jumps to 2.x (see below). |
| `tecnick.com/tcpdf` 6.2.6 (abandoned name of `tecnickcom/tcpdf`) | `>=5.3.0` | works | **fatal** on 8.0+: curly-brace string offsets (`$this->pdfdata{$strpos}`, `tcpdf_parser.php:531-534`) | **fatal** | Only its parser/filter classes are used (through pdfparser 0.9). Affected by CVE-2018-17057 (`<6.2.22`, phar deserialization) and the 2024 TCPDF advisories (`<6.8.0`; mostly PDF generation, which the app does not use). |
| Dev: `phpunit/phpunit` 9.6.38 with `php-code-coverage` 9.2.32, `sebastian/*`, `phar-io/*`, `theseer/tokenizer`, `doctrine/instantiator` 1.5.0, `myclabs/deep-copy` 1.13.4, `nikic/php-parser` v5.9.0 | `>=7.3` (php-parser `>=7.4`) | not installed (`--no-dev`; the lane uses the PHPUnit 5.7.27 PHAR) | used, suite passes | used, suite passes | Test-only. No single PHPUnit runs on both 5.6 and 8.5, so the split (PHAR on 5.6, locked 9.6 elsewhere) stays. Not an upgrade candidate. |

Candidate `smalot/pdfparser` versions (resolved with `platform.php` 5.6.40;
"text OK" means both PDFs yielded the searched texts; 8.1-8.4 were run for
v0.13.3, v0.16.2 and v0.19.0, the others on 5.6, 7.4, 8.0 and 8.5):

| Versions tested | Pulls in | 5.6 | 7.4 / 8.0-8.3 | 8.4 / 8.5 |
|---|---|---|---|---|
| v0.9.26, v0.10.0 | `tecnickcom/tcpdf` | text OK | fatal (`Object` class) | fatal |
| v0.11, v0.12.0, v0.13.0 | `tecnickcom/tcpdf` 6.7.8 | text OK | text OK; 2 compile warnings (`"continue" targeting switch`) | same, plus implicit-nullable deprecations |
| v0.13.3, v0.14.0, v0.15.1 | `tecnickcom/tcpdf` 6.7.8 (advisories `<6.8.0`) | text OK, clean | text OK, clean | text OK, 17 deprecations |
| v0.16.0, v0.16.2 | nothing | text OK, clean | text OK, clean | text OK, 14 deprecations |
| v0.17.1, v0.18.2, v0.19.0 (last `>=5.6`) | `symfony/polyfill-mbstring` v1.19.0 | text OK, clean | text OK, clean | text OK, 15-18 deprecations |
| v2.13.1 (latest, `>=7.1`) | `symfony/polyfill-mbstring` v1.43.0 | **parse error at autoload** (polyfill `bootstrap72.php`), i.e. on every page | text OK, clean | text OK, clean |

The 8.4/8.5 deprecations are all "Implicitly marking parameter ... as
nullable" (class signatures like `Header $header = null`), raised once when
each class is compiled, i.e. only on the PDF import request. Fixing them
needs `?Header` (PHP 7.1 syntax), so **no release runs on both 5.6 and 8.5
without diagnostics**. The oldest release that works functionally on 5.6
through 8.5 is v0.11 (warnings from 7.3); the oldest without warnings up to
8.3 is v0.13.3.

Recommendation (owner decision):

- [ ] While PHP 5.6 is supported, pin `smalot/pdfparser` to `v0.16.2`
  exactly (not `*`): it has no dependencies (drops TCPDF and its advisories),
  requires only `>=5.6`, parses on 5.6-8.5, and leaves only the 8.4+
  implicit-nullable deprecations on the import request. v0.17-v0.19 would
  also work but need `symfony/polyfill-mbstring` held at v1.19.0, because
  `composer.json`'s `platform.php` 7.4.33 otherwise resolves a polyfill that
  breaks every page on 5.6. The abandoned `tecnick.com/tcpdf` leaves the
  lock with it. First add an HTTP test for `import2.php` with synthetic PDF
  uploads (cover page, one ID, several IDs) and compare the extracted text
  of v0.9.23 on PHP 5.6 with the new version: only the synthetic PDFs above
  were compared.
- [ ] After dropping PHP 5.6 and 7.0: move to `^2.13` (clean on 7.4-8.5).
- [ ] Until then, any `composer update` without a package name upgrades
  pdfparser to 2.x with a polyfill for PHP 7.2+: the PHP 5.6 image then fails
  `composer check-platform-reqs`, and without that check every page would
  fail at autoload. Add packages with
  `composer require <name>` / `composer update <name>` only; check the 5.6
  lane (`composer check-platform-reqs --no-dev`) after every lock change.

## Tooling to catch these earlier

- [x] Add a dedicated modern-PHP analysis container, separate from the legacy
  application runners. Scan tracked application sources first; exclude vendor,
  attachments, and unrelated obsolete proxy code. `sh tests/static-analysis.sh`
  scans the 29 tracked top-level files in a pinned PHP 8.5 image with locked
  tools and no network; see the [test README](README.md#static-analysis).
- [x] Evaluate PHPStan, beginning with undefined/possibly undefined variables
  and adding argument-type checks. Its levels 0/1 cover undefined variables,
  and level 5 adds function argument types; see
  [PHPStan rule levels](https://phpstan.org/user-guide/rule-levels).
  PHPStan 2.3.1 runs at level 5 (34 findings at level 0, 84 at level 1, 91 at
  level 5, about 315 at level 6). It reports `edit.php:345` and
  `donations.php:116-124`, plus the pre-fix `$rights2`. It reports
  `import.php:27` and `import2.php:24` only at level 9.
- [x] Evaluate PHPCompatibility through PHP_CodeSniffer for removed/deprecated
  features and the supported PHP range. It has a specific curly-brace offset
  rule and configurable version ranges; see
  [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility).
  10.0.0-alpha2, the latest tag and the first with PHP 8.x sniffs, runs on
  PHP_CodeSniffer 4.0.4 with `testVersion` 5.6-8.5, since widened to 5.3-8.5
  (production runs PHP 5.3.3 until the `php5-last` tag; no new findings, a
  short-array canary guards the floor). The `develop` branch at
  `4b1ac83` (2026-09-21) gave identical results on these files. The 9.3.5
  stable release predates PHP 8 support.
- [x] Enable `Generic.PHP.DisallowShortOpenTag` explicitly. Start with correctness
  checks rather than a formatting rewrite; see
  [PHP_CodeSniffer usage](https://github.com/PHPCSStandards/PHP_CodeSniffer/wiki/Usage).
  It reports `getusers.php:1` and `file.php:1`.
- [x] Review existing findings before establishing a baseline, then prevent new
  findings in CI. Static analysis complements the HTTP cookie/redirect tests;
  it does not establish their runtime behavior. Approved by the owner:
  `phpcs-baseline.txt` (24 entries, after the `new.php:450` fix) and `phpstan-baseline.neon` (91
  findings in 41 entries) list every current finding (see
  [below](#static-analysis-findings)). The CI `static-analysis` job fails on new
  findings and on baseline entries that are no longer reported. Canary files
  prove the configuration still detects the known bug classes.
- [ ] Evaluate [Rector](https://github.com/rectorphp/rector) for PHP-version
  upgrades and automated refactoring. Start with selected rules and `--dry-run`,
  review the proposed diffs, and verify them against the runtime matrix before
  applying changes. Keep the minimum supported PHP version explicit.
- [x] Evaluate a mutation framework such as Infection for broader generated
  mutations, reviewed equivalent mutants, and CI reporting. Infection runs on
  the PHPUnit-tested helpers (PHP 7.4/8.5); the target-driven runner covers
  HTTP-tested code, security-relevant mutations and PHP 5.6. See the
  [mutation README](mutation/README.md).
- [ ] Add coding-standard checks with PHP_CodeSniffer, which is already pinned
  in the static-analysis container for PHPCompatibility. PHP_CodeSniffer is
  the usual choice for long-lived legacy PHP code: WordPress (WordPress Coding
  Standards, PHPCompatibilityWP) and MediaWiki (mediawiki-codesniffer) both use
  it. Prefer it over PHP-CS-Fixer while PHP 5.6 is supported, because several
  PHP-CS-Fixer rules introduce newer syntax. Target the neutral
  [PER Coding Style](https://www.php-fig.org/per/coding-style/), not a
  project-specific style. Stages:
  1. Add `.editorconfig` (line endings, indentation, final newline; the files
     currently mix CRLF and LF) and correctness-oriented sniffs that report
     without reformatting, with a reviewed baseline like the PHPStan one.
  2. "Format on touch": enforce PER style only on changed lines in CI, by
     filtering the `phpcs` report through `tests/coverage/changed-lines.sh`.
  3. Later (after the PHP 8 migration, with higher coverage, possibly after
     dropping PHP 5.6): one formatting-only commit for the whole codebase,
     proven behavior-neutral by comparing compiled opcodes before and after,
     listed in `.git-blame-ignore-revs`, with the line-based diagnostics
     baseline and mutation targets regenerated. PHP-CS-Fixer becomes an
     option at that point.
  Do not reformat the whole codebase before then: it would rewrite nearly every
  line, break `git blame`, and shift the line numbers that the diagnostics
  baseline and mutation targets depend on.

## Static-analysis findings

Produced by `sh tests/static-analysis.sh --report` on 2026-10-23 (PHPCompatibility
10.0.0-alpha2, PHPStan 2.3.1 level 5). These are the reviewed contents of the
proposed baselines. **Known** means already recorded above or in the Rector
review. Findings marked **new** were first found by these tools; each new
finding was checked by reading the source. Only those that name a
`KD-*` ID have also been observed at runtime, by the strict HTTP checks (see
[Diagnostics surfaced](#diagnostics-surfaced-by-the-strict-http-checks)).

### Pre-fix check

| Fixed issue | PHPCompatibility | PHPStan |
|---|---|---|
| `$ascii{$i}` (`8bbeb63:functions.php:14`) | Flagged: `Syntax.RemovedCurlyBraceArrayAccess.Removed` | Not flagged (its parser accepts the syntax) |
| Uninitialized `$rights2` (`8bbeb63:functions.php:266-269`) | Not flagged | Flagged from level 1: `Variable $rights2 might not be defined` |

Both are kept as canaries in `tests/static-analysis/canaries/`.

### PHPCompatibility (24 findings)

| Finding | Locations | Status |
|---|---|---|
| Short open tag | `file.php:1`, `getusers.php:1` | Known (short-tag item above) |
| `mcrypt` extension, functions and constants removed in 7.2 (4 findings per site) | `file.php:19`, `getusers.php:20`, `import2.php:189`, `mbapi.php:17`, `new.php:252` | Known (`mcrypt` item above). `import2.php:189` is the second receipt-encryption call site, on the PDF import path. |
| `fgetcsv()`/`str_getcsv()` without `$escape` (deprecated in 8.4) | `import.php:52`, `import.php:163` | Known from the Rector review; not previously in this plan |

### PHPStan (91 findings; 79 after the `closed.php`/`report.php` fixes, the `index.php:226` merge fix and the `new.php:184` rights fix, 76 after `donations.php`)

| Finding | Locations | Status and impact |
|---|---|---|
| Possibly undefined arrays reaching `count()`/`array_multisort()` | `edit.php:345-411` (`$vouchers`, 12), `donations.php:116-124` (`$donations`, `$sort_name`, `$sort_sum`, fixed) | Known (array/input table). `edit.php:346` "always false" comparisons follow from the same variable. The `donations.php` entries (5 findings) were removed with the fix. |
| Empty array passed to `foreach` | `donations.php:119`, `:127` (2) | Added with the `donations.php` fix: `$donations` is now always `array()`, because the assignment that filled it is commented out (publication disabled for legal reasons). PHPStan correctly reports the sort and output loops as dead code. Kept on purpose until the owner decides on publication; see the array/input table. |
| Malformed regular expression | `functions.php:73` | Known |
| Extra arguments to functions without parameters | `index.php:264` (fixed with `KD-NEW-RIGHTS`), `:289`, `:321`; `functions.php:163` | Known from the Rector review. Fixed at `index.php:226`: the merge dispatch passed `intval($_POST["bid1"])` and `intval($_POST["bid2"])`, which `page_edit_merge($rights)` ignores (it reads `$_GET`); the merge link is a GET request, so each merge raised two undefined-index notices (warnings on PHP 8). The arguments are dropped; `tests/write-workflows.php` covers the merge. |
| `$key` read inside functions without `global` | `file.php:19`, `new.php:252`, `import2.php:189` (2 each) | **New.** Receipt encryption and decryption use `md5($id . $key . $id)` with `$key` always undefined. The configured key is therefore never part of the receipt key, so the key depends only on the voucher ID. PHP 8 also emits an "Undefined variable" warning before the download headers in `file.php`. Do not change this without a migration plan for existing `.aes` files (see the `mcrypt` item). |
| `tag()` called with one of two required arguments | `closed.php:158` (`emptytag()`) | **Fixed.** On PHP 7.1+ this threw `ArgumentCountError` whenever a listing column was hidden through `user_prefs_hide` (ledger, open, deleted, closed listings); on 5.6 it displayed "Missing argument 2 for tag()" in every hidden cell and returned `<td></td>`. Reproduced with the new `tests/http-listing-prefs.php` (users `synthetic-hide-odd`/`synthetic-hide-even` hide every column once, in HTML and CSV): fatal error on 7.4, displayed warning on 5.6. `emptytag()` now passes `''`, which produces the same `<td></td>` that PHP 5.6 did, without the warning. Baseline entry removed. |
| Undefined functions | `index.php:266` `page_new_ack()`, `index.php:285` `page_transfer()`, `report.php:185`/`:194` `pg_prev_error()` | **New.** The `ack` POST on the new-voucher form and `?action=transfer` cause a fatal error on every PHP version. The report error paths die with a fatal error instead of their message. |
| Undefined variables in function scope | `new.php:184` (`$rights` in `page_new_save()`, fixed), `edit.php:120` (`$rightssql` in `page_edit_ack()`), `new.php:613` (`$bgfandack`), `report.php:340-397` (`$prevcond`, 8, fixed), `wk.php:145-180` (`$year`, 7) | **New** here; `new.php:184`, `edit.php:117` and `report.php:340` were then also observed at runtime as `KD-NEW-RIGHTS`, `KD-EDIT-RIGHTSSQL` and `KD-REPORT-PREVCOND` (impact and follow-up there). `new.php:613` and `wk.php` were not observed by the strict HTTP checks. Each site warns on PHP 8 before headers. |
| Possibly undefined variables | `check.php:24-38` (`$i`), `import2.php:187/193` and `new.php:250/256` (`$file_number`), `new.php:108` (`$queries`), `new.php:180/184` (`$voucher_number`), `tinyreport.php:24-74` and `vreport.php:108-158` (`$unit`, 8 each), `mbapi.php:17` (`$datakey`, 2) | **New**, lower confidence. Some depend on query results or switch branches, and `mbapi.php` gets `$datakey` from `constants.php`. Review individually. |
| Invalid regex modifier `/g` | `import.php:306` | **New.** `preg_match()` always returns `false` with a warning, so BAWAG voucher IDs are never extracted. Cover with the planned BAWAG import test. |
| Arithmetic on a possible array | `new.php:46` | **New.** `get_param()` can return an array (`amount[]=`). `array * float` is a `TypeError` on PHP 8. |
| Always-true/false conditions | `index.php:18` (`!$data` after `or die`), `import.php:161` (`'Paypal' == 'Paypal'`), `import.php:263` (`0 == 0`) | **New**, logic smells; no direct version impact. |
| `setcookie()` value is an int | `index.php:27` | **New**, low. It is coerced to a string at runtime. |
| Whitespace after the final `?>` | `check.php:64`, `index.php:356` | **New**, low. Emitted after page output. |

### Not detected by either tool

- `import.php:27` and `import2.php:24` (`count()` on `$_FILES` entries) are reported
  only at PHPStan level 9 (`count(mixed)`), with about 700 findings in total. The HTTP/upload
  tests remain the check.
- PHP 8.5's deprecation of implicit PostgreSQL connections is not modelled by
  either tool; the runtime lanes report it.
- Of the strict-HTTP diagnostics, `KD-REPORT-SQL-PG15` (SQL text, now fixed),
  `KD-PREFS-HIDE` (undefined offsets of a query-built array, now fixed) and
  `KD-EDIT-RELOCATE-CLOSED-LINK` (closed connection, now fixed) are runtime behavior that
  neither tool reports at the configured levels.
- The login header-ordering failure was runtime behavior. The tools only
  flagged its trigger, the optional-before-required parameter at
  `new.php:450`; that finding was removed from the PHPCompatibility baseline
  with the fix (24 findings since).
