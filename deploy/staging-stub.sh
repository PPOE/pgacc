#!/bin/sh
# Staging only: serves the membership stub's synthetic member list
# (tests/reference/membership/) in place of the membership system, on a host
# set up by deploy/ansible. Never run it on a production host. See
# deploy/README.md.
#
#   sudo sh deploy/staging-stub.sh
#
# Points the host name of the membership URL in getusers.php to 127.0.0.1
# (/etc/hosts), where nginx serves adm_names.b64 over HTTPS with a certificate
# of a local CA that the system trusts, and sets $datakey in
# /srv/pgacc/shared/constants.php to the stub's synthetic key. Safe to run
# again.
set -eu

die() { echo "staging-stub: $*" >&2 && exit 1; }
[ "$(id -u)" -eq 0 ] || die "run as root"
repo=$(cd "$(dirname "$0")/.." && pwd -P)
constants=/srv/pgacc/shared/constants.php
[ -f "$constants" ] || die "no $constants; run the playbook first"

url=$(sed -n 's#.*"\(https://[^"/]*/adm_api/adm_names\.php\)".*#\1#p' "$repo/getusers.php" | head -n 1)
host=${url#https://} && host=${host%%/*}
[ -n "$host" ] || die "no membership URL found in getusers.php"
datakey=$(sed -n "s/^\$datakey = '\([A-Za-z0-9._-]*\)';\$/\1/p" "$repo/tests/runtime/test-constants.php")
[ -n "$datakey" ] || die "no \$datakey in tests/runtime/test-constants.php"

stub=/srv/pgacc-staging-stub
install -d -m 0755 "$stub"
install -d -m 0700 "$stub/tls"
install -m 0644 "$repo/tests/reference/membership/adm_names.b64" "$stub/adm_names.b64"
if [ ! -f "$stub/tls/server.pem" ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 -subj '/CN=pgacc staging membership CA' \
    -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
    -keyout "$stub/tls/ca.key" -out "$stub/tls/ca.pem" 2>/dev/null
  openssl req -new -newkey rsa:2048 -nodes -sha256 -subj "/CN=$host" \
    -keyout "$stub/tls/server.key" -out "$stub/tls/server.csr" 2>/dev/null
  printf 'basicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\nsubjectAltName=DNS:%s\n' "$host" >"$stub/tls/server.ext"
  openssl x509 -req -sha256 -days 825 -in "$stub/tls/server.csr" -CA "$stub/tls/ca.pem" \
    -CAkey "$stub/tls/ca.key" -set_serial 1 -extfile "$stub/tls/server.ext" -out "$stub/tls/server.pem" 2>/dev/null
  install -m 0644 "$stub/tls/ca.pem" /usr/local/share/ca-certificates/pgacc-staging-stub-ca.crt
  update-ca-certificates >/dev/null 2>&1
fi
grep -Eq "^127\.0\.0\.1[[:space:]]+$host([[:space:]]|\$)" /etc/hosts ||
  printf '127.0.0.1 %s # pgacc staging stub\n' "$host" >>/etc/hosts

cat >/etc/nginx/sites-available/pgacc-staging-stub <<EOF
# pgacc staging membership stub (deploy/staging-stub.sh).
server {
    listen 127.0.0.1:443 ssl;
    server_name $host;
    ssl_certificate $stub/tls/server.pem;
    ssl_certificate_key $stub/tls/server.key;
    location = /adm_api/adm_names.php {
        default_type text/plain;
        alias $stub/adm_names.b64;
    }
    location / {
        return 404;
    }
}
EOF
ln -sfn ../sites-available/pgacc-staging-stub /etc/nginx/sites-enabled/pgacc-staging-stub
nginx -t -q && systemctl reload nginx.service

sed -i "s/^\$datakey = '[^']*';\$/\$datakey = '$datakey';/" "$constants"
grep -qF "\$datakey = '$datakey';" "$constants" || die "could not set \$datakey in $constants"
echo "staging-stub: $host now resolves to the local stub; \$datakey is the stub's synthetic key"
