<?php

/*
 * check.php, the command-line voucher number check: the refusal of other
 * server APIs (404 and an empty body over HTTP), the arguments, the output
 * and the exit status of a failed query or connection. Killed by
 * CheckCommandTest and the HTTP refusal in tests/http-smoke.php. See
 * tests/mutation/README.md for the format.
 */
return [
    'description' => 'check.php command line only, arguments and failure exit status',
    'file' => 'check.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'CheckCommandTest', 'file' => 'tests/unit/CheckCommandTest.php'],
        ['type' => 'http', 'script' => 'tests/http-smoke.php'],
    ],
    'mutations' => [
        [
            'id' => 'web-reachable',
            'search' => "if (PHP_SAPI !== 'cli') {",
            'replace' => 'if (false) {',
            'rationale' => 'The web server must not run the check.',
        ],
        [
            'id' => 'web-no-404',
            'search' => "header('HTTP/1.0 404 Not Found')",
            'replace' => 'true',
            'rationale' => 'A web request gets a 404.',
        ],
        [
            'id' => 'query-failure-ignored',
            'search' => "        throw new RuntimeException('Abfrage fehlgeschlagen: ' . pg_last_error(\$dbconn));",
            'replace' => '        return 0;',
            'rationale' => 'A failed query stops the check with an error instead of reporting zero vouchers.',
        ],
        [
            'id' => 'query-failure-status',
            'search' => "        fwrite(\$err, \$e->getMessage() . \"\\n\");\n        return 1;",
            'replace' => "        fwrite(\$err, \$e->getMessage() . \"\\n\");\n        return 0;",
            'rationale' => 'A failed query exits with status 1.',
        ],
        [
            'id' => 'connection-failure-status',
            'search' => "        fwrite(\$err, 'Verbindungsaufbau fehlgeschlagen: ' . (error_get_last()['message'] ?? 'no error message') . \"\\n\");\n        return 1;",
            'replace' => "        fwrite(\$err, 'Verbindungsaufbau fehlgeschlagen: ' . (error_get_last()['message'] ?? 'no error message') . \"\\n\");\n        return 0;",
            'rationale' => 'A failed connection exits with status 1.',
        ],
        [
            'id' => 'argument-count-unchecked',
            'search' => 'if (count($arguments) !== 2) {',
            'replace' => 'if (count($arguments) < 2) {',
            'rationale' => 'Extra arguments are refused with the usage message.',
        ],
        [
            'id' => 'account-unvalidated',
            'search' => "if (preg_match('/^[A-Z0-9 ]+\$/i', \$account) == 1) {",
            'replace' => 'if (true) {',
            'rationale' => 'Only letters, digits and spaces reach the queries.',
        ],
        [
            'id' => 'last-number-skipped',
            'search' => 'for ($i = 2; $i <= $max; $i++) {',
            'replace' => 'for ($i = 2; $i < $max; $i++) {',
            'rationale' => 'The numbers 2 to <max> are checked, <max> included.',
        ],
    ],
];
