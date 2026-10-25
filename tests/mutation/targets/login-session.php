<?php

/*
 * The random source of login sessions in functions.php:
 * login_random_bytes() and its call in login_start_session(). Both fail
 * closed: without random bytes no session is started. Killed by
 * LoginSessionTest. See tests/mutation/README.md for the format.
 */
$start = ['start' => 'function login_start_session(', 'end' => 'function login_session_token('];

return [
    'description' => 'login_random_bytes() and its call in login_start_session()',
    'file' => 'functions.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'LoginSessionTest', 'file' => 'tests/unit/LoginSessionTest.php'],
    ],
    'timeout' => 60,
    'mutations' => [
        [
            'id' => 'random-source-ignored',
            'search' => '        $bytes = $random($length);',
            'replace' => '        $bytes = random_bytes($length);',
            'rationale' => 'The bytes come from the given source.',
        ],
        [
            'id' => 'random-exception-narrowed',
            'search' => '    } catch (Exception) {' . "\n" . '        return false;',
            'replace' => '    } catch (\Random\RandomException) {' . "\n" . '        return false;',
            'rationale' => 'Any exception of the source fails closed, not only RandomException.',
        ],
        [
            'id' => 'random-length-unchecked',
            'search' => 'return strlen($bytes) === $length ? $bytes : false;',
            'replace' => 'return $bytes;',
            'rationale' => 'Fewer bytes than requested are no nonce.',
        ],
        [
            'id' => 'start-session-random-uncaught',
            'region' => $start,
            'search' => 'login_random_bytes(4, $random)',
            'replace' => 'random_bytes(4)',
            'rationale' => 'login_start_session() refuses the login when its random source fails.',
        ],
        [
            'id' => 'start-session-random-unchecked',
            'region' => $start,
            'search' => "    if (\$nonce === false) {\n",
            'replace' => "    if (false) {\n",
            'rationale' => 'No nonce, no session.',
        ],
    ],
];
