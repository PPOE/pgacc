<?php
/*
 * The receipt key migration (migrate-receipt-key.php): legacy files/<n>.aes
 * re-encrypted with the configured key as files/<n>.v2.aes, on synthetic
 * files in a temporary directory. Most cases call receipt_migration_main()
 * in-process with the test connection string, so pg_connect() returns the
 * test's link and sees its rolled-back voucher rows; two cases run the
 * script as a command against the fixture voucher 1003 (file 42).
 *
 * Must stay compatible with PHP 5.6 (PHPUnit 5.7).
 */

require_once dirname(dirname(__DIR__)) . '/migrate-receipt-key.php';

final class ReceiptMigrationTest extends FunctionsTestCase
{
    const TEST_KEY = 'synthetic-test-only-encryption-key';

    private $directory;
    private $connection;

    /**
     * @before
     */
    public function prepare()
    {
        $this->directory = sys_get_temp_dir() . '/pgacc-receipt-migration-' . uniqid('', true);
        mkdir($this->directory . '/files', 0700, true);
        file_put_contents($this->directory . '/constants.php', "<?php\n\$datakey = '';\n\$key = '" . self::TEST_KEY . "';\n");
        $this->connection = pg_connect(pgacc_test_connection_string());
        pg_query($this->connection, 'BEGIN');
        // Synthetic rows: file 901 (voucher 4711), 902 (815), 903 (voucher
        // 4712, but encrypted for 4711), 904 (4711, not a PDF or JPEG), 905 has
        // no row, 908 belongs to two vouchers (a merge) and was encrypted for
        // the second, 909-911 (4711) for --limit.
        $rows = array(
            array(9401, 4711, 901), array(9402, 815, 902), array(9403, 4712, 903), array(9404, 4711, 904),
            array(9405, 4711, 906), array(9406, 815, 908), array(9407, 4711, 908), array(9408, 4711, 909),
            array(9409, 4711, 910), array(9410, 4711, 911),
        );
        foreach ($rows as $row) {
            pg_query_params($this->connection, "INSERT INTO vouchers (id, voucher_id, type, orga, comment, date, file)
                VALUES ($1, $2, 20, 2, 'Synthetic migration receipt', timestamp '2024-09-15 00:00:00', $3)", $row);
        }
    }

    /**
     * @after
     */
    public function cleanUp()
    {
        pg_query($this->connection, 'ROLLBACK');
        self::removeTree($this->directory);
    }

    private static function removeTree($path)
    {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path), array('.', '..')) as $name) {
                self::removeTree($path . '/' . $name);
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    private static function vector($name)
    {
        $vectors = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/mcrypt/vectors.json'), true);
        foreach ($vectors['receipts'] as $vector) {
            if ($vector['name'] === $name) {
                return $vector;
            }
        }
        throw new RuntimeException("no receipt vector named $name");
    }

    private function put($name, $bytes)
    {
        file_put_contents($this->directory . '/files/' . $name, $bytes);
    }

    // Name => bytes of every file in the receipt directory.
    private function files()
    {
        $files = array();
        foreach (array_diff(scandir($this->directory . '/files'), array('.', '..')) as $name) {
            $path = $this->directory . '/files/' . $name;
            $files[$name] = is_dir($path) ? 'directory' : file_get_contents($path);
        }
        ksort($files);
        return $files;
    }

    // array(exit status, output, errors) of receipt_migration_main().
    private function migrate($arguments)
    {
        $arguments = array_merge(array(
            '--files=' . $this->directory . '/files',
            '--constants=' . $this->directory . '/constants.php',
            '--database=' . pgacc_test_connection_string(),
        ), $arguments);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $diagnostics = array();
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
        self::assertSame(array(), $diagnostics);
        rewind($out);
        rewind($err);
        $result = array($status, stream_get_contents($out), stream_get_contents($err));
        self::assertStringNotContainsString(self::TEST_KEY, $result[1] . $result[2]);
        return $result;
    }

    private function legacyPdf()
    {
        $vector = self::vector('pdf document');
        self::assertSame(array('4711', ''), array($vector['id'], $vector['key']));
        return hex2bin($vector['cipher']);
    }

    private function configuredPdf()
    {
        $vector = self::vector('pdf document, test-constants key');
        self::assertSame(array('4711', self::TEST_KEY), array($vector['id'], $vector['key']));
        return hex2bin($vector['cipher']);
    }

    public function testMigratesLegacyReceiptsAndSkipsTheRest()
    {
        $jpeg = self::vector('jpeg with trailing NUL bytes');
        $jpegConfigured = self::vector('jpeg with trailing NUL bytes, test-constants key');
        $this->put('901.aes', $this->legacyPdf());
        $this->put('902.aes', hex2bin($jpeg['cipher']));
        $this->put('903.aes', $this->legacyPdf());
        $this->put('904.aes', receipt_encrypt('4711', '', "\x89PNG\r\n\x1a\nsynthetic"));
        $this->put('905.aes', $this->legacyPdf());
        $this->put('906.v2.aes', 'new receipt, not touched');
        $this->put('908.aes', $this->legacyPdf());
        $this->put('notes.txt', 'not a receipt');
        $this->put('907.v2.aes.tmp', 'left by a failed write');
        $before = $this->files();

        list($status, $output, $errors) = $this->migrate(array('--dry-run'));
        self::assertSame(0, $status);
        self::assertSame('', $errors);
        self::assertSame($before, $this->files());
        self::assertSame(
            "901.aes: would migrate (voucher 4711)\n"
            . "902.aes: would migrate (voucher 815)\n"
            . "903.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "904.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "905.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "908.aes: would migrate (voucher 4711)\n"
            . "Dry run, nothing written: 3 migrated, 0 completed (legacy file deleted), "
            . "0 verified (legacy file kept), 3 skipped, 0 failed\n",
            $output
        );

        list($status, $output, $errors) = $this->migrate(array());
        self::assertSame(0, $status);
        self::assertSame('', $errors);
        self::assertSame(
            "901.aes: migrated (voucher 4711); deleted 901.aes\n"
            . "902.aes: migrated (voucher 815); deleted 902.aes\n"
            . "903.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "904.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "905.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "908.aes: migrated (voucher 4711); deleted 908.aes\n"
            . "3 migrated, 0 completed (legacy file deleted), 0 verified (legacy file kept), 3 skipped, 0 failed\n",
            $output
        );
        $expected = $before;
        unset($expected['901.aes'], $expected['902.aes'], $expected['908.aes']);
        // Byte for byte the mcrypt vectors of the configured key.
        $expected['901.v2.aes'] = $this->configuredPdf();
        $expected['902.v2.aes'] = hex2bin($jpegConfigured['cipher']);
        $expected['908.v2.aes'] = $this->configuredPdf();
        ksort($expected);
        self::assertSame($expected, $this->files());

        // Idempotent: a second run changes nothing.
        list($status, $output) = $this->migrate(array());
        self::assertSame(0, $status);
        self::assertSame($expected, $this->files());
        self::assertSame(
            "903.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "904.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "905.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "0 migrated, 0 completed (legacy file deleted), 0 verified (legacy file kept), 3 skipped, 0 failed\n",
            $output
        );
    }

    public function testKeepLegacyThenComplete()
    {
        $this->put('901.aes', $this->legacyPdf());

        list($status, $output) = $this->migrate(array('--keep-legacy'));
        self::assertSame(0, $status);
        self::assertSame("901.aes: migrated (voucher 4711); 901.aes kept\n"
            . "1 migrated, 0 completed (legacy file deleted), 0 verified (legacy file kept), 0 skipped, 0 failed\n", $output);
        self::assertSame(array('901.aes' => $this->legacyPdf(), '901.v2.aes' => $this->configuredPdf()), $this->files());

        list($status, $output) = $this->migrate(array('--keep-legacy'));
        self::assertSame(0, $status);
        self::assertSame("901.aes: already migrated (voucher 4711); 901.aes kept\n"
            . "0 migrated, 0 completed (legacy file deleted), 1 verified (legacy file kept), 0 skipped, 0 failed\n", $output);

        // Both files present, as after an interrupted run: checked, then the legacy file goes.
        list($status, $output) = $this->migrate(array('--dry-run'));
        self::assertSame(0, $status);
        self::assertSame("901.aes: already migrated (voucher 4711); would delete 901.aes\n"
            . "Dry run, nothing written: 0 migrated, 1 completed (legacy file deleted), "
            . "0 verified (legacy file kept), 0 skipped, 0 failed\n", $output);
        self::assertSame(array('901.aes' => $this->legacyPdf(), '901.v2.aes' => $this->configuredPdf()), $this->files());

        list($status, $output) = $this->migrate(array());
        self::assertSame(0, $status);
        self::assertSame("901.aes: already migrated (voucher 4711); deleted 901.aes\n"
            . "0 migrated, 1 completed (legacy file deleted), 0 verified (legacy file kept), 0 skipped, 0 failed\n", $output);
        self::assertSame(array('901.v2.aes' => $this->configuredPdf()), $this->files());
    }

    public function testFailuresKeepTheLegacyFile()
    {
        // 901: both present, but <n>.v2.aes holds other content.
        $this->put('901.aes', $this->legacyPdf());
        $this->put('901.v2.aes', receipt_encrypt('4711', self::TEST_KEY, '%PDF other'));
        // 902: the new file cannot be written (a directory has the temporary name).
        $jpeg = self::vector('jpeg with trailing NUL bytes');
        $this->put('902.aes', hex2bin($jpeg['cipher']));
        mkdir($this->directory . '/files/902.v2.aes.tmp');
        // 99999999999: not an integer file number, so the voucher query fails.
        $this->put('99999999999.aes', $this->legacyPdf());
        $before = $this->files();

        list($status, $output, $errors) = $this->migrate(array());
        self::assertSame(1, $status);
        self::assertSame('', $errors);
        self::assertStringStartsWith(
            "901.aes: 901.v2.aes exists but does not match 901.aes; both left as they are\n"
            . "902.aes: 902.v2.aes could not be written or verified; 902.aes left as it is\n"
            . "99999999999.aes: database query failed: ",
            $output
        );
        self::assertStringEndsWith(
            "0 migrated, 0 completed (legacy file deleted), 0 verified (legacy file kept), 0 skipped, 3 failed\n",
            $output
        );
        self::assertSame($before, $this->files());
    }

    public function testLimit()
    {
        foreach (array('909', '910', '911') as $number) {
            $this->put("$number.aes", $this->legacyPdf());
        }

        list($status, $output) = $this->migrate(array('--limit=2'));
        self::assertSame(0, $status);
        self::assertSame(
            "909.aes: migrated (voucher 4711); deleted 909.aes\n"
            . "910.aes: migrated (voucher 4711); deleted 910.aes\n"
            . "Stopped after --limit=2; run again for the remaining files.\n"
            . "2 migrated, 0 completed (legacy file deleted), 0 verified (legacy file kept), 0 skipped, 0 failed\n",
            $output
        );
        self::assertSame(array('909.v2.aes', '910.v2.aes', '911.aes'), array_keys($this->files()));

        list($status, $output) = $this->migrate(array('--limit=2'));
        self::assertSame(0, $status);
        self::assertSame("911.aes: migrated (voucher 4711); deleted 911.aes\n"
            . "1 migrated, 0 completed (legacy file deleted), 0 verified (legacy file kept), 0 skipped, 0 failed\n", $output);
        self::assertSame(array('909.v2.aes', '910.v2.aes', '911.v2.aes'), array_keys($this->files()));
    }

    public function testRefusesToRun()
    {
        $this->put('901.aes', $this->legacyPdf());
        $before = $this->files();
        $usage = "Usage: php migrate-receipt-key.php [--dry-run] [--limit=N] [--keep-legacy]\n"
            . "       [--files=DIR] [--constants=FILE] [--database=CONNINFO]\n";
        foreach (array('--limit=0', '--limit=x', '--limit=', '--verbose', 'files', '--files=') as $argument) {
            self::assertSame(array(2, '', $usage), $this->migrate(array($argument)), $argument);
        }

        $constants = $this->directory . '/constants.php';
        $cases = array(
            "<?php\n\$datakey = '';\n" => 'no $key',
            "<?php\n\$key = '';\n" => 'empty $key',
            "<?php\n\$key = 12345;\n" => 'not a string',
        );
        foreach ($cases as $content => $label) {
            file_put_contents($constants, $content);
            self::assertSame(
                array(2, '', "No receipt key: \$key is not set in $constants. Nothing was changed.\n"),
                $this->migrate(array()),
                $label
            );
        }
        $missing = $this->directory . '/missing.php';
        self::assertSame(
            array(2, '', "No receipt key: \$key is not set in $missing. Nothing was changed.\n"),
            $this->migrate(array('--constants=' . $missing))
        );

        file_put_contents($constants, "<?php\n\$key = '" . self::TEST_KEY . "';\n");
        $directory = $this->directory . '/no-such-directory';
        self::assertSame(
            array(2, '', "No receipt directory $directory. Nothing was changed.\n"),
            $this->migrate(array('--files=' . $directory))
        );
        self::assertSame(
            array(2, '', "Cannot connect to the database. Nothing was changed.\n"),
            $this->migrate(array('--database=host=/nonexistent-socket-directory dbname=accounting connect_timeout=2'))
        );
        self::assertSame($before, $this->files());
    }

    public function testCommandLine()
    {
        // The script as a command: default constants.php (the test constants
        // in the test images) and database; the fixture voucher 1003 has file 42.
        $script = dirname(dirname(__DIR__)) . '/migrate-receipt-key.php';
        $files = $this->directory . '/files';
        $this->put('42.aes', receipt_encrypt('1003', '', '%PDF synthetic command-line receipt'));
        $command = function ($arguments) use ($script) {
            $descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
            $process = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $arguments,
                $descriptors, $pipes);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return array(proc_close($process), $output, $errors);
        };

        self::assertSame(array(2, '', "No receipt key: \$key is not set in {$this->directory}/missing.php. Nothing was changed.\n"),
            $command('--constants=' . escapeshellarg($this->directory . '/missing.php')));

        list($status, $output, $errors) = $command('--files=' . escapeshellarg($files));
        self::assertSame(array(0, ''), array($status, $errors));
        self::assertSame("42.aes: migrated (voucher 1003); deleted 42.aes\n"
            . "1 migrated, 0 completed (legacy file deleted), 0 verified (legacy file kept), 0 skipped, 0 failed\n", $output);
        self::assertSame(
            array('42.v2.aes' => receipt_encrypt('1003', self::TEST_KEY, '%PDF synthetic command-line receipt')),
            $this->files()
        );
    }
}
