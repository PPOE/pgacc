# Provisioning and deploying pgacc

Two scripts for a dedicated Debian 13 (trixie) host:

- [`provision.sh`](provision.sh) (root, once, safe to run again) installs
  and configures the host.
- [`deploy.sh`](deploy.sh) (installed as `/usr/local/bin/pgacc-deploy`, run
  as the `pgacc` user) deploys a build archive: a release tag or, on staging,
  a commit.

Release notes and manual upgrade steps: [CHANGELOG.md](../CHANGELOG.md),
[UPGRADING.md](../UPGRADING.md). The scripts are not part of the release
archive (`.gitattributes`); run them from a checkout of the repository.

## What `provision.sh` sets up

| Part | Details |
| --- | --- |
| Packages | PHP 8.5 (`php8.5-fpm`, `-cli`, `-pgsql`, `-mbstring`) from packages.sury.org and PostgreSQL 18 from apt.postgresql.org (Debian 13 ships PHP 8.4 and PostgreSQL 17), as deb822 sources with `Signed-By` keyrings in `/etc/apt/keyrings/`; the script checks each key's fingerprint. It then checks that PHP has `pgsql`, `mbstring`, `iconv`, `zlib`, `sodium` and `openssl`. |
| User | System user and group `pgacc` (no login shell). |
| Layout | `/srv/pgacc/releases/<tag or commit>/`, `/srv/pgacc/current` (symlink to the active release), `/srv/pgacc/shared/` with `constants.php`, `files/` (receipts) and `logs/` (`php-error.log`, `deploy.log`; rotated weekly). |
| `constants.php` | Created only if none exists, with a random `$key`, and printed as a loud notice: back it up at once. Production: `$datakey` is a random placeholder that must be replaced with the key shared with the membership system. Staging: the synthetic key of the membership stub. An existing `constants.php`, `files/` or database is never changed. |
| PHP-FPM | Pool `pgacc` (user `pgacc`, socket `/run/php/pgacc-fpm.sock`), production settings in `/etc/php/8.5/fpm/conf.d/90-pgacc.ini`: `display_errors` off, errors logged to `shared/logs/php-error.log`, `expose_php` off, `cgi.fix_pathinfo=0`, time zone, upload and memory limits. `allow_url_fopen` stays on: `getusers.php` fetches the member list over HTTPS. |
| nginx | `/etc/nginx/sites-available/pgacc`: plain HTTP on `PGACC_HTTP_PORT`, document root `/srv/pgacc/current`, PHP through the pool with the resolved release path (`$realpath_root`). Denied as in [UPGRADING.md](../UPGRADING.md): `files/`, `vendor/`, `tests/`, `src/`, `deploy/`, `constants.php`, `migrate-receipt-key.php`, `check.php`, `composer.json`/`composer.lock`, and dot files; no directory listing. The default site is disabled. |
| Database | Role `pgacc` (login over the local socket with peer authentication, no password) and the database `accounting` owned by it (UTF-8, `PGACC_DB_LOCALE`), with `pgcrypto` and the schema of [tests/fixtures/schema.sql](../tests/fixtures/schema.sql); `CONNECT` revoked from `PUBLIC`. Created only if the database does not exist. |
| Backups | `pgacc-backup.timer` (nightly, systemd) runs `pg_dump --format=custom` into `PGACC_BACKUP_DIR` and deletes dumps older than `PGACC_BACKUP_KEEP_DAYS`. Only the database: back up `constants.php` and `files/` separately. |
| Deploy | `/usr/local/bin/pgacc-deploy`, its settings in `/etc/pgacc/pgacc.env`, and `/etc/sudoers.d/pgacc-deploy`, which lets `pgacc` run `systemctl reload php8.5-fpm.service` and nothing else. |

The application connects with `pg_connect("dbname=accounting")` as the PHP
process's user, and fetches the member list from the URL in
`getusers.php`. Neither is configurable in the code, so they are not
settings here either.

TLS is terminated by a reverse proxy in front of nginx, which must send
`X-Forwarded-Proto: https` (`index.php` redirects to HTTPS otherwise) and
the original `Host`. nginx trusts that header from any client it accepts:
set `PGACC_PROXY_ADDRESSES` to the proxy's addresses, or firewall the port.

### Running it

```sh
cp deploy/pgacc.conf.example /root/pgacc.conf   # and edit
sudo sh deploy/provision.sh --config /root/pgacc.conf
```

Settings: [pgacc.conf.example](pgacc.conf.example). Run it again after
changing them; it rewrites only its own files (apt sources, nginx site,
PHP-FPM pool and settings, systemd units, sudoers file,
`/etc/pgacc/pgacc.env`) and restarts PHP-FPM and reloads nginx.

Moving an existing installation: after the first provision run, before the
first deploy, replace `shared/constants.php` with the existing one (owner
`pgacc`, mode 0600) and copy the receipts into `shared/files/` (owner
`pgacc`). The provisioned `accounting` database already has the schema, so
replace it (only this new, empty one) with the dump:

```sh
sudo -u postgres dropdb accounting
sudo -u postgres createdb --owner=pgacc --encoding=UTF8 --locale=C.UTF-8 --template=template0 accounting
sudo -u postgres psql -d accounting -c 'REVOKE CONNECT, TEMPORARY ON DATABASE accounting FROM PUBLIC' \
  -c 'GRANT CONNECT, TEMPORARY ON DATABASE accounting TO pgacc'
sudo -u postgres pg_restore --no-owner --role=pgacc --exit-on-error -d accounting accounting.dump
```

(`pg_restore` reads a custom-format dump; a plain SQL dump goes through
`sudo -u pgacc psql -v ON_ERROR_STOP=1 -d accounting -f dump.sql`.) Then
follow [UPGRADING.md](../UPGRADING.md) for the releases in between.

A new database has no users. The first administrator can be created with
`psql` as `pgacc`:

```sql
INSERT INTO users (name, hash, rights, hide)
VALUES ('admin', crypt('<password of 12 or more characters>', gen_salt('bf')), 'root', '');
```

### Staging mode

`PGACC_MODE=staging` never contacts the membership system. The host name of
the membership URL in `getusers.php` is pointed to 127.0.0.1 in `/etc/hosts`,
where nginx serves the synthetic member list of the membership stub
([tests/reference/membership/](../tests/reference/membership/)) over HTTPS
with a certificate of a local CA that the host trusts (`/etc/pgacc/staging-tls/`).
A generated `constants.php` gets the stub's synthetic `$datakey`
([tests/runtime/test-constants.php](../tests/runtime/test-constants.php)).
Staging also allows commit deploys. Do not use staging mode on a production
host: it redirects the membership host for the whole system. A production
run after a staging run removes the `/etc/hosts` entry, the CA and the stub,
and warns while `constants.php` still has the synthetic `$datakey`.

## Deploying

```sh
sudo -u pgacc pgacc-deploy v2.1.0                  # a release tag
sudo -u pgacc pgacc-deploy 1a2b3c4                 # a commit (staging only)
sudo -u pgacc pgacc-deploy --file /path/to/pgacc-2.1.0.tar.gz   # a local archive
```

1. The argument must be a tag `v<major>.<minor>.<patch>[-<pre-release>]` or a
   commit of 7 to 40 hex digits; anything else is refused before it is used
   in a URL or path. Commits are refused unless `PGACC_ALLOW_COMMIT_DEPLOY=1`
   (staging); a short commit is resolved to the full one with the GitHub API.
2. Archive locations (defined only in `deploy.sh`, `archive_location`):

   | Argument | Archive (and `.sha256`) | Top directory |
   | --- | --- | --- |
   | tag `v<version>` | `https://github.com/<repo>/releases/download/v<version>/pgacc-<version>.tar.gz`, built by the Release workflow ([.github/workflows/release.yml](../.github/workflows/release.yml), [scripts/release.sh](../scripts/release.sh)) | `pgacc-<version>/` |
   | commit `<sha>` | `https://github.com/<repo>/releases/download/staging/pgacc-<40-hex sha>.tar.gz`, assets of a rolling pre-release `staging` (not built by CI yet) | `pgacc-<40-hex sha>/` |
   | `--file <path>` | the file and `<path>.sha256`; named `pgacc-<version>.tar.gz` or `pgacc-<40-hex sha>.tar.gz` | as above |

   The `.sha256` line must name the archive and match it. The checksum comes
   from the same release as the archive: it detects damaged downloads, not
   a tampered release.
3. The archive may only contain regular files and directories under its top
   directory. It is unpacked into `releases/<tag or sha>/`, with
   `constants.php` and `files` linked to `shared/`, and a record
   `.pgacc-release` (kind, id, archive SHA-256, source, time).
4. Database migrations: none. pgacc has no migration mechanism; database or
   configuration steps of a release are listed in
   [UPGRADING.md](../UPGRADING.md) and are done by hand, before or after the
   deploy as described there.
5. `current` is switched atomically (rename of a new symlink), PHP-FPM is
   reloaded, and `index.php?action=login` is requested on 127.0.0.1 with the
   host name and `X-Forwarded-Proto: https`. Unless it answers HTTP 200 with
   the login form, `current` is switched back to the previous release (or
   removed, if there was none), the new release directory is deleted and the
   script exits with 1.
6. The newest `PGACC_KEEP_RELEASES` (default 5) releases are kept; older
   ones are deleted, never the current or the previous one.

Deploying the current release again does nothing. Deploying a release that
is still in `releases/` switches back to it without downloading (a manual
rollback). Each deploy and failure is logged in `shared/logs/deploy.log`.
Nothing is built on the host: the archive contains `vendor/`, and the host
needs neither git nor Composer.
