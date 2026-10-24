<?php
/*
 * The receipt key migration (migrate-receipt-key.php): legacy files/<n>.aes
 * re-encrypted as files/<n>.v2.aes in two phases, on synthetic files in a
 * temporary directory. The cases call receipt_migration_main() in-process
 * with the test connection string, so pg_connect() returns the test's link
 * and sees its rolled-back voucher rows. The script as a command:
 * ReceiptMigrationCommandTest.
 *
 * Runs on PHP 5.3 (PHPUnit 4.8) too: no PHP 5.4+ syntax or functions, no
 * finally.
 */

require_once __DIR__ . '/ReceiptMigrationTestCase.php';

final class ReceiptMigrationTest extends ReceiptMigrationTestCase
{
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
            if (strlen($code) > 300) {
                break;
            }
        }
        self::assertStringStartsWith("if (PHP_SAPI !== 'cli') {\n"
            . "    // A web request gets a 404; php-cgi run from a shell gets a message.\n"
            . "    isset(\$_SERVER['REQUEST_METHOD']) ? header('HTTP/1.0 404 Not Found') : fwrite(fopen('php://stderr', 'w'), "
            . "\"migrate-receipt-key.php must be run with the php command-line binary.\\n\");\n"
            . "    exit(1);\n"
            . "}\n", $code);
    }

    public function testRefusesOtherServerApisFromAShell()
    {
        // php-cgi run from a shell (no web request): a message and status 1,
        // nothing migrated. Only images with php-cgi (the PHP 5.3 lane) run it.
        exec('command -v php-cgi', $found, $missing);
        if ($missing !== 0) {
            self::markTestSkipped('php-cgi is not installed');
        }
        $this->put('901.aes', $this->legacyPdf());
        $before = $this->files();
        $process = proc_open('php-cgi -q ' . escapeshellarg(dirname(dirname(__DIR__)) . '/migrate-receipt-key.php')
            . ' --files=' . escapeshellarg($this->directory . '/files'),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(array(1, '', "migrate-receipt-key.php must be run with the php command-line binary.\n"),
            array(proc_close($process), $output, $errors));
        self::assertSame($before, $this->files());
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
            ini_set('memory_limit', '1G');
            self::assertSame(134217728, receipt_migration_default_max_size());
            ini_set('memory_limit', '-1');
            self::assertSame(0, receipt_migration_default_max_size());
        } catch (Exception $exception) {
            ini_set('memory_limit', $previous);
            throw $exception;
        }
        ini_set('memory_limit', $previous);
    }

    public function testCompleteReceipts()
    {
        $pdf = "%PDF-1.4\nsynthetic\n%%EOF\n";
        self::assertTrue(receipt_migration_is_complete(rijndael256_zero_pad($pdf)));
        // %%EOF near the end, also before whitespace or an incremental update.
        self::assertTrue(receipt_migration_is_complete(rijndael256_zero_pad("%PDF-1.4\n%%EOF\r\n" . str_repeat(' ', 900))));
        self::assertFalse(receipt_migration_is_complete(rijndael256_zero_pad("%PDF-1.4\n%%EOF\n" . str_repeat(' ', 1100))));
        self::assertFalse(receipt_migration_is_complete(rijndael256_zero_pad("%PDF-1.4\nno end marker\n")));
        self::assertFalse(receipt_migration_is_complete(rijndael256_zero_pad("%PDF-1.4\n%%EO")));
        $jpeg = "\xFF\xD8\xFF\xE0synthetic\xFF\xD9";
        self::assertTrue(receipt_migration_is_complete(rijndael256_zero_pad($jpeg)));
        self::assertTrue(receipt_migration_is_complete(rijndael256_zero_pad($jpeg . "\0\0")));
        self::assertFalse(receipt_migration_is_complete(rijndael256_zero_pad("\xFF\xD8\xFF\xE0synthetic")));
        self::assertFalse(receipt_migration_is_complete(rijndael256_zero_pad("\xFF\xD8\xFF\xE0synthetic\xFF")));
        // Decrypted legacy files are whole 32-byte blocks with less than one
        // block of zero padding.
        self::assertFalse(receipt_migration_is_complete($pdf . str_repeat("\0", 32 + 32 - strlen($pdf) % 32)));
        self::assertFalse(receipt_migration_is_complete($pdf));
        self::assertTrue(receipt_migration_is_complete(str_pad($pdf, 32, ' ')));
        self::assertFalse(receipt_migration_is_complete(rijndael256_zero_pad("\x89PNG\r\n\x1a\n%%EOF\xFF\xD9")));
        self::assertFalse(receipt_migration_is_complete(''));
    }

    public function testMigratesLegacyReceiptsAndSkipsTheRest()
    {
        $jpeg = self::legacyVector('jpeg with trailing NUL bytes');
        $this->put('901.aes', $this->legacyPdf());
        $this->put('902.aes', hex2bin($jpeg['cipher']));
        $this->put('903.aes', $this->legacyPdf());
        $this->put('904.aes', receipt_encrypt('4711', '', "\x89PNG\r\n\x1a\nsynthetic"));
        $this->put('905.aes', $this->legacyPdf());
        $this->put('906.aes', receipt_encrypt('4711', '', '%PDF' . str_repeat('x', 4000) . "\n%%EOF\n"));
        $this->put('907.v2.aes', 'new receipt, not touched');
        $this->put('908.aes', $this->legacyPdf());
        $this->put('912.aes', receipt_encrypt('4711', '', "%PDF-1.4\nsynthetic, but cut off"));
        // Not file numbers: reported, not migrated.
        foreach (array('0.aes', '007.aes', '2147483648.aes', 'x.aes') as $name) {
            $this->put($name, $this->legacyPdf());
        }
        $this->put('notes.txt', 'not a receipt');
        $this->put('907.v2.aes.tmp-1-2', 'left by a failed write');
        $before = $this->files();
        $otherNames = "0.aes: not migrated: not a file number\n"
            . "007.aes: not migrated: not a file number\n"
            . "2147483648.aes: not migrated: not a file number\n"
            . "x.aes: not migrated: not a file number\n";
        $noVoucher = 'not migrated: no voucher with this file decrypts it to a complete PDF or JPEG';
        $skippedLines = "903.aes: $noVoucher\n"
            . "904.aes: $noVoucher\n"
            . "905.aes: $noVoucher\n"
            . "906.aes: not migrated: 4032 bytes, above --max-size=4000\n";

        list($status, $output, $errors) = $this->migrate(array('--dry-run', '--max-size=4000'));
        self::assertSame(array(0, ''), array($status, $errors));
        // Nothing written, not even the lock file.
        self::assertSame($before, $this->files());
        self::assertSame(
            $otherNames
            . "901.aes: would migrate (voucher 4711)\n"
            . "902.aes: would migrate (voucher 815)\n"
            . $skippedLines
            . "908.aes: would migrate (voucher 4711)\n"
            . "912.aes: $noVoucher\n"
            . self::summary(array(3, 0, 0, 0, 9, 0), true),
            $output
        );

        list($status, $output, $errors) = $this->migrate(array('--max-size=4000'));
        self::assertSame(array(0, ''), array($status, $errors));
        self::assertSame(
            $otherNames
            . "901.aes: migrated (voucher 4711); 901.aes kept until --delete-legacy\n"
            . "902.aes: migrated (voucher 815); 902.aes kept until --delete-legacy\n"
            . $skippedLines
            . "908.aes: migrated (voucher 4711); 908.aes kept until --delete-legacy\n"
            . "912.aes: $noVoucher\n"
            . self::summary(array(3, 0, 0, 0, 9, 0)),
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
            $otherNames
            . "901.aes: already migrated; 901.aes kept until --delete-legacy\n"
            . "902.aes: already migrated; 902.aes kept until --delete-legacy\n"
            . "903.aes: $noVoucher\n"
            . "904.aes: $noVoucher\n"
            . "905.aes: $noVoucher\n"
            . "906.aes: migrated (voucher 4711); 906.aes kept until --delete-legacy\n"
            . "908.aes: already migrated; 908.aes kept until --delete-legacy\n"
            . "912.aes: $noVoucher\n"
            . self::summary(array(1, 3, 0, 0, 8, 0)),
            $output
        );
    }

    public function testDeleteLegacyPhase()
    {
        $jpeg = self::legacyVector('jpeg with trailing NUL bytes');
        $this->put('901.aes', $this->legacyPdf());
        $this->put('902.aes', hex2bin($jpeg['cipher']));
        $this->put('908.aes', $this->legacyPdf());
        self::assertSame(0, $this->status(array('--limit=2')));
        // 908 is not migrated yet; 902.v2.aes is replaced by other content.
        $this->put('902.v2.aes', receipt_v2_encrypt(self::TEST_KEY, '902', '%PDF other content'));
        $before = $this->files();

        list($status, $output) = $this->migrate(array('--delete-legacy', '--dry-run'));
        self::assertSame(1, $status);
        self::assertSame(
            self::deleteReminder()
            . "901.aes: verified; would delete 901.aes\n"
            . "902.aes: 902.v2.aes does not decrypt to the content of 902.aes with this key; both kept\n"
            . "908.aes: not migrated yet; 908.aes kept\n"
            . self::summary(array(0, 0, 1, 1, 0, 1), true),
            $output
        );
        self::assertSame($before, $this->files());

        list($status, $output) = $this->migrate(array('--delete-legacy'));
        self::assertSame(1, $status);
        self::assertSame(
            self::deleteReminder()
            . "901.aes: verified; deleted 901.aes\n"
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
        self::assertSame(0, $this->status(array()));
        $this->writeConstants("<?php\n\$key = '" . self::OTHER_KEY . "';\n");
        list($status, $output) = $this->migrate(array('--delete-legacy'));
        self::assertSame(1, $status);
        self::assertSame(
            self::deleteReminder()
            . "902.aes: 902.v2.aes does not decrypt to the content of 902.aes with this key; both kept\n"
            . "908.aes: 908.v2.aes does not decrypt to the content of 908.aes with this key; both kept\n"
            . self::summary(array(0, 0, 0, 0, 0, 2), false, self::OTHER_KEY),
            $output
        );
        $this->writeConstants("<?php\n\$key = '" . self::TEST_KEY . "';\n");
        list($status, $output) = $this->migrate(array('--delete-legacy', '--limit=1'));
        self::assertSame(0, $status);
        self::assertSame(
            self::deleteReminder()
            . "902.aes: verified; deleted 902.aes\n"
            . "Stopped after --limit=1; run again for the remaining files.\n"
            . self::summary(array(0, 0, 1, 0, 0, 0)),
            $output
        );
        self::assertSame(array('.migrate-receipt-key.lock', '901.v2.aes', '902.v2.aes', '908.aes', '908.v2.aes'),
            array_keys($this->files()));
    }

    public function testExistingV2FilesAreVerified()
    {
        // 901: an invalid <n>.v2.aes and a good legacy file: re-created.
        // 902: a valid <n>.v2.aes: left alone. 903: an invalid <n>.v2.aes and
        // a legacy file that does not decrypt: reported, both kept.
        $jpeg = self::legacyVector('jpeg with trailing NUL bytes');
        $this->put('901.aes', $this->legacyPdf());
        $this->put('901.v2.aes', 'not a v2 file');
        $this->put('902.aes', hex2bin($jpeg['cipher']));
        $this->put('902.v2.aes', receipt_v2_encrypt(self::TEST_KEY, '902', hex2bin($jpeg['decrypted'])));
        $this->put('903.aes', $this->legacyPdf());
        $this->put('903.v2.aes', 'not a v2 file either');
        $before = $this->files();
        $lines = "902.aes: already migrated; 902.aes kept until --delete-legacy\n"
            . "903.aes: 903.v2.aes does not verify, and 903.aes cannot replace it (no voucher with this file decrypts it "
            . "to a complete PDF or JPEG); both kept\n";

        list($status, $output) = $this->migrate(array('--dry-run'));
        self::assertSame(1, $status);
        self::assertSame("901.aes: 901.v2.aes does not verify; would re-create it (voucher 4711)\n" . $lines
            . self::summary(array(1, 1, 0, 0, 0, 1), true), $output);
        self::assertSame($before, $this->files());

        list($status, $output) = $this->migrate(array());
        self::assertSame(1, $status);
        self::assertSame("901.aes: 901.v2.aes does not verify; re-created it (voucher 4711); 901.aes kept until --delete-legacy\n"
            . $lines . self::summary(array(1, 1, 0, 0, 0, 1)), $output);
        self::assertSame(self::paddedPdf(), $this->v2Plain('901'));
        $files = $this->files();
        unset($files['.migrate-receipt-key.lock'], $files['901.v2.aes'], $before['901.v2.aes']);
        // Nothing deleted in the first phase.
        self::assertSame($before, $files);
    }

    public function testDeleteLegacyFlushesFirst()
    {
        $this->put('901.aes', $this->legacyPdf());
        self::assertSame(0, $this->status(array()));
        $before = $this->files();
        self::assertSame(
            array(2, '', "Could not flush the receipt files to disk with `false`; nothing was deleted.\n"),
            $this->migrate(array('--delete-legacy', '--sync=false'))
        );
        self::assertSame($before, $this->files());
        // A dry run deletes nothing, so it does not flush.
        self::assertSame(0, $this->status(array('--delete-legacy', '--sync=false', '--dry-run')));
        list($status, $output) = $this->migrate(array('--delete-legacy', '--sync=sync'));
        self::assertSame(0, $status);
        self::assertStringEndsWith("901.aes: verified; deleted 901.aes\n" . self::summary(array(0, 0, 1, 0, 0, 0)), $output);
    }

    public function testFailuresKeepEveryFile()
    {
        // 901: <n>.v2.aes is a directory, so it cannot be written; the
        // directory is not removed.
        $this->put('901.aes', $this->legacyPdf());
        mkdir($this->directory . '/files/901.v2.aes');
        $before = $this->files();

        list($status, $output, $errors) = $this->migrate(array());
        self::assertSame(array(1, ''), array($status, $errors));
        self::assertSame("901.aes: 901.v2.aes could not be written; 901.aes kept\n"
            . self::summary(array(0, 0, 0, 0, 0, 1)), $output);
        $files = $this->files();
        unset($files['.migrate-receipt-key.lock']);
        self::assertSame($before, $files);
    }

    public function testDatabaseFailure()
    {
        // The test's transaction is aborted, so the voucher query fails.
        $this->put('901.aes', $this->legacyPdf());
        self::assertFalse(@pg_query($this->connection, 'SELECT 1 / 0'));
        list($status, $output) = $this->migrate(array());
        self::assertSame(1, $status);
        self::assertStringStartsWith('901.aes: database query failed: ', $output);
        self::assertStringEndsWith(self::summary(array(0, 0, 0, 0, 0, 1)), $output);
        self::assertSame(array('.migrate-receipt-key.lock', '901.aes'), array_keys($this->files()));
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
        self::assertSame(0, $this->status(array('--dry-run')));
        fclose($lock);
        self::assertFalse(is_file($this->directory . '/files/901.v2.aes'));

        // The lock is released after each run, also after a refused one.
        self::assertSame(2, $this->status(array('--database=host=/nonexistent-socket-directory connect_timeout=2')));
        self::assertSame(0, $this->status(array()));
        self::assertSame(0, $this->status(array()));

        unlink($lockPath);
        mkdir($lockPath);
        self::assertSame(array(2, '', $message), $this->migrate(array()));
    }

    public function testRefusesToRun()
    {
        $this->put('901.aes', $this->legacyPdf());
        $before = $this->files();
        $usage = "Usage: php migrate-receipt-key.php [--dry-run] [--delete-legacy] [--limit=N]\n"
            . "       [--max-size=BYTES] [--files=DIR] [--constants=FILE] [--database=CONNINFO] [--sync=COMMAND]\n";
        $arguments = array('--limit=0', '--limit=x', '--limit=', '--max-size=0', '--max-size=1k', '--keep-legacy',
            'files', '--files=', '--sync=');
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

    // array(exit status, output, errors) of a run from $directory.
    private function migrateFrom($directory, $arguments)
    {
        $workingDirectory = getcwd();
        chdir($directory);
        try {
            $result = $this->migrate($arguments, false);
        } catch (Exception $exception) {
            chdir($workingDirectory);
            throw $exception;
        }
        self::assertSame($directory, getcwd());
        chdir($workingDirectory);
        return $result;
    }

    public function testReadsTheKeyLikeTheWebApplication()
    {
        // Without --constants, constants.php is included from the application
        // directory (the test images install tests/runtime/test-constants.php
        // there), not from the working directory.
        $this->put('901.aes', $this->legacyPdf());
        $this->writeConstants("<?php\n\$key = '" . self::OTHER_KEY . "';\n");
        list($status, $output) = $this->migrateFrom($this->directory, array(
            '--files=' . $this->directory . '/files',
            '--database=' . pgacc_test_connection_string(),
            '--dry-run',
        ));
        self::assertSame(0, $status);
        self::assertStringEndsWith('key fingerprint ' . self::fingerprint(self::TEST_KEY) . "\n", $output);
    }

    public function testRelativePathsAreResolvedAgainstTheWorkingDirectory()
    {
        // An explicit relative --constants or --files names a file in the
        // directory the command was started from.
        $this->put('901.aes', $this->legacyPdf());
        $this->writeConstants("<?php\n\$key = '" . self::OTHER_KEY . "';\n");
        list($status, $output, $errors) = $this->migrateFrom($this->directory, array(
            '--files=files',
            '--constants=constants.php',
            '--database=' . pgacc_test_connection_string(),
            '--dry-run',
        ));
        self::assertSame(array(0, ''), array($status, $errors));
        self::assertSame("901.aes: would migrate (voucher 4711)\n"
            . self::summary(array(1, 0, 0, 0, 0, 0), true, self::OTHER_KEY), $output);

        list($status, , $errors) = $this->migrateFrom($this->directory . '/files', array(
            '--files=.',
            '--constants=../missing.php',
            '--database=' . pgacc_test_connection_string(),
        ));
        self::assertSame(array(2, "No valid receipt key: \$key in {$this->directory}/files/../missing.php must have "
            . "at least 16 characters and not be the CHANGE-ME placeholder. Nothing was changed.\n"), array($status, $errors));
    }
}
