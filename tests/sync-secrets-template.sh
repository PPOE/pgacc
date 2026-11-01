#!/bin/sh
# Renders deploy/ansible/templates/sync-secrets.php.j2 with Ansible's
# template module (the playbook's "Write sync-secrets.php from the
# inventory" task, same ansible-core as tests/playbook-settings.sh) and
# loads each result with the real PHP readers of this checkout:
#
#   sh tests/sync-secrets-template.sh
#
# Cases: payments only (pgacc_sync_listen_address set), members only
# (pgacc_members_url set), both. Each file must return exactly the expected
# array, and each reader present here must accept it with those values or
# refuse it when its keys are absent: sync_api_config() (src/sync-api.php,
# internal/mbapi.php) and getusers_config() (getusers.php). A reader that is
# not in this checkout is skipped; at least one must be present. Synthetic
# values only. The temporary directory is removed at the end.
set -eu

python_image=python:3.13-slim@sha256:2d44738ebf4ce5f63b9d9ea871e9c7cfdc0426f7b0c97cac915fd88ded1d86bb
php_image=php:8.5-fpm@sha256:c34bcf437ed61bd5e4c1114494eaf1ffd54253bce2b95d263bfa18b350ee8432
ansible_core_version=2.18.19

repo=$(cd "$(dirname "$0")/.." && pwd -P)
# Below the checkout (build/ is ignored): a Docker daemon in a snap or a VM
# may not see the host's /tmp.
mkdir -p "$repo/build"
tmp=$(mktemp -d "$repo/build/sync-secrets.XXXXXX")
trap 'rm -rf "$tmp"' EXIT

cat >"$tmp/render.yml" <<'EOF'
- hosts: localhost
  connection: local
  gather_facts: false
  vars:
    ansible_python_interpreter: auto_silent
    payments: &payments
      pgacc_sync_listen_address: 192.0.2.10
      pgacc_sync_peers: [192.0.2.20, 192.0.2.21]
      pgacc_payments_token: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
    members: &members
      pgacc_members_url: https://192.0.2.20:8443/members.php
      pgacc_members_token: bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
  tasks:
    - name: Render sync-secrets.php, payments only
      ansible.builtin.template: {src: /ansible/templates/sync-secrets.php.j2, dest: /out/payments.php}
      vars: *payments
    - name: Render sync-secrets.php, members only
      ansible.builtin.template: {src: /ansible/templates/sync-secrets.php.j2, dest: /out/members.php}
      vars: *members
    - name: Render sync-secrets.php, both
      ansible.builtin.template: {src: /ansible/templates/sync-secrets.php.j2, dest: /out/both.php}
      vars: {<<: [*payments, *members]}
EOF

docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -e USER=ansible -v "$repo/deploy/ansible:/ansible:ro" \
  -v "$tmp:/out" "$python_image" sh -c "
pip install --quiet --disable-pip-version-check --no-warn-script-location --user ansible-core==$ansible_core_version &&
/tmp/.local/bin/ansible-playbook -i localhost, /out/render.yml >/tmp/ansible.log || { cat /tmp/ansible.log; exit 1; }"

docker run --rm -i --network none -v "$repo:/app:ro" -v "$tmp:/out:ro" -w /app "$php_image" php <<'EOF'
<?php
$payments = ['payments_token' => str_repeat('a', 64), 'payments_peers' => ['192.0.2.20', '192.0.2.21']];
$members = ['members_url' => 'https://192.0.2.20:8443/members.php', 'members_token' => str_repeat('b', 64),
    'members_cafile' => '/etc/pgacc/admidio-addons-tls.crt'];
$cases = ['payments' => $payments, 'members' => $members, 'both' => $payments + $members];
$readers = [];
if (is_file('src/sync-api.php')) {
    require 'src/sync-api.php';
    $readers['sync_api_config'] = array_keys($payments);
}
if (is_file('getusers.php') && strpos(file_get_contents('getusers.php'), 'function getusers_config(') !== false) {
    require 'getusers.php';
    $readers['getusers_config'] = array_keys($members);
}
if ($readers === []) {
    echo "FAIL: no reader of sync-secrets.php in this checkout\n";
    exit(1);
}
$failed = 0;
foreach ($cases as $name => $expected) {
    $file = "/out/$name.php";
    $check = function ($ok, $what) use ($name, $file, &$failed) {
        echo ($ok ? 'ok' : 'FAIL') . ": $name: $what\n";
        if (!$ok) {
            $failed++;
            echo file_get_contents($file);
        }
    };
    $check((include $file) === $expected, 'returns the expected array');
    foreach ($readers as $reader => $keys) {
        $want = array_intersect_key($expected, array_flip($keys));
        $config = $reader($file);
        if (count($want) === count($keys)) {
            $check($config !== null && array_intersect_key($config, $want) === $want, "$reader() accepts it");
        } else {
            $check($config === null, "$reader() refuses it (its keys are absent)");
        }
    }
}
exit($failed ? 1 : 0);
EOF
