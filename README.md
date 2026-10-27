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

- PHP 8.5 with the `pgsql` extension (`short_open_tag` is no longer needed). `mcrypt` is no longer needed: receipts and the membership
  exchange use `phpseclib` (same ciphertext; used automatically if loaded).
- PostgreSQL 18 with the `pgcrypto` extension (passwords use `crypt()`;
  new passwords bcrypt, older MD5-crypt hashes still accepted).
- Composer, for `smalot/pdfparser` and `phpseclib/phpseclib`.
- A PHP-capable web server (e.g. Apache or Lighttpd with PHP-CGI/FPM).
- Browsers: Chrome/Edge 111+, Safari 16.4+ or Firefox 128+ (the stylesheet
  uses Tailwind CSS v4 features such as cascade layers; older browsers show
  the pages unstyled).

Tested on PHP 8.5 with PostgreSQL 18 only. The last release for PHP 5.3 and
PostgreSQL 8.4 is 1.0.0 (tag `php5-last`). The PDF bank-statement import does
not work on PHP 8 yet; see
[tests/COMPATIBILITY_PLAN.md](tests/COMPATIBILITY_PLAN.md), "PHP 8.5-only
migration".

Release notes and upgrade steps: [CHANGELOG.md](CHANGELOG.md).
Provisioning a Debian 13 host and deploying releases with scripts:
[deploy/README.md](deploy/README.md).

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

   `$key` must have at least 16 characters and must not be the `CHANGE-ME`
   placeholder; otherwise receipt uploads are refused. Use a random string of
   32 or more characters (e.g. `openssl rand -hex 32`). Back it up and keep it:
   receipts uploaded since the receipt key fix (`files/<n>.v2.aes`) cannot be
   decrypted without it or with another key. Older receipts (`files/<n>.aes`)
   stay readable; `migrate-receipt-key.php` (command line only) re-encrypts
   them, see the runbook in [UPGRADING.md](UPGRADING.md).

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
sh tests/run.sh                         # the PHP 8.5 / PostgreSQL 18 lane, with coverage
PGACC_TEST_ID=mine sh tests/run.sh      # isolate concurrent runs
sh tests/static-analysis.sh             # PHPCompatibility + PHPStan
sh scripts/format.sh [files...]         # format (PER-CS 3.0, whitespace only)
sh scripts/format.sh --check            # formatting check (CI: --check --ci)
sh tests/mutation/run.sh 8.5 <target>   # target-driven mutation runner
sh tests/mutation/infection.sh 8.5      # Infection
sh scripts/build-css.sh                 # rebuild style.css from css/app.css
sh scripts/build-css.sh --check         # style.css is up to date (CI)
```

The stylesheet source is `css/app.css`: it styles the elements, classes and
ids the pages use with Tailwind CSS (`@apply` and the theme), and
`scripts/build-css.sh` builds it into the committed `style.css` with the
pinned Tailwind CSS standalone CLI (no Node.js; the binary is downloaded once,
checked against its SHA-256 and cached in `build/`). Edit `css/app.css`, never
`style.css`, and commit both; CI fails when they differ.

PHP files follow PER Coding Style 3.0, whitespace rules only
(`.php-cs-fixer.dist.php`, `sh scripts/format.sh`), checked in CI. Agents in
Claude Code format every edited PHP file through a hook
(`.claude/settings.json`). An opt-in Git pre-commit hook checks the staged PHP
files:

```sh
git config core.hooksPath .githooks
```

The whitespace-only
formatting commit is listed in `.git-blame-ignore-revs`; let `git blame`
skip it with:

```sh
git config blame.ignoreRevsFile .git-blame-ignore-revs
```

Change workflow and verification order: [AGENTS.md](AGENTS.md).
Details: [tests/README.md](tests/README.md),
[tests/COMPATIBILITY_PLAN.md](tests/COMPATIBILITY_PLAN.md),
[tests/mutation/README.md](tests/mutation/README.md).

## Releases

Releases are created only by the Release workflow
(`.github/workflows/release.yml`). To cut one (1.0.0 is also `php5-last`):

1. In the release pull request, date the version's section in
   [CHANGELOG.md](CHANGELOG.md) (`## [1.0.0] - YYYY-MM-DD (...)`), set
   `PGACC_VERSION` in `impressum.php` (shown on the Impressum) to the new
   version (re-record the reference, `sh tests/reference/run.sh record`: the
   Impressum recording shows it), and merge to `master`. The build fails
   unless `PGACC_VERSION` equals the tagged version.
2. On the merged commit: `git tag -a v1.0.0 -m 'pgacc 1.0.0'` and
   `git tag -a php5-last -m 'Last release for PHP 5.3'`, then
   `git push origin v1.0.0 php5-last`.
3. The workflow builds `pgacc-1.0.0.tar.gz` (code plus `vendor/`, checked on
   the PHP version of the tagged commit: 5.3 for 1.0.0, 8.5 afterwards) and its `.sha256`, and creates a draft release with the
   CHANGELOG.md section as notes (a prerelease for versions with a `-`).
4. Review the draft on GitHub and publish it. Deploy: [UPGRADING.md](UPGRADING.md).

For testing only, `sh scripts/release.sh 2.0.0` builds the same files locally
(Docker, clean tree, `build/release/`); a manual workflow run does the same.

## License

See [LICENSE](LICENSE): the contributions of Peter Grassberger (PeterTheOne) are
licensed under AGPL-3.0-or-later ([full text](LICENSES/AGPL-3.0-or-later.txt));
contributions by other authors are not licensed until their authors confirm
otherwise.
