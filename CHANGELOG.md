# Changelog

All notable changes to pgacc are documented in this file. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `deploy/ansible/playbook.yml` sets up a Debian 13 host for pgacc: PHP
  8.5-FPM and PostgreSQL 18 from their upstream package repositories, an
  nginx site behind a TLS-terminating reverse proxy (required trusted proxy
  addresses), separate users for PHP (read-only code) and for deploying, the
  `/srv/pgacc` release layout and a nightly local database dump. The
  `accounting` database (`en_US.UTF-8`) is created only on an explicit fresh
  install, never over an existing one. `deploy/deploy.sh` deploys a release
  tag with checksum and archive checks, an atomic switch, a smoke test,
  automatic rollback and pruning of old releases. See
  [deploy/README.md](deploy/README.md). The application is unchanged.
  Optional `pgacc_extra_hosts` entries for `/etc/hosts` let the host reach
  the membership system when the internal DNS does not resolve its name.
  `ansible-playbook --check` works on a provisioned host.
- The nginx site sends `Cache-Control: no-cache` for static files, so
  browsers revalidate `style.css`, scripts and icons and pick up changes after
  a deploy without a hard reload, and `Cache-Control: private, no-store` for
  PHP responses that do not set their own, so no cache keeps pages with
  personal or financial data.

### Changed

- The stylesheet and script URLs carry the release version
  (`style.css?v=<version>`), so browsers load the new files after an upgrade
  instead of a cached copy. The web server can cache `style.css` and `js/`
  long-term ([UPGRADING.md](UPGRADING.md)).
- The donation list (`/donations`) shows the current year by default, like
  the report; before, it showed 2013 without a captcha. Years before 2012 and
  after 9999 also show the current year (before: 2013), still without the
  captcha.
- The report and the donation list keep the chosen year: once a year was
  chosen, the menu links to both pages carry it (`?year=<year>`), so the
  other page shows the same year. The other pages have no year and are
  unchanged.

### Documentation

- [UPGRADING.md](UPGRADING.md) describes the rewrite of the short page
  addresses (`/donations`, `/vreport?konto=...` and others) to
  `index.php?action=<name>`, which the web server needs and which was only
  part of the server configuration until now.
- [deploy/README.md](deploy/README.md) describes moving an existing
  installation: the order of the steps, restoring a dump from PostgreSQL
  before 13 (8.4 included) into PostgreSQL 18, checks after the restore, a
  cutover checklist with rollback, the network requirements of the
  membership system, and that the receipts and `constants.php` need a
  backup from outside the host.

## [2.1.0] - 2026-10-26

Security and data-integrity release: receipts, voucher saves and passwords.
Read [UPGRADING.md](UPGRADING.md) before upgrading.

### Upgrade notes

- Deny web access to the new `src/` directory (on Apache the shipped
  `src/.htaccess` does it).
- The member list is kept in a temporary table per database connection. The
  database user needs only the default `TEMPORARY` privilege, no `CREATE` on
  `public`; the old `ppmembers` table can be dropped. Do not run pgacc behind
  a connection pooler in transaction or statement mode.
- Receipt migration runs that write or delete need `--key-fingerprint` (shown
  to root in the user administration; requires the `sodium` extension).
  Fingerprints of earlier releases no longer match.
- Pages need Chrome/Edge 111+, Safari 16.4+ or Firefox 128+; older browsers
  show them unstyled.

### Security

- Saving a voucher keeps its stored receipt; a receipt number in the request
  is ignored.
- Voucher revisions are saved in one transaction under a per-voucher lock.
  A save from an outdated form, or one that touches another organization's
  row, is refused.
- New and changed passwords are stored as bcrypt and need at least 12
  characters. Existing MD5-crypt hashes are replaced at the next login.
  Logins with unknown user names take as long as with known ones.
- Listing cells are HTML-encoded by default; markup needs the explicit
  `tag_raw()`.
- Receipt downloads refuse missing, unreadable or incomplete stored files
  instead of sending partial data.
- Receipt migration: a wrong key can no longer overwrite or delete receipts.
  Existing files are verified before writing, and a legacy file is deleted
  only when its new file decrypts to exactly the same content.

### Added

- A `LICENSE` file: Peter Grassberger (PeterTheOne)'s contributions are
  licensed under AGPL-3.0-or-later. The status of other code is described
  there; third-party code keeps its own license.

### Changed

- New stylesheet built with Tailwind CSS: modernized look, usable in narrow
  windows.
- The booking progress is a table with bars, newest year first; older years
  open on demand.
- The report and the donations page select the year with a compact selector.
- The virtual-account chart uses Chart.js; jQuery and Flot are removed.
- The Impressum links to the party's legal notice and the LICENSE, lists the
  third-party software with its licenses and shows only the major versions of
  PHP and PostgreSQL.
- Logged-in users see the donor lists without the arithmetic check.
- `check.php` runs only on the command line.
- Receipt migration: new options `--include-unusual`, `--search-voucher-ids`,
  `--recreate-unverified` and `--ignore-unverified`; skipped files give exit
  status 3; new files keep the owner, group and mode of their legacy file.
  Details in [UPGRADING.md](UPGRADING.md).
- PNG and GIF receipts download with their image type.
- Library code starts moving to `src/` (database helpers); a failed query is
  logged with the page that ran it.

### Fixed

- Amounts are parsed exactly. Invalid amounts are refused in the voucher form
  and skipped in bank imports instead of being saved as 0.00 or a wrong value
  (Handkassa `12,5` was imported as 1.25).
- Listings and CSV exports no longer shift cells for a booking with an unknown
  type or organization; the edit form shows such a type as "Unbekannt".
- BAWAG exports with an empty line are imported completely.
- Concurrent requests no longer replace each other's member list. If the
  membership system is unreachable, saving a member voucher shows the form
  again instead of ending the page.
- Logging in without a login target no longer ends on a blank page.
- The closed-voucher listing shows the virtual account and the old approvals
  again.
- No more PHP 8.5 errors or warnings on edge-case requests (import pages
  without an upload, invalid years or array parameters, the removed
  `?action=transfer`, plain views of several pages).

### Removed

- PHP 5.3 fallbacks for login nonces and the constant-time comparison: login
  uses only `random_bytes()` and fails closed if it errors.
- Unused stylesheet rules, `gregor.css` and dead JavaScript.

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
