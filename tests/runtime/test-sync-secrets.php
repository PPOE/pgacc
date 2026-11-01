<?php

// Synthetic sync configuration (sync-secrets.php) of the test servers and of
// the development loop. Never a real token. members_*: the member list of
// getusers.php, fetched from the membership stub of compose.test.yaml
// (tests/reference/membership/server.php, which reads members_token from
// here); members_cafile is the test CA that signed the stub's certificate
// (tests/php-runtime.Dockerfile).
return [
    'members_url' => 'https://mitglieder.piratenpartei.at/members.php',
    'members_token' => 'synthetic-test-only-members-token',
    'members_cafile' => '/usr/local/share/ca-certificates/pgacc-synthetic-test-ca.crt',
];
