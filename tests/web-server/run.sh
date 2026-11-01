#!/bin/sh
# Runs the request matrix of the document root public/ (tests/web-server/
# matrix.php) against real web servers:
#
#   sh tests/web-server/run.sh [nginx] [apache]     (default: both)
#
# nginx: deploy/ansible/templates/nginx-site.conf.j2, rendered with Jinja2 as
#   the playbook's template task does, unchanged, in the nginx version of
#   Debian 13, with PHP-FPM on the socket the template names.
# apache: public/.htaccess and the virtual host of UPGRADING.md (the code
#   block after "<!-- tested: apache vhost -->"), PHP as an Apache module,
#   Debian's "Alias /icons/" commented out (as UPGRADING.md says);
#   plus a virtual host whose document root is the release root (a
#   misconfigured host), where the release root's .htaccess must deny every
#   request.
#
# The release is built in a temporary directory in the layout of deploy.sh
# (/srv/pgacc/current -> releases/<tag>, code in the release root, public/),
# with the entry points replaced by a stub that prints which script ran with
# which query string, and with the planted files of the matrix. The servers
# run without network (--network none); the client runs in the same network
# namespace and connects to 127.0.0.1, the address the nginx site allows.
# After the matrix, public/index.php is replaced for one check by a symbolic
# link to a sentinel script in the release root (matrix 'entry_link'), then
# restored.
#
# nginx also gets the internal sync listener of the template (rendered with
# pgacc_sync_listen_address 127.0.0.1, port 8443) and its certificate, made
# with the playbook's openssl command. internal/mbapi.php is a stub that runs
# the real peer and token check of src/sync-api.php against the release's
# sync-secrets.php (rendered from the playbook's sync-secrets.php.j2, peer
# 127.0.0.1) and answers JSON instead of the payments; the
# client checks the certificate (cafile) and sends from 127.0.0.1 and, for
# another peer, from 127.0.0.2. Containers and the temporary directory are
# removed at the end.
set -eu

nginx_image=nginx:1.26.3@sha256:41b194461e4bae16f9b25d68b0976ed4735b89ca625c89aad88e1c1c3b7e8860
fpm_image=php:8.5-fpm@sha256:c34bcf437ed61bd5e4c1114494eaf1ffd54253bce2b95d263bfa18b350ee8432
apache_image=php:8.5-apache@sha256:4cc63b3d4e05c40da73e7af5d39364ca2678ac07208919497bda3bbab975be95
python_image=python:3.13-slim@sha256:2d44738ebf4ce5f63b9d9ea871e9c7cfdc0426f7b0c97cac915fd88ded1d86bb
jinja2_version=3.1.6

repo=$(cd "$(dirname "$0")/../.." && pwd -P)
servers=${*:-nginx apache}
for server in $servers; do
  case "$server" in nginx | apache) ;; *) echo "unknown server: $server (nginx, apache)" >&2 && exit 2 ;; esac
done

id=pgacc-web-server-${PGACC_TEST_ID:-$(id -un)}
# Below the checkout (build/ is ignored): a Docker daemon in a snap or a VM
# may not see the host's /tmp.
mkdir -p "$repo/build"
tmp=$(mktemp -d "$repo/build/web-server.XXXXXX")
cleanup() {
  docker rm -f "$id-nginx" "$id-fpm" "$id-apache" >/dev/null 2>&1 || true
  rm -rf "$tmp"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
chmod 755 "$tmp"
user="$(id -u):$(id -g)"
secret=pgacc-planted-secret-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')

# The release: public/ copied, the code paths of the matrix, a synthetic
# constants.php, the planted files; current -> releases/v0.0.0-test.
tag=v0.0.0-test
release=$tmp/pgacc/releases/$tag
mkdir -p "$release/src" "$release/vendor" "$release/tests/runtime" "$release/files"
cp -R "$repo/public" "$release/public"
cp -R "$repo/internal" "$release/internal"
cp "$repo/.htaccess" "$release/.htaccess"
for file in functions.php file.php edit.php getusers.php impressum.php check.php migrate-receipt-key.php \
  composer.json composer.lock README.md src/db.php src/sync-api.php tests/run.sh tests/runtime/test-constants.php; do
  cp "$repo/$file" "$release/$file"
done
cp "$repo/tests/runtime/test-constants.php" "$release/constants.php"
# 64 hex characters, as the playbook requires of pgacc_payments_token. For
# nginx, sync-secrets.php is replaced by the playbook's template below.
sync_token=$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')
printf "<?php\nreturn ['payments_token' => '%s', 'payments_peers' => ['127.0.0.1']];\n" "$sync_token" >"$release/sync-secrets.php"
echo '<?php // vendor' >"$release/vendor/autoload.php"
echo "$secret" >"$release/files/1.v2.aes"
ln -s "releases/$tag" "$tmp/pgacc/current"
docker run --rm --network none --user "$user" -v "$tmp/pgacc:/srv/pgacc" -v "$repo/tests/web-server:/tests:ro" \
  "$fpm_image" php /tests/check.php plant "/srv/pgacc/releases/$tag" "$secret"
chmod -R a+rX "$tmp/pgacc"

check() {
  docker exec "$1" php /tests/check.php "$2" http://127.0.0.1:80 /srv/pgacc/current/public "$3" "$secret"
}
# entry_link <container> <server>: an entry point that is a symbolic link out of public/.
entry_link() {
  mv "$release/public/index.php" "$tmp/index.php"
  ln -s ../entry-sentinel.php "$release/public/index.php"
  link_status=0
  docker exec "$1" php /tests/check.php entry-link "$2" http://127.0.0.1:80 || link_status=$?
  rm "$release/public/index.php" && mv "$tmp/index.php" "$release/public/index.php"
  return "$link_status"
}

if echo " $servers " | grep -q ' nginx '; then
  # Rendered as ansible.builtin.template does (Jinja2, trim_blocks); no
  # trusted proxies, no IPv6 address.
  # sync-secrets.php.j2 likewise: listener on (the release's sync-secrets.php),
  # off, and with the member list as well.
  docker run --rm --user "$user" -e HOME=/tmp -e SYNC_TOKEN="$sync_token" -v "$repo/deploy/ansible/templates:/templates:ro" -v "$tmp:/out" \
    "$python_image" sh -c "pip install --quiet --disable-pip-version-check --user jinja2==$jinja2_version && python -c '
import jinja2
environment = jinja2.Environment(loader=jinja2.FileSystemLoader(\"/templates\"), trim_blocks=True,
    keep_trailing_newline=True, undefined=jinja2.StrictUndefined)
template = environment.get_template(\"nginx-site.conf.j2\")
open(\"/out/nginx-site.conf\", \"w\").write(template.render(ansible_facts={}, pgacc_trusted_proxies=[],
    pgacc_sync_listen_address=\"127.0.0.1\"))
open(\"/out/nginx-site-off.conf\", \"w\").write(template.render(ansible_facts={}, pgacc_trusted_proxies=[]))
import os
secrets = environment.get_template(\"sync-secrets.php.j2\")
listener = dict(pgacc_sync_listen_address=\"127.0.0.1\", pgacc_sync_peers=[\"127.0.0.1\"],
    pgacc_payments_token=os.environ[\"SYNC_TOKEN\"])
open(\"/out/sync-secrets.php\", \"w\").write(secrets.render(**listener))
open(\"/out/sync-secrets-off.php\", \"w\").write(secrets.render())
open(\"/out/sync-secrets-both.php\", \"w\").write(secrets.render(pgacc_sync_peers=[\"127.0.0.1\", \"192.0.2.20\"],
    pgacc_members_url=\"https://192.0.2.20:8443/members.php\", pgacc_members_token=64 * \"b\",
    **{k: v for k, v in listener.items() if k != \"pgacc_sync_peers\"}))
'"
  cp "$tmp/sync-secrets.php" "$release/sync-secrets.php"
  docker run --rm --network none --user "$user" -v "$tmp:/out:ro" -e SYNC_TOKEN="$sync_token" "$fpm_image" php -r '
    $want = ["payments_token" => getenv("SYNC_TOKEN"), "payments_peers" => ["127.0.0.1"]];
    $both = $want + ["members_url" => "https://192.0.2.20:8443/members.php", "members_token" => str_repeat("b", 64),
        "members_cafile" => "/etc/pgacc/admidio-addons-tls.crt"];
    $both["payments_peers"][] = "192.0.2.20";
    exit((include "/out/sync-secrets.php") === $want && (include "/out/sync-secrets-off.php") === []
        && (include "/out/sync-secrets-both.php") === $both ? 0 : 1);' ||
    { echo "sync-secrets.php.j2 does not render the expected settings" >&2 && exit 1; }
  # Without pgacc_sync_listen_address: no internal listener.
  if grep -q 'current/internal' "$tmp/nginx-site-off.conf" || ! grep -q 'listen 127.0.0.1:8443 ssl;' "$tmp/nginx-site.conf"; then
    echo "nginx: the internal listener must be rendered only with pgacc_sync_listen_address" >&2
    exit 1
  fi
  # The certificate as the playbook makes it (its openssl arguments).
  mkdir -p "$tmp/etc-pgacc"
  docker run --rm --network none --user "$user" -v "$tmp/etc-pgacc:/etc/pgacc" "$fpm_image" \
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 3650 -subj /CN=127.0.0.1 \
    -addext subjectAltName=IP:127.0.0.1 -keyout /etc/pgacc/sync-tls.key -out /etc/pgacc/sync-tls.crt 2>/dev/null
  if [ "$(stat -c %a "$tmp/etc-pgacc/sync-tls.key")" != 600 ]; then
    echo "openssl did not write the TLS key with mode 0600" >&2
    exit 1
  fi
  chmod 644 "$tmp/etc-pgacc/sync-tls.key" # readable for the nginx container's root (another user here)
  mkdir -p "$tmp/run-php"
  chmod 777 "$tmp/run-php"
  printf '%s\n' '[global]' 'daemonize = no' 'error_log = /proc/self/fd/2' '[www]' 'listen = /run/php/pgacc-fpm.sock' \
    'listen.mode = 0666' 'clear_env = no' >"$tmp/fpm.conf"
  docker run -d --name "$id-fpm" --network none -v "$tmp/pgacc:/srv/pgacc:ro" -v "$tmp/run-php:/run/php" \
    -v "$tmp/fpm.conf:/usr/local/etc/php-fpm.d/zz-docker.conf:ro" -v "$repo/tests/web-server:/tests:ro" \
    -v "$tmp/etc-pgacc:/etc/pgacc:ro" \
    "$fpm_image" >/dev/null
  docker run -d --name "$id-nginx" --network "container:$id-fpm" -v "$tmp/pgacc:/srv/pgacc:ro" \
    -v "$tmp/run-php:/run/php" -v "$tmp/nginx-site.conf:/etc/nginx/conf.d/default.conf:ro" \
    -v "$tmp/etc-pgacc:/etc/pgacc:ro" "$nginx_image" >/dev/null
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    [ -S "$tmp/run-php/pgacc-fpm.sock" ] && docker exec "$id-nginx" sh -c 'kill -0 $(cat /run/nginx.pid)' 2>/dev/null && break
    sleep 1
  done
  docker exec "$id-nginx" nginx -t -q
  # SCRIPT_FILENAME is the resolved release ($realpath_root).
  status=0
  check "$id-fpm" nginx "/srv/pgacc/releases/$tag/public" || status=$?
  [ "$status" -ne 0 ] || entry_link "$id-fpm" nginx || status=$?
  [ "$status" -ne 0 ] || docker exec "$id-fpm" php /tests/check.php internal https://127.0.0.1:8443 \
    /etc/pgacc/sync-tls.crt "$sync_token" "/srv/pgacc/releases/$tag/internal" "$secret" || status=$?
  [ "$status" -eq 0 ] || { docker logs --tail 20 "$id-nginx" >&2; exit "$status"; }
  docker rm -f "$id-nginx" "$id-fpm" >/dev/null
fi

if echo " $servers " | grep -q ' apache '; then
  awk '/^<!-- tested: apache vhost -->$/ { found = 1; next }
    found && /^```/ { if (inside) exit; inside = 1; next }
    inside { print }' "$repo/UPGRADING.md" >"$tmp/vhost.conf"
  [ -s "$tmp/vhost.conf" ] || { echo "no Apache virtual host in UPGRADING.md" >&2 && exit 1; }
  # The virtual host of UPGRADING.md on port 80; port 8080 serves the
  # release root (misconfigured), with the same directory options.
  {
    echo '<VirtualHost *:80>'
    cat "$tmp/vhost.conf"
    echo '</VirtualHost>'
    echo 'Listen 8080'
    echo '<VirtualHost *:8080>'
    sed -e 's#/srv/pgacc/current/public#/srv/pgacc/current#g' "$tmp/vhost.conf"
    echo '</VirtualHost>'
  } >"$tmp/apache-site.conf"
  docker run -d --name "$id-apache" --network none -v "$tmp/pgacc:/srv/pgacc:ro" \
    -v "$tmp/apache-site.conf:/etc/apache2/sites-enabled/000-default.conf:ro" -v "$repo/tests/web-server:/tests:ro" \
    "$apache_image" sh -c "a2enmod -q rewrite && sed -i 's|^Alias /icons/|#&|' /etc/apache2/mods-available/alias.conf \\
      && exec apache2-foreground" >/dev/null
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    docker exec "$id-apache" php -r 'exit(@fsockopen("127.0.0.1", 80) ? 0 : 1);' 2>/dev/null && break
    sleep 1
  done
  status=0
  check "$id-apache" apache /srv/pgacc/current/public || status=$?
  [ "$status" -ne 0 ] || entry_link "$id-apache" apache || status=$?
  [ "$status" -ne 0 ] ||
    docker exec "$id-apache" php /tests/check.php apache-release-root http://127.0.0.1:8080 /srv/pgacc/current \
      /srv/pgacc/current "$secret" || status=$?
  [ "$status" -eq 0 ] || { docker logs --tail 20 "$id-apache" >&2; exit "$status"; }
fi
echo "Web server allow lists: passed ($servers)"
