<?php
/*
 * The receipt file that the PDF import (import2.php) writes, compared byte for
 * byte with the mcrypt vector. page_import2_match_voucher() is called
 * directly: the HTTP route also needs smalot/pdfparser, whose locked version
 * does not load on PHP 7.2+ (tests/http-receipts.php covers it on PHP 5.6).
 * Database changes are rolled back; the file goes to a temporary directory.
 *
 * Must stay compatible with PHP 5.6 (PHPUnit 5.7).
 */

require_once dirname(dirname(__DIR__)) . '/import2.php';

final class ReceiptImportTest extends FunctionsTestCase
{
    public function testImportWritesTheMcryptCompatibleReceipt()
    {
        $vectors = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/mcrypt/vectors.json'), true);
        $vector = null;
        foreach ($vectors['receipts'] as $candidate) {
            if ($candidate['name'] === 'pdf document') {
                $vector = $candidate;
            }
        }
        self::assertSame('4711', $vector['id']);

        $directory = sys_get_temp_dir() . '/pgacc-receipt-import-' . getmypid();
        mkdir($directory . '/files', 0700, true);
        file_put_contents($directory . '/upload.pdf', hex2bin($vector['plain']));
        $_FILES = array('upload' => array('tmp_name' => array(3 => $directory . '/upload.pdf')));
        // Rights '2': the voucher's organization, so the file number is stored.
        $workingDirectory = getcwd();
        $diagnostics = array();
        // pg_connect() returns the default link that the application code uses.
        $connection = pg_connect('host=' . getenv('PGHOST') . ' port=' . getenv('PGPORT') . ' dbname=' . getenv('PGDATABASE') . ' user=' . getenv('PGUSER') . ' password=' . getenv('PGPASSWORD'));
        pg_query($connection, 'BEGIN');
        try {
            pg_query($connection, "INSERT INTO vouchers (id, voucher_id, type, orga, comment, date, file)
                      VALUES (9301, 4711, 20, 2, 'Synthetic import XY/987654321', timestamp '2024-09-15 00:00:00', 0)");
            // The import takes the next number of the sequence.
            $fileNumber = pg_fetch_result(pg_query($connection, "SELECT nextval('file_number')"), 0, 0) + 1;
            chdir($directory);
            // Not asserted: the implicit-connection deprecations of PHP 8.5
            // (a separate item in tests/COMPATIBILITY_PLAN.md).
            set_error_handler(function ($type, $message, $file, $line) use (&$diagnostics) {
                if ($type !== E_DEPRECATED) {
                    $diagnostics[] = basename($file) . ':' . $line . ': ' . $message;
                }
                return true;
            });
            ob_start();
            page_import2_match_voucher('2', 3, 'XY/987654321', '2024-09-15');
            $output = ob_get_clean();
            restore_error_handler();
            $file = pg_fetch_result(pg_query($connection, 'SELECT file FROM vouchers WHERE id = 9301'), 0, 0);
        } finally {
            chdir($workingDirectory);
            pg_query($connection, 'ROLLBACK');
            $_FILES = array();
        }
        $stored = file_get_contents($directory . "/files/$fileNumber.aes");
        unlink($directory . "/files/$fileNumber.aes");
        unlink($directory . '/upload.pdf');
        rmdir($directory . '/files');
        rmdir($directory);

        self::assertSame(strval($fileNumber), $file);
        self::assertSame('done: <a href="index.php?action=edit&id=4711&bid=9301">4711</a><br />', $output);
        self::assertSame($vector['cipher'], bin2hex($stored));
        // The receipt key is read without "global" (mcrypt item in
        // tests/COMPATIBILITY_PLAN.md), which is why it is empty.
        self::assertCount(1, $diagnostics);
        self::assertRegExpCompat('/^import2\.php:189: Undefined variable:? \$?key$/', $diagnostics[0]);
    }

    private static function assertRegExpCompat($pattern, $string)
    {
        self::assertSame(1, preg_match($pattern, $string), $string);
    }
}
