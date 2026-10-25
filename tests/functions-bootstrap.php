<?php

if (!function_exists('checklogin')) {
    function checklogin($field, $redirect = false)
    {
        // array_key_exists, not isset: tests may set null, which is what the
        // real checklogin() returns for visitors without a valid login cookie.
        return array_key_exists('functions_test_rights', $GLOBALS)
            ? $GLOBALS['functions_test_rights']
            : '';
    }
}

require_once __DIR__ . '/db-helpers.php';

// The application passes its global $dbconn (index.php) to every pg_* call.
if (function_exists('pg_connect') && getenv('PGHOST')) {
    $GLOBALS['dbconn'] = pg_connect(pgacc_test_connection_string());
    if ($GLOBALS['dbconn'] === false) {
        throw new RuntimeException('Unable to connect to the synthetic test database');
    }
}

// index.php loads the Composer autoloader before functions.php; the receipt
// and membership encryption helpers need phpseclib from it.
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Database helpers (index.php loads them after functions.php).
require_once dirname(__DIR__) . '/src/db.php';

// tests/mutation/infection.sh substitutes a class-wrapped copy for Infection.
$functionsFile = getenv('PGACC_FUNCTIONS_FILE');
require_once $functionsFile ? $functionsFile : dirname(__DIR__) . '/functions.php';
