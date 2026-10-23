<?php

if (!function_exists('checklogin')) {
    function checklogin($field, $redirect = false)
    {
        return isset($GLOBALS['functions_test_rights'])
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

// tests/mutation/infection.sh substitutes a class-wrapped copy for Infection.
$functionsFile = getenv('PGACC_FUNCTIONS_FILE');
require_once $functionsFile ? $functionsFile : dirname(__DIR__) . '/functions.php';
