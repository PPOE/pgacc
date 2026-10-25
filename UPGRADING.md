# Upgrading pgacc

Deploy and upgrade steps for the release archive `pgacc-<version>.tar.gz`
(code plus `vendor/`; production needs no Composer or GitHub access). Release
notes: [CHANGELOG.md](CHANGELOG.md).

## Upgrade to 2.0.0

2.0.0 runs only on PHP 8.5 with PostgreSQL 18; 1.0.0 is the last release for
PHP 5.3 and PostgreSQL 8.4.

1. Finish the 1.0.0 steps first (valid `$key`, receipt migration to
   `<n>.v2.aes`; `--delete-legacy` may wait).
2. Check the PHP extensions. In the unpacked release directory run
   `composer check-platform-reqs --no-dev` with the target PHP (Composer is
   needed only for this check, not to install). It must report `ext-iconv` and
   `ext-zlib` (the PDF receipt import, `smalot/pdfparser`) and PHP 8.5 as
   present. Without Composer, `php -m` must list `iconv`, `zlib`, `openssl` and
   `pgsql`.
3. Outgoing HTTPS to the membership system must work with a trusted CA bundle
   (the member list is fetched over verified HTTPS).
4. Upgrade the database to PostgreSQL 18 if needed: dump the `accounting`
   database (preferably with the PostgreSQL 18 `pg_dump`) and restore it.
   Keep `files/` and `constants.php` (with its `$key` and `$datakey`)
   unchanged.
5. Deploy the release archive and keep the web-server rules of the 1.0.0
   steps (deny `files/`, `vendor/`, `tests/`, `constants.php`,
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

5. Configure the web server to deny `/files/`, `/vendor/`, `/tests/`,
   `constants.php`, `migrate-receipt-key.php`, `check.php` and
   `composer.json`/`composer.lock`, and to disable directory listings.
   `.htaccess` covers only `migrate-receipt-key.php` on Apache. Lighttpd
   (adjust the prefix if pgacc is not at the document root):

   ```
   $HTTP["url"] =~ "^/(files|vendor|tests)(/|$)|^/(constants\.php|migrate-receipt-key\.php|check\.php|composer\.(json|lock))$" {
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

Run every step as the web server's user (file owner and database role), from
any directory. The script works only on the command line.

1. Dry run: `sudo -u <web user> php migrate-receipt-key.php --dry-run`.
   It lists each legacy file as "would migrate" or "not migrated" (with the
   reason) and writes nothing. Note the key fingerprint in the summary; do
   not post migration transcripts publicly.
2. Migrate: `sudo -u <web user> php migrate-receipt-key.php`, optionally in
   batches (`--limit=500`). Files above `--max-size` (default `memory_limit`
   / 8) are skipped; for large receipts run with `php -d memory_limit=1G` (or
   set `--max-size`). Legacy files are kept. Exit status 1 means a file
   failed; rerun until the summary shows 0 migrated and 0 failed.
3. Run `sync` once more (PHP 5.3 cannot fsync; `--delete-legacy` also runs
   `sync` itself before it deletes anything and stops if that fails).
4. Verify: spot-check several migrated receipts (PDF and JPEG) in the
   browser, before deleting anything, and check the web server's error log
   for `pgacc: receipt <n>.v2.aes could not be decrypted; sent the legacy
   <n>.aes`: while the legacy files exist, a key mismatch between the web
   application and the migration still serves the old file, so only the log
   shows it.
5. Later, delete the legacy files: `sudo -u <web user> php
   migrate-receipt-key.php --delete-legacy` (also with `--dry-run` first; it
   repeats the reminder). Each `<n>.aes` is deleted only after `<n>.v2.aes`
   decrypted to the same content with the key the run read. Its key
   fingerprint must match the one of steps 1 and 2.
6. Keep the `files/` backup from the upgrade's step 1 until the spot checks
   are done. Skipped files stay legacy `<n>.aes` and keep working as before.

## Rollback

- Before `--delete-legacy`: the legacy `files/<n>.aes` are still there, so the
  previous code can be redeployed as it was (it reads only `<n>.aes` and needs
  `mcrypt`). Receipts uploaded since the upgrade exist only as `<n>.v2.aes`
  and cannot be read by the previous code; keep those files and the `$key` for
  a later upgrade.
- After `--delete-legacy`: restore `files/` from the backup of step 1 before
  redeploying the previous code.
- Restore the database from the backup only if needed; it also undoes the
  bookings made since the upgrade. Users log in again after a rollback.
