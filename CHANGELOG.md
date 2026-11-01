# Changelog

All notable changes to pgacc are documented in this file. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Voucher page: a row's receipt is shown inline below "Beleg Anzeigen", loaded
  from the same download when scrolled into view: a PDF in a frame, a JPEG,
  PNG or GIF as an image. Other types (also HTML) and receipts the download
  refuses keep the link only.

### Changed

- The ledger listings (all, open, closed, deleted) and the voucher form show
  a member's number with the member's name from the member list, linked to
  the member's profile in the membership system; a number not in the list
  gets the hint "nicht in der Mitgliederliste". If the member list cannot be
  loaded, the number is shown as text as before. The list is fetched at most
  once per request and only by pages that show a member's number (the
  voucher form only for a voucher with the Mitglied box set), waiting at
  most 5 seconds (saves, the import and the member-fee page keep 20); the
  CSV export keeps the plain number. The linked number and name stay on
  one line.

### Fixed

- The voucher form shows its buttons (Speichern, Bestätigen, Beleg and
  Buchungszeile hinzufügen) to users with several rights, such as "1,2",
  "root,1" or "Rbgf,2"; before, only bgf and users with exactly one right
  got them. Users without bgf and without an organization right ("root"
  alone, no rights) now get none; read-only bgf ("Rbgf") still gets none.
  Saving and approving check the rights as before.
- Profile links of the member-fee page point to the profile page of
  Admidio 5 (`modules/profile/profile.php`) instead of the removed
  `adm_program/` path.

## [2.3.0] - 2026-11-01

Membership sync v2 (JSON endpoints over internal TLS with tokens), the web
server document root `public/`, and a deny rule for backup copies. Read
[UPGRADING.md](UPGRADING.md) before upgrading.

### Upgrade notes

- Hosts set up with `deploy/ansible/playbook.yml`: first run the playbook of
  this release. It sets the document root to `current/public`, adds the
  backup-copy rule and writes `shared/sync-secrets.php`. Have the archive
  ready on the host, since the site answers 404 until the deploy.
- Then deploy right away: `pgacc-deploy v2.3.0` (or `--file` with the
  downloaded archive). Without the playbook run first, the smoke test fails
  and `pgacc-deploy` rolls back.
- Member list: without `pgacc_members_url`, `pgacc_members_token` and
  `pgacc_members_cert` (and the membership system's listener serving
  `members.php`), the member-fee page, the bank import and saving a member
  voucher stop with "Mitgliederliste nicht konfiguriert!".
- Optional: the payments listener for the membership system
  (`pgacc_sync_listen_address`, `pgacc_sync_peers`, `pgacc_payments_token`).
- Other web servers: document root `public/` and the allow list by hand.

### Sync v2 (membership sync)

The data exchange with the membership system is replaced (sync v2): two
read-only JSON endpoints, called directly between the two hosts over TLS
with one token per direction and the caller's address checked, instead of
the encrypted exports of the old membership system. Both directions are
configured in the new file `sync-secrets.php` (release root, like
`constants.php`; a file that does not load counts as missing); with
`deploy/` the playbook writes `shared/sync-secrets.php` from the inventory
on every run (changes on the host are overwritten) and `pgacc-deploy`
links it into each release. Recommended: `pgacc_timezone: Europe/Vienna`.
See [UPGRADING.md](UPGRADING.md), "Sync v2 (membership sync)".

- Internal sync endpoint `internal/mbapi.php` (the payments for the
  membership system), **off unless configured**: the membership payments
  of the former `public/mbapi.php` as JSON
  (`[{"id", "amount_cents", "paid_until"}]`) on an internal TLS listener
  with its own document root `internal/`. A request must come from an
  address of `payments_peers` and carry `payments_token`
  (`Authorization: Bearer`); otherwise, and without valid settings, 403
  with an empty body; errors 503 with an empty body. Membership fees
  without a member id are left out. `deploy/`: `pgacc_sync_listen_address`
  (empty: off), `pgacc_sync_peers`, `pgacc_payments_token` (64 hex
  characters from the inventory, the same as on the membership system) and
  `pgacc_sync_port` (8443); a self-signed certificate
  `/etc/pgacc/sync-tls.{crt,key}` for the address (made again when it
  changes) and a second nginx `server`; `pgacc-deploy` requires `internal/`
  to hold only `mbapi.php` and, with the listener on, expects 403 from it
  without the token.
- **Behaviour change**: `public/mbapi.php`, the encrypted
  membership-payment export of the old membership sync, is removed:
  `/mbapi.php` on the public site now answers 404, and the nginx site of
  `deploy/`, `public/.htaccess` and `scripts/release.sh` no longer allow
  it. The helper `membership_encrypt()` (only used by the export) is
  removed.
- **Behaviour change**: the member list (`getusers.php`; the member-fee
  page, the bank import, saving a member voucher) comes from the
  membership system's `members.php` as JSON over its internal TLS listener
  instead of the encrypted `adm_names.php` of the old membership system.
  Settings in `sync-secrets.php`: `members_url` (https only),
  `members_token` (sent as `Authorization: Bearer`) and `members_cafile`
  (the only trust anchor); with `deploy/` the variables
  `pgacc_members_url`, `pgacc_members_token` (64 hex characters, the same
  as on the membership system) and `pgacc_members_cert` (written to
  `/etc/pgacc/admidio-addons-tls.crt`), empty by default, so the member
  list is off and those pages fail with "Mitgliederliste nicht
  konfiguriert!". The fetch requires a verified certificate, no redirect,
  HTTP 200 and a complete body of at most 4 MiB within 20 seconds that
  matches its `Content-Length` (if sent: 1-18 digits), and
  refuses the whole list if a row is malformed or a member id or uuid
  appears twice (fail closed as before). The member list stores each
  member's uuid; the member-fee page links profiles by `user_uuid`, the
  ledger listing and the voucher form show the member id as text instead
  of a link. `membership_decrypt()` is removed.
- With both directions on sync v2 nothing reads `$datakey` of
  `constants.php` any more: `membership_cipher_params()` is removed, the
  playbook of `deploy/` no longer writes a `$datakey` into a new
  `constants.php`, and an existing `$datakey` line is ignored.

### Changed

- **Deployment change**: the web server's
  document root is now `public/` of the release, not the release root
  ([UPGRADING.md](UPGRADING.md), "Document root `public/`"; issue #80).
  `public/` holds only the entry point `index.php`,
  `style.css`, `js/` and `icons/`; the page scripts, `functions.php`, `src/`,
  `vendor/`, the command-line scripts, `constants.php` and `files/` stay in
  the release root, so nothing there can be requested, whatever its name
  (such as a backup copy `constants.php.bak`). `constants.php` and `files/`
  stay where they are. The URLs stay the same; a direct request of a page
  script, `functions.php` or `src/*.php` (which printed nothing) now gets a
  404. Also relevant for monitoring: `/files/`, `/vendor/`, `/tests/`,
  `/src/`, `/constants.php`, `/composer.json` and the command-line scripts
  answer 404 instead of 403 (dot files and backup copies still 403);
  `/icons/readme.html`, `/js/README.md` and files of other types (`.gif`,
  `.jpg`, `.svg`, `.ico`, `.webp`, `.woff`; pgacc ships and links none) are
  no longer served; `/index.php/...` (PATH_INFO) answers 404. A symbolic
  link below `public/` is refused (403). The nginx site of `deploy/ansible/playbook.yml` serves
  `current/public` with an allow list (`/`, the entry point, the static
  files, the short page addresses; anything else 404); run the playbook of
  the new release, then deploy it. `pgacc-deploy` refuses to switch back to a
  release without `public/`; if the smoke test of the new release fails, it
  rolls back to a previous release without `public/` only if that
  release's login page still answers (the web server still serves the
  release root); otherwise the new release stays current. Apache: `public/.htaccess` has the same allow list
  (mod_rewrite, which is now required, and `AllowOverride All`; it also
  rewrites the short page addresses and turns off `PATH_INFO`) and the rules
  of the former `.htaccess`; on Debian, comment out Apache's `Alias /icons/`.
  `src/.htaccess` removed; the top-level `.htaccess` now
  denies every request (a safety net if the document root is still the
  release root). The Lighttpd examples are removed from UPGRADING.md
  and README.md (untested, no longer used); other web servers need the same
  document root, allow list and deny rules as the nginx site template.

### Security

- Web server: deny backup, editor and dump copies anywhere in the tree
  (`constants.php.bak`, `index.php~`, `functions.php.orig`, `*.sql`,
  `*.tar.gz` and the like; [UPGRADING.md](UPGRADING.md), "Web server: deny
  backup and editor copies"). Without a `.php` extension such a copy was
  served as plain text. The nginx site of `deploy/ansible/playbook.yml` and
  the top-level `.htaccess` have the rule (hosts set up with the playbook:
  run it again); for other web servers add it by hand. No URL of the
  application changes.

## [2.2.0] - 2026-10-31

Deployment scripts, versioned stylesheet and script URLs, and receipt
migration fixes. Read [UPGRADING.md](UPGRADING.md) before upgrading.

### Upgrade notes

- Run the receipt migration again with `--include-unusual` and
  `--search-voucher-ids` (dry run first), then `--delete-legacy` with the
  same options, to migrate the receipts that earlier runs skipped.
- Optional: the web server can now cache `style.css` and `js/` long-term
  (their URLs carry `?v=<version>`); not `/icons/`.
- Hosts set up with `deploy/ansible/playbook.yml`: run it again for the
  caching rules.

### Added

- `deploy/`: an Ansible playbook that sets up a Debian 13 host (PHP 8.5-FPM,
  PostgreSQL 18, nginx behind a TLS-terminating reverse proxy, separate PHP
  and deploy users, nightly database dump; the database is created only on an
  explicit fresh install), and `deploy/deploy.sh`, which deploys a release tag
  with checksum checks, an atomic switch, a smoke test and automatic
  rollback. `ansible-playbook --check` works; optional `pgacc_extra_hosts`
  entries for `/etc/hosts`. See [deploy/README.md](deploy/README.md).
- The nginx site sends `Cache-Control: no-cache` for static files and
  `private, no-store` for PHP responses that set none.

### Changed

- The stylesheet and script URLs carry the release version
  (`style.css?v=<version>`), so browsers load the new files after an upgrade.
- The donation list shows the current year by default (before: 2013), also
  for years before 2012 or after 9999. The report and the donation list keep
  the chosen year in the menu links (`?year=<year>`).

### Fixed

- Receipt migration and downloads: PDFs whose header follows white-space
  (within the first 1024 bytes) are migrated and sent as `application/pdf`
  (before: `image/jpeg` or `<n>.bin`).
- `--include-unusual` also migrates text receipts that decrypt with their own
  voucher ID; they download only as `<n>.bin`.
- The migration names receipt files that no voucher row references.
  `--search-voucher-ids --include-unusual` also migrates such a file, or one
  its rows' IDs do not decrypt, if another voucher ID decrypts it to an empty
  upload (32 bytes to 32 zero bytes).

### Documentation

- [UPGRADING.md](UPGRADING.md): the web server rewrite of the short page
  addresses (`/donations`, `/vreport?konto=...`) to
  `index.php?action=<name>`.
- [deploy/README.md](deploy/README.md): moving an existing installation
  (step order, restoring a dump from PostgreSQL before 13 into 18, checks,
  cutover checklist with rollback, network access to the membership system,
  off-host backup of the receipts and `constants.php`).

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
