# PHP compatibility findings and follow-up plan

Reviewed against the synthetic Docker harness on 2026-10-23. This list separates
completed changes from reproducible failures and configuration-dependent risks.
Application changes beyond the two completed helper fixes remain follow-up work.

## Completed and verified

- [x] Change `$ascii{$i}` to `$ascii[$i]` in `functions.php`. All 29 tracked
  application PHP files parse on PHP 5.5, 5.6, 7.4, 8.0, and 8.5.
- [x] Initialize `$rights2 = array()` in `rights2orgasql()` before collecting
  organization IDs. Empty/unknown rights still produce `AND false`; privilege
  exemptions and organization restrictions keep their existing behavior.
- [x] Remove `@` from the rights assertions and expand them from four to 24
  cases. The full PHPUnit suite passes 115 tests and 138 assertions on PHP
  5.6.40, 7.4.33, 8.0.30, and 8.5.12.
- [x] Mutation-test the rights helper. The original four cases killed 13/30
  mutants. Expanded cases killed all 30; after the initialization fix, a new
  initialization-removal mutant is also killed (31/31 on PHP 7.4 and 8.5).
- [x] Add separate Docker/GitHub Actions runtime lanes. PHP 5.6 and 7.4 pass
  the full HTTP/write harness, with the reviewed diagnostics baseline below.
  PHP 8.0 and 8.5 remain failing on login headers and unlisted diagnostics.
  The runner attempts every lane and returns nonzero if any fails.

## Login and redirect failure: confirmed

PHP 8 has not introduced a new rule prohibiting these redirects. The documented
rule is that headers must precede response output, and `Location` normally sets
status 302. See the [PHP header manual](https://www.php.net/manual/en/function.header.php).

The application calls `acc_header()` at `index.php:176` before dispatching to
`page_login()` at `index.php:284`. Login sets cookies and a redirect at
`index.php:27-33`, after rendering has begun. It currently depends on buffering.

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
  `tests/http-login.php` passes on PHP 5.6/7.4 and fails on 8.0/8.5 (login,
  login with target, and logout return 200 without `Location` or cookies).
  With `output_buffering=0` login and logout fail on every lane; these are the
  known failures `KF-LOGIN-UNBUFFERED` and `KF-LOGOUT-UNBUFFERED`, reported as
  "headers already sent by (output started at functions.php:118/122)". The
  script fails when either starts passing, so the fix below must flip them.
- [ ] Process login and other header-setting actions before rendering HTML.
  Verify logout and receipt/download redirects as well. Then move
  `KF-LOGIN-UNBUFFERED`/`KF-LOGOUT-UNBUFFERED` to regular assertions.
- [ ] Review every `page_new_buchung()` call and remove the ineffective default
  or otherwise correct the signature without changing positional arguments.
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
| `KD-REPORT-SQL-PG15` | `report.php:58` | Displayed warning: `pg_query()` fails with "trailing junk after numeric literal at or near "8AND"". `unit_report()` appends `$cond` without a leading space; PostgreSQL 15+ rejects `8AND`, and `or die` ends the 2024 report at its first per-unit income query. The existing smoke assertions still pass on the truncated page. | [ ] Add a report completeness assertion, then add the missing space. Check other `$cond`/`$prevcond` concatenations. |
| `KD-REPORT-PREVCOND` | `report.php:340` | Hidden notice: undefined `$prevcond` (warning on PHP 8). | [ ] Initialize or remove the parameter after tests pin the report output. |
| `KD-PREFS-HIDE` | `closed.php` 37 lines | Hidden notices: `$user_prefs_hide[$n]` undefined offsets for users without hide preferences (54 distinct line/offset pairs per ledger request). | [ ] Use `isset()`/defaults; output must stay identical. |
| `KD-NEW-RIGHTS` | `new.php:184` | Hidden notice: undefined `$rights` passed to `page_save_buchung()`. | [ ] Determine the intended rights argument; test scoped users. |
| `KD-EDIT-RIGHTSSQL` | `edit.php:117` | Hidden notice: undefined `$rightssql` in the finalized-voucher check, so that query has no organization restriction. | [ ] Review the authorization intent before changing it. |
| `KD-EDIT-RELOCATE-CLOSED-LINK` | `index.php:78`, `index.php:328` | Displayed warnings in approval/revision redirect bodies: `relocate()` in `edit.php` closes the connection and returns, so `checklogin()` queries without a link and the final `pg_close()` fails. | [ ] Stop processing after the redirect; assert the redirect body is diagnostic-free. |

PHP 8.0 and 8.5 have no baseline entries; every diagnostic fails the lane.
In addition to the PHP 8 forms of the issues above (seen before the run stops
at login), they report:

- PHP 8.0 and 8.5: `new.php:450` deprecation for required parameters after the
  optional `$part` (on every request that compiles `new.php`), and
  "Cannot modify header information - headers already sent" at
  `index.php:27/28/31/33` (login) and `index.php:288-290` (logout).
- PHP 8.5 only: "Automatic fetching of PostgreSQL connection is deprecated"
  for `pg_query()`, `pg_escape_string()`, and `pg_last_error()` in
  `functions.php:434`, `index.php:15-17/26`, `report.php`, `transactions.php`,
  and `spendings.php`; `strlen(): Passing null to parameter #1` at
  `functions.php:179/228/230`.

## Further array/input findings

| Location | Trigger and evidence | Follow-up |
|---|---|---|
| `edit.php:345` | A missing voucher ID leaves `$vouchers` undefined when the query yields no rows. An authenticated scoped-user request reproduced `count(null)` on PHP 8.0. | Initialize the result array; test missing, deleted, and unauthorized voucher IDs while preserving the existing BGF behavior. |
| `import.php:27` | `count($_FILES['uploadcsv']['name'])` assumes a multiple-upload array. Reproduced the missing-upload `TypeError` with the membership lookup stubbed locally. | Validate/normalize upload structure before counting; cover GET, no file, malformed structure, and upload errors. Keep membership traffic synthetic. |
| `import2.php:24` | A normal authenticated GET with no uploaded PDF reproduced `count(null)` on PHP 8.0. | Render the upload form without processing absent files; add malformed-upload tests. |
| `donations.php:116-122` | With a synthetic qualifying donation and captcha submission, `$donations` is undefined and `array_multisort()` receives null. The collection assignment is commented out for legal reasons. Reproduced on PHP 8.0. | Preserve the disabled-publication policy. Test the intended empty/disabled state before changing array initialization or sorting; do not re-enable donor publication as an incidental fix. |

Other direct `count()` sites were reviewed: the local arrays produced by
`explode()` and the guarded regular-expression result arrays do not share the
same uninitialized-variable path. No additional obsolete curly-brace offsets
were found in the tracked application files; PHP 8.5 syntax checks pass.

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

## Tooling to catch these earlier

- [ ] Add a dedicated modern-PHP analysis container, separate from the legacy
  application runners. Scan tracked application sources first; exclude vendor,
  attachments, and unrelated obsolete proxy code.
- [ ] Evaluate PHPStan, beginning with undefined/possibly undefined variables
  and adding argument-type checks. Its levels 0/1 cover undefined variables,
  and level 5 adds function argument types; see
  [PHPStan rule levels](https://phpstan.org/user-guide/rule-levels).
- [ ] Evaluate PHPCompatibility through PHP_CodeSniffer for removed/deprecated
  features and the supported PHP range. It has a specific curly-brace offset
  rule and configurable version ranges; see
  [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility).
- [ ] Enable `Generic.PHP.DisallowShortOpenTag` explicitly. Start with correctness
  checks rather than a formatting rewrite; see
  [PHP_CodeSniffer usage](https://github.com/PHPCSStandards/PHP_CodeSniffer/wiki/Usage).
- [ ] Review existing findings before establishing a baseline, then prevent new
  findings in CI. Static analysis complements the HTTP cookie/redirect tests;
  it does not establish their runtime behavior.
- [ ] Evaluate [Rector](https://github.com/rectorphp/rector) for PHP-version
  upgrades and automated refactoring. Start with selected rules and `--dry-run`,
  review the proposed diffs, and verify them against the runtime matrix before
  applying changes. Keep the minimum supported PHP version explicit.
- [ ] Evaluate a mutation framework such as Infection for broader generated
  mutations, reviewed equivalent mutants, and CI reporting. Keep the targeted
  mutation cases as evidence while deciding how to replace the custom runner.
