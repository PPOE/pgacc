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

if (function_exists('pg_connect') && getenv('PGHOST')) {
    $connectionString = sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s',
        getenv('PGHOST'),
        getenv('PGPORT'),
        getenv('PGDATABASE'),
        getenv('PGUSER'),
        getenv('PGPASSWORD')
    );
    if (pg_connect($connectionString) === false) {
        throw new RuntimeException('Unable to connect to the synthetic test database');
    }
}

// index.php loads the Composer autoloader before functions.php; the receipt
// and membership encryption helpers need phpseclib from it.
require_once dirname(__DIR__) . '/vendor/autoload.php';

// tests/mutation/infection.sh substitutes a class-wrapped copy for Infection.
$functionsFile = getenv('PGACC_FUNCTIONS_FILE');
require_once $functionsFile ? $functionsFile : dirname(__DIR__) . '/functions.php';
