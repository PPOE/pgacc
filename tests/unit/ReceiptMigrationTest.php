<?php

/*
 * The receipt key migration (migrate-receipt-key.php): legacy files/<n>.aes
 * re-encrypted as files/<n>.v2.aes in two phases, on synthetic files in a
 * temporary directory. The cases call receipt_migration_main() in-process
 * with the test connection string, so pg_connect() returns the test's link
 * and sees its rolled-back voucher rows. The script as a command:
 * ReceiptMigrationCommandTest.
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
            // The blank line after "<?php" (PER-CS).
            if ($index === 1 && is_array($token) && $token[0] === T_WHITESPACE) {
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
        // nothing migrated. Skipped where php-cgi is not installed.
        exec('command -v php-cgi', $found, $missing);
        if ($missing !== 0) {
            self::markTestSkipped('php-cgi is not installed');
        }
        $this->put('901.aes', $this->legacyPdf());
        $before = $this->files();
        $process = proc_open(
            'php-cgi -q ' . escapeshellarg(dirname(dirname(__DIR__)) . '/migrate-receipt-key.php')
            . ' --files=' . escapeshellarg($this->directory . '/files'),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(
            [1, '', "migrate-receipt-key.php must be run with the php command-line binary.\n"],
            [proc_close($process), $output, $errors],
        );
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
        // Whitespace before the PDF header; trailing NUL bytes of more than a
        // block are content after %%EOF, not padding.
        self::assertTrue(receipt_migration_is_complete(rijndael256_zero_pad("\n%PDF-1.4\nsynthetic\n%%EOF\n")));
        self::assertTrue(receipt_migration_is_complete(rijndael256_zero_pad(" %PDF-1.4\nsynthetic\n%%EOF\n")));
        self::assertFalse(receipt_migration_is_complete(rijndael256_zero_pad(" %PDF-1.4\n%%EOF\n" . str_repeat("\0", 64))));
        self::assertFalse(receipt_migration_is_complete(rijndael256_zero_pad("\n%PDF\nsynthetic\n%%EOF\n")));
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
        $this->put('907.v2.aes', receipt_v2_encrypt(self::TEST_KEY, '907', '%PDF new receipt, not touched'));
        $this->put('908.aes', $this->legacyPdf());
        $this->put('912.aes', receipt_encrypt('4711', '', "%PDF-1.4\nsynthetic, but cut off"));
        // Not file numbers: reported, not migrated.
        foreach (['0.aes', '007.aes', '2147483648.aes', 'x.aes'] as $name) {
            $this->put($name, $this->legacyPdf());
        }
        $this->put('notes.txt', 'not a receipt');
        $this->put('907.v2.aes.tmp-1-2', 'left by a failed write');
        $before = $this->files();
        $otherNames = "0.aes: skipped, legacy kept: not a file number\n"
            . "007.aes: skipped, legacy kept: not a file number\n"
            . "2147483648.aes: skipped, legacy kept: not a file number\n"
            . "x.aes: skipped, legacy kept: not a file number\n";
        $noVoucher = 'skipped, legacy kept: no voucher with this file decrypts it to a complete PDF or JPEG '
            . '(--search-voucher-ids tries the other voucher IDs)';
        $noRow = 'skipped, legacy kept: no voucher row references this file (--search-voucher-ids tries the other voucher IDs)';
        $png = 'skipped, legacy kept: voucher 4711 decrypts it to a PNG image, not a complete PDF or JPEG '
            . '(--include-unusual migrates it)';
        $cutOff = 'skipped, legacy kept: voucher 4711 decrypts it to a PDF that looks incomplete or has data after '
            . 'its end, not a complete PDF or JPEG (--include-unusual migrates it)';
        $skippedLines = "903.aes: $noVoucher\n"
            . "904.aes: $png\n"
            . "905.aes: $noRow\n"
            . "906.aes: skipped, legacy kept: 4032 bytes, above --max-size=4000\n";
        $skippedList = "Skipped, legacy kept: 0.aes, 007.aes, 2147483648.aes, x.aes, 903, 904, 905, 906, 912\n";

        // Skipped files give exit status 3.
        list($status, $output, $errors) = $this->migrate(['--dry-run', '--max-size=4000']);
        self::assertSame([3, ''], [$status, $errors]);
        // Nothing written, not even the lock file.
        self::assertSame($before, $this->files());
        self::assertSame(
            $otherNames
            . "901.aes: would migrate (voucher 4711)\n"
            . "902.aes: would migrate (voucher 815)\n"
            . $skippedLines
            . "908.aes: would migrate (voucher 4711)\n"
            . "912.aes: $cutOff\n"
            . $skippedList
            . self::summary([3, 0, 0, 9, 0], true),
            $output,
        );

        list($status, $output, $errors) = $this->migrate(['--max-size=4000']);
        self::assertSame([3, ''], [$status, $errors]);
        self::assertSame(
            $otherNames
            . "901.aes: migrated (voucher 4711); 901.aes kept until --delete-legacy\n"
            . "902.aes: migrated (voucher 815); 902.aes kept until --delete-legacy\n"
            . $skippedLines
            . "908.aes: migrated (voucher 4711); 908.aes kept until --delete-legacy\n"
            . "912.aes: $cutOff\n"
            . $skippedList
            . self::summary([3, 0, 0, 9, 0]),
            $output,
        );
        $files = $this->files();
        $names = array_merge(array_keys($before), ['.migrate-receipt-key.lock', '901.v2.aes', '902.v2.aes', '908.v2.aes']);
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
        list($status, $output) = $this->migrate([]);
        self::assertSame(3, $status);
        self::assertSame(
            $otherNames
            . "901.aes: already migrated; 901.aes kept until --delete-legacy\n"
            . "902.aes: already migrated; 902.aes kept until --delete-legacy\n"
            . "903.aes: $noVoucher\n"
            . "904.aes: $png\n"
            . "905.aes: $noRow\n"
            . "906.aes: migrated (voucher 4711); 906.aes kept until --delete-legacy\n"
            . "908.aes: already migrated; 908.aes kept until --delete-legacy\n"
            . "912.aes: $cutOff\n"
            . "Skipped, legacy kept: 0.aes, 007.aes, 2147483648.aes, x.aes, 903, 904, 905, 912\n"
            . self::summary([1, 3, 0, 8, 0]),
            $output,
        );
    }

    public function testDeleteLegacyPhase()
    {
        $jpeg = self::legacyVector('jpeg with trailing NUL bytes');
        $this->put('901.aes', $this->legacyPdf());
        $this->put('902.aes', hex2bin($jpeg['cipher']));
        $this->put('904.aes', receipt_encrypt('4711', '', "\x89PNG\r\n\x1a\nsynthetic"));
        $this->put('908.aes', $this->legacyPdf());
        self::assertSame(0, $this->migrationStatus(['--limit=2']));
        // 908 is not migrated yet, 904 is skipped; 902.v2.aes is replaced by
        // a valid file with other content.
        $this->put('902.v2.aes', receipt_v2_encrypt(self::TEST_KEY, '902', '%PDF other content'));
        $before = $this->files();
        // Without <n>.v2.aes, nothing is decrypted: skipped, legacy kept.
        $rest = "904.aes: skipped, legacy kept: no 904.v2.aes (skipped by the migration or not migrated yet; a run without --delete-legacy lists why)\n"
            . "908.aes: skipped, legacy kept: no 908.v2.aes (skipped by the migration or not migrated yet; a run without --delete-legacy lists why)\n"
            . "Skipped, legacy kept: 904, 908\n"
            . "Failed: 902\n";

        list($status, $output) = $this->migrate(['--delete-legacy', '--dry-run']);
        self::assertSame(1, $status);
        self::assertSame(
            self::deleteReminder()
            . "901.aes: verified; would delete 901.aes\n"
            . "902.aes: 902.v2.aes does not decrypt to the content of 902.aes with this key; both kept\n"
            . $rest
            . self::summary([0, 0, 1, 2, 1], true),
            $output,
        );
        self::assertSame($before, $this->files());

        list($status, $output) = $this->migrate(['--delete-legacy']);
        self::assertSame(1, $status);
        self::assertSame(
            self::deleteReminder()
            . "901.aes: verified; deleted 901.aes\n"
            . "902.aes: 902.v2.aes does not decrypt to the content of 902.aes with this key; both kept\n"
            . $rest
            . self::summary([0, 0, 1, 2, 1]),
            $output,
        );
        $expected = $before;
        unset($expected['901.aes']);
        self::assertSame($expected, $this->files());
        self::assertSame(self::paddedPdf(), $this->v2Plain('901'));

        // Without failures, skipped files give exit status 3.
        unlink($this->directory . '/files/902.v2.aes');
        unlink($this->directory . '/files/904.aes');
        list($status, $output) = $this->migrate(['--delete-legacy', '--limit=1']);
        self::assertSame(3, $status);
        self::assertSame(
            self::deleteReminder()
            . "902.aes: skipped, legacy kept: no 902.v2.aes (skipped by the migration or not migrated yet; a run without --delete-legacy lists why)\n"
            . "908.aes: skipped, legacy kept: no 908.v2.aes (skipped by the migration or not migrated yet; a run without --delete-legacy lists why)\n"
            . "Skipped, legacy kept: 902, 908\n"
            . self::summary([0, 0, 0, 2, 0]),
            $output,
        );
        self::assertSame(0, $this->migrationStatus([]));
        list($status, $output) = $this->migrate(['--delete-legacy', '--limit=1']);
        self::assertSame(0, $status);
        self::assertSame(
            self::deleteReminder()
            . "902.aes: verified; deleted 902.aes\n"
            . "Stopped after --limit=1; run again for the remaining files.\n"
            . self::summary([0, 0, 1, 0, 0]),
            $output,
        );
        self::assertSame(
            ['.migrate-receipt-key.lock', '901.v2.aes', '902.v2.aes', '908.aes', '908.v2.aes'],
            array_keys($this->files()),
        );
    }

    public function testExistingV2FilesAreVerified()
    {
        // 901: an invalid <n>.v2.aes and a good legacy file. 902: a valid
        // <n>.v2.aes. 903: an invalid <n>.v2.aes and a legacy file that does
        // not decrypt. 909-911: valid. Any checked file that does not verify
        // stops the run, unless --recreate-unverified or --ignore-unverified.
        $jpeg = self::legacyVector('jpeg with trailing NUL bytes');
        $this->put('901.aes', $this->legacyPdf());
        $this->put('901.v2.aes', 'not a v2 file');
        $this->put('902.aes', hex2bin($jpeg['cipher']));
        $this->put('902.v2.aes', receipt_v2_encrypt(self::TEST_KEY, '902', hex2bin($jpeg['decrypted'])));
        $this->put('903.aes', $this->legacyPdf());
        $this->put('903.v2.aes', 'not a v2 file either');
        foreach (['909', '910', '911'] as $number) {
            $this->put("$number.aes", $this->legacyPdf());
            $this->put("$number.v2.aes", receipt_v2_encrypt(self::TEST_KEY, $number, self::paddedPdf()));
        }
        $before = $this->files();
        foreach ([['--dry-run'], [], ['--delete-legacy']] as $arguments) {
            self::assertSame([2, '', self::unverified('901,903')], $this->migrate($arguments));
        }
        $files = $this->files();
        unset($files['.migrate-receipt-key.lock']);
        self::assertSame($before, $files);
        $valid = "902.aes: already migrated; 902.aes kept until --delete-legacy\n";
        $rest = "909.aes: already migrated; 909.aes kept until --delete-legacy\n"
            . "910.aes: already migrated; 910.aes kept until --delete-legacy\n"
            . "911.aes: already migrated; 911.aes kept until --delete-legacy\n";

        list($status, $output) = $this->migrate(['--ignore-unverified=901,903']);
        self::assertSame(3, $status);
        self::assertSame("901.aes: skipped, legacy kept: 901.v2.aes does not verify with this key (--ignore-unverified)\n"
            . $valid
            . "903.aes: skipped, legacy kept: 903.v2.aes does not verify with this key (--ignore-unverified)\n"
            . $rest . "Skipped, legacy kept: 901, 903\n" . self::summary([0, 4, 0, 2, 0]), $output);
        // Not listed: still stops.
        self::assertSame([2, '', self::unverified('903')], $this->migrate(['--ignore-unverified=901']));

        $cannot = "903.aes: 903.v2.aes does not verify, and 903.aes cannot replace it (no voucher with this file decrypts it "
            . "to a complete PDF or JPEG (--search-voucher-ids tries the other voucher IDs)); both kept\n";
        list($status, $output) = $this->migrate(['--recreate-unverified', '--dry-run']);
        self::assertSame(1, $status);
        self::assertSame("901.aes: 901.v2.aes does not verify; would re-create it (voucher 4711)\n" . $valid . $cannot
            . $rest . "Failed: 903\n" . self::summary([1, 4, 0, 0, 1], true), $output);
        self::assertSame($before, array_diff_key($this->files(), ['.migrate-receipt-key.lock' => '']));

        list($status, $output) = $this->migrate(['--recreate-unverified']);
        self::assertSame(1, $status);
        self::assertSame("901.aes: 901.v2.aes does not verify; re-created it (voucher 4711); 901.aes kept until --delete-legacy\n"
            . $valid . $cannot . $rest . "Failed: 903\n" . self::summary([1, 4, 0, 0, 1]), $output);
        self::assertSame(self::paddedPdf(), $this->v2Plain('901'));
        $files = $this->files();
        unset($files['.migrate-receipt-key.lock'], $files['901.v2.aes'], $before['901.v2.aes']);
        // Nothing deleted in the first phase.
        self::assertSame($before, $files);

        // The delete phase deletes nothing whose <n>.v2.aes does not verify.
        list($status, $output) = $this->migrate(['--delete-legacy', '--ignore-unverified=903']);
        self::assertSame(3, $status);
        self::assertStringContainsString("903.aes: skipped, legacy kept: 903.v2.aes does not verify with this key (--ignore-unverified)\n", $output);
        self::assertFileExists($this->directory . '/files/903.aes');
        self::assertFileDoesNotExist($this->directory . '/files/901.aes');
    }

    public function testAWrongKeyChangesNothing()
    {
        // Three receipts migrated with the right key, then runs with another
        // key (another constants.php): they do not re-create the files or
        // delete legacy files, and stop before writing anything.
        $jpeg = self::legacyVector('jpeg with trailing NUL bytes');
        $this->put('901.aes', $this->legacyPdf());
        $this->put('902.aes', hex2bin($jpeg['cipher']));
        $this->put('908.aes', $this->legacyPdf());
        $this->put('909.aes', $this->legacyPdf());
        self::assertSame(0, $this->migrationStatus(['--limit=3']));
        $before = $this->files();
        $this->writeConstants("<?php\n\$key = '" . self::OTHER_KEY . "';\n");
        $constants = $this->directory . '/constants.php';

        // The fingerprint of the right key does not confirm another key.
        $mismatch = '--key-fingerprint=' . self::fingerprint(self::TEST_KEY) . ' does not match the key fingerprint '
            . self::fingerprint(self::OTHER_KEY) . " of \$key in $constants. Nothing was changed.\n";
        foreach ([[], ['--delete-legacy'], ['--dry-run']] as $arguments) {
            self::assertSame([2, '', $mismatch], $this->migrate($arguments));
        }

        // Confirmed by mistake: the existing files do not verify, so the run
        // stops, also in the delete phase and in dry runs.
        $this->confirmedKey = self::OTHER_KEY;
        foreach ([[], ['--delete-legacy'], ['--dry-run'], ['--delete-legacy', '--dry-run']] as $arguments) {
            self::assertSame([2, '', self::unverified('901,902,908', self::OTHER_KEY)], $this->migrate($arguments), implode(' ', $arguments));
        }
        $files = $this->files();
        self::assertSame($before, $files);

        // With one existing file left, too.
        unlink($this->directory . '/files/901.v2.aes');
        unlink($this->directory . '/files/902.v2.aes');
        self::assertSame([2, '', self::unverified('908', self::OTHER_KEY)], $this->migrate(['--delete-legacy']));
        self::assertSame(['.migrate-receipt-key.lock', '901.aes', '902.aes', '908.aes', '908.v2.aes', '909.aes'], array_keys($this->files()));
        self::assertSame(self::paddedPdf(), $this->v2Plain('908'));
    }

    public function testTheKeyCheckIncludesV2FilesWithoutLegacyFile()
    {
        // After a partial delete phase (and with new uploads), most <n>.v2.aes
        // have no legacy file left; a run with another key still stops before
        // it migrates the rest.
        foreach (['901', '908', '909'] as $number) {
            $this->put("$number.aes", $this->legacyPdf());
        }
        $this->put('907.v2.aes', receipt_v2_encrypt(self::TEST_KEY, '907', '%PDF new upload'));
        self::assertSame(0, $this->migrationStatus(['--limit=2']));
        self::assertSame(0, $this->migrationStatus(['--delete-legacy', '--limit=2']));
        $before = $this->files();
        self::assertSame(['.migrate-receipt-key.lock', '901.v2.aes', '907.v2.aes', '908.v2.aes', '909.aes'], array_keys($before));
        $this->writeConstants("<?php\n\$key = '" . self::OTHER_KEY . "';\n");
        $this->confirmedKey = self::OTHER_KEY;
        self::assertSame([2, '', self::unverified('901,907,908', self::OTHER_KEY)], $this->migrate([]));
        self::assertSame($before, $this->files());

        // With the right key, two damaged files stop the run until they are
        // listed in --ignore-unverified.
        $this->writeConstants("<?php\n\$key = '" . self::TEST_KEY . "';\n");
        $this->confirmedKey = self::TEST_KEY;
        $this->put('908.aes', $this->legacyPdf());
        $this->put('908.v2.aes', 'damaged');
        $this->put('910.v2.aes', 'damaged');
        foreach (['911', '912'] as $number) {
            $this->put("$number.v2.aes", receipt_v2_encrypt(self::TEST_KEY, $number, '%PDF new upload'));
        }
        self::assertSame([2, '', self::unverified('910,908')], $this->migrate([]));
        list($status, $output) = $this->migrate(['--ignore-unverified=908,910']);
        self::assertSame(3, $status);
        self::assertSame("908.aes: skipped, legacy kept: 908.v2.aes does not verify with this key (--ignore-unverified)\n"
            . "909.aes: migrated (voucher 4711); 909.aes kept until --delete-legacy\n"
            . "Skipped, legacy kept: 908\n" . self::summary([1, 0, 0, 1, 0]), $output);
        // --recreate-unverified cannot re-create 910 (no legacy file).
        self::assertSame([2, '', self::unverified('910')], $this->migrate(['--recreate-unverified']));
    }

    public function testOneReceiptOfTheApplicationCatchesAWrongKey()
    {
        // A first run with another key: no file is migrated yet, but one
        // receipt uploaded since the upgrade does not verify, so nothing is
        // written.
        $this->put('901.aes', $this->legacyPdf());
        $this->put('907.v2.aes', receipt_v2_encrypt(self::TEST_KEY, '907', '%PDF new upload'));
        $before = $this->files();
        $this->writeConstants("<?php\n\$key = '" . self::OTHER_KEY . "';\n");
        $this->confirmedKey = self::OTHER_KEY;
        self::assertSame([2, '', self::unverified('907', self::OTHER_KEY)], $this->migrate([]));
        $files = $this->files();
        unset($files['.migrate-receipt-key.lock']);
        self::assertSame($before, $files);
    }

    public function testLimitBatchesCheckABoundedSample()
    {
        // 16 receipts without a legacy file fill the key check's sample; the
        // damaged 901.v2.aes is not in it. A --limit batch does not read it
        // again, the run without --limit does.
        for ($number = 920; $number < 936; $number++) {
            $this->put("$number.v2.aes", receipt_v2_encrypt(self::TEST_KEY, "$number", '%PDF new upload'));
        }
        $this->put('901.aes', $this->legacyPdf());
        $this->put('901.v2.aes', 'damaged');
        $this->put('909.aes', $this->legacyPdf());
        list($status, $output) = $this->migrate(['--limit=1']);
        self::assertSame(0, $status);
        self::assertSame("901.aes: already migrated (not checked again in a --limit run); 901.aes kept until --delete-legacy\n"
            . "909.aes: migrated (voucher 4711); 909.aes kept until --delete-legacy\n"
            . self::summary([1, 1, 0, 0, 0]), $output);
        list($status, $output) = $this->migrate([]);
        self::assertSame(1, $status);
        self::assertStringStartsWith('901.aes: 901.v2.aes does not verify with this key; both kept (--recreate-unverified '
            . 're-creates it from 901.aes, --ignore-unverified=901 leaves it)', $output);
        self::assertStringContainsString("909.aes: already migrated; 909.aes kept until --delete-legacy\n", $output);
        // --recreate-unverified checks every existing file first: 940, a
        // damaged upload outside the sample, stops it.
        $this->put('940.v2.aes', 'damaged');
        self::assertSame(1, $this->migrationStatus([]));
        self::assertSame([2, '', self::unverified('940')], $this->migrate(['--recreate-unverified', '--dry-run']));
        list($status, $output) = $this->migrate(['--recreate-unverified', '--ignore-unverified=940', '--dry-run']);
        self::assertSame(0, $status);
        self::assertStringStartsWith("901.aes: 901.v2.aes does not verify; would re-create it (voucher 4711)\n", $output);

        // --ignore-unverified skips 901 outside the sample, also in the
        // delete phase.
        list($status, $output) = $this->migrate(['--ignore-unverified=901']);
        self::assertSame(3, $status);
        self::assertStringStartsWith("901.aes: skipped, legacy kept: 901.v2.aes does not verify with this key (--ignore-unverified)\n", $output);
        list($status, $output) = $this->migrate(['--delete-legacy', '--ignore-unverified=901']);
        self::assertSame(3, $status);
        self::assertSame(self::deleteReminder()
            . "901.aes: skipped, legacy kept: 901.v2.aes does not verify with this key (--ignore-unverified)\n"
            . "909.aes: verified; deleted 909.aes\n"
            . "Skipped, legacy kept: 901\n" . self::summary([0, 0, 1, 1, 0]), $output);
    }

    public function testWritingAndDeletingNeedTheKeyFingerprint()
    {
        $this->put('901.aes', $this->legacyPdf());
        $before = $this->files();
        $message = "A run that writes or deletes needs --key-fingerprint=<8 hex digits>: the key fingerprint that the "
            . "user administration page (Benutzerverwaltung) shows root, from the application whose receipts these are. "
            . "Nothing was changed.\n";
        foreach ([[], ['--delete-legacy'], ['--recreate-unverified']] as $arguments) {
            self::assertSame([2, '', $message], $this->migrate($arguments, true, false));
        }
        self::assertSame($before, $this->files());
        // A dry run needs none; upper case is accepted.
        list($status, $output) = $this->migrate(['--dry-run'], true, false);
        self::assertSame(0, $status);
        self::assertStringEndsWith('key fingerprint ' . self::fingerprint(self::TEST_KEY) . "\n", $output);
        self::assertSame(0, $this->migrate(['--key-fingerprint=' . strtoupper(self::fingerprint(self::TEST_KEY))], true, false)[0]);
        self::assertSame(self::paddedPdf(), $this->v2Plain('901'));
    }

    public function testIncludeUnusualFiles()
    {
        // 904 (a PNG) and 912 (a cut-off PDF) decrypt with their voucher ID;
        // 903 does not (the merged case) and stays skipped.
        $png = rijndael256_zero_pad("\x89PNG\r\n\x1a\nsynthetic");
        $cutOff = rijndael256_zero_pad("%PDF-1.4\nsynthetic, but cut off");
        $this->put('903.aes', $this->legacyPdf());
        $this->put('904.aes', receipt_encrypt('4711', '', $png));
        $this->put('912.aes', receipt_encrypt('4711', '', $cutOff));
        $skipped = '903.aes: skipped, legacy kept: no voucher with this file decrypts it to a complete PDF or JPEG '
            . "(--search-voucher-ids tries the other voucher IDs)\n";

        list($status, $output) = $this->migrate(['--include-unusual']);
        self::assertSame(3, $status);
        self::assertSame($skipped
            . "904.aes: migrated (voucher 4711, unusual: a PNG image); 904.aes kept until --delete-legacy\n"
            . "912.aes: migrated (voucher 4711, unusual: a PDF that looks incomplete or has data after its end); "
            . "912.aes kept until --delete-legacy\n"
            . "Skipped, legacy kept: 903\n"
            . self::summary([2, 0, 0, 1, 0]), $output);
        self::assertSame($png, $this->v2Plain('904'));
        self::assertSame($cutOff, $this->v2Plain('912'));

        // The delete phase needs the option too.
        list($status, $output) = $this->migrate(['--delete-legacy']);
        self::assertSame(3, $status);
        self::assertStringContainsString("904.aes: skipped, legacy kept: voucher 4711 decrypts it to a PNG image", $output);
        self::assertStringContainsString("Skipped, legacy kept: 903, 904, 912\n", $output);
        list($status, $output) = $this->migrate(['--delete-legacy', '--include-unusual']);
        self::assertSame(3, $status);
        self::assertSame(self::deleteReminder()
            . "903.aes: skipped, legacy kept: no 903.v2.aes (skipped by the migration or not migrated yet; "
            . "a run without --delete-legacy lists why)\n"
            . "904.aes: verified; deleted 904.aes\n"
            . "912.aes: verified; deleted 912.aes\n"
            . "Skipped, legacy kept: 903\n"
            . self::summary([0, 0, 2, 1, 0]), $output);
        self::assertSame(['.migrate-receipt-key.lock', '903.aes', '904.v2.aes', '912.v2.aes'], array_keys($this->files()));
    }

    public function testUnusualTypes()
    {
        self::assertSame('a PNG image', receipt_migration_unusual_type("\x89PNG\r\n\x1a\nrest", 64));
        self::assertSame('a GIF image', receipt_migration_unusual_type('GIF87a', 32));
        self::assertSame('a GIF image', receipt_migration_unusual_type('GIF89a', 32));
        self::assertSame('a TIFF image', receipt_migration_unusual_type("II*\0", 32));
        self::assertSame('a TIFF image', receipt_migration_unusual_type("MM\0*", 32));
        self::assertSame('a JPEG that looks incomplete or has data after its end', receipt_migration_unusual_type("\xFF\xD8\xFF\xE0", 32));
        self::assertSame('an empty file', receipt_migration_unusual_type(str_repeat("\0", 32), 32));
        // Zeros are an empty file only if the file is one block.
        self::assertNull(receipt_migration_unusual_type(str_repeat("\0", 32), 64));
        self::assertNull(receipt_migration_unusual_type(str_repeat("\0", 31) . 'x', 32));
        self::assertNull(receipt_migration_unusual_type("\x89PNG\r\n\x1a", 32));
        self::assertNull(receipt_migration_unusual_type('GIF88a', 32));
        self::assertNull(receipt_migration_unusual_type('random bytes', 32));
        $pdf = 'a PDF that looks incomplete or has data after its end';
        self::assertSame($pdf, receipt_migration_unusual_type("\n%PDF-1.4\nsynthetic, but cut", 32));
        self::assertSame($pdf, receipt_migration_unusual_type(str_repeat(' ', 40) . '%PDF-1.4', 1024));
        self::assertSame('a text document', receipt_migration_unusual_type(str_repeat(' ', 32) . str_repeat('x', 32) . "\xC3\xA4", 1024));
        // Text: a whole first block of printable ASCII and/or white-space, zero
        // padding allowed after it.
        self::assertSame('a text document', receipt_migration_unusual_type("<!DOCTYPE html>\n<html><body>\r\n\tx", 64));
        self::assertSame('a text document', receipt_migration_unusual_type(str_pad("plain text\f~", 32, "\0"), 32));
        foreach (["<!DOCTYPE html>\n<html><body>\x01xyz", "<!DOCTYPE html>\n<html><body>\x7Fxyz", "<!DOCTYPE html>\n<html><body>\xC3\xA4xy",
            "<!DOCTYPE html>\n<html>\0<body>xyz", "\0<!DOCTYPE html>\n<html><body>xyz", "<!DOCTYPE html>\n<html><body>xyz\x0B",
            "<!DOCTYPE html>\n<html><body>xyz"] as $head) {
            // Control characters, non-ASCII bytes, NUL before the end, or
            // less than one block.
            self::assertSame(32 - ($head === "<!DOCTYPE html>\n<html><body>xyz" ? 1 : 0), strlen($head), bin2hex($head));
            self::assertNull(receipt_migration_unusual_type($head, 64), bin2hex($head));
        }
    }

    public function testLeftoverLegacyReceipts()
    {
        // 909: a complete PDF after a newline; 910: a PDF after a space with
        // NUL bytes inside and more than a block of NUL bytes after %%EOF;
        // 911: an HTML page; 912: a PDF after more than a block of spaces;
        // 905: no voucher row references it.
        $newline = rijndael256_zero_pad("\n%PDF-1.4\nsynthetic\n%%EOF\n");
        $space = rijndael256_zero_pad(" %PDF-1.4\nstream\0\0synthetic\0\nendstream\nstartxref\n9\n%%EOF\n" . str_repeat("\0", 3000));
        $html = rijndael256_zero_pad("<!DOCTYPE html>\n<html><body>synthetic</body>\n</html>\n");
        $this->put('905.aes', receipt_encrypt('4711', '', 'x'));
        $this->put('909.aes', receipt_encrypt('4711', '', $newline));
        $this->put('910.aes', receipt_encrypt('4711', '', $space));
        $this->put('911.aes', receipt_encrypt('4711', '', $html));
        $this->put('912.aes', receipt_encrypt('4711', '', str_repeat(' ', 40) . "%PDF-1.4\nsynthetic\n%%EOF\n"));
        $orphan = '905.aes: skipped, legacy kept: no voucher row references this file';

        list($status, $output) = $this->migrate(['--dry-run']);
        self::assertSame(3, $status);
        self::assertSame("$orphan (--search-voucher-ids tries the other voucher IDs)\n"
            . "909.aes: would migrate (voucher 4711)\n"
            . '910.aes: skipped, legacy kept: voucher 4711 decrypts it to a PDF that looks incomplete or has data after '
            . "its end, not a complete PDF or JPEG (--include-unusual migrates it)\n"
            . '911.aes: skipped, legacy kept: voucher 4711 decrypts it to a text document, not a complete PDF or JPEG '
            . "(--include-unusual migrates it)\n"
            . "912.aes: would migrate (voucher 4711)\n"
            . "Skipped, legacy kept: 905, 910, 911\n"
            . self::summary([2, 0, 0, 3, 0], true), $output);

        // (testSearchVoucherIds covers the search for a file without rows.)
        list($status, $output) = $this->migrate(['--include-unusual']);
        self::assertSame(3, $status);
        self::assertSame("$orphan (--search-voucher-ids tries the other voucher IDs)\n"
            . "909.aes: migrated (voucher 4711); 909.aes kept until --delete-legacy\n"
            . '910.aes: migrated (voucher 4711, unusual: a PDF that looks incomplete or has data after its end); '
            . "910.aes kept until --delete-legacy\n"
            . "911.aes: migrated (voucher 4711, unusual: a text document); 911.aes kept until --delete-legacy\n"
            . "912.aes: migrated (voucher 4711); 912.aes kept until --delete-legacy\n"
            . "Skipped, legacy kept: 905\n"
            . self::summary([4, 0, 0, 1, 0]), $output);
        // The bytes of the legacy download; PDFs are now sent as such, the
        // HTML page as a download (application/octet-stream).
        self::assertSame($newline, $this->v2Plain('909'));
        self::assertSame($space, $this->v2Plain('910'));
        self::assertSame($html, $this->v2Plain('911'));
        self::assertSame(['application/pdf', 'application/pdf', 'application/octet-stream'], [
            receipt_content_type($newline), receipt_content_type($space), receipt_content_type($html)]);
    }

    public function testPdfHeaderAcrossTheFirstBlock()
    {
        // White-space of 27, 28, 31 and 32 bytes before %PDF-: from 28 on the
        // header crosses the end of the first block (or starts after it).
        // 909-912 have rows of voucher 4711; 913-916 have no row and were
        // encrypted for voucher 4700, which no row has any more (a merge).
        $plain = [];
        foreach (['909' => ' ', '910' => "\n", '911' => ' ', '912' => "\n"] as $number => $space) {
            $length = ['909' => 27, '910' => 28, '911' => 31, '912' => 32][$number];
            $plain[$number] = rijndael256_zero_pad(str_repeat($space, $length) . "%PDF-1.4\nsynthetic\n%%EOF\n");
            $this->put("$number.aes", receipt_encrypt('4711', '', $plain[$number]));
            $plain[$number + 4] = $plain[$number];
            $this->put(($number + 4) . '.aes', receipt_encrypt('4700', '', $plain[$number]));
        }
        pg_query($this->connection, 'DELETE FROM vouchers WHERE voucher_id = 4700');

        list($status, $output) = $this->migrate(['--search-voucher-ids']);
        self::assertSame(0, $status, $output);
        $expected = '';
        foreach (['909', '910', '911', '912'] as $number) {
            $expected .= "$number.aes: migrated (voucher 4711); $number.aes kept until --delete-legacy\n";
        }
        foreach (['913', '914', '915', '916'] as $number) {
            $expected .= "$number.aes: migrated (voucher 4700, found by --search-voucher-ids; no voucher has this file); "
                . "$number.aes kept until --delete-legacy\n";
        }
        self::assertSame($expected . self::summary([8, 0, 0, 0, 0]), $output);
        foreach ($plain as $number => $bytes) {
            self::assertSame($bytes, $this->v2Plain((string) $number));
            self::assertSame('application/pdf', receipt_content_type($bytes), (string) $number);
        }
    }

    public function testPdfHeaderEndingAtTheProbeWindow()
    {
        // 1019 bytes of white-space: %PDF- ends at byte 1024, the end of the
        // probe, and the file migrates as a PDF. 1020 bytes: the header ends
        // beyond it, so the file is no PDF (its first block is text).
        $pdf = rijndael256_zero_pad(str_repeat(' ', 1019) . "%PDF-1.4\nsynthetic\n%%EOF\n");
        $late = rijndael256_zero_pad(str_repeat("\n", 1020) . "%PDF-1.4\nsynthetic\n%%EOF\n");
        $this->put('909.aes', receipt_encrypt('4711', '', $pdf));
        $this->put('910.aes', receipt_encrypt('4711', '', $late));
        list($status, $output) = $this->migrate([]);
        self::assertSame(3, $status, $output);
        self::assertSame("909.aes: migrated (voucher 4711); 909.aes kept until --delete-legacy\n"
            . '910.aes: skipped, legacy kept: voucher 4711 decrypts it to a text document, not a complete PDF or JPEG '
            . "(--include-unusual migrates it)\n"
            . "Skipped, legacy kept: 910\n"
            . self::summary([1, 0, 0, 1, 0]), $output);
        self::assertSame($pdf, $this->v2Plain('909'));
        self::assertSame('application/pdf', receipt_content_type($pdf));
        self::assertSame('application/octet-stream', receipt_content_type($late));
    }

    public function testTextIsJudgedByTheFirstBlock()
    {
        // A whole first block of spaces (or 31 spaces and a letter), ASCII in
        // the second block and UTF-8 later: text, as only the first block
        // counts, also though a block of white-space reads 1024 bytes.
        $this->put('909.aes', receipt_encrypt('4711', '', rijndael256_zero_pad(str_repeat(' ', 32) . str_repeat('x', 32) . "\xC3\xA4")));
        $this->put('910.aes', receipt_encrypt('4711', '', rijndael256_zero_pad(str_repeat(' ', 31) . str_repeat('x', 33) . "\xC3\xA4")));
        list($status, $output) = $this->migrate(['--dry-run', '--include-unusual']);
        self::assertSame(0, $status, $output);
        self::assertSame("909.aes: would migrate (voucher 4711, unusual: a text document)\n"
            . "910.aes: would migrate (voucher 4711, unusual: a text document)\n"
            . self::summary([2, 0, 0, 0, 0], true), $output);
    }

    public function testTextOnlyWithTheOwnVoucherIds()
    {
        // 903 belongs to voucher 4712 but was encrypted for 4711: the search
        // accepts no text, whose check is weaker than a complete PDF or JPEG.
        $this->put('903.aes', receipt_encrypt('4711', '', "<!DOCTYPE html>\n<html><body>synthetic</body>\n</html>\n"));
        list($status, $output) = $this->migrate(['--dry-run', '--include-unusual', '--search-voucher-ids']);
        self::assertSame(3, $status);
        self::assertSame('903.aes: skipped, legacy kept: no voucher with this file decrypts it to a complete PDF or JPEG, '
            . "and no other voucher ID does\n"
            . "Skipped, legacy kept: 903\n"
            . self::summary([0, 0, 0, 1, 0], true), $output);
    }

    public function testSearchVoucherIds()
    {
        // 903 (rows of voucher 4712) and 905 (no row) were encrypted for
        // voucher 4711; 913 for 4700, an ID that no row has any more (a
        // merge); 914 has no row and decrypts with voucher 4711 only to a
        // cut-off PDF, which the search does not accept.
        $this->put('903.aes', $this->legacyPdf());
        $this->put('905.aes', $this->legacyPdf());
        $this->put('913.aes', receipt_encrypt('4700', '', self::paddedPdf()));
        $this->put('914.aes', receipt_encrypt('4711', '', "%PDF-1.4\nsynthetic, but cut off"));
        pg_query($this->connection, 'DELETE FROM vouchers WHERE voucher_id = 4700');

        list($status, $output) = $this->migrate(['--search-voucher-ids']);
        self::assertSame(3, $status);
        self::assertSame(
            "903.aes: migrated (voucher 4711, found by --search-voucher-ids; its rows have voucher 4712); "
            . "903.aes kept until --delete-legacy\n"
            . "905.aes: migrated (voucher 4711, found by --search-voucher-ids; no voucher has this file); "
            . "905.aes kept until --delete-legacy\n"
            . "913.aes: migrated (voucher 4700, found by --search-voucher-ids; no voucher has this file); "
            . "913.aes kept until --delete-legacy\n"
            . "914.aes: skipped, legacy kept: no voucher row references this file, and no voucher ID decrypts it to a "
            . "complete PDF or JPEG\n"
            . "Skipped, legacy kept: 914\n"
            . self::summary([3, 0, 0, 1, 0]),
            $output,
        );
        foreach (['903', '905', '913'] as $number) {
            self::assertSame(self::paddedPdf(), $this->v2Plain($number));
        }
        list($status, $output) = $this->migrate(['--delete-legacy', '--search-voucher-ids']);
        self::assertSame(3, $status);
        self::assertStringEndsWith("Skipped, legacy kept: 914\n" . self::summary([0, 0, 3, 1, 0]), $output);
        self::assertSame(['.migrate-receipt-key.lock', '903.v2.aes', '905.v2.aes', '913.v2.aes', '914.aes'], array_keys($this->files()));
    }

    public function testSearchVoucherIdsDatabaseFailure()
    {
        $this->put('903.aes', $this->legacyPdf());
        pg_query($this->connection, 'DROP SEQUENCE voucher_number CASCADE');
        list($status, $output) = $this->migrate(['--search-voucher-ids']);
        self::assertSame(1, $status);
        self::assertStringStartsWith('903.aes: database query failed: ', $output);
        self::assertStringEndsWith("Failed: 903\n" . self::summary([0, 0, 0, 0, 1]), $output);
    }

    public function testGenuineLegacyFilesOfTheOriginalCode()
    {
        // tests/fixtures/legacy-php53: files written by the original upload
        // code on PHP 5.3.3 with mcrypt, and the SHA-256 of what the original
        // download sent. Voucher 711 (file 11) was merged into 799.
        $fixture = dirname(__DIR__) . '/fixtures/legacy-php53';
        $downloads = [];
        foreach (file("$fixture/downloads.txt", FILE_IGNORE_NEW_LINES) as $line) {
            if ($line[0] !== '#') {
                [$name, $type, $length, $sha256] = explode(' ', $line);
                $downloads[$name] = [$type, intval($length), $sha256];
            }
        }
        pg_query($this->connection, 'DELETE FROM vouchers WHERE voucher_id > 1000');
        $numbers = [];
        foreach (file("$fixture/cases.txt", FILE_IGNORE_NEW_LINES) as $line) {
            if ($line[0] === '#') {
                continue;
            }
            [$number, $voucher] = explode(' ', $line);
            $numbers[] = $number;
            $voucher = $voucher === '711' ? '799' : $voucher;
            copy("$fixture/files/$number.aes", "{$this->directory}/files/$number.aes");
            pg_query_params($this->connection, "INSERT INTO vouchers (id, voucher_id, type, orga, comment, date, file)
                VALUES ($1, $2, 20, 2, 'Synthetic legacy fixture', timestamp '2013-07-12 00:00:00', $3)", [9000 + $number, $voucher, $number]);
            // The legacy read sends what the original download sent.
            $plain = receipt_decrypt($voucher, '', file_get_contents("$fixture/files/$number.aes"));
            self::assertSame($downloads[$number][2], hash('sha256', $plain), $number);
        }
        self::assertSame(15, count($numbers));

        list($status, $output) = $this->migrate([]);
        self::assertSame(3, $status);
        self::assertStringEndsWith("Skipped, legacy kept: 6, 7, 9, 10, 11, 16\n" . self::summary([9, 0, 0, 6, 0]), $output);
        list($status, $output) = $this->migrate(['--include-unusual', '--search-voucher-ids']);
        self::assertSame(0, $status);
        self::assertSame(
            "1.aes: already migrated; 1.aes kept until --delete-legacy\n"
            . "2.aes: already migrated; 2.aes kept until --delete-legacy\n"
            . "3.aes: already migrated; 3.aes kept until --delete-legacy\n"
            . "5.aes: already migrated; 5.aes kept until --delete-legacy\n"
            . "6.aes: migrated (voucher 706, unusual: a JPEG that looks incomplete or has data after its end); 6.aes kept until --delete-legacy\n"
            . "7.aes: migrated (voucher 707, unusual: an empty file); 7.aes kept until --delete-legacy\n"
            . "9.aes: migrated (voucher 709, unusual: a PNG image); 9.aes kept until --delete-legacy\n"
            . "10.aes: migrated (voucher 710, unusual: a JPEG that looks incomplete or has data after its end); 10.aes kept until --delete-legacy\n"
            . "11.aes: migrated (voucher 711, found by --search-voucher-ids; its rows have voucher 799); 11.aes kept until --delete-legacy\n"
            . "12.aes: already migrated; 12.aes kept until --delete-legacy\n"
            . "13.aes: already migrated; 13.aes kept until --delete-legacy\n"
            . "14.aes: already migrated; 14.aes kept until --delete-legacy\n"
            . "15.aes: already migrated; 15.aes kept until --delete-legacy\n"
            . "16.aes: migrated (voucher 716, unusual: a PDF that looks incomplete or has data after its end); 16.aes kept until --delete-legacy\n"
            . "17.aes: already migrated; 17.aes kept until --delete-legacy\n"
            . self::summary([6, 9, 0, 0, 0]),
            $output,
        );
        // Every migrated receipt downloads the bytes the original download
        // sent before the merge.
        foreach ($numbers as $number) {
            $expected = $downloads[$number === '11' ? '11-before-merge' : $number];
            $plain = receipt_v2_read_with(self::TEST_KEY, $number, "{$this->directory}/files/$number.v2.aes");
            self::assertSame([$expected[1], $expected[2]], [strlen($plain), hash('sha256', $plain)], $number);
            // The legacy download sent everything but a PDF as image/jpeg;
            // from <n>.v2.aes a PNG is sent as image/png, an empty file as a
            // download (application/octet-stream).
            $type = ['7' => 'application/octet-stream', '9' => 'image/png'][$number] ?? $expected[0];
            self::assertSame($type, receipt_content_type($plain), $number);
        }

        list($status, $output) = $this->migrate(['--delete-legacy', '--include-unusual', '--search-voucher-ids']);
        self::assertSame(0, $status);
        self::assertStringEndsWith(self::summary([0, 0, 15, 0, 0]), $output);
        foreach ($numbers as $number) {
            self::assertFileDoesNotExist("{$this->directory}/files/$number.aes");
        }
    }

    public function testNewFilesGetTheOwnerGroupAndModeOfTheLegacyFile()
    {
        if (receipt_migration_euid() !== 0) {
            self::markTestSkipped('needs root (ReceiptMigrationCommandTest covers other users)');
        }
        $this->put('901.aes', $this->legacyPdf());
        $legacy = $this->directory . '/files/901.aes';
        self::assertTrue(chown($legacy, 33) && chgrp($legacy, 34) && chmod($legacy, 0640));
        $previous = umask(077);
        try {
            $status = $this->migrationStatus([]);
        } finally {
            umask($previous);
        }
        self::assertSame(0, $status);
        $v2 = $this->directory . '/files/901.v2.aes';
        clearstatcache();
        self::assertSame([33, 34, 0640], [fileowner($v2), filegroup($v2), fileperms($v2) & 07777]);
        self::assertSame(self::paddedPdf(), $this->v2Plain('901'));
    }

    public function testWritingNotAsRoot()
    {
        // receipt_migration_write() as for another user ($root false; run
        // here as root, so the group can always be set, the owner not).
        if (receipt_migration_euid() !== 0) {
            self::markTestSkipped('needs root to give the legacy files other owners');
        }
        $files = $this->directory . '/files';
        $cases = [
            // Owner 33, group 34, 0644: readable for everyone.
            ['1', 0644, ['', '; warning: owner or group differ from 1.aes, readable for its group']],
            // 0640: readable through the group, which could be set.
            ['2', 0640, ['', '; warning: owner or group differ from 2.aes, readable for its group']],
            // 0600: only the owner could read it.
            ['3', 0600, ['would not be readable by the owner or group of 3.aes (run as its owner, or as root)', '']],
        ];
        foreach ($cases as [$number, $mode, $expected]) {
            file_put_contents("$files/$number.aes", 'legacy');
            self::assertTrue(chown("$files/$number.aes", 33) && chgrp("$files/$number.aes", 34) && chmod("$files/$number.aes", $mode));
            self::assertSame($expected, receipt_migration_write("$files/$number.v2.aes", 'new', "$files/$number.aes", false), $number);
            clearstatcache();
            if ($expected[0] === '') {
                self::assertSame([0, 34, $mode], [fileowner("$files/$number.v2.aes"), filegroup("$files/$number.v2.aes"), fileperms("$files/$number.v2.aes") & 0777]);
            } else {
                self::assertFileDoesNotExist("$files/$number.v2.aes");
            }
        }
        // The owner itself: no warning.
        file_put_contents("$files/4.aes", 'legacy');
        chmod("$files/4.aes", 0600);
        self::assertSame(['', ''], receipt_migration_write("$files/4.v2.aes", 'new', "$files/4.aes", false));
        self::assertSame(['1.aes', '1.v2.aes', '2.aes', '2.v2.aes', '3.aes', '4.aes', '4.v2.aes'], array_keys($this->files()));
    }

    public function testWarnsWhenRunAsRoot()
    {
        if (receipt_migration_euid() !== 0) {
            self::markTestSkipped('needs root');
        }
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertSame(2, receipt_migration_main(['--constants=' . $this->directory . '/missing.php'], $out, $err));
        rewind($err);
        self::assertStringStartsWith(self::rootWarning() . 'No valid receipt key', stream_get_contents($err));
    }

    public function testDeleteLegacyFlushesFirst()
    {
        $this->put('901.aes', $this->legacyPdf());
        self::assertSame(0, $this->migrationStatus([]));
        $before = $this->files();
        self::assertSame(
            [2, '', "Could not flush the receipt files to disk with `false`; nothing was deleted.\n"],
            $this->migrate(['--delete-legacy', '--sync=false']),
        );
        self::assertSame($before, $this->files());
        // A dry run deletes nothing, so it does not flush.
        self::assertSame(0, $this->migrationStatus(['--delete-legacy', '--sync=false', '--dry-run']));
        list($status, $output) = $this->migrate(['--delete-legacy', '--sync=sync']);
        self::assertSame(0, $status);
        self::assertStringEndsWith("901.aes: verified; deleted 901.aes\n" . self::summary([0, 0, 1, 0, 0]), $output);
    }

    public function testFailuresKeepEveryFile()
    {
        // 901: <n>.v2.aes is a directory, so it cannot be written; the
        // directory is not removed.
        $this->put('901.aes', $this->legacyPdf());
        mkdir($this->directory . '/files/901.v2.aes');
        $before = $this->files();

        list($status, $output, $errors) = $this->migrate([]);
        self::assertSame([1, ''], [$status, $errors]);
        self::assertSame("901.aes: 901.v2.aes could not be written; 901.aes kept\n"
            . "Failed: 901\n" . self::summary([0, 0, 0, 0, 1]), $output);
        $files = $this->files();
        unset($files['.migrate-receipt-key.lock']);
        self::assertSame($before, $files);
    }

    public function testUnreadableLegacyFileFails()
    {
        // Not read as an empty file (which --include-unusual would migrate).
        if (receipt_migration_euid() === 0) {
            self::markTestSkipped('root ignores the file mode');
        }
        $this->put('901.aes', $this->legacyPdf());
        chmod($this->directory . '/files/901.aes', 0);
        list($status, $output) = $this->migrate(['--include-unusual']);
        chmod($this->directory . '/files/901.aes', 0644);
        self::assertSame(1, $status);
        self::assertSame("901.aes: 901.aes could not be read\nFailed: 901\n" . self::summary([0, 0, 0, 0, 1]), $output);
        self::assertFileDoesNotExist($this->directory . '/files/901.v2.aes');
    }

    public function testLegacyFileNotReadToItsSizeFails()
    {
        // Also as root: /proc/version reports size 0 but reads non-empty, so
        // the read does not match the file's size (like a cut-off read).
        symlink('/proc/version', $this->directory . '/files/901.aes');
        list($status, $output) = $this->migrate(['--include-unusual']);
        self::assertSame(1, $status);
        self::assertSame("901.aes: 901.aes could not be read\nFailed: 901\n" . self::summary([0, 0, 0, 0, 1]), $output);
        self::assertFileDoesNotExist($this->directory . '/files/901.v2.aes');
        self::assertSame('/proc/version', readlink($this->directory . '/files/901.aes'));
    }

    public function testDatabaseFailure()
    {
        // The test's transaction is aborted, so the voucher query fails.
        $this->put('901.aes', $this->legacyPdf());
        self::assertFalse(@pg_query($this->connection, 'SELECT 1 / 0'));
        list($status, $output) = $this->migrate([]);
        self::assertSame(1, $status);
        self::assertStringStartsWith('901.aes: database query failed: ', $output);
        self::assertStringEndsWith(self::summary([0, 0, 0, 0, 1]), $output);
        self::assertSame(['.migrate-receipt-key.lock', '901.aes'], array_keys($this->files()));
    }

    public function testLimit()
    {
        foreach (['909', '910', '911'] as $number) {
            $this->put("$number.aes", $this->legacyPdf());
        }

        list($status, $output) = $this->migrate(['--limit=2']);
        self::assertSame(0, $status);
        self::assertSame(
            "909.aes: migrated (voucher 4711); 909.aes kept until --delete-legacy\n"
            . "910.aes: migrated (voucher 4711); 910.aes kept until --delete-legacy\n"
            . "Stopped after --limit=2; run again for the remaining files.\n"
            . self::summary([2, 0, 0, 0, 0]),
            $output,
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
        self::assertSame([2, '', $message], $this->migrate([]));
        self::assertSame([2, '', $message], $this->migrate(['--delete-legacy']));
        // A dry run does not lock.
        self::assertSame(0, $this->migrationStatus(['--dry-run']));
        fclose($lock);
        self::assertFalse(is_file($this->directory . '/files/901.v2.aes'));

        // The lock is released after each run, also after a refused one.
        self::assertSame(2, $this->migrationStatus(['--database=host=/nonexistent-socket-directory connect_timeout=2']));
        self::assertSame(0, $this->migrationStatus([]));
        self::assertSame(0, $this->migrationStatus([]));

        unlink($lockPath);
        mkdir($lockPath);
        self::assertSame([2, '', $message], $this->migrate([]));
    }

    public function testRefusesToRun()
    {
        $this->put('901.aes', $this->legacyPdf());
        $before = $this->files();
        $usage = receipt_migration_usage();
        self::assertSame("Usage: php migrate-receipt-key.php [--dry-run] [--key-fingerprint=HEX] [--delete-legacy]\n"
            . "       [--recreate-unverified] [--ignore-unverified=N,...] [--include-unusual] [--search-voucher-ids]\n"
            . "       [--limit=N] [--max-size=BYTES] [--files=DIR] [--constants=FILE]\n"
            . "       [--database=CONNINFO] [--sync=COMMAND]\n", $usage);
        $arguments = ['--limit=0', '--limit=x', '--limit=', '--max-size=0', '--max-size=1k', '--keep-legacy',
            'files', '--files=', '--sync=', '--key-fingerprint=', '--key-fingerprint=1234567', '--key-fingerprint=123456789',
            '--key-fingerprint=1234567g', '--recreate-invalid', '--include-unusual=1', '--ignore-unverified=',
            '--ignore-unverified=0', '--ignore-unverified=1,', '--ignore-unverified=1,,2'];
        foreach ($arguments as $argument) {
            self::assertSame([2, '', $usage], $this->migrate([$argument]), $argument);
        }

        $constants = $this->directory . '/constants.php';
        $keys = [
            "<?php\n\$datakey = '';\n" => 'no $key',
            "<?php\n\$key = '';\n" => 'empty $key',
            "<?php\n\$key = 'CHANGE-ME';\n" => 'the placeholder',
            "<?php\n\$key = 'CHANGE-ME-CHANGE-ME-CHANGE-ME';\n" => 'a longer placeholder',
            "<?php\n\$key = '0123456789abcde';\n" => '15 characters',
            "<?php\n\$key = 1234567890123456789;\n" => 'not a string',
        ];
        $message = "No valid receipt key: \$key in $constants must have at least 16 characters "
            . "and not be the CHANGE-ME placeholder. Nothing was changed.\n";
        foreach ($keys as $content => $label) {
            $this->writeConstants($content);
            self::assertSame([2, '', $message], $this->migrate([]), $label);
        }
        $missing = $this->directory . '/missing.php';
        self::assertSame(
            [2, '', str_replace($constants, $missing, $message)],
            $this->migrate(['--constants=' . $missing]),
        );

        $this->writeConstants("<?php\n\$key = '" . self::TEST_KEY . "';\n");
        $directory = $this->directory . '/no-such-directory';
        self::assertSame(
            [2, '', "Cannot read the receipt directory $directory. Nothing was changed.\n"],
            $this->migrate(['--files=' . $directory]),
        );
        self::assertSame(
            [2, '', "Cannot connect to the database. Nothing was changed.\n"],
            $this->migrate(['--database=host=/nonexistent-socket-directory connect_timeout=2']),
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
        list($status, $output) = $this->migrateFrom($this->directory, [
            '--files=' . $this->directory . '/files',
            '--database=' . pgacc_test_connection_string(),
            '--dry-run',
        ]);
        self::assertSame(0, $status);
        self::assertStringEndsWith('key fingerprint ' . self::fingerprint(self::TEST_KEY) . "\n", $output);
    }

    public function testRelativePathsAreResolvedAgainstTheWorkingDirectory()
    {
        // An explicit relative --constants or --files names a file in the
        // directory the command was started from.
        $this->put('901.aes', $this->legacyPdf());
        $this->writeConstants("<?php\n\$key = '" . self::OTHER_KEY . "';\n");
        list($status, $output, $errors) = $this->migrateFrom($this->directory, [
            '--files=files',
            '--constants=constants.php',
            '--database=' . pgacc_test_connection_string(),
            '--dry-run',
        ]);
        self::assertSame([0, ''], [$status, $errors]);
        self::assertSame("901.aes: would migrate (voucher 4711)\n"
            . self::summary([1, 0, 0, 0, 0], true, self::OTHER_KEY), $output);

        list($status, , $errors) = $this->migrateFrom($this->directory . '/files', [
            '--files=.',
            '--constants=../missing.php',
            '--database=' . pgacc_test_connection_string(),
        ]);
        self::assertSame([2, "No valid receipt key: \$key in {$this->directory}/files/../missing.php must have "
            . "at least 16 characters and not be the CHANGE-ME placeholder. Nothing was changed.\n"], [$status, $errors]);
    }
}
