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
    // array(exit status, output, errors) of the script with $arguments
    // (shell words), run with $prefix (e.g. another user).
    private static function command($arguments, $prefix = '')
    {
        $script = dirname(dirname(__DIR__)) . '/migrate-receipt-key.php';
        $process = proc_open(
            $prefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $arguments,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $errors];
    }

    public function testCommandLine()
    {
        $files = $this->directory . '/files';
        $receipt = "%PDF synthetic command-line receipt\n%%EOF\n";
        $this->put('42.aes', receipt_encrypt('1003', '', $receipt));
        $fingerprint = self::fingerprint(self::TEST_KEY);
        $warning = receipt_migration_euid() === 0 ? self::rootWarning() : '';

        self::assertSame(
            [2, '', $warning . "Cannot read the receipt directory {$this->directory}/missing. Nothing was changed.\n"],
            self::command("--key-fingerprint=$fingerprint --files=" . escapeshellarg($this->directory . '/missing')),
        );
        self::assertSame([2, ''], array_slice(self::command('--files=' . escapeshellarg($files)), 0, 2));

        self::assertSame([0, "42.aes: migrated (voucher 1003); 42.aes kept until --delete-legacy\n"
            . "1 migrated, 0 already migrated, 0 legacy deleted, 0 skipped, 0 failed; "
            . "key fingerprint $fingerprint\n", $warning], self::command("--key-fingerprint=$fingerprint --files=" . escapeshellarg($files)));
        self::assertSame([0, self::deleteReminder() . "42.aes: verified; deleted 42.aes\n"
            . "0 migrated, 0 already migrated, 1 legacy deleted, 0 skipped, 0 failed; "
            . "key fingerprint $fingerprint\n", $warning], self::command("--delete-legacy --key-fingerprint=$fingerprint --files=" . escapeshellarg($files)));
        self::assertSame(['.migrate-receipt-key.lock', '42.v2.aes'], array_keys($this->files()));
        self::assertSame(rijndael256_zero_pad($receipt), $this->v2Plain('42'));
    }

    public function testRunAsAnotherUser()
    {
        // Not root: the new <n>.v2.aes gets the mode of <n>.aes and its group
        // where permitted; another owner only gives a warning while the file
        // stays readable (the unreadable case: testWritingNotAsRoot).
        exec('command -v setpriv', $found, $missing);
        if ($missing !== 0 || receipt_migration_euid() !== 0) {
            self::markTestSkipped('needs root and setpriv');
        }
        $files = $this->directory . '/files';
        chmod($this->directory, 0755);
        chmod($files, 0777);
        chmod($this->directory . '/constants.php', 0644);
        $legacy = "$files/42.aes";
        $arguments = '--key-fingerprint=' . self::fingerprint(self::TEST_KEY) . ' --files=' . escapeshellarg($files)
            . ' --constants=' . escapeshellarg($this->directory . '/constants.php');
        // Owner, group and mode of the legacy file; the run's groups; the
        // expected owner and group of the new file and the warning.
        $cases = [
            'the web server\'s user' => [65534, 65534, 0640, '', 65534, 65534, ''],
            'another owner, readable for everyone' => [0, 0, 0644, '', 65534, 65534,
                '; warning: owner or group differ from 42.aes, readable for everyone'],
            'another owner, a shared group' => [0, 34, 0640, '--groups=34', 65534, 34,
                '; warning: owner or group differ from 42.aes, readable for its group'],
        ];
        foreach ($cases as $label => [$uid, $gid, $mode, $groups, $newUid, $newGid, $warning]) {
            foreach (array_keys($this->files()) as $name) {
                unlink("$files/$name");
            }
            $this->put('42.aes', receipt_encrypt('1003', '', "%PDF synthetic\n%%EOF\n"));
            chown($legacy, $uid);
            chgrp($legacy, $gid);
            chmod($legacy, $mode);
            $prefix = 'setpriv --reuid=65534 --regid=65534 ' . ($groups === '' ? '--clear-groups' : $groups) . ' ';
            self::assertSame([0, "42.aes: migrated (voucher 1003); 42.aes kept until --delete-legacy$warning\n"
                . self::summary([1, 0, 0, 0, 0]), ''], self::command($arguments, $prefix), $label);
            clearstatcache();
            self::assertSame([$newUid, $newGid, $mode], [fileowner("$files/42.v2.aes"), filegroup("$files/42.v2.aes"),
                fileperms("$files/42.v2.aes") & 07777], $label);
        }
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
