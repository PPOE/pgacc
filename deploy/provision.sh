#!/bin/sh
# Turns a Debian 13 (trixie) host into a pgacc host: PHP 8.5-FPM
# (packages.sury.org), PostgreSQL 18 (apt.postgresql.org), nginx serving the
# release under <root>/current over plain HTTP (TLS ends at a reverse proxy
# that sends X-Forwarded-Proto), a php-fpm pool running as a dedicated user,
# the `accounting` database, the /srv/pgacc layout and a nightly pg_dump.
# See deploy/README.md.
#
#   sudo sh deploy/provision.sh [--config FILE]
#
# Run as root from a checkout of the repository (it reads the schema, the
# membership URL of getusers.php and, in staging mode, the membership stub
# from it). Safe to run again: it rewrites only the files it owns (apt
# sources, nginx vhost, php-fpm pool, systemd units, /etc/pgacc/pgacc.env)
# and never overwrites an existing constants.php, files/ or database.
set -eu

usage() {
  echo "Usage: sh deploy/provision.sh [--config FILE]" >&2
  exit 2
}

say() { printf '%s\n' "provision: $*"; }
warn() { printf '%s\n' "provision: WARNING: $*" >&2; }
die() {
  printf '%s\n' "provision: $*" >&2
  exit 1
}

config=
while [ $# -gt 0 ]; do
  case "$1" in
    --config)
      [ $# -ge 2 ] || usage
      config=$2
      shift 2
      ;;
    --config=*)
      config=${1#--config=}
      shift
      ;;
    *) usage ;;
  esac
done

[ "$(id -u)" -eq 0 ] || die "run as root"

repo=$(cd "$(dirname "$0")/.." && pwd -P)
schema=$repo/tests/fixtures/schema.sql
pgcrypto_sql=$repo/tests/fixtures/pgcrypto.sql
[ -f "$schema" ] && [ -f "$pgcrypto_sql" ] && [ -f "$repo/getusers.php" ] ||
  die "run from a checkout of the pgacc repository (missing tests/fixtures/schema.sql or getusers.php)"

# Settings: KEY=VALUE lines of the config file (only the keys below; no shell
# code is run). Variables set in the environment win over the file, the file
# wins over the defaults.
load_config() {
  file=$1
  [ -f "$file" ] || die "config file not found: $file"
  while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in '' | '#'*) continue ;; esac
    key=${line%%=*}
    value=${line#*=}
    # Optional surrounding quotes.
    case "$value" in
      \"*\")
        value=${value#\"}
        value=${value%\"}
        ;;
      \'*\')
        value=${value#\'}
        value=${value%\'}
        ;;
    esac
    case "$key" in
      PGACC_HOSTNAME | PGACC_MODE | PGACC_ROOT | PGACC_HTTP_PORT | PGACC_PROXY_ADDRESSES | \
        PGACC_TIMEZONE | PGACC_UPLOAD_MAX | PGACC_MEMORY_LIMIT | PGACC_DB_LOCALE | \
        PGACC_BACKUP_DIR | PGACC_BACKUP_KEEP_DAYS | PGACC_GITHUB_REPO | \
        PGACC_ALLOW_COMMIT_DEPLOY | PGACC_KEEP_RELEASES | PGACC_RELEASE_BASE_URL | \
        PGACC_GITHUB_API_URL)
        # The environment wins.
        if eval "[ -z \"\${$key+x}\" ]"; then
          eval "$key=\$value"
        fi
        ;;
      *) die "unknown setting in $file: $key" ;;
    esac
  done <"$file"
}
[ -z "$config" ] || load_config "$config"

PGACC_HOSTNAME=${PGACC_HOSTNAME:-}
PGACC_MODE=${PGACC_MODE:-production}
PGACC_ROOT=${PGACC_ROOT:-/srv/pgacc}
PGACC_HTTP_PORT=${PGACC_HTTP_PORT:-80}
PGACC_PROXY_ADDRESSES=${PGACC_PROXY_ADDRESSES:-}
PGACC_TIMEZONE=${PGACC_TIMEZONE:-UTC}
PGACC_UPLOAD_MAX=${PGACC_UPLOAD_MAX:-20M}
PGACC_MEMORY_LIMIT=${PGACC_MEMORY_LIMIT:-256M}
PGACC_DB_LOCALE=${PGACC_DB_LOCALE:-C.UTF-8}
PGACC_BACKUP_DIR=${PGACC_BACKUP_DIR:-/var/backups/pgacc}
PGACC_BACKUP_KEEP_DAYS=${PGACC_BACKUP_KEEP_DAYS:-14}
PGACC_GITHUB_REPO=${PGACC_GITHUB_REPO:-PPOE/pgacc}
PGACC_KEEP_RELEASES=${PGACC_KEEP_RELEASES:-5}
PGACC_RELEASE_BASE_URL=${PGACC_RELEASE_BASE_URL:-}
PGACC_GITHUB_API_URL=${PGACC_GITHUB_API_URL:-}
if [ "$PGACC_MODE" = staging ]; then
  PGACC_ALLOW_COMMIT_DEPLOY=${PGACC_ALLOW_COMMIT_DEPLOY:-1}
else
  PGACC_ALLOW_COMMIT_DEPLOY=${PGACC_ALLOW_COMMIT_DEPLOY:-0}
fi

# Fixed by the code, not settings: the database name (pg_connect("dbname=
# accounting") in index.php and mbapi.php, as the web server's user) and the
# membership URL (getusers.php).
db_name=accounting
app_user=pgacc
php_version=8.5
pg_version=18
fpm_service=php$php_version-fpm.service
fpm_socket=/run/php/pgacc-fpm.sock

# Validation: every value ends up in config files or paths.
matches() { printf '%s\n' "$1" | grep -Eq "$2"; }
[ -n "$PGACC_HOSTNAME" ] || die "PGACC_HOSTNAME is required (the host name the reverse proxy forwards)"
matches "$PGACC_HOSTNAME" '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$' || die "invalid PGACC_HOSTNAME"
case "$PGACC_MODE" in production | staging) ;; *) die "PGACC_MODE must be production or staging" ;; esac
matches "$PGACC_ROOT" '^/[A-Za-z0-9._/-]*[A-Za-z0-9]$' || die "invalid PGACC_ROOT"
matches "$PGACC_HTTP_PORT" '^[0-9]{1,5}$' || die "invalid PGACC_HTTP_PORT"
[ "$PGACC_HTTP_PORT" -ne 443 ] || die "PGACC_HTTP_PORT must not be 443 (plain HTTP only)"
[ -z "$PGACC_PROXY_ADDRESSES" ] || matches "$PGACC_PROXY_ADDRESSES" '^[0-9A-Fa-f.:/ ]+$' ||
  die "invalid PGACC_PROXY_ADDRESSES (addresses or CIDR ranges, separated by spaces)"
matches "$PGACC_TIMEZONE" '^[A-Za-z0-9_+/-]+$' || die "invalid PGACC_TIMEZONE"
[ -f "/usr/share/zoneinfo/$PGACC_TIMEZONE" ] || die "unknown PGACC_TIMEZONE: $PGACC_TIMEZONE"
matches "$PGACC_UPLOAD_MAX" '^[0-9]+[KMG]$' || die "invalid PGACC_UPLOAD_MAX (e.g. 20M)"
matches "$PGACC_MEMORY_LIMIT" '^[0-9]+[KMG]$' || die "invalid PGACC_MEMORY_LIMIT (e.g. 256M)"
matches "$PGACC_DB_LOCALE" '^[A-Za-z0-9_.@-]+$' || die "invalid PGACC_DB_LOCALE"
matches "$PGACC_BACKUP_DIR" '^/[A-Za-z0-9._/-]*[A-Za-z0-9]$' || die "invalid PGACC_BACKUP_DIR"
matches "$PGACC_BACKUP_KEEP_DAYS" '^[0-9]+$' || die "invalid PGACC_BACKUP_KEEP_DAYS"
matches "$PGACC_GITHUB_REPO" '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$' || die "invalid PGACC_GITHUB_REPO"
case "$PGACC_ALLOW_COMMIT_DEPLOY" in 0 | 1) ;; *) die "PGACC_ALLOW_COMMIT_DEPLOY must be 0 or 1" ;; esac
matches "$PGACC_KEEP_RELEASES" '^[1-9][0-9]*$' || die "invalid PGACC_KEEP_RELEASES"
[ "$PGACC_KEEP_RELEASES" -ge 2 ] || die "PGACC_KEEP_RELEASES must be at least 2 (the rollback target)"
for url in "$PGACC_RELEASE_BASE_URL" "$PGACC_GITHUB_API_URL"; do
  [ -z "$url" ] || matches "$url" '^https?://[A-Za-z0-9._:/-]+$' || die "invalid URL: $url"
done

# shellcheck source=/dev/null
. /etc/os-release
[ "${ID:-}" = debian ] && [ "${VERSION_CODENAME:-}" = trixie ] || die "Debian 13 (trixie) is required"

# Membership URL of the code; staging redirects its host to the local stub.
membership_url=$(sed -n 's#.*"\(https://[^"/]*/adm_api/adm_names\.php\)".*#\1#p' "$repo/getusers.php" | head -n 1)
[ -n "$membership_url" ] || die "no membership URL found in getusers.php"
membership_host=${membership_url#https://}
membership_host=${membership_host%%/*}

export DEBIAN_FRONTEND=noninteractive
changed_files=

# Writes stdin to $1 (mode $2, owner $3) if the content differs.
put_file() {
  target=$1 mode=$2 owner=$3
  tmp=$(mktemp "${target%/*}/.pgacc-provision.XXXXXX")
  cat >"$tmp"
  if [ -f "$target" ] && cmp -s "$tmp" "$target"; then
    rm -f "$tmp"
  else
    chmod "$mode" "$tmp"
    chown "$owner" "$tmp"
    mv -f "$tmp" "$target"
    changed_files="$changed_files $target"
    say "wrote $target"
  fi
  chmod "$mode" "$target"
  chown "$owner" "$target"
}
changed() { case " $changed_files " in *" $1 "*) return 0 ;; *) return 1 ;; esac }

# --- Packages -----------------------------------------------------------

say "installing base packages"
apt-get update -qq
apt-get install -y -qq --no-install-recommends ca-certificates curl gpg openssl sudo tar gzip logrotate >/dev/null

# Downloads a signing key to $2 (binary keyring) and checks that its primary
# key has the fingerprint $3.
install_key() {
  url=$1 target=$2 fingerprint=$3
  install -d -m 0755 /etc/apt/keyrings
  tmp=$(mktemp -d)
  curl -fsSL --proto '=https' -o "$tmp/key" "$url"
  gpg --batch --yes --dearmor -o "$tmp/key.gpg" "$tmp/key" 2>/dev/null || cp "$tmp/key" "$tmp/key.gpg"
  found=$(gpg --batch --show-keys --with-colons "$tmp/key.gpg" 2>/dev/null | awk -F: '$1 == "fpr" { print $10; exit }')
  if [ "$found" != "$fingerprint" ]; then
    rm -rf "$tmp"
    die "the key from $url has fingerprint '$found', expected $fingerprint"
  fi
  put_file "$target" 0644 root:root <"$tmp/key.gpg"
  rm -rf "$tmp"
}

# Fingerprints as published by the repositories (sury: DEB.SURY.ORG
# Automatic Signing Key; PGDG: PostgreSQL Debian Repository).
install_key https://packages.sury.org/php/apt.gpg /etc/apt/keyrings/sury-php.gpg \
  15058500A0235D97F5D10063B188E2B695BD4743
install_key https://www.postgresql.org/media/keys/ACCC4CF8.asc /etc/apt/keyrings/pgdg.gpg \
  B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8

put_file /etc/apt/sources.list.d/sury-php.sources 0644 root:root <<EOF
Types: deb
URIs: https://packages.sury.org/php/
Suites: $VERSION_CODENAME
Components: main
Signed-By: /etc/apt/keyrings/sury-php.gpg
EOF
put_file /etc/apt/sources.list.d/pgdg.sources 0644 root:root <<EOF
Types: deb
URIs: https://apt.postgresql.org/pub/repos/apt/
Suites: $VERSION_CODENAME-pgdg
Components: main
Signed-By: /etc/apt/keyrings/pgdg.gpg
EOF

say "installing nginx, PHP $php_version and PostgreSQL $pg_version"
apt-get update -qq
# Extensions pgacc needs: pgsql; mbstring (mb_strlen, mb_detect_encoding);
# iconv, zlib (smalot/pdfparser), sodium (ext-sodium in composer.json) and
# openssl ship in php8.5-common or the PHP binary.
apt-get install -y -qq --no-install-recommends \
  nginx "php$php_version-fpm" "php$php_version-cli" "php$php_version-pgsql" "php$php_version-mbstring" \
  "postgresql-$pg_version" "postgresql-client-$pg_version" >/dev/null
modules=$("php$php_version" -m)
for module in pgsql mbstring iconv zlib sodium openssl; do
  printf '%s\n' "$modules" | grep -qx "$module" || die "PHP extension missing: $module"
done

# --- User and layout -------------------------------------------------------

if ! id -u "$app_user" >/dev/null 2>&1; then
  adduser --system --group --home "$PGACC_ROOT" --no-create-home --shell /usr/sbin/nologin "$app_user" >/dev/null
  say "created system user $app_user"
fi

# <root> and releases/ belong to the deploy user, group www-data (setgid), so
# nginx can read the code; shared/ (constants.php, files/, logs/) is private
# to the user.
install -d -m 2750 -o "$app_user" -g www-data "$PGACC_ROOT" "$PGACC_ROOT/releases"
install -d -m 0750 -o "$app_user" -g "$app_user" "$PGACC_ROOT/shared" "$PGACC_ROOT/shared/logs"
if [ -e "$PGACC_ROOT/shared/files" ] || [ -L "$PGACC_ROOT/shared/files" ]; then
  say "kept the existing $PGACC_ROOT/shared/files"
  [ "$(stat -c %U "$PGACC_ROOT/shared/files")" = "$app_user" ] ||
    warn "$PGACC_ROOT/shared/files is not owned by $app_user; receipt uploads need write access"
else
  install -d -m 0750 -o "$app_user" -g "$app_user" "$PGACC_ROOT/shared/files"
  say "created $PGACC_ROOT/shared/files"
fi
install -d -m 0750 -o "$app_user" -g "$app_user" "$PGACC_BACKUP_DIR"

# --- constants.php ---------------------------------------------------------

constants=$PGACC_ROOT/shared/constants.php
if [ -e "$constants" ] || [ -L "$constants" ]; then
  say "kept the existing $constants"
else
  key=$(openssl rand -hex 32)
  if [ "$PGACC_MODE" = staging ]; then
    # The synthetic key of the membership stub's member list.
    datakey=$(sed -n "s/^\$datakey = '\\([A-Za-z0-9._-]*\\)';\$/\\1/p" "$repo/tests/runtime/test-constants.php")
    [ -n "$datakey" ] || die "no \$datakey in tests/runtime/test-constants.php"
    datakey_note="the synthetic key of the membership stub (staging)"
  else
    datakey=$(openssl rand -hex 32)
    datakey_note="RANDOM PLACEHOLDER: replace it with the key shared with the membership system"
  fi
  tmp=$(mktemp "$PGACC_ROOT/shared/.constants.XXXXXX")
  cat >"$tmp" <<EOF
<?php
// Generated by deploy/provision.sh. Back this file up: without \$key the
// stored receipts cannot be decrypted.
// \$datakey: $datakey_note.
\$datakey = '$datakey';
\$key = '$key';
EOF
  chmod 0600 "$tmp"
  chown "$app_user:$app_user" "$tmp"
  mv "$tmp" "$constants"
  cat >&2 <<EOF

************************************************************************
provision: GENERATED A NEW $constants
  with a fresh random receipt key (\$key).
  BACK IT UP NOW to a safe place separate from this host: receipts
  uploaded from now on cannot be decrypted without this \$key.
  If you are moving an existing installation, stop here, replace this
  file with the existing constants.php (keep its \$key) before the
  first deploy, and restore files/ and the database.
  \$datakey: $datakey_note.
************************************************************************

EOF
fi

# --- PHP-FPM ------------------------------------------------------------------

put_file "/etc/php/$php_version/fpm/conf.d/90-pgacc.ini" 0644 root:root <<EOF
; pgacc production settings (deploy/provision.sh).
display_errors = Off
display_startup_errors = Off
log_errors = On
expose_php = Off
allow_url_include = Off
; getusers.php fetches the member list with file_get_contents() over HTTPS.
allow_url_fopen = On
cgi.fix_pathinfo = 0
date.timezone = $PGACC_TIMEZONE
memory_limit = $PGACC_MEMORY_LIMIT
upload_max_filesize = $PGACC_UPLOAD_MAX
post_max_size = $PGACC_UPLOAD_MAX
opcache.enable = 1
EOF
put_file "/etc/php/$php_version/cli/conf.d/90-pgacc.ini" 0644 root:root <<EOF
; pgacc command-line tools (migrate-receipt-key.php) (deploy/provision.sh).
date.timezone = $PGACC_TIMEZONE
EOF
put_file "/etc/php/$php_version/fpm/pool.d/pgacc.conf" 0644 root:root <<EOF
; pgacc pool (deploy/provision.sh).
[pgacc]
user = $app_user
group = $app_user
listen = $fpm_socket
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 60s
pm.max_requests = 500
security.limit_extensions = .php
php_admin_value[error_log] = $PGACC_ROOT/shared/logs/php-error.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
EOF
systemctl enable --quiet "$fpm_service"
systemctl restart "$fpm_service"

# deploy.sh (as $app_user) reloads php-fpm after switching the release.
put_file /etc/sudoers.d/pgacc-deploy 0440 root:root <<EOF
# pgacc-deploy switches releases as $app_user and reloads php-fpm.
$app_user ALL=(root) NOPASSWD: /usr/bin/systemctl reload $fpm_service
EOF
visudo -cqf /etc/sudoers.d/pgacc-deploy || die "invalid /etc/sudoers.d/pgacc-deploy"

put_file /etc/logrotate.d/pgacc 0644 root:root <<EOF
$PGACC_ROOT/shared/logs/*.log {
	su $app_user $app_user
	weekly
	rotate 12
	compress
	delaycompress
	missingok
	notifempty
	create 0640 $app_user $app_user
}
EOF

# --- deploy.sh and its settings ---------------------------------------------

install -m 0755 -o root -g root "$repo/deploy/deploy.sh" /usr/local/bin/pgacc-deploy
install -d -m 0755 /etc/pgacc
put_file /etc/pgacc/pgacc.env 0644 root:root <<EOF
# Written by deploy/provision.sh; read by pgacc-deploy (deploy/deploy.sh).
# Change the provision config and run provision again instead of editing.
PGACC_ROOT=$PGACC_ROOT
PGACC_USER=$app_user
PGACC_HOSTNAME=$PGACC_HOSTNAME
PGACC_HTTP_PORT=$PGACC_HTTP_PORT
PGACC_PHP_FPM_SERVICE=$fpm_service
PGACC_GITHUB_REPO=$PGACC_GITHUB_REPO
PGACC_ALLOW_COMMIT_DEPLOY=$PGACC_ALLOW_COMMIT_DEPLOY
PGACC_KEEP_RELEASES=$PGACC_KEEP_RELEASES
PGACC_RELEASE_BASE_URL=$PGACC_RELEASE_BASE_URL
PGACC_GITHUB_API_URL=$PGACC_GITHUB_API_URL
EOF

# --- PostgreSQL ----------------------------------------------------------------

systemctl enable --quiet postgresql.service
systemctl start postgresql.service
pg_cluster_ready() { runuser -u postgres -- pg_isready -q >/dev/null 2>&1; }
i=0
until pg_cluster_ready; do
  i=$((i + 1))
  [ "$i" -lt 30 ] || die "PostgreSQL does not answer"
  sleep 1
done
psql_admin() { runuser -u postgres -- psql -X -q -v ON_ERROR_STOP=1 "$@"; }
server_version=$(psql_admin -tAc 'SHOW server_version_num')
[ "${server_version%????}" = "$pg_version" ] ||
  die "the PostgreSQL server on the default port is not version $pg_version ($server_version)"

if [ "$(psql_admin -tAc "SELECT 1 FROM pg_roles WHERE rolname = '$app_user'")" != 1 ]; then
  # No password: the application connects over the local socket with peer
  # authentication as the operating-system user $app_user.
  psql_admin -c "CREATE ROLE $app_user LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE"
  say "created database role $app_user"
fi

if [ "$(psql_admin -tAc "SELECT 1 FROM pg_database WHERE datname = '$db_name'")" = 1 ]; then
  say "kept the existing database $db_name (schema not loaded)"
else
  psql_admin -c "CREATE DATABASE $db_name OWNER $app_user ENCODING 'UTF8' LOCALE '$PGACC_DB_LOCALE' TEMPLATE template0"
  # The schema of tests/fixtures/ (as the README setup), owned by $app_user;
  # pgcrypto first (passwords use crypt()). Drop the new database again if
  # anything fails, so that a rerun starts clean.
  if psql_admin -d "$db_name" -c "REVOKE CONNECT, TEMPORARY ON DATABASE $db_name FROM PUBLIC" \
    -c "GRANT CONNECT, TEMPORARY ON DATABASE $db_name TO $app_user" -f - <"$pgcrypto_sql" &&
    runuser -u "$app_user" -- psql -X -q -v ON_ERROR_STOP=1 --single-transaction -d "$db_name" -f - <"$schema"; then
    say "created the database $db_name with the schema of tests/fixtures/schema.sql"
  else
    psql_admin -c "DROP DATABASE $db_name"
    die "loading the schema failed; dropped the new database $db_name"
  fi
fi

# --- Nightly database dump ------------------------------------------------------

put_file /usr/local/lib/pgacc-backup 0755 root:root <<EOF
#!/bin/sh
# Nightly dump of the $db_name database (deploy/provision.sh), as $app_user.
set -eu
umask 077
dir=$PGACC_BACKUP_DIR
stamp=\$(date -u +%Y%m%dT%H%M%SZ)
pg_dump --format=custom --file="\$dir/.$db_name-\$stamp.dump.partial" $db_name
mv "\$dir/.$db_name-\$stamp.dump.partial" "\$dir/$db_name-\$stamp.dump"
find "\$dir" -maxdepth 1 -type f -name '$db_name-*.dump' -mtime +$PGACC_BACKUP_KEEP_DAYS -delete
EOF
put_file /etc/systemd/system/pgacc-backup.service 0644 root:root <<EOF
[Unit]
Description=pgacc database dump
After=postgresql.service

[Service]
Type=oneshot
User=$app_user
Group=$app_user
ExecStart=/usr/local/lib/pgacc-backup
EOF
put_file /etc/systemd/system/pgacc-backup.timer 0644 root:root <<EOF
[Unit]
Description=Nightly pgacc database dump

[Timer]
OnCalendar=*-*-* 02:30:00
RandomizedDelaySec=15m
Persistent=true

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --quiet --now pgacc-backup.timer

# --- Staging: local membership stub -----------------------------------------------

stub_server=
if [ "$PGACC_MODE" = staging ]; then
  # The member list of the membership stub (tests/reference/membership/),
  # served by nginx on 127.0.0.1:443 under the host name of the code's
  # membership URL, which /etc/hosts points to 127.0.0.1, with a certificate
  # of a local CA that the system trusts. The list is encrypted with the
  # synthetic $datakey of tests/runtime/test-constants.php.
  stub_dir=$PGACC_ROOT/staging-membership
  tls=/etc/pgacc/staging-tls
  install -d -m 0755 "$stub_dir"
  put_file "$stub_dir/adm_names.b64" 0644 root:root <"$repo/tests/reference/membership/adm_names.b64"
  install -d -m 0700 "$tls"
  if [ ! -f "$tls/server.pem" ]; then
    openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 -subj '/CN=pgacc staging membership CA' \
      -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
      -keyout "$tls/ca.key" -out "$tls/ca.pem" 2>/dev/null
    openssl req -newkey rsa:2048 -nodes -sha256 -subj "/CN=$membership_host" \
      -keyout "$tls/server.key" -out "$tls/server.csr" 2>/dev/null
    printf 'basicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:%s\n' \
      "$membership_host" >"$tls/server.ext"
    openssl x509 -req -sha256 -days 825 -in "$tls/server.csr" -CA "$tls/ca.pem" -CAkey "$tls/ca.key" \
      -set_serial 1 -extfile "$tls/server.ext" -out "$tls/server.pem" 2>/dev/null
    say "created the staging membership certificate for $membership_host"
  fi
  put_file /usr/local/share/ca-certificates/pgacc-staging-membership-ca.crt 0644 root:root <"$tls/ca.pem"
  if changed /usr/local/share/ca-certificates/pgacc-staging-membership-ca.crt; then
    update-ca-certificates >/dev/null 2>&1
  fi
  if ! grep -Eq "^127\.0\.0\.1[[:space:]]+$membership_host([[:space:]]|\$)" /etc/hosts; then
    printf '127.0.0.1 %s # pgacc staging membership stub\n' "$membership_host" >>/etc/hosts
    say "pointed $membership_host to 127.0.0.1 in /etc/hosts (staging)"
  fi
  stub_server=$(
    cat <<EOF

# Staging only: the membership stub's member list for getusers.php.
server {
    listen 127.0.0.1:443 ssl;
    server_name $membership_host;
    ssl_certificate $tls/server.pem;
    ssl_certificate_key $tls/server.key;
    access_log /var/log/nginx/pgacc-staging-membership.access.log;
    location = /adm_api/adm_names.php {
        default_type text/plain;
        alias $stub_dir/adm_names.b64;
    }
    location / {
        return 404;
    }
}
EOF
  )
fi

# --- nginx ---------------------------------------------------------------------

proxy_rules=
if [ -n "$PGACC_PROXY_ADDRESSES" ]; then
  # Only the reverse proxy (and local smoke tests) may connect.
  proxy_rules="    allow 127.0.0.1;
    allow ::1;"
  for address in $PGACC_PROXY_ADDRESSES; do
    proxy_rules="$proxy_rules
    allow $address;"
  done
  proxy_rules="$proxy_rules
    deny all;"
else
  warn "PGACC_PROXY_ADDRESSES is empty: any client that reaches port $PGACC_HTTP_PORT can send its own X-Forwarded-Proto; restrict access with a firewall or set it"
fi

listen_ipv6=
if [ -f /proc/net/if_inet6 ]; then
  listen_ipv6="    listen [::]:$PGACC_HTTP_PORT default_server;"
fi

put_file /etc/nginx/sites-available/pgacc 0644 root:root <<EOF
# pgacc (deploy/provision.sh). Plain HTTP behind a TLS-terminating reverse
# proxy, which must send X-Forwarded-Proto: https (index.php redirects
# otherwise). Only the release under $PGACC_ROOT/current is served.
server {
    listen $PGACC_HTTP_PORT default_server;
$listen_ipv6
    server_name $PGACC_HOSTNAME;
$proxy_rules
    root $PGACC_ROOT/current;
    index index.php;
    autoindex off;
    server_tokens off;
    client_max_body_size $PGACC_UPLOAD_MAX;
    access_log /var/log/nginx/pgacc.access.log;
    error_log /var/log/nginx/pgacc.error.log;

    # Not web content (UPGRADING.md): receipts, libraries, tests, command-line
    # tools, configuration, Composer files, dot files (.htaccess, the release
    # record).
    location ~ ^/(files|vendor|tests|src|deploy)(/|\$) {
        deny all;
    }
    location ~ ^/(constants\.php|migrate-receipt-key\.php|check\.php|composer\.(json|lock))\$ {
        deny all;
    }
    location ~ (^|/)\. {
        deny all;
    }

    location ~ \.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        # The resolved release directory, so a switch of the current symlink
        # never mixes files of two releases.
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_pass unix:$fpm_socket;
    }

    location / {
        try_files \$uri \$uri/ =404;
    }
}
$stub_server
EOF
ln -sfn ../sites-available/pgacc /etc/nginx/sites-enabled/pgacc
if [ -L /etc/nginx/sites-enabled/default ]; then
  rm -f /etc/nginx/sites-enabled/default
  say "disabled the default nginx site"
fi
nginx -t -q || die "nginx configuration test failed"
systemctl enable --quiet nginx.service
systemctl reload-or-restart nginx.service

say "done (mode: $PGACC_MODE, root: $PGACC_ROOT, commit deploys: $([ "$PGACC_ALLOW_COMMIT_DEPLOY" = 1 ] && echo allowed || echo refused))"
if [ ! -L "$PGACC_ROOT/current" ]; then
  say "no release deployed yet: run 'sudo -u $app_user pgacc-deploy v<version>'"
fi
