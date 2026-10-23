<?php
/*
 * The receipt key migration (migrate-receipt-key.php): legacy files/<n>.aes
 * re-encrypted as files/<n>.v2.aes in two phases, on synthetic files in a
 * temporary directory. Most cases call receipt_migration_main() in-process
 * with the test connection string, so pg_connect() returns the test's link
 * and sees its rolled-back voucher rows; one case runs the script as a
 * command against the fixture voucher 1003 (file 42).
 *
 * Must stay compatible with PHP 5.6 (PHPUnit 5.7).
 */

require_once dirname(dirname(__DIR__)) . '/migrate-receipt-key.php';

final class ReceiptMigrationTest extends FunctionsTestCase
{
    const TEST_KEY = 'synthetic-test-only-encryption-key';
    const OTHER_KEY = 'another-synthetic-receipt-key';

    private $directory;
    private $connection;

    /**
     * @before
     */
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
        // two vouchers and was encrypted for the second, 909-911 (4711).
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

    private function writeConstants($content)
    {
        file_put_contents($this->directory . '/constants.php', $content);
    }

    private static function fingerprint($key)
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/receipt-v2/vectors.json'), true);
        return $fixture['fingerprints'][$key];
    }

    private static function legacyVector($name)
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

    // Name => bytes ('directory' for a directory) of the receipt directory.
    private function files()
    {
        $files = array();
        foreach (array_diff(scandir($this->directory . '/files'), array('.', '..')) as $name) {
            $path = $this->directory . '/files/' . $name;
            $files[$name] = is_dir($path) ? 'directory' : file_get_contents($path);
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function v2Plain($number)
    {
        return receipt_v2_decrypt(self::TEST_KEY, $number, file_get_contents($this->directory . "/files/$number.v2.aes"));
    }

    // array(exit status, output, errors) of receipt_migration_main().
    private function migrate($arguments, $defaults = true)
    {
        if ($defaults) {
            $arguments = array_merge(array(
                '--files=' . $this->directory . '/files',
                '--constants=' . $this->directory . '/constants.php',
                '--database=' . pgacc_test_connection_string(),
            ), $arguments);
        }
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

    private static function summary($counts, $dryRun = false, $key = self::TEST_KEY)
    {
        return ($dryRun ? 'Dry run, nothing written: ' : '')
            . "{$counts[0]} migrated, {$counts[1]} already migrated, {$counts[2]} legacy deleted, "
            . "{$counts[3]} not yet migrated, {$counts[4]} skipped, {$counts[5]} failed; "
            . 'key fingerprint ' . self::fingerprint($key) . "\n";
    }

    private function legacyPdf()
    {
        $vector = self::legacyVector('pdf document');
        self::assertSame(array('4711', ''), array($vector['id'], $vector['key']));
        return hex2bin($vector['cipher']);
    }

    private static function paddedPdf()
    {
        return hex2bin(self::legacyVector('pdf document')['decrypted']);
    }

    public function testTheCommandLineGuardIsTheFirstStatement()
    {
        $tokens = token_get_all(file_get_contents(dirname(dirname(__DIR__)) . '/migrate-receipt-key.php'));
        $code = '';
        foreach ($tokens as $index => $token) {
            if ($index === 0) {
                self::assertSame(T_OPEN_TAG, $token[0]);
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
            if (strlen($code) > 80) {
                break;
            }
        }
        self::assertStringStartsWith("if (PHP_SAPI !== 'cli') {\n    header('HTTP/1.0 404 Not Found');\n    exit;\n}\n", $code);
    }

    public function testSizesAndDefaultLimit()
    {
        self::assertSame(134217728, receipt_migration_bytes('128M'));
        self::assertSame(134217728, receipt_migration_bytes(' 128m '));
        self::assertSame(1073741824, receipt_migration_bytes('1G'));
        self::assertSame(524288, receipt_migration_bytes('512K'));
        self::assertSame(1048576, receipt_migration_bytes('1048576'));
        self::assertSame(-1, receipt_migration_bytes('-1'));
        $previous = ini_get('memory_limit');
        try {
            // Above the coverage run's memory use.
            ini_set('memory_limit', '8G');
            self::assertSame(1073741824, receipt_migration_default_max_size());
            ini_set('memory_limit', '-1');
            self::assertSame(0, receipt_migration_default_max_size());
        } catch (Exception $exception) {
            ini_set('memory_limit', $previous);
            throw $exception;
        }
        ini_set('memory_limit', $previous);
    }

    public function testMigratesLegacyReceiptsAndSkipsTheRest()
    {
        $jpeg = self::legacyVector('jpeg with trailing NUL bytes');
        $this->put('901.aes', $this->legacyPdf());
        $this->put('902.aes', hex2bin($jpeg['cipher']));
        $this->put('903.aes', $this->legacyPdf());
        $this->put('904.aes', receipt_encrypt('4711', '', "\x89PNG\r\n\x1a\nsynthetic"));
        $this->put('905.aes', $this->legacyPdf());
        $this->put('906.aes', receipt_encrypt('4711', '', '%PDF' . str_repeat('x', 4000)));
        $this->put('907.v2.aes', 'new receipt, not touched');
        $this->put('908.aes', $this->legacyPdf());
        $this->put('notes.txt', 'not a receipt');
        $this->put('907.v2.aes.tmp-1-2', 'left by a failed write');
        $before = $this->files();
        $skippedLines = "903.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "904.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "905.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "906.aes: not migrated: 4032 bytes, above --max-size=4000\n";

        list($status, $output, $errors) = $this->migrate(array('--dry-run', '--max-size=4000'));
        self::assertSame(array(0, ''), array($status, $errors));
        // Nothing written, not even the lock file.
        self::assertSame($before, $this->files());
        self::assertSame(
            "901.aes: would migrate (voucher 4711)\n"
            . "902.aes: would migrate (voucher 815)\n"
            . $skippedLines
            . "908.aes: would migrate (voucher 4711)\n"
            . self::summary(array(3, 0, 0, 0, 4, 0), true),
            $output
        );

        list($status, $output, $errors) = $this->migrate(array('--max-size=4000'));
        self::assertSame(array(0, ''), array($status, $errors));
        self::assertSame(
            "901.aes: migrated (voucher 4711); 901.aes kept until --delete-legacy\n"
            . "902.aes: migrated (voucher 815); 902.aes kept until --delete-legacy\n"
            . $skippedLines
            . "908.aes: migrated (voucher 4711); 908.aes kept until --delete-legacy\n"
            . self::summary(array(3, 0, 0, 0, 4, 0)),
            $output
        );
        $files = $this->files();
        $names = array_merge(array_keys($before), array('.migrate-receipt-key.lock', '901.v2.aes', '902.v2.aes', '908.v2.aes'));
        sort($names, SORT_STRING);
        self::assertSame($names, array_keys($files));
        foreach ($before as $name => $bytes) {
            self::assertSame($bytes, $files[$name], $name);
        }
        // The downloads stay byte-identical: the padded legacy plain text.
        self::assertSame(self::paddedPdf(), $this->v2Plain('901'));
        self::assertSame(hex2bin($jpeg['decrypted']), $this->v2Plain('902'));
        self::assertSame(self::paddedPdf(), $this->v2Plain('908'));

        // Idempotent, and the size limit can be raised.
        list($status, $output) = $this->migrate(array());
        self::assertSame(0, $status);
        self::assertSame(
            "901.aes: already migrated; 901.aes kept until --delete-legacy\n"
            . "902.aes: already migrated; 902.aes kept until --delete-legacy\n"
            . "903.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "904.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "905.aes: not migrated: no voucher with this file decrypts it to a PDF or JPEG\n"
            . "906.aes: migrated (voucher 4711); 906.aes kept until --delete-legacy\n"
            . "908.aes: already migrated; 908.aes kept until --delete-legacy\n"
            . self::summary(array(1, 3, 0, 0, 3, 0)),
            $output
        );
    }

    public function testDeleteLegacyPhase()
    {
        $this->put('901.aes', $this->legacyPdf());
        $this->put('902.aes', hex2bin(self::legacyVector('jpeg with trailing NUL bytes')['cipher']));
        $this->put('908.aes', $this->legacyPdf());
        self::assertSame(0, $this->migrate(array('--limit=2'))[0]);
        // 908 is not migrated yet; 902.v2.aes is replaced by other content.
        $this->put('902.v2.aes', receipt_v2_encrypt(self::TEST_KEY, '902', '%PDF other content'));
        $before = $this->files();

        list($status, $output) = $this->migrate(array('--delete-legacy', '--dry-run'));
        self::assertSame(1, $status);
        self::assertSame(
            "901.aes: verified; would delete 901.aes\n"
            . "902.aes: 902.v2.aes does not decrypt to the content of 902.aes with this key; both kept\n"
            . "908.aes: not migrated yet; 908.aes kept\n"
            . self::summary(array(0, 0, 1, 1, 0, 1), true),
            $output
        );
        self::assertSame($before, $this->files());

        list($status, $output) = $this->migrate(array('--delete-legacy'));
        self::assertSame(1, $status);
        self::assertSame(
            "901.aes: verified; deleted 901.aes\n"
            . "902.aes: 902.v2.aes does not decrypt to the content of 902.aes with this key; both kept\n"
            . "908.aes: not migrated yet; 908.aes kept\n"
            . self::summary(array(0, 0, 1, 1, 0, 1)),
            $output
        );
        $expected = $before;
        unset($expected['901.aes']);
        self::assertSame($expected, $this->files());
        self::assertSame(self::paddedPdf(), $this->v2Plain('901'));

        // With another key nothing verifies, so nothing is deleted.
        unlink($this->directory . '/files/902.v2.aes');
        self::assertSame(0, $this->migrate(array())[0]);
        $this->writeConstants("<?php\n\$key = '" . self::OTHER_KEY . "';\n");
        list($status, $output) = $this->migrate(array('--delete-legacy'));
        self::assertSame(1, $status);
        self::assertSame(
            "902.aes: 902.v2.aes does not decrypt to the content of 902.aes with this key; both kept\n"
            . "908.aes: 908.v2.aes does not decrypt to the content of 908.aes with this key; both kept\n"
            . self::summary(array(0, 0, 0, 0, 0, 2), false, self::OTHER_KEY),
            $output
        );
        $this->writeConstants("<?php\n\$key = '" . self::TEST_KEY . "';\n");
        list($status, $output) = $this->migrate(array('--delete-legacy', '--limit=1'));
        self::assertSame(0, $status);
        self::assertSame(
            "902.aes: verified; deleted 902.aes\n"
            . "Stopped after --limit=1; run again for the remaining files.\n"
            . self::summary(array(0, 0, 1, 0, 0, 0)),
            $output
        );
        self::assertSame(array('.migrate-receipt-key.lock', '901.v2.aes', '902.v2.aes', '908.aes', '908.v2.aes'),
            array_keys($this->files()));
    }

    public function testFailuresKeepEveryFile()
    {
        // 901: <n>.v2.aes is a directory, so it cannot be written; the
        // directory is not removed.
        $this->put('901.aes', $this->legacyPdf());
        mkdir($this->directory . '/files/901.v2.aes');
        // 99999999999: not an integer file number, so the voucher query fails.
        $this->put('99999999999.aes', $this->legacyPdf());
        $before = $this->files();

        list($status, $output, $errors) = $this->migrate(array());
        self::assertSame(array(1, ''), array($status, $errors));
        self::assertStringStartsWith(
            "901.aes: 901.v2.aes could not be written; 901.aes kept\n"
            . "99999999999.aes: database query failed: ",
            $output
        );
        self::assertStringEndsWith(self::summary(array(0, 0, 0, 0, 0, 2)), $output);
        $files = $this->files();
        unset($files['.migrate-receipt-key.lock']);
        self::assertSame($before, $files);
    }

    public function testLimit()
    {
        foreach (array('909', '910', '911') as $number) {
            $this->put("$number.aes", $this->legacyPdf());
        }

        list($status, $output) = $this->migrate(array('--limit=2'));
        self::assertSame(0, $status);
        self::assertSame(
            "909.aes: migrated (voucher 4711); 909.aes kept until --delete-legacy\n"
            . "910.aes: migrated (voucher 4711); 910.aes kept until --delete-legacy\n"
            . "Stopped after --limit=2; run again for the remaining files.\n"
            . self::summary(array(2, 0, 0, 0, 0, 0)),
            $output
        );
        self::assertFalse(is_file($this->directory . '/files/911.v2.aes'));
    }

    public function testOneRunAtATime()
    {
        $this->put('901.aes', $this->legacyPdf());
        $lockPath = $this->directory . '/files/.migrate-receipt-key.lock';
        $lock = fopen($lockPath, 'c');
        self::assertTrue(flock($lock, LOCK_EX));
        $message = "Another migration is running, or $lockPath cannot be opened. Nothing was changed.\n";
        self::assertSame(array(2, '', $message), $this->migrate(array()));
        self::assertSame(array(2, '', $message), $this->migrate(array('--delete-legacy')));
        // A dry run does not lock.
        self::assertSame(0, $this->migrate(array('--dry-run'))[0]);
        fclose($lock);
        self::assertFalse(is_file($this->directory . '/files/901.v2.aes'));

        // The lock is released after each run, also after a refused one.
        self::assertSame(2, $this->migrate(array('--database=host=/nonexistent-socket-directory connect_timeout=2'))[0]);
        self::assertSame(0, $this->migrate(array())[0]);
        self::assertSame(0, $this->migrate(array())[0]);

        unlink($lockPath);
        mkdir($lockPath);
        self::assertSame(array(2, '', $message), $this->migrate(array()));
    }

    public function testRefusesToRun()
    {
        $this->put('901.aes', $this->legacyPdf());
        $before = $this->files();
        $usage = "Usage: php migrate-receipt-key.php [--dry-run] [--delete-legacy] [--limit=N]\n"
            . "       [--max-size=BYTES] [--files=DIR] [--constants=FILE] [--database=CONNINFO]\n";
        $arguments = array('--limit=0', '--limit=x', '--limit=', '--max-size=0', '--max-size=1k', '--keep-legacy',
            'files', '--files=');
        foreach ($arguments as $argument) {
            self::assertSame(array(2, '', $usage), $this->migrate(array($argument)), $argument);
        }

        $constants = $this->directory . '/constants.php';
        $keys = array(
            "<?php\n\$datakey = '';\n" => 'no $key',
            "<?php\n\$key = '';\n" => 'empty $key',
            "<?php\n\$key = 'CHANGE-ME';\n" => 'the placeholder',
            "<?php\n\$key = 'CHANGE-ME-CHANGE-ME-CHANGE-ME';\n" => 'a longer placeholder',
            "<?php\n\$key = '0123456789abcde';\n" => '15 characters',
            "<?php\n\$key = 1234567890123456789;\n" => 'not a string',
        );
        $message = "No valid receipt key: \$key in $constants must have at least 16 characters "
            . "and not be the CHANGE-ME placeholder. Nothing was changed.\n";
        foreach ($keys as $content => $label) {
            $this->writeConstants($content);
            self::assertSame(array(2, '', $message), $this->migrate(array()), $label);
        }
        $missing = $this->directory . '/missing.php';
        self::assertSame(
            array(2, '', str_replace($constants, $missing, $message)),
            $this->migrate(array('--constants=' . $missing))
        );

        $this->writeConstants("<?php\n\$key = '" . self::TEST_KEY . "';\n");
        $directory = $this->directory . '/no-such-directory';
        self::assertSame(
            array(2, '', "Cannot read the receipt directory $directory. Nothing was changed.\n"),
            $this->migrate(array('--files=' . $directory))
        );
        self::assertSame(
            array(2, '', "Cannot connect to the database. Nothing was changed.\n"),
            $this->migrate(array('--database=host=/nonexistent-socket-directory connect_timeout=2'))
        );
        $files = $this->files();
        unset($files['.migrate-receipt-key.lock']);
        self::assertSame($before, $files);
    }

    public function testReadsTheKeyLikeTheWebApplication()
    {
        // Without --constants, constants.php is included from the application
        // directory (the test images install tests/runtime/test-constants.php
        // there), not from the working directory.
        $this->put('901.aes', $this->legacyPdf());
        $this->writeConstants("<?php\n\$key = '" . self::OTHER_KEY . "';\n");
        $workingDirectory = getcwd();
        chdir($this->directory);
        try {
            list($status, $output) = $this->migrate(array(
                '--files=' . $this->directory . '/files',
                '--database=' . pgacc_test_connection_string(),
                '--dry-run',
            ), false);
        } catch (Exception $exception) {
            chdir($workingDirectory);
            throw $exception;
        }
        self::assertSame($this->directory, getcwd());
        chdir($workingDirectory);
        self::assertSame(0, $status);
        self::assertStringEndsWith('key fingerprint ' . self::fingerprint(self::TEST_KEY) . "\n", $output);
    }

    public function testCommandLine()
    {
        // The script as a command, with the default constants.php and
        // database; the fixture voucher 1003 has file 42.
        $script = dirname(dirname(__DIR__)) . '/migrate-receipt-key.php';
        $files = $this->directory . '/files';
        $this->put('42.aes', receipt_encrypt('1003', '', '%PDF synthetic command-line receipt'));
        $plain = receipt_decrypt('1003', '', receipt_encrypt('1003', '', '%PDF synthetic command-line receipt'));
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
        $fingerprint = self::fingerprint(self::TEST_KEY);

        self::assertSame(array(2, '', "Cannot read the receipt directory {$this->directory}/missing. Nothing was changed.\n"),
            $command('--files=' . escapeshellarg($this->directory . '/missing')));

        self::assertSame(array(0, "42.aes: migrated (voucher 1003); 42.aes kept until --delete-legacy\n"
            . "1 migrated, 0 already migrated, 0 legacy deleted, 0 not yet migrated, 0 skipped, 0 failed; "
            . "key fingerprint $fingerprint\n", ''), $command('--files=' . escapeshellarg($files)));
        self::assertSame(array(0, "42.aes: verified; deleted 42.aes\n"
            . "0 migrated, 0 already migrated, 1 legacy deleted, 0 not yet migrated, 0 skipped, 0 failed; "
            . "key fingerprint $fingerprint\n", ''), $command('--delete-legacy --files=' . escapeshellarg($files)));
        self::assertSame(array('.migrate-receipt-key.lock', '42.v2.aes'), array_keys($this->files()));
        self::assertSame($plain, $this->v2Plain('42'));
    }
}
