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
require_once __DIR__ . '/AppExitGuard.php';

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

        $directory = sys_get_temp_dir() . '/pgacc-receipt-import-' . uniqid('', true);
        mkdir($directory . '/files', 0700, true);
        file_put_contents($directory . '/upload.pdf', hex2bin($vector['plain']));
        $_FILES = array('upload' => array('tmp_name' => array(3 => $directory . '/upload.pdf')));
        $workingDirectory = getcwd();
        $diagnostics = array();
        $stored = array();
        // pg_connect() returns the default link that the application code uses.
        $connection = pg_connect(pgacc_test_connection_string());
        pg_query($connection, 'BEGIN');
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
        try {
            pg_query($connection, "INSERT INTO vouchers (id, voucher_id, type, orga, comment, date, file)
                      VALUES (9301, 4711, 20, 2, 'Synthetic import XY/987654321', timestamp '2024-09-15 00:00:00', 0)");
            AppExitGuard::enter('page_import2_match_voucher()');
            // Rights '2': the voucher's organization, so the file number is stored.
            page_import2_match_voucher('2', 3, 'XY/987654321', '2024-09-15');
            $file = pg_fetch_result(pg_query($connection, 'SELECT file FROM vouchers WHERE id = 9301'), 0, 0);
        } finally {
            // die() skips finally blocks, so this only runs when the call returned.
            AppExitGuard::leave();
            $output = ob_get_clean();
            restore_error_handler();
            chdir($workingDirectory);
            pg_query($connection, 'ROLLBACK');
            $_FILES = array();
            foreach (glob($directory . '/files/*') as $path) {
                $stored[basename($path)] = file_get_contents($path);
                unlink($path);
            }
            unlink($directory . '/upload.pdf');
            rmdir($directory . '/files');
            rmdir($directory);
        }

        self::assertSame('done: <a href="index.php?action=edit&id=4711&bid=9301">4711</a><br />', $output);
        // The voucher row names the one file the import wrote.
        self::assertGreaterThan(0, intval($file));
        self::assertSame(array("$file.aes"), array_keys($stored));
        self::assertSame($vector['cipher'], bin2hex($stored["$file.aes"]));
        self::assertSame(array(), $diagnostics);
    }
}
