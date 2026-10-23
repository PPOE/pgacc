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
    private static function receiptVector($name)
    {
        $vectors = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/mcrypt/vectors.json'), true);
        foreach ($vectors['receipts'] as $vector) {
            if ($vector['name'] === $name) {
                return $vector;
            }
        }
        throw new RuntimeException("no receipt vector named $name");
    }

    /*
     * Imports $plain as the receipt of a synthetic voucher 4711 and returns
     * the output, the voucher's file number, the written files (name =>
     * bytes) and the diagnostics.
     */
    private static function importReceipt($plain)
    {
        $directory = sys_get_temp_dir() . '/pgacc-receipt-import-' . uniqid('', true);
        mkdir($directory . '/files', 0700, true);
        file_put_contents($directory . '/upload.pdf', $plain);
        $_FILES = array('upload' => array('tmp_name' => array(3 => $directory . '/upload.pdf')));
        $workingDirectory = getcwd();
        $diagnostics = array();
        $stored = array();
        $file = null;
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
        return array($output, $file, $stored, $diagnostics);
    }

    public function testImportWritesTheMcryptCompatibleReceipt()
    {
        $vector = self::receiptVector('pdf document');
        self::assertSame('4711', $vector['id']);

        list($output, $file, $stored, $diagnostics) = self::importReceipt(hex2bin($vector['plain']));

        self::assertSame('done: <a href="index.php?action=edit&id=4711&bid=9301">4711</a><br />', $output);
        // The voucher row names the one file the import wrote.
        self::assertGreaterThan(0, intval($file));
        self::assertSame(array("$file.aes"), array_keys($stored));
        self::assertSame($vector['cipher'], bin2hex($stored["$file.aes"]));
        self::assertSame(array(), $diagnostics);
    }

    /**
     * Known failure KF-RECEIPT-KEY-IGNORED (tests/COMPATIBILITY_PLAN.md):
     * receipts are encrypted without the configured $key. With the synthetic
     * key of tests/runtime/test-constants.php set, the import writes the
     * empty-key ciphertext instead of the configured-key one. This test fails
     * once the configured key is used; then make the last check a regular
     * assertion of the configured-key ciphertext.
     */
    public function testImportIgnoresTheConfiguredKey()
    {
        $configured = self::receiptVector('pdf document, test-constants key');
        $emptyKey = self::receiptVector('pdf document');
        self::assertSame($emptyKey['plain'], $configured['plain']);
        self::assertSame('', $emptyKey['key']);
        $constants = call_user_func(function ($path) {
            require $path;
            return $key;
        }, dirname(__DIR__) . '/runtime/test-constants.php');
        self::assertSame($constants, $configured['key']);

        $hadKey = array_key_exists('key', $GLOBALS);
        $previousKey = $hadKey ? $GLOBALS['key'] : null;
        $GLOBALS['key'] = $configured['key'];
        try {
            list($output, $file, $stored, $diagnostics) = self::importReceipt(hex2bin($configured['plain']));
        } finally {
            if ($hadKey) {
                $GLOBALS['key'] = $previousKey;
            } else {
                unset($GLOBALS['key']);
            }
        }

        self::assertSame('done: <a href="index.php?action=edit&id=4711&bid=9301">4711</a><br />', $output);
        self::assertGreaterThan(0, intval($file));
        self::assertCount(1, $stored);
        self::assertSame(array(), $diagnostics);
        $cipher = bin2hex(reset($stored));
        if ($cipher === $configured['cipher']) {
            self::fail('KF-RECEIPT-KEY-IGNORED passes now: the receipt uses the configured key. '
                . 'Make this a regular assertion and update tests/COMPATIBILITY_PLAN.md.');
        }
        self::assertSame($emptyKey['cipher'], $cipher, 'KF-RECEIPT-KEY-IGNORED: not the empty-key ciphertext either');
    }
}
