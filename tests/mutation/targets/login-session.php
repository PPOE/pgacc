<?php

/*
 * The random source of login sessions in functions.php:
 * login_random_bytes() and its call in login_start_session(). Both fail
 * closed: without random bytes no session is started. Killed by
 * LoginSessionTest. See tests/mutation/README.md for the format.
 */
$start = ['start' => 'function login_start_session(', 'end' => 'function login_session_token('];
$bytes = ['start' => 'function login_random_bytes(', 'end' => 'function login_session_key('];

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
            'region' => $bytes,
            'search' => '        $bytes = $random($length);',
            'replace' => '        $bytes = random_bytes($length);',
            'rationale' => 'The bytes come from the given source.',
        ],
        [
            'id' => 'random-exception-narrowed',
            'region' => $bytes,
            'search' => '    } catch (Throwable) {' . "\n" . '        return false;',
            'replace' => '    } catch (\Random\RandomException) {' . "\n" . '        return false;',
            'rationale' => 'Any exception of the source fails closed, not only RandomException.',
        ],
        [
            'id' => 'random-error-escapes',
            'region' => $bytes,
            'search' => '    } catch (Throwable) {' . "\n" . '        return false;',
            'replace' => '    } catch (Exception) {' . "\n" . '        return false;',
            'rationale' => 'An Error of the source (TypeError, ValueError) fails closed too.',
        ],
        [
            'id' => 'random-length-unchecked',
            'region' => $bytes,
            'search' => 'return is_string($bytes) && strlen($bytes) === $length ? $bytes : false;',
            'replace' => 'return $bytes;',
            'rationale' => 'Fewer bytes than requested are no nonce.',
        ],
        [
            'id' => 'random-non-string-accepted',
            'region' => $bytes,
            'search' => 'return is_string($bytes) && strlen($bytes) === $length ? $bytes : false;',
            'replace' => 'return strlen($bytes) === $length ? $bytes : false;',
            'rationale' => 'A source that returns no string fails closed instead of throwing a TypeError.',
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
