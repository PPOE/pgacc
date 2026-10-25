<?php

/*
 * The receipt file that the PDF import (import2.php) writes (files/<n>.v2.aes,
 * decrypted with the configured key and the file number), and the refusal of
 * the PDF import and of the receipt upload (new.php) without a valid key.
 * page_import2_match_voucher() and page_new_file() are called directly: the
 * import's HTTP route (with smalot/pdfparser) is covered by
 * tests/http-receipts.php, and a stored upload ends the request (relocate()),
 * so only its refusal runs here.
 * Database changes are rolled back; files go to a temporary directory.
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
        $_FILES = [$field => [
            'name' => [3 => 'statement.pdf'],
            'tmp_name' => [3 => $directory . '/upload.pdf'],
        ]];
        if ($field === 'file0') {
            $_FILES[$field] = ['name' => 'receipt.pdf', 'tmp_name' => $directory . '/upload.pdf',
                'size' => strlen($plain)];
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
        $diagnostics = [];
        $stored = [];
        $file = null;
        // pg_connect() returns the default link that the application code uses.
        $connection = pg_connect(pgacc_test_connection_string());
        pg_query($connection, 'BEGIN');
        chdir($directory);
        set_error_handler(function ($type, $message, $file, $line) use (&$diagnostics) {
            $diagnostics[] = basename($file) . ':' . $line . ': ' . $message;
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
            $_FILES = [];
            $_POST = [];
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
        return [$output, $file, $stored, $diagnostics, $logText];
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
        self::assertSame(["$file.v2.aes"], array_keys($stored));
        self::assertSame('PGR2', substr($stored["$file.v2.aes"], 0, 4));
        self::assertSame($plain, receipt_v2_decrypt($key, $file, $stored["$file.v2.aes"]));
        self::assertSame([], $diagnostics);
        self::assertSame('', $log);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('refusedImportProvider')]
    public function testImportOfAnInaccessibleVoucherStoresNothing($rights, $setup)
    {
        // A voucher of another organization, or one with a finalized row (also
        // of another organization), is not matched: no receipt is stored and
        // the voucher is unchanged.
        $vector = self::receiptVector('pdf document, test-constants key');
        list($output, $file, $stored, $diagnostics, $log) = self::runWithReceipt(
            $vector['key'],
            hex2bin($vector['plain']),
            'upload',
            function () use ($rights, $setup) {
                if ($setup !== null) {
                    pg_query($GLOBALS['dbconn'], $setup);
                }
                page_import2_match_voucher($rights, 3, 'XY/987654321', '2024-09-15');
            },
        );
        self::assertSame('Keine Buchung gefunden.<br />', $output);
        self::assertSame('0', $file);
        self::assertSame([], $stored);
        self::assertSame([], $diagnostics);
        self::assertSame('', $log);
    }

    public function testImportResetsTheApprovalsOfTheWholeVoucher()
    {
        // Attaching a receipt resets the approvals of every
        // current row of the voucher, also of another organization's row.
        $vector = self::receiptVector('pdf document, test-constants key');
        $approvals = null;
        list($output) = self::runWithReceipt(
            $vector['key'],
            hex2bin($vector['plain']),
            'upload',
            function () use (&$approvals) {
                pg_query($GLOBALS['dbconn'], "INSERT INTO vouchers (id, voucher_id, type, orga, comment, date, file, ack1, ack2)
                          VALUES (9303, 4711, 20, 1, 'Synthetic approved row', timestamp '2024-09-15 00:00:00', 0, 'synthetic-a', 'synthetic-b')");
                page_import2_match_voucher('2', 3, 'XY/987654321', '2024-09-15');
                $approvals = pg_fetch_row(pg_query($GLOBALS['dbconn'], 'SELECT ack1, ack2, ack1_old, ack2_old FROM vouchers WHERE id = 9303'));
            },
        );
        self::assertSame('done: <a href="index.php?action=edit&id=4711&bid=9301">4711</a><br />', $output);
        self::assertSame([null, null, 'synthetic-a', 'synthetic-b'], $approvals);
    }

    public static function refusedImportProvider()
    {
        return [
            'other organization' => ['1', null],
            'finalized row' => ['2', 'UPDATE vouchers SET receipt_received = true WHERE id = 9301'],
            'finalized row of another organization' => [
                'bgf',
                "INSERT INTO vouchers (id, voucher_id, type, orga, comment, date, file, receipt_received)
                 VALUES (9302, 4711, 20, 1, 'Synthetic other row', timestamp '2024-09-15 00:00:00', 0, true)",
            ],
        ];
    }

    public function testImportWithoutAConfiguredKeyIsRefused()
    {
        $vector = self::receiptVector('pdf document');
        foreach ([null, '', 'CHANGE-ME', 'short-key'] as $key) {
            list($output, $file, $stored, $diagnostics, $log) = self::import($key, hex2bin($vector['plain']));

            self::assertSame('Beleg konnte nicht gespeichert werden.<br />', $output);
            self::assertSame('0', $file);
            self::assertSame([], $stored);
            self::assertSame([], $diagnostics);
            self::assertStringContainsString('pgacc: receipt not stored: no valid receipt key ($key) configured', $log);
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('refusedUploadProvider')]
    public function testUploadToAnInaccessibleRowChangesNothing($rights, $post)
    {
        // The posted row must be a current row of the posted voucher that the
        // user may change; otherwise nothing is stored and no approval moves.
        $vector = self::receiptVector('pdf document, test-constants key');
        $_POST = $post;
        $approvals = null;
        list($output, $file, $stored, $diagnostics, $log) = self::runWithReceipt(
            $vector['key'],
            hex2bin($vector['plain']),
            'file0',
            function () use ($rights, &$approvals) {
                pg_query($GLOBALS['dbconn'], "UPDATE vouchers SET ack1 = 'synthetic-a', ack2 = 'synthetic-b' WHERE id = 9301");
                pg_query($GLOBALS['dbconn'], "INSERT INTO vouchers (id, voucher_id, type, orga, comment, date, file, ack1)
                          VALUES (9304, 4712, 20, 2, 'Synthetic other voucher', timestamp '2024-09-15 00:00:00', 0, 'synthetic-c')");
                page_new_file($rights);
                $approvals = pg_fetch_all(pg_query($GLOBALS['dbconn'], 'SELECT id, file, ack1, ack2, ack1_old, ack2_old FROM vouchers WHERE id IN (9301, 9304) ORDER BY id'));
            },
        );
        self::assertSame('<div class="slot_error" id="slot_error">FEHLER: Buchungszeile nicht gefunden.</div>'
            . '<br /><br /><br />', $output);
        self::assertSame('0', $file);
        self::assertSame([], $stored);
        self::assertSame([
            ['id' => '9301', 'file' => '0', 'ack1' => 'synthetic-a', 'ack2' => 'synthetic-b', 'ack1_old' => null, 'ack2_old' => null],
            ['id' => '9304', 'file' => '0', 'ack1' => 'synthetic-c', 'ack2' => null, 'ack1_old' => null, 'ack2_old' => null],
        ], $approvals);
        self::assertSame([], $diagnostics);
        self::assertSame('', $log);
    }

    public static function refusedUploadProvider()
    {
        return [
            'row of another organization' => ['1', ['parts' => '1', 'bid0' => '9301', 'id' => '4711']],
            'nonexistent row' => ['2', ['parts' => '1', 'bid0' => '9399', 'id' => '4711']],
            'row of another voucher' => ['2', ['parts' => '1', 'bid0' => '9301', 'id' => '4712']],
        ];
    }

    public function testUploadFailureKeepsTheRowsPreviousReceipt()
    {
        // A row that already has a receipt keeps it when storing the new one
        // fails (no receipt key).
        $vector = self::receiptVector('pdf document');
        $_POST = ['parts' => '1', 'bid0' => '9301', 'id' => '4711'];
        list($output, $file, $stored) = self::runWithReceipt(
            null,
            hex2bin($vector['plain']),
            'file0',
            function () {
                pg_query($GLOBALS['dbconn'], 'UPDATE vouchers SET file = 77 WHERE id = 9301');
                page_new_file('2');
            },
        );
        self::assertSame('<div class="slot_error" id="slot_error">FEHLER: Beleg konnte nicht gespeichert werden.</div>'
            . '<br /><br /><br />', $output);
        self::assertSame('77', $file);
        self::assertSame([], $stored);
    }

    public function testUploadWithoutAConfiguredKeyIsRefused()
    {
        // The receipt upload on the voucher edit page (new.php page_new_file()).
        $vector = self::receiptVector('pdf document');
        foreach ([null, '', 'CHANGE-ME', 'short-key'] as $key) {
            $_POST = ['parts' => '1', 'bid0' => '9301', 'id' => '4711'];
            list($output, $file, $stored, $diagnostics, $log) = self::runWithReceipt(
                $key,
                hex2bin($vector['plain']),
                'file0',
                function () {
                    page_new_file('2');
                },
            );

            self::assertSame('<div class="slot_error" id="slot_error">FEHLER: Beleg konnte nicht gespeichert werden.</div>'
                . '<br /><br /><br />', $output);
            self::assertSame('0', $file);
            self::assertSame([], $stored);
            self::assertSame([], $diagnostics);
            self::assertStringContainsString('pgacc: receipt not stored: no valid receipt key ($key) configured', $log);
        }
    }

    // An upload error reports the file and imports nothing. Browsers send no
    // type with an upload error (PHP then reports an empty type, refused
    // first), so only a constructed upload reaches it.
    public function testUploadErrorIsReported()
    {
        $_FILES = ['upload' => [
            'name' => ['<i>a&b</i>.pdf'],
            'type' => ['application/pdf'],
            'tmp_name' => [''],
            'error' => [UPLOAD_ERR_PARTIAL],
            'size' => [100],
        ]];
        ob_start();
        try {
            page_import2_process('2');
        } finally {
            $output = ob_get_clean();
            $_FILES = [];
        }
        self::assertStringContainsString('Datei 1 (&lt;i&gt;a&amp;b&lt;/i&gt;.pdf):<br />'
            . 'Wurde nicht erfolgreich hochgeladen und konnte daher nicht importiert werden.<br />', $output);
        self::assertStringNotContainsString('gefunden', $output);
    }
}
