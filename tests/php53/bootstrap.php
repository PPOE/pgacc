<?php
// PHPUnit 4.8 bootstrap on PHP 5.3 (tests/php53/phpunit.xml): hex2bin()
// (PHP 5.4+), which the crypto contract tests use to read their vectors, and
// the FunctionsTestCase base class for PHPUnit_Framework_TestCase.
// Test-only: the application does not call hex2bin().
if (!function_exists('hex2bin')) {
    function hex2bin($hex)
    {
        return pack('H*', $hex);
    }
}

require_once dirname(dirname(__FILE__)) . '/functions-bootstrap.php';

abstract class FunctionsTestCase extends PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        $_GET = array();
        $GLOBALS['make_csv'] = false;
        $GLOBALS['functions_test_rights'] = '';
    }

    // PHPUnit 4.8 predates these assertion names. Its string assertions use
    // the same substring checks, so both runners can execute the same tests.
    public static function assertStringContainsString($needle, $haystack, $message = '')
    {
        self::assertContains($needle, $haystack, $message);
    }

    public static function assertStringNotContainsString($needle, $haystack, $message = '')
    {
        self::assertNotContains($needle, $haystack, $message);
    }
}
