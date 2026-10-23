<?php

require_once dirname(__DIR__) . '/functions-bootstrap.php';

abstract class FunctionsTestCase extends PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        $_GET = array();
        $GLOBALS['make_csv'] = false;
        $GLOBALS['functions_test_rights'] = '';
    }

    // PHPUnit 5.7 predates these assertion names. Its string assertions use
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
