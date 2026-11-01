# Provisioning and deploying pgacc

For a dedicated Debian 13 (trixie) host or container:

- [`ansible/playbook.yml`](ansible/playbook.yml) sets up the host (run it
  again after changes; a second run changes nothing).
- [`deploy.sh`](deploy.sh), installed as `pgacc-deploy`, deploys a release
  tag.

They are not part of the release archive (`.gitattributes`); run them from a
checkout of the repository. Release notes and manual upgrade steps:
[CHANGELOG.md](../CHANGELOG.md), [UPGRADING.md](../UPGRADING.md).

## Provisioning

```sh
cp deploy/ansible/inventory.example.yml ~/pgacc-inventory.yml   # and edit
ansible-playbook -i ~/pgacc-inventory.yml deploy/ansible/playbook.yml
# new installation only, once: also create the database with the schema
ansible-playbook -i ~/pgacc-inventory.yml deploy/ansible/playbook.yml -e pgacc_fresh_install=true
```

The target needs Python 3 and an Ansible connection. For a Proxmox container
the example inventory shows the `community.proxmox.proxmox_pct_remote`
connection (SSH to the Proxmox host, then `pct exec`), which needs:

- on the Proxmox host, sudo for `pct exec` of that one container without a
  password (the plugin cannot answer a sudo prompt; `--ask-become-pass` only
  applies inside the container), e.g. in `/etc/sudoers.d/`:
  `<user> ALL=(root) NOPASSWD: /usr/sbin/pct exec <container ID> -- *`
  (check it with `visudo -cf` before installing it). This rule effectively
  grants root in that container to the user: for a production container add
  it only for the provisioning run and remove it afterwards;
- `ansible_become: false`, as `pct exec` already runs as root in the
  container, which may not have sudo before the first run;
- an `ansible_host`/`ansible_port` that paramiko reaches directly: it does not
  read `~/.ssh/config` (no host aliases, `AddressFamily`, `ProxyJump`), and
  the host key must be in `~/.ssh/known_hosts` under that exact address.

Settings (inventory variables, see
[inventory.example.yml](ansible/inventory.example.yml)):

| Variable | Default | |
| --- | --- | --- |
| `pgacc_trusted_proxies` | none, required | Addresses or CIDR ranges of the TLS-terminating reverse proxy. |
| `pgacc_timezone` | `UTC` | PHP `date.timezone`. |
| `pgacc_backup_keep_days` | `7` | Days the nightly dumps are kept. |
| `pgacc_disable_sshd` | `true` | Stops and disables the SSH server (`ssh.service`, `ssh.socket`): the host is administered through the container runtime. Set it to `false` if Ansible connects over SSH to the host itself. |
| `pgacc_extra_hosts` | `[]` | Entries for `/etc/hosts` (list of `{ip, names}`), kept in a block marked by Ansible; see "Membership system". Nothing is written while the list is empty. |
| `pgacc_sync_listen_address` | `''` (off) | Internal IPv4 address of the host for the internal sync listener (see "Membership system"). Empty: no listener, no `sync.php`, no certificate. |
| `pgacc_sync_peers` | `[]` | IPv4 addresses allowed to call `internal/mbapi.php` (written into `shared/sync.php` on every run); required with `pgacc_sync_listen_address`. |
| `pgacc_sync_port` | `8443` | TCP port of the internal sync listener. |
| `pgacc_fresh_install` | `false` | Pass `-e pgacc_fresh_install=true` once to create the database (see below). |

Defaults: time zone UTC and dumps kept 7 days (the settings above); uploads
up to 20M (nginx and PHP) and PHP `memory_limit` 256M (fixed).

What it sets up:

| Part | Details |
| --- | --- |
| Packages | nginx; PostgreSQL 18 from apt.postgresql.org (set up with `apt.postgresql.org.sh` of Debian's `postgresql-common`); PHP 8.5 (`php8.5-fpm`, `-cli`, `-pgsql`, `-mbstring`) from packages.sury.org with its keyring package `debsuryorg-archive-keyring`. It checks the PHP extensions `pgsql`, `mbstring`, `iconv`, `zlib`, `sodium`, `openssl`. |
| Users | `pgacc` runs PHP (the PHP-FPM pool) and is the database role. `pgacc-deploy` owns the releases and may run `systemctl reload php8.5-fpm.service` with sudo, nothing else. Both are system users without a login shell. |
| Layout | `/srv/pgacc/releases/v<version>/` and the symlink `/srv/pgacc/current` belong to `pgacc-deploy`; PHP can read them but not write. `/srv/pgacc/shared/files/` (receipts) belongs to `pgacc` (mode 0700). `/srv/pgacc/shared/constants.php` is `root:pgacc` 0640: PHP reads it, nobody but root writes it. PHP errors go to `/var/log/pgacc/php-error.log` (rotated weekly). |
| `constants.php` | Created only if there is none, with a random `$key` and a random placeholder `$datakey` (replace it with the key shared with the membership system). The playbook prints a notice when it creates one. An existing file is never changed (only its owner and mode). |
| PHP-FPM | Pool `pgacc` (socket `/run/php/pgacc-fpm.sock`, `display_errors` off, errors logged); Debian's default pool `www` is removed. `90-pgacc.ini`: `expose_php` off, `cgi.fix_pathinfo=0`, time zone, 20M uploads, 256M memory. `allow_url_fopen` stays on (Debian's default): `getusers.php` fetches the member list over HTTPS. Reloaded only when this configuration changes. |
| nginx | Port 80, `server_name _`, document root `/srv/pgacc/current/public` (only `public/` is served; the code, `constants.php` and `files/` are in the release root, see [UPGRADING.md](../UPGRADING.md), "Document root `public/`"), PHP with the resolved release path (`$realpath_root`) and `SERVER_NAME` set to the request's `Host` (the proxy must pass the public host name; `index.php` redirects to it). The short page addresses the application links to (`/donations`, `/kdonations`, `/spendings`, `/wk`, `/transactions`, `/vreport`, `/tinyreport`, each with an optional query string) are rewritten to `index.php?action=<name>`. Only the trusted proxies and the host itself may connect, so `X-Forwarded-Proto` is trusted only from them. Allow list: only `/`, `index.php`, `mbapi.php`, `style.css`, `js/*.js`, `icons/*.png` and the short page addresses are served, anything else answers 404; as a second line of defence dot files and backup, editor and dump copies (names ending in `~`, `.bak`, `.orig`, `.save`, `.swp`, `.sql`, `.tar.gz` and the like, or with something after `.php`, such as `constants.php.bak`) answer 403. Caching: the static files (`style.css`, `js/*.js`, `icons/*.png`) get `Cache-Control: no-cache`, so browsers revalidate them on every use (ETag/`Last-Modified`, a 304 if unchanged) and pick up a deploy on the next page view; the asset URLs are not versioned yet. PHP responses get `Cache-Control: private, no-store` unless the application sets its own Cache-Control, which is kept (the application sets none at present); pages after login hold personal and financial data. The default site is disabled. |
| Sync listener | Only with `pgacc_sync_listen_address`: `/srv/pgacc/shared/sync.php` (`root:pgacc` 0640), created once with a random token (`openssl rand -hex 32`), its peers set from `pgacc_sync_peers` on every run; a self-signed certificate `/etc/pgacc/sync-tls.crt` (subject alternative name: the address; key `/etc/pgacc/sync-tls.key`, mode 0600), created once; a second nginx `server` on `<address>:<port>` with TLS, document root `/srv/pgacc/current/internal`, serving only `/mbapi.php` (dot files and backup copies 403, anything else 404), with the socket's address as `REMOTE_ADDR` (no proxy headers) and no access log. |
| Database | PostgreSQL 18 with the `en_US.UTF-8` locale generated (the collation of the existing installation). A normal run never creates or changes a database. Only with `-e pgacc_fresh_install=true`: role `pgacc` (peer authentication over the local socket, no password) and the database `accounting` owned by it (UTF-8, `en_US.UTF-8`), with `pgcrypto` and [tests/fixtures/schema.sql](../tests/fixtures/schema.sql); `CONNECT` revoked from `PUBLIC`. It refuses if `accounting` exists already, with or without tables. |
| Dumps | `pgacc-backup.timer` runs `pg_dump --format=custom` nightly as `postgres` into `/var/backups/pgacc` (owner `postgres`, mode 0700; neither `pgacc` nor `pgacc-deploy` can write there) and deletes dumps older than `pgacc_backup_keep_days`. |

TLS ends at the reverse proxy, which must send `X-Forwarded-Proto: https`
(`index.php` redirects to HTTPS otherwise).

### Backups

The nightly dumps cover **only the database**, and only make restoring it
quick. Nothing the playbook sets up backs up the receipts in
`/srv/pgacc/shared/files/` or the key in `/srv/pgacc/shared/constants.php`.
The real backup is a snapshot of the whole host or container, taken from
outside; it must cover `shared/files/`, `shared/constants.php` (and
`/var/backups/pgacc`). Keep a copy of `constants.php` off the host as well:
without its `$key` the stored receipts cannot be decrypted.

### Moving an existing installation

The deploy script's smoke test fetches the login page, which fails without
the database, so the database comes before the first deploy. Order:

1. Run the playbook (a normal run, without `pgacc_fresh_install`).
2. Replace `shared/constants.php` with the existing one (owner `root`, group
   `pgacc`, mode 0640) and check that it loads, without printing the keys:
   `sudo -u pgacc php -r 'include "/srv/pgacc/shared/constants.php"; echo strlen($key), " ", strlen($datakey), PHP_EOL;'`
   (two non-zero lengths).
3. Create the role and database and restore the dump (below).
4. Copy the receipts into `shared/files/` and fix owner and mode (a tar from
   the old server keeps its owner and modes):
   `chown -R pgacc:pgacc /srv/pgacc/shared/files && chmod 0700 /srv/pgacc/shared/files`.
5. Deploy: `sudo -u pgacc-deploy pgacc-deploy v<version>`.
6. Follow [UPGRADING.md](../UPGRADING.md) for the releases in between; with
   legacy receipts (`files/<n>.aes`) this includes the receipt migration
   (`migrate-receipt-key.php`). Run
   it as `pgacc` from `/srv/pgacc/current`: the dry run (its last line shows
   the fingerprint of the key it read; compare it with the user
   administration), the migration, a rerun until it shows 0 migrated and 0
   failed, the spot checks, then `--delete-legacy` with `--dry-run` first:

   ```sh
   cd /srv/pgacc/current
   sudo -u pgacc php migrate-receipt-key.php --dry-run --key-fingerprint=<fingerprint>
   sudo -u pgacc php migrate-receipt-key.php --key-fingerprint=<fingerprint>
   sudo -u pgacc php migrate-receipt-key.php --delete-legacy --dry-run --key-fingerprint=<fingerprint>
   sudo -u pgacc php migrate-receipt-key.php --delete-legacy --key-fingerprint=<fingerprint>
   ```

7. Check the new host through the reverse proxy before switching the public
   name, with the public host name as `Host`: from the proxy,
   `curl -H 'Host: <public name>' -H 'X-Forwarded-Proto: https' 'http://<new host>/index.php?action=login'`
   must return the login form (on the new host itself the same works
   against `http://127.0.0.1/`). Log in, open a voucher with a receipt and
   download it, and open the member-fee page either before the switch
   (through a temporary proxy entry) or right after it, with the rollback
   ready.
8. Switch the reverse proxy to the new host.

Restoring the database (step 3). Dump on the old server with its own
`pg_dump -Fc` (the custom format; PostgreSQL 18's `pg_dump` cannot dump
servers older than 9.2, and `pg_restore` 18 reads the old dump). A dump from
before PostgreSQL 13 contains `CREATE SCHEMA public` (which exists already)
and, in older installations, pgcrypto as plain C functions in `public` owned
by `postgres`, which the `pgacc` role cannot create. Create the extension
instead and leave those entries out of the restore. Run as root, with the
dump in a place `postgres` can read:

```sh
sudo -u postgres createuser pgacc
sudo -u postgres createdb --owner=pgacc --encoding=UTF8 --locale=en_US.UTF-8 --template=template0 accounting
sudo -u postgres psql -d accounting -c 'CREATE EXTENSION IF NOT EXISTS pgcrypto'
sudo -u postgres psql -d accounting -c 'REVOKE CONNECT, TEMPORARY ON DATABASE accounting FROM PUBLIC' \
  -c 'GRANT CONNECT, TEMPORARY ON DATABASE accounting TO pgacc'
sudo -u postgres pg_restore -l accounting.dump \
  | grep -vE ' (SCHEMA|COMMENT|ACL) - | FUNCTION public ' > toc.list
sudo -u postgres pg_restore --no-owner --role=pgacc --exit-on-error --single-transaction \
  -L toc.list -d accounting accounting.dump
```

pgacc defines no database functions of its own; if `pg_restore -l` lists
`FUNCTION public` entries other than pgcrypto's, check them before leaving
them out. (A plain SQL dump: `sudo -u pgacc psql -v ON_ERROR_STOP=1 -d
accounting -f dump.sql`, after the same first four commands.)

Compare old and new: the row count of every table and the sequences
`voucher_number` and `file_number`. This works on both servers (also 8.4):

```sh
sudo -u postgres psql -XtA -d accounting -c "
  SELECT table_name, (xpath('/row/c/text()', query_to_xml(
           'SELECT count(*) AS c FROM public.' || quote_ident(table_name), false, true, '')))[1]::text
  FROM information_schema.tables
  WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY 1"
sudo -u postgres psql -XtA -d accounting -c 'SELECT last_value FROM voucher_number'
sudo -u postgres psql -XtA -d accounting -c 'SELECT last_value FROM file_number'
```

(One `-c` per call: `psql` before 9.6 runs only the last one.)

A fresh database has no users; the first administrator can be created as
`pgacc`:

```sql
INSERT INTO users (name, hash, rights, hide)
VALUES ('admin', crypt('<password of 12 or more characters>', gen_salt('bf')), 'root', '');
```

#### Cutover

1. Stop writes on the old server: stop its web server, or put the reverse
   proxy into maintenance for pgacc.
2. Take the final dump and copy of the receipts.
3. Steps 3 to 7 above.
4. Switch the reverse proxy and test from outside.

Users have to log in again (sessions are not moved). Rollback: point the
reverse proxy back and start the old web server again. Anything entered on
the new host after the switch is then lost (or has to be entered again).

## Deploying

```sh
sudo -u pgacc-deploy pgacc-deploy v2.1.0
sudo -u pgacc-deploy pgacc-deploy --file /path/to/pgacc-2.1.0.tar.gz   # local archive (testing)
```

As root with `runuser` instead of `sudo` (e.g. through the container
runtime), give the full path, as the `PATH` there may not include
`/usr/local/bin`:
`runuser -u pgacc-deploy -- /usr/local/bin/pgacc-deploy v2.1.0`.

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
   `pgacc-<version>/`, no `..`, and no `constants.php`, `sync.php` or `files/`, and must
   have `public/index.php` and `vendor/autoload.php`, and its `internal/`
   must hold `mbapi.php` only. It is unpacked into
   `releases/v<version>/`, with `constants.php`, `sync.php` and `files`
   linked to `shared/` in the release root (outside the document roots
   `public/` and `internal/`; `sync.php` may not exist: see "Membership
   system").
4. Database migrations: none. pgacc has no migration mechanism; the database
   or configuration steps of a release are in [UPGRADING.md](../UPGRADING.md)
   and are done by hand.
5. `current` is switched atomically, PHP-FPM is reloaded and
   `http://127.0.0.1/index.php?action=login` must answer with the login form,
   and `/js/README.md` and `/index.php/x` with 404 (the allow list is active).
   Otherwise `current` goes back to the previous release (or is removed if
   there was none), the new release directory is deleted, and the script
   exits with 1. Exception: if the previous release has no `public/` (from
   before the document root change), `current` goes back to it only if its
   login page answers (the web server still serves `current/`, the new
   playbook was not run); otherwise the new release stays current. Either
   way the script prints the recovery steps and exits with 1.
6. The newest 5 releases are kept, always including the current and the
   previous one.

Deploying the current release again only runs the smoke test (exit 1 if it
fails). Deploying a release that is
still in `releases/` switches back to it without downloading (a manual
rollback); a release without `public/` (from before the document root
change) is refused, see [UPGRADING.md](../UPGRADING.md), "Document root
`public/`". Nothing is built on the host: the archive contains `vendor/`.

## Membership system

pgacc fetches the member list from the membership system over verified HTTPS
(the fixed URL in `getusers.php`), and the membership system calls pgacc's
`mbapi.php`. So the host must resolve the membership system's public name and
reach it over HTTPS with a certificate valid for that name (Debian's CA
bundle), and the membership system must resolve pgacc's public name and reach
it.

If the internal DNS does not resolve the public name and the public address
is not reachable from inside (no hairpin NAT), point the name at the reverse
proxy in `/etc/hosts` with `pgacc_extra_hosts` and allow outgoing HTTPS from
the host to the proxy:

```yaml
pgacc_extra_hosts:
  - { ip: 192.0.2.1, names: [members.example.org] }
```

### Internal sync listener (optional)

For the next membership sync, the membership system fetches the payments
from `internal/mbapi.php` directly over the internal network, with TLS and a
token, not through the reverse proxy (format and answers: [UPGRADING.md](../UPGRADING.md),
"Internal sync endpoint"). Off by default. To turn it on:

```yaml
pgacc_sync_listen_address: 192.0.2.10   # this host's internal address
pgacc_sync_peers: [192.0.2.20]          # the membership system
```

After the playbook run, give the membership system:

- the URL `https://192.0.2.10:8443/mbapi.php` (the internal address: the
  certificate is made for it, and the public name resolves to the proxy);
- the certificate `/etc/pgacc/sync-tls.crt` as its only trust anchor
  (`cafile`); it is public, the key stays on this host;
- the token from `/srv/pgacc/shared/sync.php` (copy it without printing it
  into a log).

Allow TCP to the port from the membership system's address only, in the
firewall of both hosts. A new token: delete `shared/sync.php`, run the
playbook again and give the new token to the membership system. A new
address: delete `/etc/pgacc/sync-tls.crt` and `/etc/pgacc/sync-tls.key` as
well, so that the certificate is made again for it. The listener has no
access log; errors go to `/var/log/nginx/pgacc-sync.error.log` and PHP's to
the pgacc error log.

## Staging

A staging host must not call the live membership system and must use
synthetic data only.

## Fixed in the code

The database name (`pg_connect("dbname=accounting")` in `index.php`,
`mbapi.php` and `src/sync-api.php`) and the membership URL (`getusers.php`) are fixed in the code,
so they are not settings here.
