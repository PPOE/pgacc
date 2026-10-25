<?php

/*
 * Shared setup of the receipt key migration tests (ReceiptMigrationTest,
 * ReceiptMigrationCommandTest): synthetic legacy receipts in a temporary
 * directory and synthetic voucher rows, rolled back after each test.
 */

require_once dirname(dirname(__DIR__)) . '/migrate-receipt-key.php';

abstract class ReceiptMigrationTestCase extends FunctionsTestCase
{
    public const TEST_KEY = 'synthetic-test-only-encryption-key';
    public const OTHER_KEY = 'another-synthetic-receipt-key';

    protected $directory;
    protected $connection;

    #[PHPUnit\Framework\Attributes\Before]
    public function prepare()
    {
        $this->directory = sys_get_temp_dir() . '/pgacc-receipt-migration-' . uniqid('', true);
        mkdir($this->directory . '/files', 0700, true);
        $this->writeConstants("<?php\n\$datakey = '';\n\$key = '" . self::TEST_KEY . "';\n");
        $this->connection = pg_connect(pgacc_test_connection_string());
        pg_query($this->connection, 'BEGIN');
        // Synthetic rows: file 901 (voucher 4711), 902 (815), 903 (voucher
        // 4712, but encrypted for 4711, as after a merge), 904 (4711, not a
        // PDF or JPEG), 905 has no row, 906 (4711, too large), 908 belongs to
        // two vouchers and was encrypted for the second, 909-911 (4711), 912
        // (4711, a PDF that does not end like one).
        $rows = [
            [9401, 4711, 901], [9402, 815, 902], [9403, 4712, 903], [9404, 4711, 904],
            [9405, 4711, 906], [9406, 815, 908], [9407, 4711, 908], [9408, 4711, 909],
            [9409, 4711, 910], [9410, 4711, 911], [9411, 4711, 912],
        ];
        foreach ($rows as $row) {
            pg_query_params($this->connection, "INSERT INTO vouchers (id, voucher_id, type, orga, comment, date, file)
                VALUES ($1, $2, 20, 2, 'Synthetic migration receipt', timestamp '2024-09-15 00:00:00', $3)", $row);
        }
    }

    #[PHPUnit\Framework\Attributes\After]
    public function cleanUp()
    {
        pg_query($this->connection, 'ROLLBACK');
        self::removeTree($this->directory);
    }

    protected static function removeTree($path)
    {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $name) {
                self::removeTree($path . '/' . $name);
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    protected function writeConstants($content)
    {
        file_put_contents($this->directory . '/constants.php', $content);
    }

    protected static function fingerprint($key)
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/receipt-v2/vectors.json'), true);
        return $fixture['fingerprints'][$key];
    }

    protected static function legacyVector($name)
    {
        $vectors = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/mcrypt/vectors.json'), true);
        foreach ($vectors['receipts'] as $vector) {
            if ($vector['name'] === $name) {
                return $vector;
            }
        }
        throw new RuntimeException("no receipt vector named $name");
    }

    protected function put($name, $bytes)
    {
        file_put_contents($this->directory . '/files/' . $name, $bytes);
    }

    // Name => bytes ('directory' for a directory) of the receipt directory.
    protected function files()
    {
        $files = [];
        foreach (array_diff(scandir($this->directory . '/files'), ['.', '..']) as $name) {
            $path = $this->directory . '/files/' . $name;
            $files[$name] = is_dir($path) ? 'directory' : file_get_contents($path);
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    protected function v2Plain($number)
    {
        return receipt_v2_decrypt(self::TEST_KEY, $number, file_get_contents($this->directory . "/files/$number.v2.aes"));
    }

    // array(exit status, output, errors) of receipt_migration_main().
    protected function migrate($arguments, $defaults = true)
    {
        if ($defaults) {
            $arguments = array_merge([
                '--files=' . $this->directory . '/files',
                '--constants=' . $this->directory . '/constants.php',
                '--database=' . pgacc_test_connection_string(),
            ], $arguments);
        }
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $diagnostics = [];
        set_error_handler(function ($type, $message) use (&$diagnostics) {
            if ((error_reporting() & $type) && $type !== E_DEPRECATED) {
                $diagnostics[] = $message;
            }
            return true;
        });
        try {
            $status = receipt_migration_main($arguments, $out, $err);
        } catch (Exception $exception) {
            restore_error_handler();
            throw $exception;
        }
        restore_error_handler();
        self::assertSame([], $diagnostics);
        rewind($out);
        rewind($err);
        $result = [$status, stream_get_contents($out), stream_get_contents($err)];
        self::assertStringNotContainsString(self::TEST_KEY, $result[1] . $result[2]);
        return $result;
    }

    protected static function summary($counts, $dryRun = false, $key = self::TEST_KEY)
    {
        return ($dryRun ? 'Dry run, nothing written: ' : '')
            . "{$counts[0]} migrated, {$counts[1]} already migrated, {$counts[2]} legacy deleted, "
            . "{$counts[3]} not yet migrated, {$counts[4]} skipped, {$counts[5]} failed; "
            . 'key fingerprint ' . self::fingerprint($key) . "\n";
    }

    protected function legacyPdf()
    {
        $vector = self::legacyVector('pdf document');
        self::assertSame(['4711', ''], [$vector['id'], $vector['key']]);
        return hex2bin($vector['cipher']);
    }

    protected static function paddedPdf()
    {
        $vector = self::legacyVector('pdf document');
        return hex2bin($vector['decrypted']);
    }

    // The exit status of a migration run.
    protected function migrationStatus($arguments)
    {
        $result = $this->migrate($arguments);
        return $result[0];
    }

    // The reminder that every --delete-legacy run prints first.
    protected static function deleteReminder()
    {
        return "*** --delete-legacy: only after `sync` and after checking several migrated receipts in the browser. "
            . "Deleted legacy files can only be restored from the backup. ***\n";
    }
}
