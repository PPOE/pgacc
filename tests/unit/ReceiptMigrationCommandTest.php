<?php

/*
 * migrate-receipt-key.php as a command: the default constants.php (the test
 * images install tests/runtime/test-constants.php there) and database; the
 * fixture voucher 1003 has file 42. The in-process cases are in
 * ReceiptMigrationTest.
 */

require_once __DIR__ . '/ReceiptMigrationTestCase.php';

final class ReceiptMigrationCommandTest extends ReceiptMigrationTestCase
{
    public function testCommandLine()
    {
        $script = dirname(dirname(__DIR__)) . '/migrate-receipt-key.php';
        $files = $this->directory . '/files';
        $receipt = "%PDF synthetic command-line receipt\n%%EOF\n";
        $this->put('42.aes', receipt_encrypt('1003', '', $receipt));
        $command = function ($arguments) use ($script) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $arguments,
                $descriptors,
                $pipes,
            );
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $output, $errors];
        };
        $fingerprint = self::fingerprint(self::TEST_KEY);

        self::assertSame(
            [2, '', "Cannot read the receipt directory {$this->directory}/missing. Nothing was changed.\n"],
            $command('--files=' . escapeshellarg($this->directory . '/missing')),
        );

        self::assertSame([0, "42.aes: migrated (voucher 1003); 42.aes kept until --delete-legacy\n"
            . "1 migrated, 0 already migrated, 0 legacy deleted, 0 not yet migrated, 0 skipped, 0 failed; "
            . "key fingerprint $fingerprint\n", ''], $command('--files=' . escapeshellarg($files)));
        self::assertSame([0, self::deleteReminder() . "42.aes: verified; deleted 42.aes\n"
            . "0 migrated, 0 already migrated, 1 legacy deleted, 0 not yet migrated, 0 skipped, 0 failed; "
            . "key fingerprint $fingerprint\n", ''], $command('--delete-legacy --files=' . escapeshellarg($files)));
        self::assertSame(['.migrate-receipt-key.lock', '42.v2.aes'], array_keys($this->files()));
        self::assertSame(rijndael256_zero_pad($receipt), $this->v2Plain('42'));
    }

    public function testVerifiesALargeV2FileInBoundedMemory()
    {
        // Phase 1 checks an existing 20 MB <n>.v2.aes without holding its
        // plain text: the run fits a 16M memory_limit.
        $files = $this->directory . '/files';
        $source = $this->directory . '/large.tmp';
        $out = fopen($source, 'wb');
        for ($i = 0; $i < 320; $i++) {
            fwrite($out, str_repeat(chr($i % 256), 65536));
        }
        fclose($out);
        $in = fopen($source, 'rb');
        $v2 = fopen($files . '/901.v2.aes', 'wb');
        self::assertTrue(receipt_v2_encrypt_stream(self::TEST_KEY, '901', $in, $v2));
        fclose($in);
        fclose($v2);
        unlink($source);
        $this->put('901.aes', 'legacy file, kept');
        $process = proc_open(
            escapeshellarg(PHP_BINARY) . ' -d memory_limit=16M '
            . escapeshellarg(dirname(dirname(__DIR__)) . '/migrate-receipt-key.php') . ' --dry-run --files=' . escapeshellarg($files),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $errors);
        self::assertStringStartsWith("901.aes: already migrated; 901.aes kept until --delete-legacy\n", $output);
    }
}
