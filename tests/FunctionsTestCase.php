<?php

abstract class FunctionsTestCase extends PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $GLOBALS['make_csv'] = false;
        $GLOBALS['functions_test_rights'] = '';
    }
}
