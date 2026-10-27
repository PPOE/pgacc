# Provisioning and deploying pgacc

For a dedicated Debian 13 (trixie) host or container:

- [`ansible/playbook.yml`](ansible/playbook.yml) sets up the host (run it
  again after changes; a second run changes nothing).
- [`deploy.sh`](deploy.sh), installed as `pgacc-deploy`, deploys a release
  tag.
- [`staging-stub.sh`](staging-stub.sh) (optional, staging only) replaces the
  membership system with the membership stub.

They are not part of the release archive (`.gitattributes`); run them from a
checkout of the repository. Release notes and manual upgrade steps:
[CHANGELOG.md](../CHANGELOG.md), [UPGRADING.md](../UPGRADING.md).

## Provisioning

```sh
cp deploy/ansible/inventory.example.yml ~/pgacc-inventory.yml   # and edit
ansible-playbook -i ~/pgacc-inventory.yml deploy/ansible/playbook.yml
```

The target needs Python 3 and SSH (or another Ansible connection). Settings
(inventory variables, see [inventory.example.yml](ansible/inventory.example.yml)):

| Variable | Default | |
| --- | --- | --- |
| `pgacc_trusted_proxies` | none, required | Addresses or CIDR ranges of the TLS-terminating reverse proxy. |
| `pgacc_timezone` | `UTC` | PHP `date.timezone`. |
| `pgacc_backup_keep_days` | `7` | Days the nightly dumps are kept. |

What it sets up:

| Part | Details |
| --- | --- |
| Packages | nginx; PostgreSQL 18 from apt.postgresql.org (set up with `apt.postgresql.org.sh` of Debian's `postgresql-common`); PHP 8.5 (`php8.5-fpm`, `-cli`, `-pgsql`, `-mbstring`) from packages.sury.org with its keyring package `debsuryorg-archive-keyring`. It checks the PHP extensions `pgsql`, `mbstring`, `iconv`, `zlib`, `sodium`, `openssl`. |
| Users | `pgacc` runs PHP (the PHP-FPM pool) and is the database role. `pgacc-deploy` owns the releases and may run `systemctl reload php8.5-fpm.service` with sudo, nothing else. Both are system users without a login shell. |
| Layout | `/srv/pgacc/releases/v<version>/` and the symlink `/srv/pgacc/current` belong to `pgacc-deploy`; PHP can read them but not write. `/srv/pgacc/shared/files/` (receipts) belongs to `pgacc` (mode 0700). `/srv/pgacc/shared/constants.php` is `root:pgacc` 0640: PHP reads it, nobody but root writes it. PHP errors go to `/var/log/pgacc/php-error.log` (rotated weekly). |
| `constants.php` | Created only if there is none, with a random `$key` and a random placeholder `$datakey` (replace it with the key shared with the membership system). The playbook prints a notice when it creates one. An existing file is never changed (only its owner and mode). |
| PHP-FPM | Pool `pgacc` (socket `/run/php/pgacc-fpm.sock`); `90-pgacc.ini`: `display_errors` off, `expose_php` off, `cgi.fix_pathinfo=0`, time zone, 20M uploads, 256M memory. `allow_url_fopen` stays on: `getusers.php` fetches the member list over HTTPS. Reloaded only when this configuration changes. |
| nginx | Port 80, `server_name _`, document root `/srv/pgacc/current`, PHP with the resolved release path (`$realpath_root`). Only the trusted proxies and the host itself may connect, so `X-Forwarded-Proto` is trusted only from them. Denied as in [UPGRADING.md](../UPGRADING.md): `files/`, `vendor/`, `tests/`, `src/`, `deploy/`, `constants.php`, `migrate-receipt-key.php`, `check.php`, `composer.json`/`.lock` and dot files. The default site is disabled. |
| Database | Role `pgacc` (peer authentication over the local socket, no password) and the database `accounting` owned by it (UTF-8, `C.UTF-8`), with `pgcrypto` and [tests/fixtures/schema.sql](../tests/fixtures/schema.sql); `CONNECT` revoked from `PUBLIC`. Created only if it does not exist. If `accounting` exists but belongs to another role, the playbook stops. |
| Dumps | `pgacc-backup.timer` runs `pg_dump --format=custom` nightly as `postgres` into `/var/backups/pgacc` (owner `postgres`, mode 0700; neither `pgacc` nor `pgacc-deploy` can write there) and deletes dumps older than `pgacc_backup_keep_days`. |

TLS ends at the reverse proxy, which must send `X-Forwarded-Proto: https`
(`index.php` redirects to HTTPS otherwise).

### Backups

The local dumps only make restoring the database quick. The real backup is a
snapshot of the whole host or container, taken from outside. It must cover
`/srv/pgacc/shared/constants.php` and `/srv/pgacc/shared/files/` (and
`/var/backups/pgacc`). Keep a copy of `constants.php` off the host as well:
without its `$key` the stored receipts cannot be decrypted.

### Moving an existing installation

After the first playbook run and before the first deploy, replace
`shared/constants.php` with the existing one (owner `root`, group `pgacc`,
mode 0640) and copy the receipts into `shared/files/` (owner `pgacc`). Replace
the new, empty `accounting` database with the dump:

```sh
sudo -u postgres dropdb accounting
sudo -u postgres createdb --owner=pgacc --encoding=UTF8 --locale=C.UTF-8 --template=template0 accounting
sudo -u postgres psql -d accounting -c 'REVOKE CONNECT, TEMPORARY ON DATABASE accounting FROM PUBLIC' \
  -c 'GRANT CONNECT, TEMPORARY ON DATABASE accounting TO pgacc'
sudo -u postgres pg_restore --no-owner --role=pgacc --exit-on-error -d accounting accounting.dump
```

(A plain SQL dump: `sudo -u pgacc psql -v ON_ERROR_STOP=1 -d accounting -f dump.sql`.)
Then follow [UPGRADING.md](../UPGRADING.md) for the releases in between. A new
database has no users; the first administrator can be created as `pgacc`:

```sql
INSERT INTO users (name, hash, rights, hide)
VALUES ('admin', crypt('<password of 12 or more characters>', gen_salt('bf')), 'root', '');
```

## Deploying

```sh
sudo -u pgacc-deploy pgacc-deploy v2.1.0
sudo -u pgacc-deploy pgacc-deploy --file /path/to/pgacc-2.1.0.tar.gz   # local archive (testing)
```

1. The argument must be a tag `v<major>.<minor>.<patch>[-<pre-release>]`.
2. It downloads `pgacc-<version>.tar.gz` and `pgacc-<version>.tar.gz.sha256`
   from the GitHub release of the tag (built by
   [.github/workflows/release.yml](../.github/workflows/release.yml) with
   [scripts/release.sh](../scripts/release.sh)), or takes the local file and
   the `.sha256` next to it, and checks the checksum (`sha256sum -c`). **The
   `.sha256` comes from the same release as the archive: it proves that the
   download is intact, not who built it.** Signed archives or build
   attestations are a later step.
3. The archive may only contain files and directories under
   `pgacc-<version>/`, no `..`, and no `constants.php` or `files/`. It is
   unpacked into `releases/v<version>/`, with `constants.php` and `files`
   linked to `shared/`.
4. Database migrations: none. pgacc has no migration mechanism; the database
   or configuration steps of a release are in [UPGRADING.md](../UPGRADING.md)
   and are done by hand.
5. `current` is switched atomically, PHP-FPM is reloaded and
   `http://127.0.0.1/index.php?action=login` must answer with the login form.
   Otherwise `current` goes back to the previous release (or is removed if
   there was none), the new release directory is deleted, and the script
   exits with 1.
6. The newest 5 releases are kept, always including the current and the
   previous one.

Deploying the current release again does nothing. Deploying a release that is
still in `releases/` switches back to it without downloading (a manual
rollback). Nothing is built on the host: the archive contains `vendor/`.

## Staging: membership stub

```sh
sudo sh deploy/staging-stub.sh
```

Only on a staging host, after the playbook. It points the host name of the
membership URL in `getusers.php` to 127.0.0.1 in `/etc/hosts`, where nginx
serves the stub's synthetic member list
([tests/reference/membership/](../tests/reference/membership/)) over HTTPS
with a certificate of a local CA that the host trusts, and sets `$datakey` in
`constants.php` to the stub's synthetic key
([tests/runtime/test-constants.php](../tests/runtime/test-constants.php)).
It changes the whole host's view of the membership system, so never run it on
a production host, and build production hosts fresh rather than converting a
staging host.

## Fixed in the code

The database name (`pg_connect("dbname=accounting")` in `index.php` and
`mbapi.php`) and the membership URL (`getusers.php`) are fixed in the code,
so they are not settings here.
