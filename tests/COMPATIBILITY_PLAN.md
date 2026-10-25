# PHP compatibility findings and follow-up plan

Reviewed against the synthetic Docker harness on 2026-10-23. This list separates
completed changes from reproducible failures and configuration-dependent risks.
Application changes beyond the completed fixes listed below remain follow-up work.

Test lanes after the 1.0.0 release (tag `php5-last`; owner decision 2026-10-25):
PHP 8.5 / PostgreSQL 18 only (see [PHP 8.5-only migration](#php-85-only-migration)).
The PHP 5.3 / 8.4.20 production-pair lane, the PHP 7.4 lanes (18 and 8.4),
and earlier the PHP 5.6 and 8.0 lanes, the separate syntax matrix and the
PostgreSQL 9.4, 14 and 17 servers were removed; findings below that name them
are historical.

## PHP 8.5-only migration

Owner decision 2026-10-25: after the 1.0.0 release (tag `php5-last`, PHP 5.3)
the application supports and tests only PHP 8.5 and PostgreSQL 18, on a new
server. First step (branch `php85/lanes`, CI and harness only, no application
code): the PHP 5.3, 7.4 / 18 and 7.4 / 8.4 lanes, the PHP 5.6 client image,
`tests/php53/`, `tests/postgres84/`, `PGACC_TEST_PG`, the reference
re-recording (`record.sh`), the Infection 0.26 project, the 5.3 floor canaries
and the PHP 7.4-only mutation equivalents were removed. Coverage (Xdebug
3.5.3), the changed-line gate, the mutation runner, Infection and the reference
comparison run on the PHP 8.5 / 18 lane; `composer.json` has `platform.php`
8.5.0 (lock unchanged apart from its hash); PHPCompatibility `testVersion`
8.5-, PHPStan `phpVersion` 8.5; the release check of `vendor/` runs on 8.5
(`tests/release-vendor-check.php`).

State of the lane at that step (`sh tests/run.sh`, exit 1): 9 of 12 groups
fail (8 HTTP scripts, and PHPUnit with 2 `UnusedOutputTest` failures), all from PHP
8.5's implicit-connection deprecation: 86 unexpected diagnostic sites (73
`pg_query()`, 12 `pg_escape_string()`, 1 `pg_version()`; 7727 occurrences)
and their consequences (4 "headers already sent" warnings, non-empty or
broken redirects, deprecation text in pages, downloads and CSV). The
reference comparison passes (0 unaccepted differences, 2 accepted and 25 `todo`
entries). The mutation self-test passes; most targets' baselines fail on the
same deprecations (no `tests/known-diagnostics.php` entries are added for
them).

Next steps, in order:

- [x] (a) Pass the connection explicitly to every `pg_*` call. Done in the same
  branch: 141 `pg_query()`, 30 `pg_escape_string()`, 134 `pg_last_error()` and
  1 `pg_version()` calls in 26 application files now pass `$dbconn`; 40
  functions got `global $dbconn` (appended to an existing `global` line where
  it came before the first call, else a new line after the opening brace).
  The three `pg_connect(...) or die(... pg_last_error())` lines are unchanged:
  no connection exists there. The PHPUnit bootstrap stores its link in
  `$GLOBALS['dbconn']`; `ReceiptImportTest` now also fails on deprecations.
  With the deprecations gone the lane reached pages it never finished before
  and showed two PHP 8.1+ null-argument deprecations, now guarded as in
  `null-arguments` (same output): `rawurlencode()` of a missing account in
  `tinyreport.php` and `str_replace()` of a null `account` in
  `transactions.php` (mutations `tinyreport-konto-null-guard-removed`,
  `transactions-account-null-guard-removed` in `output-escaping`).
  `vreport.php:188` has the same unguarded `rawurlencode($k)`, but the tests
  never reach it with a null account. The `fgetcsv()`/`str_getcsv()` calls
  in `import.php` pass the default enclosure and escape explicitly (same
  behaviour; the two PHPCompatibility findings are gone). `csrf` is in the
  `ci.sh` list, and the CI harness job is blocking. `sh tests/run.sh` passes
  (exit 0).
- [x] (b) `smalot/pdfparser` ^2 (locked v2.13.1, with
  `symfony/polyfill-mbstring` v1.43.0; TCPDF 6.2.6 left the lock), so that
  the PDF bank-statement import (`import2.php`) works on PHP 8.5: v0.9.23
  declared a class `Object` (fatal since 7.2) and its TCPDF did not parse on
  PHP 8 (curly-brace offsets). Same `parseContent()`/`getText()` API.
  `import2.php` changes: 2.x writes a tab where 0.9 wrote a space for a wide
  gap within a line, so tabs are stripped with the spaces (same matching
  text as 0.9; newlines are kept as before); the parser gets a 64 MiB
  decode limit per stream and drops image data. **Behaviour change** (review
  finding on PR #24): a file the parser cannot read (no `startxref`,
  encrypted, broken) was an uncaught exception (HTTP 500, later files not
  processed); it is now reported per file ("Konnte nicht als PDF gelesen
  werden ...") and the next file is imported. The PDF import in
  `tests/http-receipts.php` runs again, `tests/release-vendor-check.php`
  parses a synthetic statement, and the `RD-PDFPARSER-PHP72-*` reference
  entries are removed: on 8.5 the import2 pages, the imported receipt's
  download and view, `db/final.txt` and the manifest match `php5/` (only
  `RD-CHARSET-DEFAULT` remains, as on every page).
- [ ] (c) phpseclib: 2.0.55 reproduces all 21 mcrypt contract vectors on PHP
  8.5 without diagnostics (release check) and `CryptoTest` passes, also after
  (a). Moving to v3 is optional.
- [ ] (d) Re-baseline the reference on PHP 8.5 (a new recording next to
  `tests/reference/php5/`, which stays the historical PHP 5 golden master),
  once each `todo` difference from `php5/` is explained or fixed.
- [ ] (e) Later: receipt v3 with sodium `secretstream`.

Found while covering the changed lines (owner decisions, not fixed):

- [ ] Known failure `KF-CLOSED-MISSING-COLUMNS`: `page_closed()`
  (`closed.php`, `action=closed`) selects no `vaccount`, `ack1_old` and
  `ack2_old`, which `page_listing_line()` reads: "Undefined array key"
  warnings, and those cells stay empty in the closed listing. Fix: add the
  three columns to the query (changes the listing's output).
  `http-output-escaping.php` attributes the warnings to the KF id.
- [ ] Known failure `KF-NEW-ACK-UNDEFINED-FUNCTION` (found while raising
  per-file coverage): a POST with `ack` to `?action=new` calls
  `page_new_ack()`, which is defined nowhere (see the "Undefined functions"
  finding): a fatal error, nothing is saved. `write-workflows.php` attributes
  it to the KF id. Fix: remove the dispatch branch or show the form (owner).
- [ ] Known failure `KF-REPORT-YEAR-SUFFIX` (same): `index.php` accepts
  `?year=2024-1` and passes the string to `page_report()`, whose arithmetic
  (`$year - 1`) warns "A non-numeric value encountered" on PHP 8 and uses
  2024. `http-page-views.php` attributes it to the KF id. Fix: drop the
  variant or parse it (owner; the PHP 5 recording `report-year-variant-anon`
  pins its output).
- [x] Plain page views (`tests/http-page-views.php`, every dispatched page
  and menu link without parameters): `?action=import`/`import2` died with a
  `count(null)` `TypeError`; `search` without `member`, `donations` and
  `kdonations` without `year`, `file` without `id` (undefined keys, now
  `?? 0`, the value `intval(null)` gave), `tinyreport` for visitors
  (`strlen(null)`), `new` (`$bgfandack`), `wk` (`$year`), `tinyreport` and
  `vreport` (`$unit`) warned. Fixed without output changes.
- [x] `KF-IMPORT-REGEX-G` resolved by removal (owner decision): the BAWAG
  voucher-ID duplicate check in `import.php` (11 lines from b08f998
  "Preserve production pgacc changes", a production-only edit) never ran:
  its `/g` regex is invalid (warning on every imported line, no match), and
  its `SELECT` on `import` names columns that table does not have (only
  `line`). Removing it changes no behaviour; identical lines stay skipped
  via `import.line`. `http-import.php` asserts a BAWAG line with a voucher ID
  imports without diagnostics.
- [ ] Optional later feature: a working duplicate check by BAWAG voucher ID
  against `vouchers`.
- CI: mutation testing runs in its own job only with the `mutation` or
  `full-ci` label, weekly and on manual runs (owner decision).
- [x] Review fixes: a failed `pg_connect()` in `index.php`, `check.php` and
  `mbapi.php` called `pg_last_error()` without a connection (an uncaught
  Error on PHP 8); they now log `error_get_last()` and die with
  "Verbindungsaufbau fehlgeschlagen" (`http-connect-failure.php`).
  `report.php` called the nonexistent `pg_prev_error()` on two query
  failures; now `pg_last_error($dbconn)`. `vreport.php` guards a NULL
  `vaccount` like `tinyreport.php` (mutation `vreport-vaccount-null-guard-removed`).
- [ ] Later: a static rule (PHPStan or a grep check) against implicit `pg_*`
  connections.
- [ ] Later: the changed-line base of pull request `synchronize` runs (the
  previous head, not the PR base) for the coverage gate and the mutation job.
- [ ] Later: adding a label (`mutation`, `full-ci`) re-runs the whole
  pipeline, not only the added job.

Harness follow-ups found in the first step (not done there):

- [ ] HTTP branch coverage looks incomplete on PHP 8.5: most HTTP-only pages
  report 0% branches while their lines are covered. Likely cause: OPcache is
  on for `php -S` (`opcache.enable`), so Xdebug sees branch data only when a
  file is compiled. Line coverage and the changed-line gate are not affected;
  the branch report is advisory (`PGACC_COVERAGE_STRICT_BRANCHES=0`).
  Candidate fix: `-d opcache.enable=0` for the coverage server, which also
  makes compile-time deprecations appear on every request (owner decision).
- [x] Remove the inert pre-9.x PostgreSQL branches (`tests/database-smoke.sh`,
  `pgacc_mutation_fixture_files()`/`pgacc_mutation_drop_database_sql()`,
  `tests/fixtures/plpgsql-*.sql`, the reference statement log and
  `PGACC_REFERENCE_PGOPTIONS` in `tests/reference/runtime/prepend.php`), the
  PHP 5.x/7.x remarks and dead branches in the tests, the
  `$http_response_header` fallback and the CI lane matrix. PHPCompatibility
  stays: it reports deprecated functions, constants and casts that PHPStan
  does not. The reference allowlist's `pg` key and `PGACC_TEST_PG` stay
  until the reference is re-baselined.
- [x] `csrf` added to the `ci.sh` list (all 14 targets).

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
  PHP 8.0 passes too since the `edit.php:120` fix (no baseline entries);
  PHP 8.5 remains failing on unlisted diagnostics.
  The runner attempts every lane and returns nonzero if any fails.

## Security fixes (before `php5-last`)

Hotfixes for the production server, each tested in `tests/write-workflows.php`
with synthetic users and covered by a mutation target.

- [x] Request values in SQL. Every top-level file was reviewed for request
  values (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, posted form fields)
  that reach SQL. Fixed (mutation target `sql-injection`):
  - The voucher ID of approval, finalization, approval reset and revision
    (`edit.php`) and of the receipt upload (`new.php`) was used unchecked.
    It must now consist of digits only (`/^\d+$/D`, the same set that
    `page_edit()` passes through `intval()`); anything else gets the existing
    "Buchung nicht gefunden" error and nothing runs. Valid IDs behave as
    before. Values that the database used to accept as well, such as a
    leading `+`, surrounding whitespace or a final newline, are now refused.
  - Old approval names posted with a revision (`ack1`/`ack2`,
    `ack1_old`/`ack2_old`) are escaped before the insert in
    `page_save_buchung()`. Names with a quote used to fail the query; they
    are now stored as posted.
  - The voucher date pattern in `get_voucher()` anchored each alternative at
    one end only. It is grouped now, so only a whole `D.M.20YY` or
    `20YY-M-D` is accepted; text before or after a date is treated like a
    missing date ("Kein Datum angegeben", saved as 1800-01-01, the existing
    handling) instead of being passed to the database.
  - The old-approval listing filter (`getfilter()`, `filter_ack_old`)
    inserted the value of `filter_ack`, which is not checked against a
    pattern there. First escaped; since the review fix it uses its own
    value, which its pattern checks (bug fix: the filter now filters by the
    entered old approver; without a valid `filter_ack_old` it is not applied).
  Reviewed without change: `intval()`/digit-pattern values (`edit.php`
  merge, `page_edit()`, `recover.php`, `search.php`, `donations.php`,
  `index.php` hide and cookies, `file.php`), escaped values (`index.php`
  login, `accounts.php`, the text fields of `get_voucher()`), pattern-checked
  values without quotes (the other `getfilter()` filters, `kdonations.php`,
  `spendings.php`, `tinyreport.php`, `vreport.php`, `check.php`,
  `index.php` year), and whitelists (`getsort()`, `index.php` action).
  Review fix: a member voucher (`page_save_buchung()`) takes the member's
  name from the membership list (`ppmembers`) and inserted it unescaped (a
  name with a quote failed the query); it is escaped now. PR review fix:
  `import.php` has the same assignment (member found by its name in a
  line without a name); it is escaped too, and its `break`, which ended
  the import of the whole file there (the remaining lines were dropped
  while the file was reported as imported), is removed. **Behaviour note:**
  such a line and the following ones are imported now. The bank import
  compares the line without spaces, so only one-word member names match.
  Third PR review fix: the lookup escaped the already escaped line a
  second time (quoted names never matched) and used member names as LIKE
  patterns (`%`, `_` and an empty name matched every line, which since the
  `break` fix led to wrong member vouchers). The line is escaped once, the
  wildcards and the escape character of the name are escaped (`E''`
  strings, so independent of `standard_conforming_strings`) and empty
  names are skipped. Tests in `ImportMemberMatchTest` (synthetic member
  list through a replaced stream wrapper), mutations in `import-checks`.
  Second review fix: `getusers()` inserted the member id of the membership
  list unchecked into `ppmembers` (a second-order value); it is `intval()`
  now. Name (escaped), organization (mapped by a `switch`) and paid flag
  (`intval()`) were already safe. Test in `GetUsersTest`.
  Review fix: `import.php` inserted dates read from the uploaded bank CSV
  without escaping (`format_date()` passes unrecognized text through).
  Every imported line now needs a valid date (`import_date_valid()`:
  `YYYY-M-D` after `format_date()` and `checkdate()`, so no month 13 or
  February 30) and an
  organization the user may use (`organization_allowed()`, the rule of the
  voucher form; the bank import used the CSV's organization column or the
  account's organization unchecked). Otherwise the line gets the existing
  "Ungültige Buchungszeile" report with its line number and is skipped; the
  remaining lines and files are imported, and each file ends with a summary
  of its skipped lines (second review fix; at first the import stopped
  there, as it still does for an unknown account). Behaviour difference: organization users can import only
  lines of their own organizations (before, any line, e.g. PayPal and
  Sparkasse lines of organizations 10 and 6). The other CSV values were
  already escaped or `intval()`; `import2.php` uses only values matched by
  digit/ID patterns. Second review fix in `import2.php`
  (`page_import2_match_voucher()`): the voucher lookup had no rights
  restriction and no finalized check, so a statement could match a voucher
  of another organization (the receipt was stored and "done" printed,
  although the scoped update changed nothing) or a finalized voucher. The
  lookup now uses the rights SQL and skips vouchers with a finalized row
  of any organization ("Keine Buchung gefunden."), and the receipt is
  stored only after the scoped update matched the row (otherwise "Beleg
  konnte nicht gespeichert werden.", and a failed store resets the row's
  file number). Tests in `ReceiptImportTest` (the PDF import itself runs
  only on PHP < 7.2, see the dependency notes). New `tests/http-import.php`
  (synthetic CSV fixtures of `tests/reference/fixtures/import/` and the
  membership stub), mutation target `import-checks`.
- [x] Posted organization of a new voucher or a revision. `get_voucher()`
  saved any posted organization (`lo<part>`). Now `page_new_save()` and
  `page_edit_save()` first check every posted organization with
  `posted_organizations_allowed()`: users with the exact `bgf` right
  (`has_right()`, so not `Rbgf`) may post any, everybody else only the
  numeric organizations of their rights. Otherwise the
  whole voucher is refused before anything is saved, with the
  "FEHLER: Keine Zugriffsrechte." error of `accounts.php`. Without a posted
  organization the default applies as before. Behaviour differences: the
  rights SQL exempts `Rbgf`, but it may not post an organization outside
  its numeric rights; an organization such as `01` is refused.
  Mutation target `rights-checks`. The check is `organization_allowed()`
  in `functions.php`. (A review round briefly let the exact `root` right
  post any organization, as the form offered it; the PR review reverted
  that, see below.)
  Second review fix: rights tokens are trimmed (`rights_tokens()`, used by
  `has_right()`, `write_rights()` and `organization_allowed()`), so
  `bgf, root` keeps `root` (still exact: ` Rbgf ` is not `bgf`), and
  organizations are compared as numbers like the database compares the
  rights SQL (`03` allows organization 3, `01` posts organization 1). The
  voucher form's organization list uses `organization_allowed()`, so it
  offers exactly what the save allows (before, `bgf`/`root` with spaces saw
  only their numeric organizations). PR review fix: writes are a subset of
  what the user can read. `rights2orgasql()` (reads) uses `rights_tokens()`
  too, so ` 2` and ` bgf` count for reads as for writes, and `root` is no
  organization right for writes either (it never was for reads): a user
  with only `root` reads and writes no organization, `root,3` reads and
  writes organization 3. This matches the base branch, where `root` could
  not post an organization outside its numeric rights either; the form
  no longer offers `root` organizations it cannot save. Users with `bgf`
  (all real admins have `root,bgf`) are unaffected. Owner decision: rights are
  trimmed everywhere; the voucher form's remaining checks (organization
  field, save buttons in `end_of_form()`, row delete link) use the trimmed
  helpers too, which also removes the PHP-version difference of
  `is_numeric(' 2')`.
  Review fix: a part without a posted organization is checked with the
  organization `get_voucher()` falls back to (`default_organization()`: the
  user's single organization, otherwise 10). Behaviour difference: users
  without rights, and users of several organizations who post none, can
  no longer save vouchers into organization 10 (the form always posts an
  organization for several organizations).
- [x] Revisions (`page_edit_save()`, review fix). The finalized and
  approved checks used the rights SQL, so a user of one organization could
  revise (add rows to) a voucher whose rows of another organization were
  finalized or approved, and a revision of a voucher number without rows,
  or without rows of the user's organization, created rows under that
  number. Now a revision needs a current (not deleted) row that the user
  sees ("FEHLER: Buchung nicht gefunden." otherwise), and the finalized
  check covers the whole voucher. Behaviour difference: `bgf` can no
  longer revive a voucher number whose rows are all deleted through the
  empty voucher form. Mutation target `rights-checks`.
- [x] Approvals when a voucher changes (owner decision
  on item 8 of the second review). The "bereits bestätigt" refusal of
  revisions (first organization-scoped, then briefly voucher-wide) is
  removed: a user may correct their own rows also while rows of another
  organization are approved. Instead every revision, receipt upload
  (`page_new_file()`) and PDF receipt import (`import2.php`) resets the
  approvals of the whole voucher: for every current row of every
  organization `ack1`/`ack2` move to `ack1_old`/`ack2_old` and are cleared
  (`vouchers_reset_ack()` without rights restriction). The new rows of a
  revision take their old approvals from the user's first current row in
  the database, after the reset (the same names the form used to post);
  posted `ack1`/`ack2`/`ack1_old`/`ack2_old` are no longer used, which
  closes the open "posted old approval names" finding. A finalized row
  anywhere in the voucher still refuses the change. Behaviour differences:
  approved vouchers can be revised and get receipts again (their approvals
  are reset, also of other organizations' rows); before, a revision of an
  approved voucher was refused and an upload reset only the user's rows.
  Tests in `tests/write-workflows.php` and `ReceiptImportTest`, mutations
  in `rights-checks` and `import-checks`.
- [x] Receipt upload (`page_new_file()`, second review fix): there was no
  finalized check, so a receipt could be attached to a finalized voucher
  (which also reset its approvals). A finalized row of any organization now
  refuses the upload with "bereits finalisiert". Its approved check is
  removed with the approval reset below. Mutation target
  `rights-checks`. Third review fix: the upload stored the receipt and
  reset the voucher's approvals before its organization-scoped update,
  which might match no row (a row of another organization or voucher, or
  none). Now the scoped update must match the posted row first ("FEHLER:
  Buchungszeile nicht gefunden." otherwise, nothing stored or reset), and
  a failed store gives the row back its previous file number (PR review
  fix; at first it was reset to 0, which dropped an existing receipt). The
  update also requires the file number it checked, and the PDF import's
  update requires `file = 0`, so a concurrent change is not overwritten
  (race only; the mutants are accepted equivalents). Tests
  in `ReceiptImportTest`, mutations in `import-checks`. PR review fix: the
  row check (a current row of the voucher in the user's scope) now comes
  before the finalized check, so a user of another organization gets
  "Buchungszeile nicht gefunden" instead of learning that the voucher is
  finalized.
- [x] Merging vouchers (`page_edit_merge()`, `?action=merge`) had no
  server-side check; the edit page offers it only to `root`
  (`edit.php`, `strpos($rights, 'root')`). It now requires the exact `root`
  right and otherwise shows the warning of the recovery page ("nur von
  Admins"), changing nothing. Behaviour difference: `bgf` and organization
  users without `root` could merge by a direct request before (within their
  rights SQL). Mutation target `rights-checks`.
- [x] Approval reset (`page_edit_drop_acks()`). The button is shown only to
  `bgf` users, but the server did not check it, and the query appended the
  rights instead of the rights SQL: with empty rights it reset the
  approvals of any voucher, other rights failed with an SQL error. It now
  requires the exact `bgf` right; other users get "FEHLER: Keine
  Zugriffsrechte." and nothing changes. At first the argument was left
  as it was (the reset failed for `bgf` users with a syntax error, known
  failure `KF-DROP-ACKS-BGF`). PR review fix: appending the rights string
  to the SQL was a second-order injection, so the reset now gets the rights
  SQL (empty for `bgf`: the approvals of the whole voucher are reset, as on
  every other change), and a finalized row refuses the reset ("bereits
  finalisiert"), as for approval, revision and upload. **Behaviour change
  approved by the owner:** the reset button works for `bgf` users for the
  first time (it was first left broken on purpose). Mutation target
  `rights-checks`.
- [x] Finalization (`page_edit_finalize()`, POST `beleg`) is **removed**
  (owner decision). History: the treasurer button ("Ja, es ist alles in
  Ordnung. Ich möchte die Buchung finalisieren.") was shown to `bsm` after
  both approvals until `68cda09` (2015) and was never checked on the
  server; afterwards the action stayed reachable by a direct request for
  every user, within the rights SQL. Evidence: on production a single
  voucher row has ever been finalized (booking date 2011), none of about
  6,400 rows booked 2012-2025 (read-only count by the owner). The function,
  its `index.php` dispatch and the unused `$beleg` of `page_edit()` are
  deleted; a POST with `beleg` now shows the voucher page and writes
  nothing (tested for admin, `bgf`, an organization user and `Rbgf`). The
  `receipt_received` column and every "bereits finalisiert" check (approval,
  revision, receipt upload) stay, so the existing finalized row stays
  locked. Mutation target `rights-checks` (`finalization-write-restored`).
- [x] Approval of a voucher of another organization (`page_edit_ack()`,
  `KD-EDIT-RIGHTSSQL`): the finalized check ran without the (then undefined)
  rights SQL, so a user of another organization learned whether a voucher
  number was finalized. The approval is now refused with "Hier fehlt noch
  der Beleg!" first when the user sees no row of the voucher; see the
  [diagnostics table](#diagnostics-surfaced-by-the-strict-http-checks).
  No approval outcome changes. Review fix: the visibility check counts only
  rows that are not deleted, so a voucher with nothing but old revisions in
  the user's organization is refused too (before, its deleted rows could
  get approvals; the approval updates themselves are unchanged). Second
  review fix: the receipt check and both approval updates skip deleted
  rows too, so an approval no longer stamps old revisions or clears their
  old approvals (`ack1_old`/`ack2_old`, shown in the revision history), and
  an unapproved old revision no longer lets the receipt check pass.
- [x] New users (`accounts.php`, "Benutzer -1 erstellen", root only) were
  stored with the creating root user's rights instead of the entered ones
  (the update path used the entered rights). Bug fix: the entered, escaped
  rights are stored. **Flagged for the owner:** users created so far have
  the rights of their creator; review them in the user list. Mutation
  target `rights-checks`.
- [x] Read-only `Rbgf` (owner decision). `accounts.php` documents a
  leading `R` as read-only access ("Rbgf"), but only the rights SQL knew
  `Rbgf` (as unrestricted), so it could write everywhere. `write_rights()`
  (`functions.php`) drops the `Rbgf` token for writes and returns false
  when nothing else remains; rights without `Rbgf` are returned unchanged,
  so `bgf` and all other users are unaffected. Write paths and their
  checks:
  - voucher creation (`page_new_save()`), receipt upload
    (`page_new_file()`), revision (`page_edit_save()`) and approval
    (`page_edit_ack()`): `write_rights()`, refused with "FEHLER: Keine
    Zugriffsrechte.", otherwise run with the write rights;
  - finalization and approval reset: exact `bgf`; merge: exact `root`
    (fixes above); recovery: `root` (unchanged);
  - user changes (`accounts.php`, also the own password): `write_rights()`,
    with the page's existing error;
  - bank import and receipt import (`index.php`, before `page_import()`,
    which calls `getusers()` and rebuilds `ppmembers`, and
    `page_import2()`): `write_rights()`;
  - allowed as the user's own data: login cookie and the listing column
    preferences (`?hide=`);
  - unchanged: the member-fee page (`mb.php`) is a read page for `bgf`
    and `Rbgf` that rebuilds the `ppmembers` cache through `getusers()`;
    `?action=transfer` and the `ack` button of the new-voucher form call
    undefined functions (fatal for everyone).
  The menu hides "Erfassen", "Import" and "Belege Import", and the voucher
  form (`end_of_form()`) shows no save, approval or reset buttons for
  read-only users; the new-voucher form itself still renders. Reads are
  unchanged (tested: ledger, CSV, open and old-revision listings, voucher
  pages of two organizations). Behaviour differences for `Rbgf` alone:
  every write above is refused. For `Rbgf` with organizations (e.g.
  `Rbgf,2`): reads stay unrestricted, writes are restricted to those
  organizations like an organization user (before: approval and revision
  of every organization; a voucher without a posted organization now
  defaults to organization 2 instead of 10). Tests in
  `tests/write-workflows.php` (users `synthetic-read-only` and
  `synthetic-read-only-two`) and `FunctionsTest`, mutation target
  `read-only-rights` (the bank import check is not mutated, because its
  removal would contact the membership system).

- [x] Exact role checks (review fix). Role checks used `strpos($rights,
  'root'|'bgf')`, which matches any right containing the word (`xroot`,
  `Rbgf`), so for example a user with a right `xroot` could change other
  users' rights and passwords. All are exact tokens now (`has_right()`):
  `accounts.php` (9 checks: other users, password and rights updates, user
  list, help text, rights column and inputs, new-user row), `recover.php`
  (root), the merge link (`edit.php`, root), the approval reset button
  (`$bgfandack`, bgf). Read checks keep `Rbgf` explicitly: the empty
  voucher page for bgf (`edit.php`, bgf or Rbgf), the member-fee page
  (`mb.php`, bgf or Rbgf) and the old-revisions menu link (`functions.php`,
  bgf, Rbgf or root). Behaviour difference: only for rights that contain
  but are not `root`/`bgf`/`Rbgf`; real users are unaffected. The other
  role checks (`in_array()` in `new.php`, `rights2orgasql()`) were already
  exact. Tests in `tests/write-workflows.php` (a user with `2,xroot,xbgf`)
  and `FunctionsTest`, mutations in `rights-checks`; the empty voucher page
  and the reset button are not mutated (the first raises the open
  `$vouchers` finding, the second is hidden by `end_of_form()` anyway).
- [x] Login session token (`login()`, `checklogin()`, helpers in
  `functions.php`). The `pp_pgacc_login` cookie was the `mt_rand()` value
  stored in `users.cookie`. `users.cookie` stays an `integer` column (no
  schema change): it now holds a nonce of 31 random bits (1 to 2147483647)
  from a CSPRNG (`random_bytes()` on PHP 7+, else
  `openssl_random_pseudo_bytes()` with a strong result, else
  `mcrypt_create_iv()` with `/dev/urandom`; PHP 5.3 has no `random_bytes()`),
  replaced by every login. The cookie is
  `hash_hmac('sha256', "pgacc-login|<id>|<nonce>|<users.login>", K)` in hex
  (64 digits) with the session key
  `K = hash_hmac('sha256', 'pgacc-session-key', $key, true)`, derived from the
  configured `$key` of `constants.php`. `checklogin()` selects the user by
  the id cookie and compares the token in constant time
  (`login_token_equals()`, through `receipt_equals()`: `hash_equals()`, or
  its fallback on PHP 5.3). Neither the cookie nor `users.cookie` alone lets
  anyone forge a session without `$key`. Fails closed: without a valid `$key`
  (`receipt_configured_key()`, the same check as for receipts) or a CSPRNG, or when the session cannot be
  stored, nobody can log in (the login form is shown again);
  `login_start_session()` logs the reason (`pgacc: login refused: ...`,
  without key material), so that operators can tell it from a wrong
  password. Tests in `tests/http-login.php` (token format, rotation,
  wrong, old-format and other users' tokens, 24-hour expiry) and
  `LoginSessionTest`, mutations in `login-logout`. Review fixes: a
  logged-out session (`users.cookie` NULL) is refused, also with a token
  computed for an empty nonce. `checklogin()` compares a token even when
  the query returns no row, using the result in the same authentication
  guard instead of discarding a dummy comparison (which PHPStan rejected).
  It still iterates over rows: the legacy schema does not guarantee unique
  user IDs. This equalizes the comparison step, not the complete HTTP/SQL
  request timing. Tests also reject the exact dummy token for an absent user
  and preserve lookup after a first nonmatching row. The session check and the logout pass
  the connection explicitly (PHP 8.5 deprecates the implicit one, displayed
  before the logout headers).
  **Deploy:** check that `constants.php` sets a valid `$key` (also needed for
  receipts, see the receipt key runbook). Every user is logged out once by
  the deploy (old cookies are numbers, no longer valid tokens). Changing
  `$key` later logs everyone out again; the receipt migration does not
  change `$key`.
- [x] Logout ends the session on the server: it clears `users.cookie` of
  the logged-in user before clearing the browser cookies, so a kept copy of
  the cookies no longer authenticates (before, it stayed valid until the
  24-hour expiry). Only the session of valid cookies is ended; a logout with
  wrong or no cookies changes nothing. Logout ends every session of that
  user (all sessions of a user share `users.cookie`, as before: a new login
  also ends the previous one). Tests in `tests/http-login.php`, mutations in
  `login-logout`. A standalone `tests/http-session-lifecycle.php logout`
  run on pre-fix `bac674e` fails because the cleared-nonce token authenticates;
  the same scenario passes on the fixed branch without diagnostics.
- [x] A password change ends the sessions of that user (`accounts.php`
  clears `users.cookie` with the new hash, for an own change and a change by
  root), so stolen cookies stop working. A change of the rights only keeps
  the sessions. Behaviour change: a user who changes the own password is
  logged out and logs in again with the new one (`page_accounts()` checks
  the session once, so the page after the change still shows the own
  account). Tests moved from the end of `tests/write-workflows.php` into
  `tests/http-session-lifecycle.php`, run by both the 7.4 and production
  lane clients and by `rights-checks`. The standalone `password` scenario
  fails on `7c49674` because an own password change leaves `users.cookie`
  set, and passes on the fixed branch. Root changing another password and
  rights-only updates remain covered; the former ends only the target's
  session, the latter preserves its token. No baseline entries or assertion
  strength were relaxed.
  Follow-up verification on the isolated login branch: static analysis passes
  unchanged baselines; PHP 7.4 covers 83/83 executable lines in the login delta
  from `2be90d8`; the PHP 5.3 lane passes. The login mutation target kills 56
  mutants with three existing equivalents, and Infection kills all 127
  generated login-helper mutants. Its unrelated legacy-helper survivors are
  still reported. The read-only account target follows the cached `$ownId`
  introduced with password invalidation and its mutant is killed.
  The existing approval-visibility equivalent classification from `923ac01`
  was carried to this fork: `edit.php` is byte-identical to that reviewed
  version, and the equivalent was reconfirmed separately from the other 62
  killed rights-check mutants.
  **Separate inherited test gap:** `new-save-read-only-allowed` survives in
  both parent `69ac7c3` and this follow-up; the other 12 read-only mutants are
  killed. No voucher code changed here, and no new equivalence was assumed
  for that case. Resolve it with the security integration rather than
  expanding this login task. Raw mutation results remain visible.
- [x] Login cookie attributes. `pp_pgacc_login` and `pp_pgacc_id` were set
  with `setcookie()` and only an expiry: readable by scripts, sent over plain
  HTTP and on cross-site requests. `index.php` now sends both Set-Cookie
  headers itself (`login_cookie_header()`; `setcookie()` of PHP 5.3 has no
  SameSite) with `secure; HttpOnly; SameSite=Lax`, still without path or
  domain, and with `expires` and `Max-Age` on every PHP version (PHP 5.3
  sent no `Max-Age` before). Logout deletes them as `setcookie()` of PHP
  5.5+ does (`deleted`, 1970 expiry, `Max-Age=0`). The app is only served
  over HTTPS (the proxy; `index.php` redirects other requests), so `secure`
  does not change what browsers send there. Tests in `tests/http-login.php`
  (the HTTP test client sends cookies itself, so the attributes do not
  affect it) and `LoginSessionTest`, mutations in `login-logout`.
- [x] `mb.php` divided by zero on the synthetic data (member-fee page for
  `bgf`): the average of the sum row without member payments in the period.
  Fixed: it shows `0.00 €`, the value PHP 5.3 printed (with a warning);
  PHP 7.4 printed `NaN €`, PHP 8 stopped with `DivisionByZeroError`. Tested in
  `tests/http-output-escaping.php`, mutations `mb-sum-*` in `output-escaping`.

Open security findings (not changed here; owner decisions):

- [x] No CSRF protection: every write action is a plain form POST or GET
  link authenticated by cookies. Fixed: form tokens and POST-only writes
  (see "Planned security follow-ups").
- [x] Output is not HTML-escaped in several places, e.g. the user names in
  the user list (`accounts.php`, root only) and the listing filter fields
  (`closed.php`). Fixed: the voucher page (`page_edit()`) echoed the
  request's voucher and row IDs in its notices and heading; it now uses
  them as `intval()` numbers throughout, as its query already did (a
  request such as `id=5abc` shows "Buchung 5" instead of "Buchung 5abc").
  Fixed (output encoding): database and request values are encoded with
  `html_escape()` (`htmlspecialchars($v, ENT_QUOTES, 'ISO-8859-1')`) where
  they are echoed into HTML text or attributes: the ledger listings
  (`closed.php` rows, sort links and filter inputs, also used by `all`,
  `open`, `deleted`, `transactions`, `spendings` and the `kdonations` header,
  which the HTTP tests do not request), the voucher
  form and approvals (`new.php`, `edit.php`), the user list
  (`accounts.php`), the public report, campaign, transaction, spending and
  virtual-account pages (`report.php`, `wk.php`, `transactions.php`,
  `spendings.php`, `vreport.php`), the account report (`tinyreport.php`),
  the membership-fee page (`mb.php`, also the member names from the
  membership system), the file names, MIME types and rejected lines of
  the imports (`import.php`, `import2.php`) and the database error of a
  failing user change (`accounts.php`, which no longer prints the query
  with the new password; tested with a duplicate user name, whose
  `pg_query()` warning is the known failure `KF-ACCOUNTS-QUERY-ERROR`. A
  server that displays errors still shows that warning with the query). The account links of `vreport.php` and
  `tinyreport.php` carry the account `rawurlencode()`d. The two sinks that
  no page calls, `kdonations_page_listing_line()` (donor list, disabled for
  legal reasons) and the `wk` variant of `wk_unit_report()`, are encoded too
  and tested directly (`tests/unit/UnusedOutputTest.php`). Listing cells shared with the
  CSV export use `listing_text()`, which leaves the CSV unchanged. The sort
  links carry the filter values `rawurlencode()`d (so `&`, `#`, `%` and `+`
  survive a re-sort) and the whole link, like the CSV link, is
  HTML-encoded; filter inputs use `html_escape()`. **Byte change** for
  ordinary listings: `&` in these links is now `&amp;`, filter values in
  sort links are percent-encoded (`>0` is `%3E0`, `Büro` is `B%C3%BCro`) and
  an amount filter input shows `&gt;0`; browsers send the same requests.
  Bug fix: the sort links dropped the refund filter (`filter_refund`), unlike
  every other filter; they keep it now.
  Encoding: the pages declare UTF-8 and the database is UTF-8; ISO-8859-1
  makes `htmlspecialchars()` byte-transparent on PHP 5.3 to 8.5 (with
  `UTF-8`, PHP 5.3 returns an empty string for invalid input), so text
  without `& < > " '` (umlauts included) is byte-identical. **Behaviour
  change** for values containing these characters: they are shown as text
  instead of being interpreted (e.g. a stored `&uuml;` now displays as
  written, not as `ü`). Tests: `tests/unit/FunctionsTest.php`,
  `tests/http-output-escaping.php`, `tests/unit/UnusedOutputTest.php`;
  mutation target `output-escaping`. `login.php` uses `html_escape()` for
  its (hex-only) target. Not encoded: values that are already digits or
  validated by a pattern without these characters (IDs, dates, the `konto`
  heading of the reports). `import.php`'s upload-error message is encoded
  and tested with a constructed upload (`ImportMemberMatchTest`): browsers'
  upload errors arrive without a MIME type, so the type check reports the
  file first.
- [x] CSV export cells that start with `=`, `+`, `-`, `@` or `"`, also
  after leading whitespace (an explicit class, so PCRE 8 on PHP 5.3 and
  PCRE2 agree on the vertical tab), are prefixed with `'` (`tag()`), so spreadsheets
  neither evaluate them as formulas nor unquote them (a leading `"` would
  also merge cells). Tabs inside a cell become spaces, and `\r\n` and a
  lone `\r` become ` / ` like `\n`, so a cell can no longer split the
  tab-separated row (before, `one<TAB>two` added a field). The amount
  pattern's decimal point is escaped (`-1+2€` passed as an amount); an
  amount without decimals such as `-12€` still counts as one. Amount cells (`-12,50€`) and plain numbers
  (`-5`, `-5,25`) are unchanged. **Behaviour change** for such cells only
  (e.g. a name `+43 ...` or a text starting with `-` or `=`).
- [ ] CSV export opened by Excel with the locale's list separator (`;` or
  `,`) instead of the tab: a formula after such a separator inside one cell
  starts a new cell and escapes the formula guard. Options: name the file
  `.tsv`, add a `sep=` first line, or neutralize after separators too; owner
  decision.
- [ ] `tag()` could encode its text by default (with an explicit raw
  variant for markup) instead of each listing caller using
  `listing_text()`, so a new caller cannot forget it.
- [x] Bug fix: the voucher form shown again from the posted values (adding
  or removing a part, `get_voucher()` in `new.php`) showed the SQL-escaped
  text, so `O'Brien` became `O''Brien` (and a backslash doubled on
  PostgreSQL 8.4), and every save of that form doubled them again. The form
  now shows the posted text; only saving escapes it.
- [x] Bug fix: the open loans of the membership-fee page (`mb.php`) showed
  `intval()` of the name, i.e. `0`; they show the (encoded) name now.
- [x] The default organization of a single-organization user
  (`default_organization()`, `new.php`) used `is_numeric()` and `intval()`,
  whose results differ between PHP versions for unusual rights values.
  Fixed in the PR review: it takes the trimmed single token
  (`rights_tokens()`, null-safe) and accepts only digits, like
  `organization_allowed()`, so `2 ` is organization 2 on every version and
  hex or exponent notation falls back to 10.
- [x] The login `target` was redirected to without a check
  (`login_redirect()`, `login()`): any URL, and on PHP 5.3 line breaks that
  add headers. Now only a page of this application is allowed
  (`login_target_allowed()`): empty (the form's default, sent as an empty
  `Location` as before), `index.php` with an optional query or fragment, or
  an absolute path such as the `/index.php?...` targets of `checklogin()`;
  printable ASCII only, without spaces, backslashes, a scheme or `//`.
  Anything else, also a target that is not hex, redirects to `index.php`.
  The login form (`page_login()`) echoed `?target=` unescaped into its
  hidden field; it now keeps only an allowed hex target (else empty) and
  escapes it. Behaviour change: targets with raw non-ASCII characters or
  spaces (browsers send them percent-encoded) and relative paths other than
  `index.php` now lead to `index.php`. Other `Location` headers were
  reviewed: `relocate()` (`edit.php`, `new.php`) builds them from
  `intval()` IDs, `page_search()` from an `intval()` member id, and
  `checklogin()` hex-encodes the request URI; the HTTPS redirect of
  `index.php` uses the request's own host and URI (only for requests that
  did not come through the proxy). Tests in `tests/http-login.php` and
  `LoginSessionTest`, mutations in `login-logout`.
- [x] Old approval names posted with a revision were stored as given
  (`page_edit_save()`); fixed with the approval reset (they
  come from the database).
- [ ] `edit.php` still shows the approval reset button to `bgf` via
  `strpos()` (`$bgfandack`); it is hidden for `Rbgf` by `end_of_form()`.

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
  The receipt upload redirect (`new.php:266`, PDF upload on the edit page)
  is tested in `tests/http-receipts.php` since the `mcrypt` replacement.
  `page_edit_drop_acks()` ("Bestätigungen zurücksetzen!") reaches its
  redirect for `bgf` users since the PR review fix (see
  [Security fixes](#security-fixes-before-php5-last)). The receipt download (`file.php`) and the
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
| `KD-NEW-RIGHTS` | `new.php:184` | Hidden notice: undefined `$rights` passed to `page_save_buchung()`. | [x] Fixed: `page_new_save()` takes the `$rights` that `index.php:264` already passed, as `page_edit_save()` does for revisions. The rights only choose the default organization in `get_voucher()` when the form posts none, which is always the case for a user with a single organization (the form shows the organization field only for rights with several entries). Before: such a user's new voucher was saved in organization 10 (Bund), which the user cannot see or edit. After: it is saved in the user's organization. Unchanged: `bgf`-only, multi-entry and admin rights still default to 10. A posted organization is checked since the security fix above. **Owner decision:** behaviour change; vouchers created this way so far are in organization 10. Tests in `tests/write-workflows.php`, mutation target `rights-checks`. Entry and PHPStan baseline entries (undefined `$rights`, `page_new_save` invoked with 1 parameter) removed. |
| `KD-EDIT-RIGHTSSQL` | `edit.php:120` | Hidden notice: undefined `$rightssql` in the finalized-voucher check, so that query had no organization restriction. | [x] Fixed: `page_edit_ack()` computes `$rightssql` first and answers a user who sees no row of the voucher (same scope as the receipt check, deleted rows included) with the existing "Hier fehlt noch der Beleg!" before the finalized check; the finalized check itself stays explicitly unscoped. Users of the voucher's organizations get the same answers as before (a finalized row of another organization still blocks the approval of their rows); a user of another organization can no longer tell whether a voucher is finalized. Entries (5.6/7.4 and the reported PHP 5.3 one) and PHPStan baseline entry removed; tests in `tests/write-workflows.php`, mutation target `rights-checks`. |
| `KD-RECEIPT-KEY` | `new.php:252`, `import2.php:189`, `file.php:19` | Hidden notice: undefined `$key` in receipt upload, PDF import and download, reached once the `mcrypt` replacement made these paths testable. | [x] Removed: the call sites pass `''`, the value the undefined variable had, so the ciphertext is unchanged. Since then new receipts use the configured key (receipt key item under [Short tags and other migration debt](#short-tags-and-other-migration-debt)). |

`KD-EDIT-RIGHTSSQL`, `KD-NEW-RIGHTS` and `KD-REPORT-PREVCOND` (all fixed) were also PHPStan
baseline findings ("Undefined variables in function scope" under
[Static-analysis findings](#static-analysis-findings)). A fix for any of them
must remove both its entry here and its PHPStan baseline entry; the static
check fails until the stale baseline entry is gone.

PHP 8.0 and 8.5 have no baseline entries; every diagnostic fails the lane.
In addition to the PHP 8 forms of the issues above, they report:

- PHP 8.0 and 8.5: undefined `$rightssql` (`edit.php:120`) as a warning
  (fixed, like `$rights` at `new.php:184`). The `new.php:450` deprecation (also a former
  PHPCompatibility baseline finding), the login/logout "headers already sent"
  warnings, and the uncaught `Error` "No PostgreSQL link opened yet" at
  `index.php:78` (from `acc_footer()` after `relocate()`) are fixed. A
  targeted PHP 8.0 run after the redirect fix: `http-login.php` and
  `http-redirects.php` pass (also unbuffered), `write-workflows.php` fails
  only on the two warnings above; after the `new.php:184` fix a PHP 8.0 run
  failed only on `edit.php:120`, which is fixed too (see the security
  fixes).
- PHP 8.0 and 8.5: `tests/http-receipts.php` stopped at the receipt upload,
  where `relocate()` led to the uncaught "No PostgreSQL link" error (fixed
  since; not re-checked on PHP 8 after the rebase onto that fix).
  The PHPUnit contract tests pass on both.
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

Since 2026-10-24 the harness tests production's own server, PostgreSQL 8.4.20
(Debian squeeze i386 packages 8.4.20-0squeeze1, configured like production;
see the [test README](README.md#postgresql-versions)), and PostgreSQL 18. The
former stand-ins 9.4, 14 and 17 are gone.

| Lane | 8.4.20 | 18.6 |
|---|---|---|
| PHP 5.3 (`run.sh 5.3`) | pass (default, production pair) | not run |
| PHP 7.4 (`run.sh 7.4`) | CI weekly/PRs (reference compare passes locally) | pass (coverage lane) |
| PHP 8.5 (`run.sh 8.5`) | not run | pass (the only lane since `php5-last`) |

Test infrastructure adapted for 8.4 (no application change): fixtures chosen
by server version (`CREATE EXTENSION pgcrypto` only on 9.1+, pgcrypto from
contrib in 8.4's `template1`; plpgsql created around the characterization
only), characterization without `DO` and `array_agg(... ORDER BY)`, the
mutation runner's `procpid` before 9.2, and the reference statement log
without `application_name` (attributed by connection). Earlier findings from
9.4-18 (`psql` running only the last `--file`, `regnamespace`, PostgreSQL 18
NOT NULL constraint rows, `password_encryption` as a boolean before 10,
`DROP DATABASE ... WITH (FORCE)` from 13, `KD-REPORT-SQL-PG15`) stay handled.

The PHP 5 reference is now recorded on PHP 5.3 / PostgreSQL 8.4. Compared with
the earlier recording on 5.3 / 17, SQL, database changes and helper output
are identical; one page differs:

- [x] `mb.php:19` (member fees per organization) grouped by `lo.name` without
  `ORDER BY`; 8.4 and 17/18 return the groups in a different order
  (`RD-PG-UNORDERED-MB-LO`, removed). The loop added the unknown-organization
  row (name NULL or empty) to "Keine" only when it came before "Keine", and
  only the last of them. Fixed (owner decision): all rows are read first, every
  unknown row counts towards "Keine" (still dropped when there is no "Keine"
  row, as before), and the rows follow `lo.id` (`ORDER BY MIN(lo_id)`) with
  "Keine" last. Visible changes on production: row order, and "Keine" now
  includes every unknown row. Reference re-recorded. Tested in
  `tests/http-output-escaping.php` on 8.4 and 18, mutations `mb-lo-*` in
  `output-escaping`.

Still open from the 8.4 review:

- [ ] `standard_conforming_strings` is **off** on 8.4 (on from 9.1), and the
  8.4 lanes now run with it off. `pg_escape_string()` follows the connection
  setting; no difference appeared in the reference (no literal with
  backslashes reaches SQL in the scenario). Any value with a backslash differs
  between 8.4 and 18 (`escape_string_warning` warnings on 8.4).
- [ ] `bytea_output` is `escape` on 8.4 (`hex` from 9.0); the schema has no
  `bytea` columns.
- [ ] Planner and sort differences beyond `mb.php`: queries without `ORDER BY`
  (or with ties) may differ with production-sized data.

## Further array/input findings

| Location | Trigger and evidence | Follow-up |
|---|---|---|
| `edit.php:345` | A missing voucher ID leaves `$vouchers` undefined when the query yields no rows. An authenticated scoped-user request reproduced `count(null)` on PHP 8.0. | [x] Fixed in the PR review: `page_edit()` initializes `$vouchers` and answers "Buchung nicht gefunden" for a voucher without current rows for every user. **Behaviour note:** `bgf`/`Rbgf` users got an empty form there before, which the save refuses since the revision check; deleted rows are restored with the recovery page. PHPStan baseline entries (`$vouchers` 12, the two always-false conditions) removed. |
| `import.php:27` | `count($_FILES['uploadcsv']['name'])` assumes a multiple-upload array. Reproduced the missing-upload `TypeError` with the membership lookup stubbed locally. | [x] Fixed (plain page views): the upload loop runs only when the upload field was sent, so a GET shows the form as on PHP 5; `tests/http-page-views.php` covers it and the `RD-PHP8-COUNT-NULL-IMPORT*`/`-MANIFEST` entries are removed. |
| `import2.php:24` | A normal authenticated GET with no uploaded PDF reproduced `count(null)` on PHP 8.0. | [x] Fixed (plain page views): the upload loop runs only when the upload field was sent, so a GET shows the form as on PHP 5; `tests/http-page-views.php` covers it and the `RD-PHP8-COUNT-NULL-IMPORT*`/`-MANIFEST` entries are removed. |
| `donations.php:116-122` | With a synthetic qualifying donation and captcha submission, `$donations` is undefined and `array_multisort()` receives null. The collection assignment is commented out for legal reasons. Reproduced on PHP 8.0. | [x] Fixed: `page_donations()` initializes `$donations`, `$sort_sum` and `$sort_name` to empty arrays. Donor publication stays disabled (the commented-out assignment is untouched), so the list is the header row only, as before; only the diagnostics disappear (on 5.6/7.4 they were displayed between the header row and `</table>`). New `tests/http-donations.php` (captcha answered from the form, wrong/missing answer, 2024 above and 2025 below the threshold, years before 2012; asserts that no donor name, encoded name, row or sum appears) failed before the fix on 5.6 and 7.4 and passes on 5.6, 7.4 and 8.0. Mutation target `donations`. `kdonations.php` does not share the pattern: its loop body is empty (publication disabled the same way) and it collects nothing. |
| `index.php:293`, `:289` (`?action=donations`/`kdonations` without `year`) | From the source, not run: `intval($_GET["year"])` without `isset()`, so the menu links (`href="donations"`, `href="kdonations"`) raise an undefined-index notice (warning on PHP 8). `tests/http-donations.php` therefore always passes `year`. | Default to 0 with `isset()` (same page: `page_donations()` maps years before 2012 to 2013) and test the menu links; `index.php` is being changed in parallel. |
| `closed.php:222`, `:246` (`?action=closed`) | Found while testing the listing column preferences, not covered by the HTTP tests: `page_closed()` selects neither `vaccount` nor `ack1_old`/`ack2_old`, so `page_listing_line()` reads three undefined indexes per row (hidden notices on 5.6/7.4, warnings on PHP 8) and the closed listing always shows those columns empty. Observed on PHP 7.4 with `synthetic-admin`. | Behavior decision for the owner: selecting the columns changes the closed listing (it would then show virtual accounts and old confirmations, like the ledger); `isset()` defaults keep today's output. Then add `?action=closed` to the HTTP tests. |
| `search.php:4` (`?action=search` without parameters) | Hidden notice "Undefined index: member", observed on PHP 7.4 while comparing listings before and after the fix above; not covered by the HTTP tests. | Review with the search form tests. |

Other direct `count()` sites were reviewed: the local arrays produced by
`explode()` and the guarded regular-expression result arrays do not share the
same uninitialized-variable path. No additional obsolete curly-brace offsets
were found in the tracked application files; PHP 8.5 syntax checks pass.

## Found by the PHP 5 reference

Pinned as current behaviour in `tests/reference/php5/` (recorded on the
production-like PHP 5.3 lane); none is fixed. Behaviour changes are the owner's
decision. The differences of the other lanes are reviewed entries in
`tests/reference/allowlist.php`: `accepted` (environment) or `todo` (to fix; the
`RD-*` IDs below).

| Location | Finding | Follow-up |
|---|---|---|
| `import.php:252` | When a CSV line without a parsed name matches exactly one member through the `ILIKE` lookup, `break` leaves the `fgetcsv()` loop: that line and all later lines of the file are not imported, yet the page reports "wurde erfolgreich importiert" (`http/import-bawag.txt`). All versions. | Owner decision: the `break` probably meant to end only the lookup. Test a match on a middle line first. |
| `import2.php:103`, `:133` | `page_import2_process_multi_ids()` strips all bytes `\x80-\xFF` before matching `Entgeltf.+r(Kontof.+hrung\|...)`, so "Entgelt für Kontoführung" becomes `EntgeltfrKontofhrung` and can never match (`.+` needs a character between `f` and `r`). All versions. | Match with the umlauts removed (or before stripping); add a PDF fixture for the fee rows. |
| `index.php:283-285` (`?action=transfer`) | Listed in the allowed actions, but `page_transfer()` is defined nowhere: fatal error on every version, also for visitors (`diagnostics/transfer-anon.txt`). `tests/http-page-views.php` attributes it to known failure `KF-TRANSFER-UNDEFINED-FUNCTION`. | Remove the action or restore the page (owner). |
| `import.php:118`, `donations.php:4-6`, `sprintf('%1.2f', ...)` sums | Numeric strings differ between 5.6 and 7.x: `intval('1e3')` is 1 on 5.6 and 1000 on 7.1+ (an import amount `1e3,2` becomes `PHP_INT_MAX` on 7.4); hex strings such as `'0x1A'` are numeric (26) on 5.6 only (`helpers/idioms-values.txt`, `helpers/page-helpers.txt`). | Validate amount and year formats before `intval()`. Allowlisted as `todo`: `RD-INTVAL-EXPONENT-VALUES`, `RD-INTVAL-EXPONENT-DATES`, `RD-HEX-NUMERIC-STRING` (7.4+). |
| `functions.php:275` `konto2lo()`, `switch`/`==` idioms | PHP 8 string-number comparison: an account number with a trailing space (`'50110117300 '`) maps to organization 2 on PHP 8 but falls through on 5.6/7.4; `0 == 'abc'` is no longer true; `sort()` already orders mixed values differently on 7.4 than on 5.6 (`helpers/konto2lo.txt`, `helpers/idioms-*.txt`). | Normalize (trim) account numbers and compare strictly where the input is a string. Allowlisted as `todo`: `RD-PHP8-KONTO2LO-WHITESPACE`, `RD-PHP8-STRING-NUMBER-*`, `RD-SORT-ORDER`. |
| `edit.php:348`; `new.php:46`; `getfilter()`/`get_param()`/`hex2ascii()`/`rights2orgasql()` | PHP 8 `TypeError`s: `count(null)` (unknown or foreign voucher; the import forms without an upload are fixed) gives HTTP 500; arithmetic on non-numeric amount strings; array request parameters. PHP 8.4 `round()`: an amount of 1,005 EUR is stored as 100 instead of 101 cents. | PHP 8 track. Allowlisted as `todo`: `RD-PHP8-NONNUMERIC-ARITHMETIC`, `RD-PHP8-ARRAY-ARG-*`, `RD-PHP84-ROUND`. |
| `import2.php` (smalot/pdfparser 0.9.23, TCPDF 6.2.6) | The PDF statement import died on PHP 7.2+ (the libraries do not compile: class `Object`, curly-brace offsets): HTTP 500, no receipts attached (also the later download and voucher view). | **Fixed**: pdfparser v2.13.1 ("PHP 8.5-only migration", step b); the `RD-PDFPARSER-PHP72-*` entries are removed. |

### 32-bit production integers

Production is i686 (`PHP_INT_SIZE` 4, `PHP_INT_MAX` 2147483647); every lane
except PHP 5.3 is 64-bit, so a move to a 64-bit host changes these
(`RD-INT-32BIT`, `accepted`: the helper corpus values at or above 2^31).
On 32-bit `intval()` saturates numeric strings at 2147483647 and wraps floats
(`intval(2147483648.0)` is -2147483648). Application call sites, checked:

- Account numbers are never converted to integers: `konto2lo()`
  (`functions.php:275`) switches on the string, and the imports keep
  `konto`/`gegenkonto` as strings (`import.php` SQL at `:308`, `:320`,
  `:328`). A `switch` between numeric strings beyond 2^31 compares them as
  floats on 32-bit, which is exact for 11-digit numbers, so `'50110117300'`
  maps to 2 on both. (`konto2lo()` is only called with the rights array in
  `new.php:362`, `:437`, `:619`, where it returns the array unchanged.)
- Import amounts in cents: `import.php:118`, `:124`, `:128`, `:151`, `:157`,
  `:164`, `:177`, `:184` use `intval()` of the amount with `.`/`,` removed,
  so on 32-bit an amount of 21,474,836.48 EUR or more becomes 2147483647
  cents (64-bit: exact). `import.php:130` multiplies the clamped integer by
  100, so a whole-euro amount above 21,474,836 EUR becomes a float. No
  realistic party transaction reaches this; a 64-bit host would import such
  amounts exactly instead of clamping them.
- Report and statistics sums: `report.php:20`, `:60`, `:97`, `wk.php:20`,
  `:61`, `:101` only test `intval($sum) != 0`, which stays correct when a
  sum is clamped. `functions.php:436-445` counts rows (small). The SUMs are
  formatted from PostgreSQL's string value, not through `intval()`.
- Request IDs and years (`file.php:6`, `edit.php:51`, `:53`, `:342`,
  `recover.php`, `index.php:217`, `:289`, `:293`, `donations.php:5-7`): only
  values beyond 2147483647 differ (32-bit clamps them), e.g. `?id=9999999999`
  selects voucher 2147483647 instead of 9999999999; both find nothing.
- `new.php:46` parses entered amounts with `round(... * 100.0)` (a float), so
  32- and 64-bit agree there.

No change is needed for the `php5-last` hotfix; on a 64-bit host the
differences above are only visible for out-of-range input.

## Short tags and other migration debt

- [x] Replace `<?` with `<?php` at `getusers.php:1` and `file.php:1`. Short tags
  depend on `short_open_tag`; when disabled, PHP can treat these files as text
  instead of defining their functions. A plain `php -l` can then miss the issue.
  Add a check with short tags disabled and assert that source text is never
  emitted. This is a portability risk: source emission was not observed in the
  tested PHP 8.0 default or short-tags-enabled control, and enabling short tags
  did not fix the login response. Fixed: `tests/unit/ShortOpenTagTest.php`
  includes both files with `short_open_tag=0` in a separate process and
  asserts no output and the defined function (before: 1,707 bytes of source
  from `getusers.php`, nothing defined). No other tracked application file
  uses `<?` or `<?=`. Both `DisallowShortOpenTag` baseline entries removed.
- [ ] Replace implicit PostgreSQL connection use incrementally with explicit
  connection arguments; PHP 8.5 emits deprecations from the current helpers.
- [x] Replace the HTTP test helper's deprecated `$http_response_header` use
  with `http_get_last_response_headers()`.
- [ ] Fix the malformed amount-filter regular expression at `functions.php:73`
  in a separate tested change. Its following statement is currently unreachable.
- [x] Protect receipt and membership encryption contracts with synthetic fixtures
  before modernizing the `mcrypt` dependency. `tests/fixtures/mcrypt/vectors.json`
  holds ciphertexts produced by the real extension (PHP 5.6.40 and 7.1.33 gave
  identical bytes; see `generate-vectors.php`): receipts of several lengths
  (empty, below, at and above one 32-byte block, PDF- and JPEG-like, all byte
  values, trailing NUL bytes), truncated and empty ciphertext, the `mbapi.php`
  framing and the `getusers.php` import. `tests/unit/CryptoTest.php` checks
  them on every lane.
- [x] Replace `mcrypt` (removed in PHP 7.2). `functions.php` now has
  `rijndael256_encrypt()`/`rijndael256_decrypt()` (phpseclib 2.0.55
  `Rijndael`, 256-bit block, CBC, its padding disabled, mcrypt's zero padding
  to at least one block) and the key derivations `receipt_encrypt()`/
  `receipt_decrypt()` (`md5(id . key . id)`, IV `md5(key . id)`) and
  `membership_encrypt()`/`membership_decrypt()` (`md5(datakey)`, IV
  `md5(md5(datakey))`). All five call sites use them; ciphertext and
  plaintext are byte-identical to mcrypt, so existing `files/*.aes` and the
  membership exchange keep working. phpseclib uses the mcrypt extension where
  it is loaded (same bytes, checked against the vectors) and its own
  implementation elsewhere, also on 32-bit PHP (checked on i386 PHP 5.6).
  The receipt call sites pass `''` for the key, which is what their undefined
  local `$key` was (next item). Key and IV come from
  `receipt_cipher_params()`/`membership_cipher_params()`, shared by
  encryption and decryption.
  Tests: the vectors, `tests/unit/GetUsersTest.php` (stubbed `http://`
  wrapper, never the live service), `tests/unit/ReceiptImportTest.php` (the
  file `import2.php` writes, byte for byte) and `tests/http-receipts.php`
  (upload, PDF import on PHP 5.6, download headers and body, `mbapi.php`
  framing and selection). Mutation target `receipt-crypto`.
- [x] Receipt key (formerly the known failure `KF-RECEIPT-KEY-IGNORED`). The
  callers read `$key` without `global` (PHPStan row below), so legacy
  receipts were encrypted with an empty key part, keyed on the voucher ID
  only. Owner decision, with explicit file name versioning:
  - New receipts (upload in `new.php`, PDF import in `import2.php`) are
    `files/<n>.v2.aes`: `"PGR2"`, a random 16-byte IV, AES-256-CBC with
    PKCS#7 padding and an HMAC-SHA256 over all of that (encrypt-then-MAC,
    compared in constant time with `hash_equals()`, an own loop on PHP 5.3).
    The encryption and MAC keys come from RFC 5869 HKDF-SHA256 of the
    configured `$key` (no salt) with the info `pgacc-receipt-v2|enc|<n>` and
    `pgacc-receipt-v2|mac|<n>` (own implementation with `hash_hmac()`,
    checked against the RFC vectors and `hash_hkdf()`), so a merge or
    revision, which changes voucher IDs, does not affect them, and no
    database lookup is needed. The `PGR2` magic leaves room for a later
    format (e.g. ext-sodium secretstream on PHP 8). Uploads are encrypted in
    64 KiB chunks from the uploaded file into a unique temporary file that
    is renamed into place; downloads read the open file twice in chunks (MAC
    over the whole file, then decrypt and send; the send stops, and is
    logged, if a read comes back short or the open file's size or change
    times differ from the MAC pass, since receipts are only ever replaced by
    rename), so a 20 MB receipt needs
    about 4 MB of memory (`tests/receipt-memory.php` runs it with
    `memory_limit=40M`; whole-file processing does not fit). The legacy
    path still decrypts whole files, and the migration holds a file in
    memory (`--max-size`). Contract vectors from the openssl extension and
    `hash_hkdf()`: `tests/fixtures/receipt-v2/`. The earlier drafts of
    `<n>.v2.aes` (md5-based, then HMAC keys without HKDF) were never
    deployed.
  - Key validation: `receipt_configured_key()` reads `$GLOBALS['key']` and
    accepts only a string of at least 16 characters that does not contain the
    `CHANGE-ME` placeholder. Without a valid key uploads are refused with the
    page's usual error ("Beleg konnte nicht gespeichert werden."), the
    voucher keeps no file number, and `error_log()` names the reason; a write
    failure is refused the same way (it used to be ignored).
  - Download (`file.php`): `<n>.v2.aes` if it exists: MAC checked, then
    decrypted. If that fails (a wrong key, a file copied from another number
    or a modified file) and `<n>.aes` still exists (before
    `--delete-legacy`), the legacy file is sent as before and logged;
    without it the response is a 500 ("FEHLER: Der Beleg konnte nicht
    entschlüsselt werden."). The log lines name the file only, never the key
    or its fingerprint. v2 responses: Content-Type from the magic bytes
    (PDF, JPEG, otherwise `application/octet-stream` with `Content-Disposition:
    attachment; filename="<n>.bin"`), `X-Content-Type-Options: nosniff`
    (correct types render as before; checked by headers only, not in a
    browser), Content-Length is the plain text size. Without `<n>.v2.aes`,
    the legacy `<n>.aes` exactly as before (no new headers). The v2 bytes
    are never decrypted with the legacy scheme.
  - `migrate-receipt-key.php` re-encrypts legacy files in two phases (PHP 5.3
    has no fsync): a normal run writes and verifies `<n>.v2.aes` and keeps
    `<n>.aes`; it checks an existing `<n>.v2.aes` like a download (MAC, then
    chunked decryption) and re-creates an invalid one from `<n>.aes`, or
    reports it (exit status 1); it never deletes anything. `--delete-legacy`
    first runs `sync` (`--sync=COMMAND`; nothing is deleted if it fails),
    then decrypts both files again, the new one through the download's
    chunked path, and deletes `<n>.aes` only where the plain texts match; it
    prints a reminder to check receipts first. It reads `$key` like `index.php` (an explicit
    relative `--constants` or `--files` is relative to the working
    directory), refuses invalid keys, prints the key fingerprint (first 8 hex
    digits of `HMAC-SHA256($key, "pgacc-key-fingerprint")`, only in its own
    output), holds an exclusive lock (`files/.migrate-receipt-key.lock`),
    never removes a `<n>.v2.aes` it did not just write, probes the first
    block per candidate voucher ID and then requires a complete PDF
    (`%%EOF` near the end) or JPEG (`FF D9`) with consistent zero padding,
    skips files above `--max-size` (default `memory_limit` / 8), names that
    are not canonical file numbers in PostgreSQL's integer range (listed,
    exit status unchanged) and other types, and exits 2 when it cannot read
    the directory. Its first statement stops any server API but the
    command line (404 for web requests, a message for php-cgi from a shell,
    exit status 1), before any include; `.htaccess` also denies it (defence
    in depth; production runs Lighttpd). Runbook below.
  - Tests: `tests/unit/ReceiptStorageTest.php` (key validation, fingerprint,
    v2 vectors, random IV, rejected keys/numbers/modifications, atomic
    writes, store and read); `ReceiptImportTest.php` (the import's
    `<n>.v2.aes`; import and upload refused without a valid key);
    `ReceiptMigrationTest.php` (dry run, migration with skips, complete
    receipts and the size limit, idempotent reruns, the delete phase,
    failures, `--limit`, the lock, refusals, key loading, relative paths,
    php-cgi; also on PHP 5.3) and `ReceiptMigrationCommandTest.php` (the
    command); `tests/http-receipts.php`
    (stored files, legacy downloads, `<n>.v2.aes` wins, other types as
    downloads, the error cases, merges, the script over HTTP; it shares the
    server's `files/` through a Compose volume). Mutation target
    `receipt-crypto`; Infection also runs `ReceiptStorageTest`.
  - [x] `KD-PHPSECLIB-OPENSSL-53` (PHP 5.3 only, hidden; entry removed with the PHP 5.3 lane): phpseclib's AES-CBC
    decryption on PHP 5.3 (no `OPENSSL_RAW_DATA`) encrypts one ECB block
    under `@` (`Crypt/Base.php:1159`), which warns about the empty IV that
    ECB never uses. Harmless; `ReceiptStorageTest` checks the v2 vectors on
    the 32-bit PHP 5.3 image. Gone with PHP 5.4+ (`php5-last`).
  - [ ] Known bug `KB-RECEIPT-LEGACY-MERGE`, not changed: a legacy `<n>.aes`
    is keyed on the voucher ID, so after a merge (which changes the merged
    rows' voucher ID) its download sends unreadable bytes as `image/jpeg`,
    and the migration cannot find its key either (skipped and listed).
    Characterized in `tests/http-receipts.php`. A `<n>.v2.aes` is not
    affected.
  - Open: a download that finds no `<n>.v2.aes` while a migration renames it
    into place still reads the legacy `<n>.aes`, which is kept until the
    delete phase, so it gets the right receipt.
- [ ] Deploy checklist for the `mcrypt` replacement (owner):
  - Run `composer install`, which adds `phpseclib/phpseclib` 2.0.55.
  - Check `mbstring.func_overload` on production. phpseclib's `bootstrap.php`
    is loaded by `vendor/autoload.php` on every request of `index.php` and
    `mbapi.php`, and throws when the value includes string overloading (2),
    so then **every page** fails, not only receipts.
  - Speed: production has the mcrypt extension, which phpseclib uses (1 MiB in
    about 0.01 s). Without it, phpseclib's pure-PHP code is slow. Measured
    2026-10-23 in the lane images without mcrypt, Xdebug off, on a shared
    and busy host (so only indicative): a 10 MB receipt took 10-11 s to
    encrypt and the same to decrypt on PHP 7.4.33 and about 7 s each on
    8.5.12; 20 MB took 15 s each on 7.4 and 16-26 s on 8.5. Peak memory
    grew by about twice the receipt size (+20 MB for 10 MB, +40 MB for 20 MB
    beyond the data). On a server without mcrypt, uploads and downloads of
    large receipts can therefore hit `max_execution_time` (30 s by default)
    and need `memory_limit` headroom; the upload limit is 100 MB.
- [ ] Deploy runbook for the receipt key (owner); shipped copy in `UPGRADING.md`, keep both in sync:
  1. Back up `files/` and `constants.php` (its `$key`) to separate, safe
     places. Without that `$key` no new receipt can be decrypted again; keep
     the backup as long as receipts must be kept.
  2. Check `$key` in `constants.php`. The application requires at least 16
     characters and no `CHANGE-ME` placeholder (otherwise uploads are refused
     after the deploy; legacy downloads keep working); use a random string
     of 32 or more characters, e.g. `openssl rand -hex 32`. Legacy receipts
     never used `$key`, so it can still be replaced before the deploy; do not
     change it afterwards: receipts encrypted with it cannot be read with
     another key. (Earlier drafts of `<n>.v2.aes` were never deployed, so no
     such files exist.)
  3. Deploy. From now on new receipts are `files/<n>.v2.aes`.
  4. Dry run, as the web server's user (file owner and database role), from
     any directory: `sudo -u <web user> php migrate-receipt-key.php --dry-run`.
     It lists each legacy file as "would migrate" or "not migrated" (with the
     reason) and writes nothing. Note the key fingerprint in the summary; do
     not post migration transcripts publicly.
  5. Migrate: `sudo -u <web user> php migrate-receipt-key.php`, optionally in
     batches (`--limit=500`). Files above `--max-size` (default
     `memory_limit` / 8) are skipped; for large receipts run with
     `php -d memory_limit=1G` (or set `--max-size`). Legacy files are kept.
     Exit status 1 means a file failed; rerun until the summary shows 0
     migrated and 0 failed.
  6. Run `sync` once more (PHP 5.3 cannot fsync; `--delete-legacy` also runs
     `sync` itself before it deletes anything and stops if that fails).
  7. Spot-check several migrated receipts (PDF and JPEG) in the browser,
     before deleting anything, and check the web server's error log for
     `pgacc: receipt <n>.v2.aes could not be decrypted; sent the legacy
     <n>.aes`: while the legacy files exist, a key mismatch between the web
     application and the migration still serves the old file, so only the
     log shows it.
  8. Delete the legacy files: `sudo -u <web user> php migrate-receipt-key.php
     --delete-legacy` (also with `--dry-run` first; it repeats the reminder).
     Each `<n>.aes` is deleted only after `<n>.v2.aes` decrypted to the same
     content with the key the run read. Its key fingerprint must match the
     one of steps 4 and 5.
  9. Keep the `files/` backup from step 1 until the spot checks are done.
     Skipped files stay legacy `<n>.aes` and keep working as before.

## Planned security follow-ups

- [ ] Known limitation, receipt download (owner: fix later). A download checks the
  MAC of `files/<n>.v2.aes`, then reads the file again to decrypt and send it.
  `receipt_v2_identity()` only compares size and second-resolution change times
  around that second pass, so a same-size in-place edit within the same second
  is sent unauthenticated. The padding tail and the type-sniffing head are also
  read again after the MAC pass. The app writes receipts only by rename, so this
  needs local write access to `files/`. Planned fix: pin a SHA-256 digest of each
  64 KiB ciphertext chunk during the MAC pass and compare it before decrypting
  and sending that chunk (as paragonie/Halite `File::decrypt` does); take the
  tail and head from the verified pass. No format change. A v3 format with
  per-chunk authentication (STREAM, libsodium secretstream) would be the later
  principled fix. Tests: in-place same-size change with restored mtime (no
  changed byte sent), change in the first chunk, change in the padding block.
- [x] CSRF protection, before the `php5-last` tag. Done, with a different
  derivation than first planned: `$key` is now a validated deploy
  prerequisite (`receipt_configured_key()`), so no `$csrf_secret` and no
  fallback are needed. `csrf_session_token()` (`functions.php`) is
  `hash_hmac('sha256', 'pgacc-csrf|<id>|<nonce>|<login>', K)` with
  `K = hash_hmac('sha256', 'pgacc-csrf-key', $key, true)`: the same session
  data as the `pp_pgacc_login` cookie (user id, the `users.cookie` nonce,
  the login time) under its own subkey. It changes with every login, stops
  working when logout or a password change clears `users.cookie`, needs no
  storage, and a page that shows it does not reveal the HttpOnly cookie
  (an HMAC of the cookie value would have needed the extra secret for the
  same property). `checklogin()` derives it for the accepted session;
  `csrf_field()` adds it as a hidden field to every form that writes (voucher
  form for new/edit including receipt upload, approval and reset, merge
  form, recovery confirmation, user list, both imports, the listing column
  buttons, the Logout button). `index.php` checks every POST request except
  the login form and the public captcha forms (`donations`, `kdonations`)
  right after the login check, before any page code: `csrf_request_valid()`
  compares with `login_token_equals()` (constant time). On failure the page
  shows "FEHLER: Ungültiges Formular-Token. Bitte die Seite neu laden." with
  HTTP 200 and nothing is written. Tests: `tests/http-csrf.php` (missing,
  wrong and other-session tokens per write action, no table or sequence
  change), `LoginSessionTest`, mutations in `csrf`. Known limitations: the
  login form has no token (login CSRF: a forged login into the attacker's
  account stays possible; `SameSite=Lax` does not stop a top-level POST); a
  POST larger than `post_max_size` arrives without fields and gets the token
  error.
- [x] State-changing actions only on POST (behaviour change, owner-approved
  for the last PHP 5.x release): merge (`?action=merge&bid1=&bid2=`, root
  only), recovery (`?action=recover&id=&bid=&doit=1`, root only), the column
  preferences (`?hide=`, own user only) and logout. Their links are small
  POST forms with the token (the merge link submits a hidden form after the
  prompt; column icons and Logout are buttons styled as before). GET writes
  nothing: merge shows "FEHLER: Buchungen nur über die Schaltfläche „Merge“
  der Buchung zusammenführen.", recovery shows its confirmation form, `?hide=`
  shows the listing unchanged, logout shows a hint to the Logout button and
  keeps the session. A POST logout without a valid session gets the token
  error (it used to clear the cookies and redirect). Old bookmarks of these
  actions no longer write; forms opened before a new login become invalid.
- [x] Bug: merge (`page_edit_merge()`) redirected with "Buchungen
  zusammengeführt" even when its `UPDATE` changed no row (unknown or
  invisible second voucher). Fixed with the POST conversion: it now shows
  "FEHLER: Buchungen nicht gefunden." (`pg_affected_rows()`).
- [ ] Open, found with the CSRF work (not changed): the import and
  member-fee pages refresh the `ppmembers` cache from the membership system
  (`getusers()`) on every view, also on GET; a GET of `?action=import2` and
  of `?action=new` raises diagnostics (`count()` of the missing upload,
  undefined `$bgfandack`); the test fixtures do not advance the `users` id
  sequence, so a user created over HTTP gets id 1 next to `synthetic-admin`.
- [x] Member-list refresh (`getusers.php`): fetched over plain HTTP, and a
  short, damaged or undecryptable response (no MAC) or an SQL error left an
  empty or garbage `ppmembers` table. Now `https://` with PHP's default
  certificate verification (needs TLS 1.2, i.e. the new server; PHP 5.3 with
  OpenSSL 0.9.8 cannot fetch it). The response is decrypted and parsed
  first; a failed fetch (refused certificate, timeout of 20 s), an HTTP error
  status or any redirect (not followed: no other host, no plain HTTP;
  "Mitgliederliste konnte nicht abgerufen werden!", the cause goes to the
  error log), a short response (unchanged), a line of five or more
  characters without the five tab-separated fields, or a list without any
  member line ("Daten von Mitgliederverwaltung ungültig!") stops the page
  before the table is touched.
  Behaviour change, accepted by the owner (merged in #25): before, such
  a line was imported as member 0 with PHP warnings, and a list without
  member lines left an empty table. Drop, create and insert run in one
  transaction (a savepoint inside a caller's transaction, as in the unit
  tests); an SQL error rolls back and stops the page. The member-fee, import
  and voucher pages still drop `ppmembers` after each view, so a failed
  refresh shows the error instead of serving an older list. Tests:
  `tests/http-import.php` (stub modes `short`, `garbage`, `empty`, `error`,
  `redirect`, `redirect-http`, `selfsigned`, `wrongname`, `overflow` for a
  failed INSERT after DROP and CREATE, a `ppmembers` view for the SQL error,
  a member match with the `flow` list), `GetUsersTest` (exact rows of the
  `flow` list over HTTPS); the stub speaks HTTPS with a per-build test CA
  (`tests/README.md`).
- [ ] Planned (not now): a per-connection `TEMP` table instead of the shared
  `ppmembers`, so that concurrent requests do not drop each other's list.
- [ ] Open follow-up (not changed with the HTTPS refresh): the member list is
  still fetched on every GET of the import and member-fee pages (`import.php`
  `page_import()`, `mb.php` `page_mb()`) and when a member voucher is saved
  (`new.php`). Moving the refresh off GET (an explicit action, or a cache
  with an age) is a separate owner decision.
- [ ] Deferred (owner decision): the arithmetic check on the public donor pages
  (`donations.php`, `kdonations.php`) is no real bot protection, since its
  answer can be derived from the page (hidden field + 37). Logged-in users
  skip it. Decide later between real protection and removing the check.

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

- [x] After dropping PHP 5.6 and 7.0: move to `^2.13` (clean on 7.4-8.5).
  Done after `php5-last`: `^2.13.1`, locked v2.13.1 ("PHP 8.5-only
  migration", step b).
- [ ] Owner decision: license notice. pdfparser 2.x is LGPL-3.0 (0.9.23 was
  GPL-3.0), and LGPL-3.0 refers to the GPL-3.0 text. The repository has no
  license file (README, "License"). Decide whether the release archive
  needs the GPL/LGPL texts or a third-party notice for `vendor/`.

## Tooling to catch these earlier

- [x] Try Semgrep (2026-10-24, report-only). Semgrep CE 1.181.1 with the stock
  `p/php` pack parsed all application files; its matches were mostly noise
  (unmodeled validation and output contexts) and confirmed no new issue.
  Not adopted as a CI gate. Revisit only with narrow, project-specific rules
  and safe/unsafe canaries.
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
- [x] Coding standard: PER Coding Style 3.0, whitespace rules only, applied once
  to the whole codebase by the formatting pull request (PHP-CS-Fixer 3.95.27,
  `.php-cs-fixer.dist.php`, `sh scripts/format.sh`; the earlier plan preferred
  PHP_CodeSniffer only while PHP 5.6 was supported). Proven behaviour-neutral
  by `tests/coverage/token-equivalence.php` (same token stream; inline HTML,
  strings and heredocs byte for byte) and the PHP 5 reference comparison. The
  former CRLF files are LF (`.gitattributes`); their HTML and SQL newlines
  changed from CRLF to LF (allowlist `RD-FORMAT-CRLF-TO-LF`). The commit is in
  `.git-blame-ignore-revs`; mutation targets were rewritten (every mutant
  token-equivalent to the old one).
- [x] Enforce the formatting: CI runs `sh scripts/format.sh --check --ci`; a
  Claude Code hook formats edited PHP files; `.githooks/pre-commit` (opt-in)
  checks staged blobs. The changed-line gates are strict again (no
  token-equivalence exemption); `tests/coverage/token-equivalence.sh` stays as
  a standalone review tool.
- [ ] Optional: a Codex hook like the Claude Code one (project-level Codex hooks
  need trust, and Codex is used read-only here, so none is configured).
- [ ] Modern syntax, code only (no behaviour change), in small pull requests:
  the token-changing PER-CS 3.0 rules left out of `.php-cs-fixer.dist.php`
  (`array_syntax`, `elseif`, `modifier_keywords`, `no_closing_tag`,
  `control_structure_braces`, `operator_linebreak`, imports and ordering,
  ...), each reviewed with the reference comparison and with the changed-line
  coverage and mutation gates. Then PER-CS 3.1 once PHP-CS-Fixer ships a set
  for it.
  - [x] Step 1, short arrays (branch `syntax/short-arrays`): `array()` -> `[]`
    and `list()` -> `[]` in the application pages with the pinned Rector
    (`tests/rector/`, only `LongArrayToShortArrayRector` and
    `ListToArrayDestructRector`), then `sh scripts/format.sh`. Every changed
    file is proven AST-equivalent (`tests/coverage/ast-equivalence.sh`); the
    reference comparison shows no new difference. Only rules with an
    identical syntax tree may use the AST exemption of the gates
    (a flag set by CI for that pull request only).
  - [x] After step 1: the exemption is removed again (branch
    `syntax/remove-ast-exemption`); the gates are strict and the AST check is
    a standalone review tool, like the token-equivalence check. A future
    syntax-only step opts in only by owner decision (`tests/README.md`,
    "Coverage").
  - [ ] Later steps change the syntax tree (`elseif`, visibility, closing tags,
    imports, ...) and run with the strict gates.

- [ ] `.htaccess` still sets `php_value short_open_tag 1`, although the 1.0
  changelog says short tags are no longer needed (the
  `Generic.PHP.DisallowShortOpenTag` sniff keeps them out). Decide whether to
  drop the line (a runtime configuration change, separate from tooling); until
  then `tests/coverage/token-equivalence.sh` checks both settings.

### Deferred tooling improvements

Proposals from the development-workflow review; not implemented.

- [ ] A supported focused-test entry point that manages the warm setup,
  fixture reset, image freshness and cleanup.
- [ ] Show the selected mutant count and IDs before a run, including why a
  changed target expanded to a full run.
- [ ] Fail explicitly when a requested coverage or diff input is missing,
  instead of silently skipping the gate.
- [ ] A machine-readable verification manifest (source, base, command, exit
  status) so evidence can be reused safely across commits.

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

### PHPCompatibility (24 findings; 4 after the `mcrypt` replacement, 2 after the short-tag fix, 3 with `hash_equals()`, 2 with `testVersion` 8.5-, 0 after the CSV escape fix)

| Finding | Locations | Status |
|---|---|---|
| Short open tag | `file.php:1`, `getusers.php:1` | **Fixed** (short-tag item above) |
| `mcrypt` extension, functions and constants removed in 7.2 (4 findings per site) | `file.php:19`, `getusers.php:20`, `import2.php:189`, `mbapi.php:17`, `new.php:252` | **Fixed** (`mcrypt` replacement above); 20 baseline entries removed. |
| `fgetcsv()`/`str_getcsv()` without `$escape` (deprecated in 8.4) | `import.php:52`, `import.php:163` | **Fixed**: the default enclosure and escape are passed explicitly (step a of the PHP 8.5-only migration) |
| `hash_equals()` not in PHP 5.5 or earlier | `functions.php:693` (`receipt_equals()`) | **Accepted**: guarded by `function_exists()`, which PHPCompatibility does not see; PHP 5.3 uses `receipt_equals_fallback()` (tested on the PHP 5.3 lane) |

### PHPStan (91 findings; 79 after the `closed.php`/`report.php` fixes, the `index.php:226` merge fix and the `new.php:184` rights fix, 76 after `donations.php`, 72 after the `mcrypt` replacement, 69 after the explicit receipt key, one fewer after `edit.php:120`, one fewer after the finalization removal, one fewer after the login session token, one fewer after the removal of the dead voucher-ID check)

| Finding | Locations | Status and impact |
|---|---|---|
| Possibly undefined arrays reaching `count()`/`array_multisort()` | `edit.php:345-411` (`$vouchers`, 12; 11 after the finalization removal; fixed in the PR review), `donations.php:116-124` (`$donations`, `$sort_name`, `$sort_sum`, fixed) | Known (array/input table). `edit.php:346` "always false" comparisons follow from the same variable. The `donations.php` entries (5 findings) were removed with the fix. |
| Empty array passed to `foreach` | `donations.php:119`, `:127` (2) | Added with the `donations.php` fix: `$donations` is now always `array()`, because the assignment that filled it is commented out (publication disabled for legal reasons). PHPStan correctly reports the sort and output loops as dead code. Kept on purpose until the owner decides on publication; see the array/input table. |
| Malformed regular expression | `functions.php:73` | Known. Observed at runtime by `tests/http-output-escaping.php` (an unmatched `filter_amount`) as known failure `KF-AMOUNT-FILTER-REGEX`. |
| Extra arguments to functions without parameters | `index.php:264` (fixed with `KD-NEW-RIGHTS`), `:289`, `:321` (fixed: `page_import()` takes the rights for the import checks); `functions.php:163` | Known from the Rector review. Fixed at `index.php:226`: the merge dispatch passed `intval($_POST["bid1"])` and `intval($_POST["bid2"])`, which `page_edit_merge($rights)` ignores (it reads `$_GET`); the merge link is a GET request, so each merge raised two undefined-index notices (warnings on PHP 8). The arguments are dropped; `tests/write-workflows.php` covers the merge. |
| `$key` read inside functions without `global` | `file.php:19`, `new.php:252`, `import2.php:189` (2 each) | **Fixed without a behaviour change**: the call sites pass `''`, the value they always used; entries removed. Since then fixed: new receipts use the configured key (receipt key item). Receipt encryption and decryption use `md5($id . $key . $id)` with `$key` always undefined. The configured key is therefore never part of the receipt key, so the key depends only on the voucher ID. PHP 8 also emits an "Undefined variable" warning before the download headers in `file.php`. Do not change this without a migration plan for existing `.aes` files (see the `mcrypt` item). |
| `tag()` called with one of two required arguments | `closed.php:158` (`emptytag()`) | **Fixed.** On PHP 7.1+ this threw `ArgumentCountError` whenever a listing column was hidden through `user_prefs_hide` (ledger, open, deleted, closed listings); on 5.6 it displayed "Missing argument 2 for tag()" in every hidden cell and returned `<td></td>`. Reproduced with the new `tests/http-listing-prefs.php` (users `synthetic-hide-odd`/`synthetic-hide-even` hide every column once, in HTML and CSV): fatal error on 7.4, displayed warning on 5.6. `emptytag()` now passes `''`, which produces the same `<td></td>` that PHP 5.6 did, without the warning. Baseline entry removed. |
| Undefined functions | `index.php:266` `page_new_ack()`, `index.php:285` `page_transfer()`, `report.php:185`/`:194` `pg_prev_error()` | **New.** The `ack` POST on the new-voucher form and `?action=transfer` cause a fatal error on every PHP version. The report error paths died with a fatal error instead of their message: **fixed** (`pg_last_error($dbconn)`, PHP 8.5-only migration review fixes). |
| Undefined variables in function scope | `new.php:184` (`$rights` in `page_new_save()`, fixed), `edit.php:120` (`$rightssql` in `page_edit_ack()`, fixed), `new.php:613` (`$bgfandack`, fixed), `report.php:340-397` (`$prevcond`, 8, fixed), `wk.php:145-180` (`$year`, 7, fixed) | **New** here; `new.php:184`, `edit.php:117` and `report.php:340` were then also observed at runtime as `KD-NEW-RIGHTS`, `KD-EDIT-RIGHTSSQL` and `KD-REPORT-PREVCOND` (impact and follow-up there). `new.php:613` (`$bgfandack`) is observed by `tests/http-output-escaping.php` (re-rendered new-voucher form) as the former known failure `KF-NEW-UNDEFINED-BGFANDACK` (fixed: `page_new()` passes `false`, the value the undefined variable had); `wk.php` as the former `KF-WK-UNDEFINED-YEAR` (fixed: `page_wk()` sets `$year = null`, which `wk_unit_report()` does not use). Each site warns on PHP 8 before headers. |
| Possibly undefined variables | `check.php:24-38` (`$i`), `import2.php:187/193` and `new.php:250/256` (`$file_number`), `new.php:108` (`$queries`), `new.php:180/184` (`$voucher_number`), `tinyreport.php:24-74` and `vreport.php:108-158` (`$unit`, 8 each; the former known failure `KF-REPORT-UNDEFINED-UNIT`, fixed: set to -1, the unused `$unit_s` is not part of the queries; PHPStan now baselines the dead `$unit == -1` conditions, `equal.alwaysTrue`, 4 each), `mbapi.php:19` (`$datakey`, 2; 1 since the `mcrypt` replacement) | **New**, lower confidence. Some depend on query results or switch branches, and `mbapi.php` gets `$datakey` from `constants.php`. Review individually. |
| Invalid regex modifier `/g` | `import.php:306` | **Removed** with the dead voucher-ID check it guarded (see "PHP 8.5-only migration"); formerly known failure `KF-IMPORT-REGEX-G`. |
| Arithmetic on a possible array | `new.php:46` | **New.** `get_param()` can return an array (`amount[]=`). `array * float` is a `TypeError` on PHP 8. |
| Always-true/false conditions | `index.php:18` (`!$data` after `or die`), `import.php:161` (`'Paypal' == 'Paypal'`), `import.php:263` (`0 == 0`, gone since the member-match fix of the PR review) | **New**, logic smells; no direct version impact. |
| `setcookie()` value is an int | `index.php:27` | **Fixed** with the login session token (the cookie is a hex string now); baseline entry removed. |
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
