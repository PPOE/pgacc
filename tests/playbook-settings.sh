#!/bin/sh
# Runs the settings check of deploy/ansible/playbook.yml (its first task,
# "Check the settings", with the play's vars) against localhost, with the
# facts it reads given as extra vars, in a Python container with
# ansible-core:
#
#   sh tests/playbook-settings.sh
#
# Cases: the listener off, pgacc_sync_listen_address an address of the host
# (ansible_all_ipv4_addresses) pass; an address the host does not have fails.
# The play must gather facts (it reads them).
set -eu

python_image=python:3.13-slim@sha256:2d44738ebf4ce5f63b9d9ea871e9c7cfdc0426f7b0c97cac915fd88ded1d86bb
ansible_core_version=2.18.19

repo=$(cd "$(dirname "$0")/.." && pwd -P)
docker run --rm -e HOME=/tmp -v "$repo/deploy/ansible:/ansible:ro" "$python_image" sh -c "
pip install --quiet --disable-pip-version-check --no-warn-script-location --root-user-action=ignore --user ansible-core==$ansible_core_version && python -c '
import json, subprocess, sys, yaml
play = yaml.safe_load(open(\"/ansible/playbook.yml\"))[0]
task = play[\"tasks\"][0]
assert task[\"name\"] == \"Check the settings\", task[\"name\"]
# The check reads facts: the play must gather them.
assert play.get(\"gather_facts\", True) is True, \"the play does not gather facts\"
check = [{\"hosts\": \"localhost\", \"connection\": \"local\", \"gather_facts\": False,
    \"vars\": play[\"vars\"], \"tasks\": [task]}]
yaml.safe_dump(check, open(\"/tmp/check.yml\", \"w\"))
base = {\"ansible_distribution\": \"Debian\", \"ansible_distribution_release\": \"trixie\",
    \"ansible_all_ipv4_addresses\": [\"192.0.2.10\", \"127.0.0.1\"], \"pgacc_trusted_proxies\": [\"192.0.2.1\"]}
listener = {\"pgacc_sync_peers\": [\"192.0.2.20\"], \"pgacc_payments_token\": 64 * \"a\"}
cases = [
    (\"listener off\", {}, True),
    (\"address of the host\", dict(listener, pgacc_sync_listen_address=\"192.0.2.10\"), True),
    (\"address not on the host\", dict(listener, pgacc_sync_listen_address=\"192.0.2.11\"), False),
]
failed = 0
for name, extra, passes in cases:
    json.dump(dict(base, **extra), open(\"/tmp/vars.json\", \"w\"))
    result = subprocess.run([\"/tmp/.local/bin/ansible-playbook\", \"-i\", \"localhost,\", \"/tmp/check.yml\",
        \"-e\", \"@/tmp/vars.json\"], capture_output=True, text=True)
    if (result.returncode == 0) != passes:
        failed += 1
        print(\"FAIL: %s: expected the check to %s\" % (name, \"pass\" if passes else \"fail\"))
        print(result.stdout[-2000:])
    else:
        print(\"ok: %s\" % name)
sys.exit(1 if failed else 0)
'"
