<?php
// PHPUnit 4.8 bootstrap on PHP 5.3: the PHP 5.6 bootstrap plus hex2bin()
// (PHP 5.4+), which the crypto contract tests use to read their vectors.
// Test-only: the application does not call hex2bin().
if (!function_exists('hex2bin')) {
    function hex2bin($hex)
    {
        return pack('H*', $hex);
    }
}
require dirname(dirname(__FILE__)) . '/php56/bootstrap.php';
