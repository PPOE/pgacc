<?php

/*
 * With short_open_tag disabled, a file that starts with "<?" is plain text:
 * including it prints its source and defines nothing. Checked in a separate
 * process for the page files that only define functions.
 *
 * Must stay compatible with PHP 5.6 (PHPUnit 5.7).
 */

final class ShortOpenTagTest extends FunctionsTestCase
{
    public static function fileProvider()
    {
        return array(
            'file.php' => array('file.php', 'download_file'),
            'getusers.php' => array('getusers.php', 'getUsers'),
        );
    }

    /**
     * @dataProvider fileProvider
     */
    public function testFileWorksWithShortOpenTagDisabled($file, $function)
    {
        $path = dirname(dirname(__DIR__)) . '/' . $file;
        $code = 'ob_start(); include ' . var_export($path, true) . '; $output = ob_get_clean();'
            . ' echo strlen($output), ",", function_exists(' . var_export($function, true) . ') ? "defined" : "missing";';
        $command = escapeshellarg(PHP_BINARY) . ' -n -d short_open_tag=0 -r ' . escapeshellarg($code);
        exec($command, $lines, $status);
        self::assertSame(0, $status);
        self::assertSame(array('0,defined'), $lines);
    }
}
