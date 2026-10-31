# Upgrading pgacc

Deploy and upgrade steps for the release archive `pgacc-<version>.tar.gz`
(code plus `vendor/`; production needs no Composer or GitHub access). Release
notes: [CHANGELOG.md](CHANGELOG.md).

On a Debian 13 host set up with `deploy/ansible/playbook.yml`, `deploy/deploy.sh`
verifies, unpacks and activates the archive, with a smoke test and automatic
rollback ([deploy/README.md](deploy/README.md)). It does not run the manual
steps below (pgacc has no database migrations); do them as described.

## Web server: long-term caching of `style.css` and `js/`

With the changes under "Unreleased" in [CHANGELOG.md](CHANGELOG.md), the pages
link the stylesheet and the scripts with the release version in the URL
(`style.css?v=<version>`, `js/vreport-chart.js?v=<version>`). Each upgrade
changes these URLs, so browsers load the new files instead of a cached copy.
The web server can therefore let browsers cache `style.css` and `/js/`
long-term (for example `Cache-Control: public, max-age=31536000, immutable`).
Only a release changes the version: a deploy of unreleased code with changed
files is not picked up by such a cache. The icons (`/icons/`) keep URLs
without a version; do not cache them long-term.

## Browsers

From 2.1.0 (the Tailwind CSS stylesheet, see
[CHANGELOG.md](CHANGELOG.md), "2.1.0"), the pages need Chrome/Edge 111+,
Safari 16.4+ or Firefox 128+. Older browsers still work but show the pages
unstyled. The stylesheet is part of the release archive; nothing needs to be
built on the server.

## Web server: deny `src/`

From 2.1.0 the archive has a `src/` directory with library
files (first `src/db.php`, the database helpers). Deny web access to `/src/`
like `/vendor/` and `/tests/`. On Apache the shipped `src/.htaccess` does
this (it needs `AllowOverride` like the top-level `.htaccess`); on Lighttpd
add `src` to the directory list of the rule below. A request would print
nothing (the files only define functions), but the code is not meant to be
served.

## Web server: short page addresses

The pages link to `/donations`, `/kdonations`, `/spendings`, `/wk`,
`/transactions`, `/vreport` and `/tinyreport` (with an optional query string,
e.g. `/donations?year=2024`). The web server has to rewrite them to
`index.php?action=<name>`, keeping the query string; this was always needed
but only lived in the server configuration. Lighttpd:

```
url.rewrite-once = (
  "^/(vreport|tinyreport|donations|kdonations|spendings|wk|transactions)$" => "index.php?action=$1",
  "^/(vreport|tinyreport|donations|kdonations|spendings|wk|transactions)\?(.*)$" => "index.php?action=$1&$2"
)
```

The nginx site of `deploy/ansible/playbook.yml` has the same rule.

## Passwords: bcrypt and 12 characters

From 2.1.0 new and changed passwords are stored as bcrypt
(`$2a$` hashes) instead of MD5-crypt (`$1$`). Nothing needs to be migrated:
existing hashes stay valid and are replaced with bcrypt at the user's next
successful login (or when the password is set). Accounts that never log in
keep their MD5-crypt hash; root can set a new password for them. A
password that is set needs at least 12 characters; login still accepts
existing shorter passwords. bcrypt uses only the first 72 bytes of a
password, so longer passwords give no extra strength.

## Member list: no shared table

From 2.1.0 each request keeps the member list of the
membership system in a temporary table of its own database connection. The
database user no longer needs to create tables in the `public` schema (only
the `TEMPORARY` privilege on the database, which PostgreSQL grants to every
user by default). The shared `ppmembers` table of earlier releases is no
longer used; after the deploy it can be dropped:

```sql
DROP TABLE IF EXISTS ppmembers;
```

The temporary table lives as long as the request's database connection, so
pgacc needs a session of its own per request: do not put a connection pooler
in transaction or statement mode between pgacc and PostgreSQL (session
pooling, or none, is fine).

## Upgrade to 2.0.0

2.0.0 runs only on PHP 8.5 with PostgreSQL 18; 1.0.0 is the last release for
PHP 5.3 and PostgreSQL 8.4.

1. Finish the 1.0.0 steps first (valid `$key`, receipt migration to
   `<n>.v2.aes`; `--delete-legacy` may wait).
2. Check the PHP extensions. In the unpacked release directory run
   `composer check-platform-reqs --no-dev` with the target PHP (Composer is
   needed only for this check, not to install). It must report `ext-iconv` and
   `ext-zlib` (the PDF receipt import, `smalot/pdfparser`), `ext-sodium` (the
   receipt key fingerprint) and PHP 8.5 as present. Without Composer, `php -m`
   must list `iconv`, `zlib`, `sodium`, `openssl` and `pgsql`.
3. Outgoing HTTPS to the membership system must work with a trusted CA bundle
   (the member list is fetched over verified HTTPS).
4. Upgrade the database to PostgreSQL 18 if needed: dump the `accounting`
   database (preferably with the PostgreSQL 18 `pg_dump`) and restore it.
   Keep `files/` and `constants.php` (with its `$key` and `$datakey`)
   unchanged.
5. Deploy the release archive and keep the web-server rules of the 1.0.0
   steps (deny `files/`, `vendor/`, `tests/`, `src/` (see above), `constants.php`,
   `migrate-receipt-key.php`, `check.php`, `composer.*`; no directory
   listing; HTTPS).
6. Check: log in, open a voucher with a receipt, download it, open the
   member-fee page (member list fetch), and run a test bank or PDF import on
   a copy if possible.

## Upgrade to 1.0.0

1. Back up the database, `files/` and `constants.php` (its `$key`) to
   separate, safe places. Without that `$key` no new receipt can be decrypted
   again; keep the backup as long as receipts must be kept.
2. Check `$key` in `constants.php`. The application requires at least 16
   characters and no `CHANGE-ME` placeholder (otherwise uploads are refused
   after the deploy; legacy downloads keep working); use a random string of 32
   or more characters, e.g. `openssl rand -hex 32`. Legacy receipts never used
   `$key`, so it can still be replaced before the deploy; do not change it
   afterwards: receipts encrypted with it cannot be read with another key, and
   changing it logs everyone out.
3. Check that `mbstring.func_overload` is `0` (or at least does not include
   string overloading, 2) for the web server's PHP and the command line.
   phpseclib's `bootstrap.php` is loaded by `vendor/autoload.php` on every
   request of `index.php` and `mbapi.php` and throws otherwise, so **every
   page** fails, not only receipts.
4. Verify and unpack the archive, then copy the code **including `vendor/`**
   over the installation. Keep `constants.php` and `files/`; the archive
   contains neither.

   ```sh
   sha256sum -c pgacc-<version>.tar.gz.sha256
   tar xzf pgacc-<version>.tar.gz
   ```

5. Configure the web server to deny `/files/`, `/vendor/`, `/tests/`, `/src/` (from 2.1.0),
   `constants.php`, `migrate-receipt-key.php`, `check.php` and
   `composer.json`/`composer.lock`, and to disable directory listings.
   `migrate-receipt-key.php` and `check.php` run only on the command line
   and answer 404 over HTTP; on Apache, `.htaccess` denies only these two.
   Lighttpd
   (adjust the prefix if pgacc is not at the document root):

   ```
   $HTTP["url"] =~ "^/(files|vendor|tests|src)(/|$)|^/(constants\.php|migrate-receipt-key\.php|check\.php|composer\.(json|lock))$" {
     url.access-deny = ( "" )
   }
   dir-listing.activate = "disable"
   ```

6. Tell users that everyone has to log in again: the deploy logs every user
   out once (old cookies are numbers, no longer valid session tokens).
   Also tell them that merge, recovery, the listing column icons and Logout
   are now buttons: old bookmarks or links to these actions no longer change
   anything, and a form left open across a new login shows "Ungültiges
   Formular-Token" (reload the page). No configuration is needed for this.
7. From now on new receipts are stored as `files/<n>.v2.aes`. Migrate the
   existing receipts as described below.

## Receipt migration (`migrate-receipt-key.php`)

Run every step as the web server's user (the owner of `files/` and the
database role), from any directory, on the server whose `constants.php` the
web application uses, with the final `$key`. The script works only on the
command line. Do not post migration transcripts publicly.

1. Back up `files/` (and `constants.php`) first, to a separate place, and
   keep the backup until the legacy files have been deleted and the
   receipts checked.
2. Get the **key fingerprint from the application**: log in as root and
   open the user administration (Benutzerverwaltung). It shows "Fingerabdruck
   des Beleg-Schlüssels" (8 hex digits, a slow Argon2id derivation of the
   `$key` the application uses, never the key itself; it also warns if the
   key has fewer than 32 characters). Every run that writes or deletes needs
   it as `--key-fingerprint=<fingerprint>`; a run with another `$key`
   (another `constants.php`, another installation) then stops without
   changing anything.
3. Dry run: `sudo -u <web user> php migrate-receipt-key.php --dry-run
   --key-fingerprint=<fingerprint>`. It writes nothing and lists each legacy
   file as "would migrate" or "skipped, legacy kept" with the reason; the
   last line shows the fingerprint of the key the script read.
4. Review the skipped files listed at the end ("Skipped, legacy kept: ...").
   They keep working as legacy files. A file that no voucher row references
   is listed as such ("no voucher row references this file"); there is
   nothing to migrate for it. A PDF whose header follows white-space
   (spaces, tabs, line breaks, form feeds or NUL bytes, header within the
   first 1024 bytes, as PDF readers accept it) counts as a PDF. The legacy
   download sent such a PDF as `image/jpeg` (it looked only for `%PDF` at
   the start); once migrated, it is sent as `application/pdf`, with the
   same bytes. The same rule applies to every download of a migrated or
   uploaded receipt: such a PDF, already stored as `<n>.v2.aes` or
   uploaded later, is now shown as `application/pdf` instead of
   being offered as `<n>.bin`. Optional, also as a dry run first:
   - `--include-unusual` also migrates files that decrypt to another
     recognized type (PNG, GIF, TIFF), to an empty file, to text (such as an
     HTML page uploaded as a receipt), or to a PDF or JPEG that looks
     incomplete or has data after its end (also more than one block of NUL
     bytes after `%%EOF`). The download sends the same bytes as before; PDFs
     and JPEGs also keep their type, but the legacy download sent everything
     else as `image/jpeg`: a migrated PNG or GIF is shown as `image/png` or
     `image/gif`, a TIFF, an empty file, text and other types are offered as
     a download `<n>.bin` (`application/octet-stream`, `Content-Disposition:
     attachment`, `X-Content-Type-Options: nosniff`), so a migrated HTML
     page is never shown as a page of the application. Text is accepted
     only with the voucher IDs of the file's own rows, never by
     `--search-voucher-ids`; with it, an empty file is the only unusual
     type also found with another voucher ID (see below).
   - `--search-voucher-ids` tries the other voucher IDs for files that no
     voucher ID of their rows decrypts (after a voucher merge) and migrates
     a file only if one decrypts it to a complete PDF or JPEG or, with
     `--include-unusual`, to an empty file: a file of exactly 32 bytes that
     decrypts to exactly 32 zero bytes (how the old code stored an empty
     upload). The whole block must match, which a wrong voucher ID does with
     a chance of 2^-256, a stronger check than a complete PDF or JPEG; text,
     PNG, GIF, TIFF and incomplete PDFs or JPEGs are never accepted by the
     search. The line names the voucher ID found. (Such a receipt downloaded as unreadable
     bytes before; afterwards it is the original receipt.) It costs one
     single-block decryption per file and candidate ID (up to 1024 bytes
     when the first block is white-space; all voucher IDs up
     to the highest one; roughly 0.15 ms each, so 20,000 IDs take about 3 s
     per file); IDs found for one file are tried first for the next. Run it
     after the normal migration, when only the skipped files are left.
   Pass the same options again to `--delete-legacy` (step 7).
5. Migrate: `sudo -u <web user> php migrate-receipt-key.php
   --key-fingerprint=<fingerprint>`, optionally in batches (`--limit=500`).
   Files above `--max-size` (default `memory_limit` / 8) are skipped; for
   large receipts run with `php -d memory_limit=1G` (or set `--max-size`).
   Legacy files are kept. Finish with a run without `--limit`, and rerun
   until the summary shows 0 migrated and 0 failed.
   - Before writing, each run checks the MAC of up to 16 existing
     `<n>.v2.aes`, first receipts without a legacy file (uploaded since the
     upgrade). If any of them does not verify, the run stops and lists them:
     the key is probably wrong. If the key is certainly right and the files
     are damaged, keep a copy of them and either re-create those that still
     have a legacy file with `--recreate-unverified` (which then checks
     every existing `<n>.v2.aes` before writing, also in `--limit` batches),
     or leave them alone with `--ignore-unverified=<n>,...` (listed as
     skipped).
   - A run without `--limit` also checks every other existing `<n>.v2.aes`
     it reaches; one that does not verify is kept and reported as failed.
     `--limit` batches do not read the already migrated files again (listed
     as "not checked again").
   - Run as the web server's user: each new `<n>.v2.aes` gets the mode of
     its `<n>.aes` (the umask does not matter) and its group where the user
     may set it; if the owner or group still differ, the line warns, and if
     the owner or group of `<n>.aes` could not read the new file, it is not
     written. Run as root (a warning is printed), the new file gets the
     owner, group and mode of `<n>.aes`.
6. Verify: run `sync` once more (PHP 5.3 cannot fsync; `--delete-legacy`
   also runs `sync` itself and stops if that fails). Spot-check several
   migrated receipts (PDF and JPEG, and any migrated with the options of
   step 4) in the browser, before deleting anything, and search the web
   server's error log for downloads that fell back to the legacy file:
   `grep 'pgacc: receipt .*; sent the legacy'` (each line is `pgacc: receipt
   <n>.v2.aes <reason>; sent the legacy <n>.aes`). While the legacy files
   exist, a key mismatch between the web application and the migration
   still serves the old file, so only the log shows the problem. The reason
   is `could not be decrypted (wrong key or modified file)` (e.g. a key
   mismatch), `could not be opened` (missing, unreadable or not a regular
   file, e.g. file permissions) or `not decrypted: no valid receipt key
   ($key) configured`. Also look for refused downloads, logged as `pgacc:
   receipt <n>: no readable stored file` or `pgacc: receipt <n>.v2.aes
   <reason>; no readable <n>.aes`.
7. Only then delete the legacy files: `sudo -u <web user> php
   migrate-receipt-key.php --delete-legacy --key-fingerprint=<fingerprint>`
   (with `--dry-run` first; it repeats the reminder). Each `<n>.aes` is
   deleted only when `<n>.v2.aes` verifies with the key of the run and
   decrypts to exactly the content of `<n>.aes`. Legacy files without
   `<n>.v2.aes` are listed as "skipped, legacy kept" (skipped by the
   migration or not migrated yet; a run without `--delete-legacy` lists the
   reason) and keep their legacy file.
8. Keep the `files/` backup of step 1 until the spot checks are done.

Exit status of every run:

| Status | Meaning |
|---|---|
| 0 | done; nothing skipped |
| 1 | a file failed (listed under "Failed: ..."); fix the cause and run again |
| 2 | not run, nothing changed (usage, invalid key, missing or wrong `--key-fingerprint`, a checked `<n>.v2.aes` does not verify (step 5), lock, database, `sync`) |
| 3 | done, but legacy files were skipped (with `--delete-legacy`: every legacy file without `<n>.v2.aes`; also those of `--ignore-unverified`); listed at the end, their legacy files are kept |

## Rollback

- Before `--delete-legacy`: the legacy `files/<n>.aes` are still there, so the
  previous code can be redeployed as it was (it reads only `<n>.aes` and needs
  `mcrypt`). Receipts uploaded since the upgrade exist only as `<n>.v2.aes`
  and cannot be read by the previous code; keep those files and the `$key` for
  a later upgrade.
- After `--delete-legacy`: restore `files/` from the backup (upgrade step 1)
  before redeploying the previous code.
- Restore the database from the backup only if needed; it also undoes the
  bookings made since the upgrade. Users log in again after a rollback.
