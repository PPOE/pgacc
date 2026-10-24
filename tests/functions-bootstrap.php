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

if (function_exists('pg_connect') && getenv('PGHOST')) {
    if (pg_connect(pgacc_test_connection_string()) === false) {
        throw new RuntimeException('Unable to connect to the synthetic test database');
    }
}

// index.php loads the Composer autoloader before functions.php; the receipt
// and membership encryption helpers need phpseclib from it.
require_once dirname(__DIR__) . '/vendor/autoload.php';

// tests/mutation/infection.sh substitutes a class-wrapped copy for Infection.
$functionsFile = getenv('PGACC_FUNCTIONS_FILE');
require_once $functionsFile ? $functionsFile : dirname(__DIR__) . '/functions.php';
