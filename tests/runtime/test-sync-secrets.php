<?php

// Synthetic sync v2 configuration (sync-secrets.php) of the test servers and
// of the development loop: the image's and the development loop's
// sync-secrets.php. Never a real token.
// payments_*: internal/mbapi.php (tests/http-sync.php) allows the peer
// 127.0.0.1 with payments_token.
// members_*: the member list of getusers.php, fetched from the membership
// stub of compose.test.yaml (tests/reference/membership/server.php, which
// reads members_token from here); members_cafile is the test CA that signed
// the stub's certificate (tests/php-runtime.Dockerfile).
return [
    'payments_token' => 'synthetic-test-only-payments-token',
    'payments_peers' => ['127.0.0.1'],
    'members_url' => 'https://mitglieder.piratenpartei.at/members.php',
    'members_token' => 'synthetic-test-only-members-token',
    'members_cafile' => '/usr/local/share/ca-certificates/pgacc-synthetic-test-ca.crt',
];
