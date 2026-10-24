# PHP compatibility findings and follow-up plan

Reviewed against the synthetic Docker harness on 2026-10-23. This list separates
completed changes from reproducible failures and configuration-dependent risks.
Application changes beyond the completed fixes listed below remain follow-up work.

Test lanes since 2026-10-24 (owner decision): PHP 5.3 / PostgreSQL 8.4.20 (the
production pair), PHP 7.4 / 18 (coverage) and 7.4 / 8.4, PHP 8.5 / 18. The PHP
5.6 and 8.0 lanes, the separate syntax matrix and the PostgreSQL 9.4, 14 and 17
servers were removed; findings below that name them are historical.

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
- [ ] `mb.php` divides by zero on the synthetic data (member-fee page for
  `bgf`); not requested by the tests.

Open security findings (not changed here; owner decisions):

- [ ] No CSRF protection: every write action is a plain form POST or GET
  link authenticated by cookies.
- [ ] Output is not HTML-escaped in several places, e.g. the user names in
  the user list (`accounts.php`, root only) and the listing filter fields
  (`closed.php`). [x] Fixed: the voucher page (`page_edit()`) echoed the
  request's voucher and row IDs in its notices and heading; it now uses
  them as `intval()` numbers throughout, as its query already did (a
  request such as `id=5abc` shows "Buchung 5" instead of "Buchung 5abc").
- [x] The default organization of a single-organization user
  (`default_organization()`, `new.php`) used `is_numeric()` and `intval()`,
  whose results differ between PHP versions for unusual rights values.
  Fixed in the PR review: it takes the trimmed single token
  (`rights_tokens()`, null-safe) and accepts only digits, like
  `organization_allowed()`, so `2 ` is organization 2 on every version and
  hex or exponent notation falls back to 10.
- [ ] The login `target` is redirected to without a check
  (`login_redirect()`, `login()`).
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
| PHP 8.5 (`run.sh 8.5`) | not run | CI lane (fails by design, see above) |

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

- [ ] `mb.php:19` (member fees per organization) groups by `lo.name` without
  `ORDER BY`; 8.4 and 17/18 return the groups in a different order
  (`RD-PG-UNORDERED-MB-LO`, todo, PostgreSQL 18 lanes). Owner decision:
  add `ORDER BY name` (a visible order change on production).

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

Pinned as current behaviour in `tests/reference/php5/` (recorded on the
production-like PHP 5.3 lane); none is fixed. Behaviour changes are the owner's
decision. The differences of the other lanes are reviewed entries in
`tests/reference/allowlist.php`: `accepted` (environment) or `todo` (to fix; the
`RD-*` IDs below).

| Location | Finding | Follow-up |
|---|---|---|
| `import.php:252` | When a CSV line without a parsed name matches exactly one member through the `ILIKE` lookup, `break` leaves the `fgetcsv()` loop: that line and all later lines of the file are not imported, yet the page reports "wurde erfolgreich importiert" (`http/import-bawag.txt`). All versions. | Owner decision: the `break` probably meant to end only the lookup. Test a match on a middle line first. |
| `import2.php:103`, `:133` | `page_import2_process_multi_ids()` strips all bytes `\x80-\xFF` before matching `Entgeltf.+r(Kontof.+hrung\|...)`, so "Entgelt für Kontoführung" becomes `EntgeltfrKontofhrung` and can never match (`.+` needs a character between `f` and `r`). All versions. | Match with the umlauts removed (or before stripping); add a PDF fixture for the fee rows. |
| `index.php:283-285` (`?action=transfer`) | Listed in the allowed actions, but `page_transfer()` is defined nowhere: fatal error on every version, also for visitors (`diagnostics/transfer-anon.txt`). | Remove the action or restore the page (owner). |
| `import.php:118`, `donations.php:4-6`, `sprintf('%1.2f', ...)` sums | Numeric strings differ between 5.6 and 7.x: `intval('1e3')` is 1 on 5.6 and 1000 on 7.1+ (an import amount `1e3,2` becomes `PHP_INT_MAX` on 7.4); hex strings such as `'0x1A'` are numeric (26) on 5.6 only (`helpers/idioms-values.txt`, `helpers/page-helpers.txt`). | Validate amount and year formats before `intval()`. Allowlisted as `todo`: `RD-INTVAL-EXPONENT-VALUES`, `RD-INTVAL-EXPONENT-DATES`, `RD-HEX-NUMERIC-STRING` (7.4+). |
| `functions.php:275` `konto2lo()`, `switch`/`==` idioms | PHP 8 string-number comparison: an account number with a trailing space (`'50110117300 '`) maps to organization 2 on PHP 8 but falls through on 5.6/7.4; `0 == 'abc'` is no longer true; `sort()` already orders mixed values differently on 7.4 than on 5.6 (`helpers/konto2lo.txt`, `helpers/idioms-*.txt`). | Normalize (trim) account numbers and compare strictly where the input is a string. Allowlisted as `todo`: `RD-PHP8-KONTO2LO-WHITESPACE`, `RD-PHP8-STRING-NUMBER-*`, `RD-SORT-ORDER`. |
| `edit.php:348`, `import.php:27`, `import2.php:24`; `new.php:46`; `getfilter()`/`get_param()`/`hex2ascii()`/`rights2orgasql()` | PHP 8 `TypeError`s: `count(null)` (unknown or foreign voucher, import forms without an upload) gives HTTP 500; arithmetic on non-numeric amount strings; array request parameters. PHP 8.4 `round()`: an amount of 1,005 EUR is stored as 100 instead of 101 cents. | PHP 8 track. Allowlisted as `todo`: `RD-PHP8-COUNT-NULL-*`, `RD-PHP8-NONNUMERIC-ARITHMETIC`, `RD-PHP8-ARRAY-ARG-*`, `RD-PHP84-ROUND`. |
| `import2.php` (smalot/pdfparser 0.9.23, TCPDF 6.2.6) | The PDF statement import dies on PHP 7.2+ (the libraries do not compile: class `Object`, curly-brace offsets): HTTP 500, no receipts attached (also the later download and voucher view). | Upgrade pdfparser (see [Dependencies](#dependencies)). Allowlisted as `todo`: `RD-PDFPARSER-PHP72-*`. |

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
- [x] Update the HTTP test helper's deprecated `$http_response_header` use on
  PHP 8.5, with feature detection and a fallback for the legacy runtimes.
  The fallback lives in a separate file that PHP 8.4+ never compiles; a
  variable-variable read is not enough, since PHP 8.0 then leaves the
  variable unset.
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
    over the whole file, then decrypt and send), so a 20 MB receipt needs
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
    `<n>.aes`; after `sync`, `--delete-legacy` decrypts both again and
    deletes `<n>.aes` only where the plain texts match; it prints a reminder
    to check receipts first. It reads `$key` like `index.php` (an explicit
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
    exit status 1), before any include. Runbook below.
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
  - [ ] `KD-PHPSECLIB-OPENSSL-53` (PHP 5.3 only, hidden): phpseclib's AES-CBC
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
- [ ] Deploy runbook for the receipt key (owner):
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
  6. Run `sync` (the migration cannot flush files to disk on PHP 5.3).
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
  GET-triggered writes found in the security review: merge
  (`?action=merge&bid1=&bid2=`, root only), recovery
  (`?action=recover&id=&bid=&doit=1`, root only) and the column preferences
  (`?hide=`, own user only).
- [ ] Bug: merge (`page_edit_merge()`) redirects with "Buchungen
  zusammengeführt" even when its `UPDATE` changed no row (unknown or
  invisible second voucher). Fix the message together with the POST
  conversion.

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

### PHPCompatibility (24 findings; 4 after the `mcrypt` replacement, 2 after the short-tag fix, 3 with `hash_equals()`)

| Finding | Locations | Status |
|---|---|---|
| Short open tag | `file.php:1`, `getusers.php:1` | **Fixed** (short-tag item above) |
| `mcrypt` extension, functions and constants removed in 7.2 (4 findings per site) | `file.php:19`, `getusers.php:20`, `import2.php:189`, `mbapi.php:17`, `new.php:252` | **Fixed** (`mcrypt` replacement above); 20 baseline entries removed. |
| `fgetcsv()`/`str_getcsv()` without `$escape` (deprecated in 8.4) | `import.php:52`, `import.php:163` | Known from the Rector review; not previously in this plan |
| `hash_equals()` not in PHP 5.5 or earlier | `functions.php:693` (`receipt_equals()`) | **Accepted**: guarded by `function_exists()`, which PHPCompatibility does not see; PHP 5.3 uses `receipt_equals_fallback()` (tested on the PHP 5.3 lane) |

### PHPStan (91 findings; 79 after the `closed.php`/`report.php` fixes, the `index.php:226` merge fix and the `new.php:184` rights fix, 76 after `donations.php`, 72 after the `mcrypt` replacement, 69 after the explicit receipt key, one fewer after `edit.php:120`, one fewer after the finalization removal)

| Finding | Locations | Status and impact |
|---|---|---|
| Possibly undefined arrays reaching `count()`/`array_multisort()` | `edit.php:345-411` (`$vouchers`, 12; 11 after the finalization removal; fixed in the PR review), `donations.php:116-124` (`$donations`, `$sort_name`, `$sort_sum`, fixed) | Known (array/input table). `edit.php:346` "always false" comparisons follow from the same variable. The `donations.php` entries (5 findings) were removed with the fix. |
| Empty array passed to `foreach` | `donations.php:119`, `:127` (2) | Added with the `donations.php` fix: `$donations` is now always `array()`, because the assignment that filled it is commented out (publication disabled for legal reasons). PHPStan correctly reports the sort and output loops as dead code. Kept on purpose until the owner decides on publication; see the array/input table. |
| Malformed regular expression | `functions.php:73` | Known |
| Extra arguments to functions without parameters | `index.php:264` (fixed with `KD-NEW-RIGHTS`), `:289`, `:321` (fixed: `page_import()` takes the rights for the import checks); `functions.php:163` | Known from the Rector review. Fixed at `index.php:226`: the merge dispatch passed `intval($_POST["bid1"])` and `intval($_POST["bid2"])`, which `page_edit_merge($rights)` ignores (it reads `$_GET`); the merge link is a GET request, so each merge raised two undefined-index notices (warnings on PHP 8). The arguments are dropped; `tests/write-workflows.php` covers the merge. |
| `$key` read inside functions without `global` | `file.php:19`, `new.php:252`, `import2.php:189` (2 each) | **Fixed without a behaviour change**: the call sites pass `''`, the value they always used; entries removed. Since then fixed: new receipts use the configured key (receipt key item). Receipt encryption and decryption use `md5($id . $key . $id)` with `$key` always undefined. The configured key is therefore never part of the receipt key, so the key depends only on the voucher ID. PHP 8 also emits an "Undefined variable" warning before the download headers in `file.php`. Do not change this without a migration plan for existing `.aes` files (see the `mcrypt` item). |
| `tag()` called with one of two required arguments | `closed.php:158` (`emptytag()`) | **Fixed.** On PHP 7.1+ this threw `ArgumentCountError` whenever a listing column was hidden through `user_prefs_hide` (ledger, open, deleted, closed listings); on 5.6 it displayed "Missing argument 2 for tag()" in every hidden cell and returned `<td></td>`. Reproduced with the new `tests/http-listing-prefs.php` (users `synthetic-hide-odd`/`synthetic-hide-even` hide every column once, in HTML and CSV): fatal error on 7.4, displayed warning on 5.6. `emptytag()` now passes `''`, which produces the same `<td></td>` that PHP 5.6 did, without the warning. Baseline entry removed. |
| Undefined functions | `index.php:266` `page_new_ack()`, `index.php:285` `page_transfer()`, `report.php:185`/`:194` `pg_prev_error()` | **New.** The `ack` POST on the new-voucher form and `?action=transfer` cause a fatal error on every PHP version. The report error paths die with a fatal error instead of their message. |
| Undefined variables in function scope | `new.php:184` (`$rights` in `page_new_save()`, fixed), `edit.php:120` (`$rightssql` in `page_edit_ack()`, fixed), `new.php:613` (`$bgfandack`), `report.php:340-397` (`$prevcond`, 8, fixed), `wk.php:145-180` (`$year`, 7) | **New** here; `new.php:184`, `edit.php:117` and `report.php:340` were then also observed at runtime as `KD-NEW-RIGHTS`, `KD-EDIT-RIGHTSSQL` and `KD-REPORT-PREVCOND` (impact and follow-up there). `new.php:613` and `wk.php` were not observed by the strict HTTP checks. Each site warns on PHP 8 before headers. |
| Possibly undefined variables | `check.php:24-38` (`$i`), `import2.php:187/193` and `new.php:250/256` (`$file_number`), `new.php:108` (`$queries`), `new.php:180/184` (`$voucher_number`), `tinyreport.php:24-74` and `vreport.php:108-158` (`$unit`, 8 each), `mbapi.php:19` (`$datakey`, 2; 1 since the `mcrypt` replacement) | **New**, lower confidence. Some depend on query results or switch branches, and `mbapi.php` gets `$datakey` from `constants.php`. Review individually. |
| Invalid regex modifier `/g` | `import.php:306` | **New.** `preg_match()` always returns `false` with a warning, so BAWAG voucher IDs are never extracted. `tests/http-import.php` records the warning as known failure `KF-IMPORT-REGEX-G`. Cover with the planned BAWAG import test. |
| Arithmetic on a possible array | `new.php:46` | **New.** `get_param()` can return an array (`amount[]=`). `array * float` is a `TypeError` on PHP 8. |
| Always-true/false conditions | `index.php:18` (`!$data` after `or die`), `import.php:161` (`'Paypal' == 'Paypal'`), `import.php:263` (`0 == 0`, gone since the member-match fix of the PR review) | **New**, logic smells; no direct version impact. |
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
