# Changelog

All notable changes to pgacc are documented in this file. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed

- Logged-in users see the donor lists without the arithmetic check.
- The virtual-account chart uses Chart.js; jQuery and Flot are removed.
- The Impressum links to the party's legal notice and to pgacc's LICENSE,
  shows the pgacc version but only the major versions of PHP and PostgreSQL,
  and lists the third-party software (Composer packages, Chart.js, Silk icons)
  with its licenses, left-aligned.

### Fixed

- Logging in without a login target no longer ends on a blank page (empty
  redirect).
- The import pages (`?action=import`, `?action=import2`) show the upload form
  again on PHP 8.5 instead of failing with a fatal error when no file was
  uploaded.
- Plain views of the new-voucher, search, donations, in-kind donations,
  campaign, account and virtual account pages and of a receipt link without ID
  no longer raise PHP warnings.
- `?action=transfer` (a page removed in 2014) shows the report like any
  unknown action instead of a fatal error.
- An `ack` POST on the new-voucher form shows the form again instead of a
  fatal error; nothing is saved.
- A year that is not four digits (`?year=2024-1`) shows the report of the
  current year instead of a warning and a failed query.
- The voucher form and the listing filters of the CSV export ignore array
  request parameters (`bid0[]=1`, `?filter_id[]=1`) like other invalid values
  instead of failing with HTTP 500.
- The closed-voucher listing shows the virtual account and the old
  approvals again.
- Saving a member voucher part without a member number and without a name no
  longer raises PHP warnings.
- Users without stored column preferences, an empty sort parameter and a
  missing import CSV field no longer raise PHP 8.5 deprecations.

## [2.0.0] - 2026-10-25

First release for PHP 8.5 and PostgreSQL 18. PHP 5 and 7 are no longer
supported; v1.0.0 (`php5-last`) is the last release for them. Read UPGRADING.md
before upgrading.

### Security

- The member list is fetched from the membership system over HTTPS with
  certificate verification; redirects are refused. The list is decrypted and
  checked before the member table is replaced, in one transaction: a failed,
  short or invalid response keeps the previous list.

### Changed

- Requires PHP 8.5 and PostgreSQL 18 (tested only there). Every database call
  passes the connection explicitly, so PHP 8.5 runs without deprecations.
- The PDF receipt import works again (`smalot/pdfparser` 2.13.1 replaces
  0.9.23 and TCPDF 6.2.6). A file that cannot be read as a PDF is reported and
  skipped; the other files of the upload are still imported.
- A member list with a malformed line or without any member is rejected
  instead of being imported partly as member 0.
- Saving an edited voucher whose values had to be corrected (missing date,
  refund or purpose flags) still saves, then shows a note on the voucher page
  instead of discarding the warning.
- Amounts are always shown with cents, and table cells use tabular digits so
  amounts line up ([#1](https://github.com/PPOE/pgacc/issues/1)).

### Fixed

- The CSV download link works on pages without a query string
  ([#2](https://github.com/PPOE/pgacc/issues/2)).
- A failed database connection shows "Verbindungsaufbau fehlgeschlagen"
  instead of a PHP error; the report page's error handling used a function
  that does not exist.
- A never-working, production-only duplicate check in the bank import was
  removed (identical lines are still skipped as before).

### Upgrade notes

- Requires PHP 8.5 with `pgsql`, `iconv`, `zlib` and `openssl`, PostgreSQL 18,
  and outgoing HTTPS to the membership system. Steps in UPGRADING.md.

## [1.0.0] - 2026-10-25 (php5-last)

First tagged release, also tagged `php5-last`: the last release that supports
PHP 5.3. Production runs PHP 5.3.3 on Debian 6 with PostgreSQL 8.4; this
release is tested on PHP 5.3 and 7.4 (see Compatibility). It hardens
authorization, login sessions, receipt encryption and output encoding, removes
the obsolete voucher finalization and fixes a number of bugs. Read the upgrade
notes before deploying: a valid `$key` is required and every user has to log in
again.

### Security

- Authorization: writes stay within the organizations a user may access, roles
  are checked by exact rights, and merging, approval resets and read-only
  (`Rbgf`) access require the matching right.
- SQL: voucher IDs are validated and request values and membership data are
  escaped or cast before they are used in queries.
- Receipts: new receipts are stored as `files/<n>.v2.aes`, encrypted and
  authenticated (AES-256-CBC with HMAC-SHA256) with keys derived from the
  configured `$key`.
  `migrate-receipt-key.php` (command line only) re-encrypts existing receipts;
  legacy `<n>.aes` files stay readable until they are migrated.
- Login sessions: the login cookie is a random, server-side session token
  bound to the configured key; logout and password changes end sessions on
  the server. Cookies are sent `secure`, `HttpOnly` and `SameSite=Lax`. The
  login redirect target is restricted to pages of the application.
- Output: database and request values are HTML-escaped in listings, forms,
  reports and import results. CSV exports neutralize cells that a spreadsheet
  would treat as formulas, and tabs and line breaks no longer split cells.
- CSRF protection: every form that changes data carries a per-session token,
  checked centrally before any write. Merge, recovery, the column preferences
  and logout now work only via POST; GET requests to them change nothing.

### Changed

- Old bookmarks or links to merge, recovery, column preferences and logout no
  longer change anything; forms left open across a re-login must be reloaded.
- Voucher finalization is removed (no button since 2015). Existing finalized
  vouchers stay locked against approval, revision and receipt upload.
- Any change to a voucher (revision, receipt upload, PDF receipt import)
  resets the approvals of the whole voucher.
- Bank CSV import: lines with an invalid date or a disallowed organization are
  reported and skipped instead of stopping the import; a summary is shown.
- A password change ends that user's sessions, including your own.
- Every user is logged out once after the upgrade, and again whenever `$key`
  changes.
- Receipt uploads are refused without a valid `$key` or when the file cannot
  be stored.
- Stored values containing HTML entities (e.g. `&uuml;`) are shown literally.
- Member-fee page ("MB Zahlungen nach LO"): rows are ordered by organization,
  with "Keine" last, and "Keine" now includes every unknown organization.

### Fixed

- New users created by root get the rights entered in the form.
- Annual report cut off on newer PostgreSQL versions.
- Listings with hidden columns, the donations page and the open-loans names on
  the member-fee page.
- Login and logout redirects and cookies failed when output was sent first.
- Forms shown again after adding or removing a part doubled quotes and
  backslashes.
- Sort links lost the refund filter.
- Bank import: member names are matched literally and lines after a member
  match are imported.
- Division by zero on an empty member-fee page.

### Compatibility

- Tested on PHP 5.3 (production) and PHP 7.4, with PostgreSQL 8.4 and 18.
- PHP 8.5 runs in CI but still emits known deprecations (implicit database
  connections), so it is not supported yet; PHP 8.0 to 8.4 are not tested.
- The PDF receipt import needs PHP below 7.2 (the locked `smalot/pdfparser`).
- `mcrypt` is no longer required: `phpseclib` (via Composer) replaces it with
  byte-identical results and uses `mcrypt` for speed when it is loaded.
- Short open tags are no longer needed.
- Characterization test harness, CI on PHP 5.3/7.4/8.5 × PostgreSQL 8.4/18,
  mutation testing.

### Upgrade notes

Follow [UPGRADING.md](UPGRADING.md) (also in the release archive). In short:
back up the database, `files/` and `constants.php`; set a valid `$key`
(at least 16 characters, not `CHANGE-ME`); deploy the code including `vendor/`;
migrate the receipts with `migrate-receipt-key.php`; deny web access to
`files/`, `vendor/`, `tests/` and the configuration; everyone logs in again.
