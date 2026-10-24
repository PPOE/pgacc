<?php
/*
 * The receipt file that the PDF import (import2.php) writes (files/<n>.v2.aes,
 * decrypted with the configured key and the file number), and the refusal of
 * the PDF import and of the receipt upload (new.php) without a valid key.
 * page_import2_match_voucher() and page_new_file() are called directly: the
 * import's HTTP route also needs smalot/pdfparser, whose locked version does
 * not load on PHP 7.2+ (tests/http-receipts.php covers it on PHP 5.6), and a
 * stored upload ends the request (relocate()), so only its refusal runs here.
 * Database changes are rolled back; files go to a temporary directory.
 *
 * Must stay compatible with PHP 5.6 (PHPUnit 5.7).
 */

require_once dirname(dirname(__DIR__)) . '/import2.php';
require_once dirname(dirname(__DIR__)) . '/new.php';
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
     * Runs $callback (the application code) with the synthetic voucher 9301
     * (voucher 4711, organization 2) inserted, $key set to $key (null: unset),
     * the upload $plain at $_FILES[$field] and the working directory in a
     * temporary directory. Returns the output, the voucher's file number, the
     * written files (name => bytes), the diagnostics and the error_log() text.
     */
    private static function runWithReceipt($key, $plain, $field, $callback)
    {
        $directory = sys_get_temp_dir() . '/pgacc-receipt-import-' . uniqid('', true);
        mkdir($directory . '/files', 0700, true);
        file_put_contents($directory . '/upload.pdf', $plain);
        $_FILES = array($field => array(
            'name' => array(3 => 'statement.pdf'),
            'tmp_name' => array(3 => $directory . '/upload.pdf'),
        ));
        if ($field === 'file0') {
            $_FILES[$field] = array('name' => 'receipt.pdf', 'tmp_name' => $directory . '/upload.pdf',
                'size' => strlen($plain));
        }
        $hadKey = array_key_exists('key', $GLOBALS);
        $previousKey = $hadKey ? $GLOBALS['key'] : null;
        if ($key === null) {
            unset($GLOBALS['key']);
        } else {
            $GLOBALS['key'] = $key;
        }
        $log = $directory . '/error.log';
        $previousLog = ini_set('error_log', $log);
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
            AppExitGuard::enter('receipt application code');
            call_user_func($callback);
            $file = pg_fetch_result(pg_query($connection, 'SELECT file FROM vouchers WHERE id = 9301'), 0, 0);
        } finally {
            // die() skips finally blocks, so this only runs when the call returned.
            AppExitGuard::leave();
            $output = ob_get_clean();
            restore_error_handler();
            chdir($workingDirectory);
            pg_query($connection, 'ROLLBACK');
            ini_set('error_log', $previousLog);
            if ($hadKey) {
                $GLOBALS['key'] = $previousKey;
            } else {
                unset($GLOBALS['key']);
            }
            $_FILES = array();
            $_POST = array();
            foreach (glob($directory . '/files/*') as $path) {
                $stored[basename($path)] = file_get_contents($path);
                unlink($path);
            }
            $logText = is_file($log) ? file_get_contents($log) : '';
            if (is_file($log)) {
                unlink($log);
            }
            unlink($directory . '/upload.pdf');
            rmdir($directory . '/files');
            rmdir($directory);
        }
        return array($output, $file, $stored, $diagnostics, $logText);
    }

    private static function import($key, $plain)
    {
        return self::runWithReceipt($key, $plain, 'upload', function () {
            // Rights '2': the voucher's organization, so the file number is stored.
            page_import2_match_voucher('2', 3, 'XY/987654321', '2024-09-15');
        });
    }

    private static function configuredTestKey()
    {
        return call_user_func(function ($path) {
            require $path;
            return $key;
        }, dirname(__DIR__) . '/runtime/test-constants.php');
    }

    public function testImportWritesTheConfiguredKeyReceipt()
    {
        // Formerly the known failure KF-RECEIPT-KEY-IGNORED: the import wrote
        // the empty-key ciphertext although a key was configured. Now the
        // file is <n>.v2.aes, keyed on its number and the configured key.
        $key = self::configuredTestKey();
        $plain = hex2bin(self::receiptVector('pdf document')['plain']);

        list($output, $file, $stored, $diagnostics, $log) = self::import($key, $plain);

        self::assertSame('done: <a href="index.php?action=edit&id=4711&bid=9301">4711</a><br />', $output);
        // The voucher row names the one file the import wrote.
        self::assertGreaterThan(0, intval($file));
        self::assertSame(array("$file.v2.aes"), array_keys($stored));
        self::assertSame('PGR2', substr($stored["$file.v2.aes"], 0, 4));
        self::assertSame($plain, receipt_v2_decrypt($key, $file, $stored["$file.v2.aes"]));
        self::assertSame(array(), $diagnostics);
        self::assertSame('', $log);
    }

    public function testImportWithoutAConfiguredKeyIsRefused()
    {
        $vector = self::receiptVector('pdf document');
        foreach (array(null, '', 'CHANGE-ME', 'short-key') as $key) {
            list($output, $file, $stored, $diagnostics, $log) = self::import($key, hex2bin($vector['plain']));

            self::assertSame('Beleg konnte nicht gespeichert werden.<br />', $output);
            self::assertSame('0', $file);
            self::assertSame(array(), $stored);
            self::assertSame(array(), $diagnostics);
            self::assertStringContainsString('pgacc: receipt not stored: no valid receipt key ($key) configured', $log);
        }
    }

    public function testUploadWithoutAConfiguredKeyIsRefused()
    {
        // The receipt upload on the voucher edit page (new.php page_new_file()).
        $vector = self::receiptVector('pdf document');
        foreach (array(null, '', 'CHANGE-ME', 'short-key') as $key) {
            $_POST = array('parts' => '1', 'bid0' => '9301', 'id' => '4711');
            list($output, $file, $stored, $diagnostics, $log) = self::runWithReceipt(
                $key,
                hex2bin($vector['plain']),
                'file0',
                function () {
                    page_new_file('2');
                }
            );

            self::assertSame('<div class="slot_error" id="slot_error">FEHLER: Beleg konnte nicht gespeichert werden.</div>'
                . '<br /><br /><br />', $output);
            self::assertSame('0', $file);
            self::assertSame(array(), $stored);
            self::assertSame(array(), $diagnostics);
            self::assertStringContainsString('pgacc: receipt not stored: no valid receipt key ($key) configured', $log);
        }
    }
}
