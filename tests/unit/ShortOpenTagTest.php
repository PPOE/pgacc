<?php

/*
 * With short_open_tag disabled, a file that starts with "<?" is plain text:
 * including it prints its source and defines nothing. Checked in a separate
 * process for the page files that only define functions.
 */

final class ShortOpenTagTest extends FunctionsTestCase
{
    public static function fileProvider()
    {
        return [
            'file.php' => ['file.php', 'download_file'],
            'getusers.php' => ['getusers.php', 'getUsers'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('fileProvider')]
    public function testFileWorksWithShortOpenTagDisabled($file, $function)
    {
        $path = dirname(dirname(__DIR__)) . '/' . $file;
        $code = 'ob_start(); include ' . var_export($path, true) . '; $output = ob_get_clean();'
            . ' echo strlen($output), ",", function_exists(' . var_export($function, true) . ') ? "defined" : "missing";';
        $command = escapeshellarg(PHP_BINARY) . ' -n -d short_open_tag=0 -r ' . escapeshellarg($code);
        exec($command, $lines, $status);
        self::assertSame(0, $status);
        self::assertSame(['0,defined'], $lines);
    }
}
