# pgacc

JIT accounting system for political parties according to the Austrian
Parteiengesetz (PartG) 2012. pgacc is the finance tool ("Finanzen") of the
Piratenpartei Österreichs. It is written in PHP on PostgreSQL; the user
interface is in German.

Core concepts:

- **Vouchers** (Belege): booking lines in the `vouchers` table; lines that share
  a `voucher_id` form one voucher.
- **Two-person approval**: each voucher is acknowledged twice (`ack1`, `ack2`).
- **Organizations/units**: bookings belong to party organizations; user rights
  are scoped to them.
- **Receipts**: PDF receipts are uploaded, stored encrypted in `files/`.
- **Public reports**: annual report (Rechenschaftsbericht), transactions and
  spendings pages, donation lists.
- **Import**: bank statement CSV import (BAWAG format).
- **Membership integration**: `getusers.php` fetches member names from the
  membership system, `mbapi.php` exports membership payments.

## Requirements

- PHP with the `pgsql` extension and `short_open_tag` enabled (set in
  `.htaccess`). `mcrypt` is no longer needed: receipts and the membership
  exchange use `phpseclib` (same ciphertext; used automatically if loaded).
- PostgreSQL with the `pgcrypto` extension (passwords use `crypt()`).
- Composer, for `smalot/pdfparser` and `phpseclib/phpseclib`.
- A PHP-capable web server (e.g. Apache or Lighttpd with PHP-CGI/FPM).

Tested on PHP 5.6, 7.4, 8.0 and 8.5 and PostgreSQL 17. The PHP 8 lanes still
have known failures; see [tests/COMPATIBILITY_PLAN.md](tests/COMPATIBILITY_PLAN.md).

## Setup

1. Create a database named `accounting` (the code connects with
   `pg_connect("dbname=accounting")`, i.e. as the web server's database user)
   and load the schema from [tests/fixtures/schema.sql](tests/fixtures/schema.sql)
   (`table-schema.txt` is an older `psql` dump of the same tables).
2. Create `constants.php` in the repository root. It is gitignored and must
   never be committed:

   ```php
   <?php
   $datakey = 'CHANGE-ME';  // shared key for the membership system exchange
   $key = 'CHANGE-ME';      // key for encrypting uploaded receipts
   ```

3. Run `composer install`.
4. Make `files/` writable by the web server. It holds the receipt files and
   must not be web-accessible.
5. Serve the repository root over HTTPS. `index.php` redirects unless the
   request carries `X-Forwarded-Proto: https`, so it expects a TLS-terminating
   proxy in front. Older versions rely on PHP output buffering for the login
   redirect and cookies.

## Usage

All pages are served through `index.php?action=<name>`, e.g.:

| Action | Purpose |
| --- | --- |
| *(none)* / `year=YYYY` | Public annual report |
| `transactions`, `spendings` | Public transaction and spending lists |
| `login`, `logout` | Sign in / out |
| `new` | Enter a new voucher |
| `open`, `edit` | Open vouchers; review, approve, attach receipts |
| `all`, `search`, `closed`, `deleted` | Ledger views (`format=csv` for export) |
| `import` | Bank CSV import |

## Development and tests

The test harness runs in Docker with synthetic data only.

```sh
sh tests/run.sh                         # syntax matrix + all PHP lanes
sh tests/run.sh 7.4                     # one lane: 5.6, 7.4, 8.0 or 8.5
PGACC_TEST_ID=mine sh tests/run.sh 7.4  # isolate concurrent runs
sh tests/static-analysis.sh             # PHPCompatibility + PHPStan
sh tests/mutation/run.sh 7.4 <target>   # target-driven mutation runner
sh tests/mutation/infection.sh 7.4      # Infection (7.4 or 8.5)
```

Details: [tests/README.md](tests/README.md),
[tests/COMPATIBILITY_PLAN.md](tests/COMPATIBILITY_PLAN.md),
[tests/mutation/README.md](tests/mutation/README.md).

## License

No license file is included in this repository.
