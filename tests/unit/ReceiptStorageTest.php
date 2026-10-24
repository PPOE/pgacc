<?php
/*
 * Receipt storage helpers in functions.php: key validation and fingerprint,
 * the file names (legacy files/<n>.aes, files/<n>.v2.aes), the v2 format
 * (contract vectors from the openssl extension in
 * tests/fixtures/receipt-v2/vectors.json), atomic writes, storing new
 * receipts and reading <n>.v2.aes files.
 *
 * Also runs on PHP 5.3 (PHPUnit 4.8): no PHP 5.4+ syntax or functions
 * (hex2bin() comes from tests/php53/bootstrap.php there). The 20 MB memory
 * check runs tests/receipt-memory.php in its own process.
 */

final class ReceiptStorageTest extends FunctionsTestCase
{
    const TEST_KEY = 'synthetic-test-only-encryption-key';
    const OTHER_KEY = 'another-synthetic-receipt-key';

    private $directory;
    private $hadKey;
    private $previousKey;

    /**
     * @before
     */
    public function createDirectory()
    {
        $this->directory = sys_get_temp_dir() . '/pgacc-receipt-storage-' . uniqid('', true);
        mkdir($this->directory, 0700);
        $this->hadKey = array_key_exists('key', $GLOBALS);
        $this->previousKey = $this->hadKey ? $GLOBALS['key'] : null;
    }

    /**
     * @after
     */
    public function removeDirectory()
    {
        if ($this->hadKey) {
            $GLOBALS['key'] = $this->previousKey;
        } else {
            unset($GLOBALS['key']);
        }
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

    private static function fixture()
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/receipt-v2/vectors.json'), true);
    }

    public static function v2Provider()
    {
        $fixture = self::fixture();
        $cases = array();
        foreach ($fixture['vectors'] as $vector) {
            $cases[$vector['name']] = array($vector);
        }
        return $cases;
    }

    private static function vector($name)
    {
        foreach (self::v2Provider() as $case) {
            if ($case[0]['name'] === $name) {
                return $case[0];
            }
        }
        throw new RuntimeException("no v2 vector named $name");
    }

    // The plain text of a vector (long ones are stored as a recipe).
    private static function plain($vector)
    {
        if (isset($vector['plain'])) {
            return hex2bin($vector['plain']);
        }
        list($prefix, $unit, $times, $suffix) = $vector['plain_parts'];
        return hex2bin($prefix) . str_repeat(hex2bin($unit), $times) . hex2bin($suffix);
    }

    // Asserts that $file is the vector's file (long ones by length and SHA-256).
    private static function assertVectorFile($vector, $file, $label)
    {
        if (isset($vector['file'])) {
            self::assertSame($vector['file'], bin2hex($file), $label);
        } else {
            self::assertSame(array($vector['file_length'], $vector['file_sha256']),
                array(strlen($file), hash('sha256', $file)), $label);
        }
    }

    // The bytes of a vector's file.
    private static function file($vector)
    {
        $file = receipt_v2_encrypt($vector['key'], $vector['number'], self::plain($vector), hex2bin($vector['iv']));
        self::assertVectorFile($vector, $file, $vector['name']);
        return $file;
    }

    private function names()
    {
        $names = array_values(array_diff(scandir($this->directory), array('.', '..')));
        sort($names);
        return $names;
    }

    /*
     * Runs $callback with error_log() redirected to a temporary file and
     * diagnostics collected; returns array(result, log text, diagnostics).
     */
    private static function logged($callback)
    {
        $log = tempnam(sys_get_temp_dir(), 'pgacc-log-');
        $previous = ini_set('error_log', $log);
        $diagnostics = array();
        set_error_handler(function ($type, $message) use (&$diagnostics) {
            if (error_reporting() & $type) {
                $diagnostics[] = $message;
            }
            return true;
        });
        try {
            $result = call_user_func($callback);
        } catch (Exception $exception) {
            restore_error_handler();
            ini_set('error_log', $previous);
            unlink($log);
            throw $exception;
        }
        restore_error_handler();
        ini_set('error_log', $previous);
        $text = file_get_contents($log);
        unlink($log);
        return array($result, $text, $diagnostics);
    }

    public function testKeyValidation()
    {
        self::assertTrue(receipt_key_is_valid(self::TEST_KEY));
        self::assertTrue(receipt_key_is_valid('0123456789abcdef'));
        self::assertFalse(receipt_key_is_valid('0123456789abcde'));
        self::assertFalse(receipt_key_is_valid(''));
        self::assertFalse(receipt_key_is_valid('CHANGE-ME'));
        self::assertFalse(receipt_key_is_valid('CHANGE-ME-CHANGE-ME-CHANGE-ME'));
        self::assertFalse(receipt_key_is_valid('our-key-CHANGE-ME-later'));
        self::assertFalse(receipt_key_is_valid(1234567890123456));
        self::assertFalse(receipt_key_is_valid(array(self::TEST_KEY)));
        self::assertFalse(receipt_key_is_valid(null));
    }

    public function testConfiguredKeyIsTheValidGlobalKey()
    {
        // index.php requires constants.php in global scope; the receipt code
        // runs inside functions, so it must read $GLOBALS['key'].
        $GLOBALS['key'] = self::TEST_KEY;
        self::assertSame(self::TEST_KEY, receipt_configured_key());
        foreach (array('', 'CHANGE-ME', 'too-short-key', 1234567890123456789) as $key) {
            $GLOBALS['key'] = $key;
            self::assertSame('', receipt_configured_key(), var_export($key, true));
        }
        unset($GLOBALS['key']);
        self::assertSame('', receipt_configured_key());
    }

    public function testKeyFingerprint()
    {
        $fixture = self::fixture();
        self::assertSame($fixture['fingerprints'][self::TEST_KEY], receipt_key_fingerprint(self::TEST_KEY));
        self::assertSame($fixture['fingerprints'][self::OTHER_KEY], receipt_key_fingerprint(self::OTHER_KEY));
        self::assertSame(8, strlen(receipt_key_fingerprint(self::TEST_KEY)));
    }

    public function testFileNames()
    {
        self::assertSame('/srv/files/42.v2.aes', receipt_v2_path('/srv/files', 42));
        self::assertSame('/srv/files/42.aes', receipt_legacy_path('/srv/files', '42'));
    }

    public function testContentTypes()
    {
        self::assertSame('application/pdf', receipt_content_type("%PDF-1.4\n"));
        self::assertSame('application/pdf', receipt_content_type('%PDF'));
        self::assertSame('image/jpeg', receipt_content_type("\xFF\xD8\xFF\xE0\x00\x10JFIF"));
        self::assertSame('image/jpeg', receipt_content_type("\xFF\xD8\xFF"));
        foreach (array('%PD', "\xFF\xD8", "\xFF\xD8\xFE", ' %PDF', "\x89PNG\r\n", '', str_repeat("\0", 32)) as $plain) {
            self::assertSame('application/octet-stream', receipt_content_type($plain), bin2hex($plain));
            self::assertFalse(receipt_has_known_type($plain), bin2hex($plain));
        }
        self::assertTrue(receipt_has_known_type('%PDF'));
        self::assertTrue(receipt_has_known_type("\xFF\xD8\xFF"));
    }

    public function testEquals()
    {
        foreach (array('receipt_equals', 'receipt_equals_fallback') as $equals) {
            self::assertTrue($equals('', ''), $equals);
            self::assertTrue($equals("a\0b", "a\0b"), $equals);
            self::assertFalse($equals('abc', 'abd'), $equals);
            self::assertFalse($equals('abc', 'xbc'), $equals);
            self::assertFalse($equals('abc', 'ab'), $equals);
            self::assertFalse($equals('ab', 'abc'), $equals);
        }
    }

    public function testHkdfMatchesRfc5869()
    {
        // RFC 5869, appendix A, test cases 1 and 3 (SHA-256).
        $ikm = str_repeat("\x0b", 22);
        $prk = receipt_hkdf_extract(hex2bin('000102030405060708090a0b0c'), $ikm);
        self::assertSame('077709362c2e32df0ddc3f0dc47bba6390b6c73bb50f9c3122ec844ad7c2b3e5', bin2hex($prk));
        self::assertSame('3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865',
            bin2hex(receipt_hkdf_expand($prk, hex2bin('f0f1f2f3f4f5f6f7f8f9'), 42)));
        $prk = receipt_hkdf_extract('', $ikm);
        self::assertSame('19ef24a32c717b167f33a91d6f648bdf96596776afdb6377ac434c1c293ccb04', bin2hex($prk));
        self::assertSame('8da4e775a563c18f715f802a063c5a31b8a11f5c5ee1879ec3454e5f3c738d2d9d201395faa4b61a96c8',
            bin2hex(receipt_hkdf_expand($prk, '', 42)));
        self::assertSame(1, strlen(receipt_hkdf_expand($prk, '', 1)));
        self::assertSame(100, strlen(receipt_hkdf_expand($prk, 'x', 100)));
    }

    public function testHkdfMatchesHashHkdf()
    {
        if (!function_exists('hash_hkdf')) {
            self::markTestSkipped('hash_hkdf() needs PHP 7.1.2');
        }
        foreach (array(1, 31, 32, 33, 64, 100) as $length) {
            foreach (array('', 'pgacc-receipt-v2|enc|42') as $info) {
                foreach (array('', 'salt') as $salt) {
                    self::assertSame(
                        bin2hex(hash_hkdf('sha256', self::TEST_KEY, $length, $info, $salt)),
                        bin2hex(receipt_hkdf_expand(receipt_hkdf_extract($salt, self::TEST_KEY), $info, $length)),
                        "$length/$info/$salt"
                    );
                }
            }
        }
        list($encryptionKey, $macKey) = receipt_v2_keys(self::TEST_KEY, 42);
        self::assertSame(hash_hkdf('sha256', self::TEST_KEY, 32, 'pgacc-receipt-v2|enc|42'), $encryptionKey);
        self::assertSame(hash_hkdf('sha256', self::TEST_KEY, 32, 'pgacc-receipt-v2|mac|42'), $macKey);
    }

    /**
     * @dataProvider v2Provider
     */
    public function testV2FormatMatchesTheVectors(array $vector)
    {
        $plain = self::plain($vector);
        $file = self::file($vector);
        self::assertSame($plain, receipt_v2_decrypt($vector['key'], $vector['number'], $file));
        self::assertSame(68 + 16 * intval(floor(strlen($plain) / 16)), strlen($file));

        // The same bytes in 64 KiB chunks.
        $in = fopen('php://memory', 'w+');
        fwrite($in, $plain);
        rewind($in);
        $out = fopen('php://memory', 'w+');
        self::assertTrue(receipt_v2_encrypt_stream($vector['key'], $vector['number'], $in, $out, hex2bin($vector['iv'])));
        rewind($out);
        self::assertVectorFile($vector, stream_get_contents($out), $vector['name'] . ', streamed');

        // Checked and sent in chunks.
        $path = $this->directory . '/42.v2.aes';
        file_put_contents($path, $file);
        $receipt = receipt_v2_open_with($vector['key'], $vector['number'], $path);
        self::assertSame(strlen($plain), $receipt['length']);
        self::assertSame(substr($plain, 0, 16), $receipt['head']);
        ob_start();
        receipt_v2_send($receipt);
        self::assertTrue(ob_get_clean() === $plain);
        self::assertFalse(is_resource($receipt['handle']));
    }

    public function testV2UsesARandomIv()
    {
        $vector = self::vector('pdf document');
        $plain = self::plain($vector);
        $first = receipt_v2_encrypt(self::TEST_KEY, '42', $plain);
        $second = receipt_v2_encrypt(self::TEST_KEY, 42, $plain);
        self::assertSame('PGR2', substr($first, 0, 4));
        self::assertNotSame(substr($first, 4, 16), substr($second, 4, 16));
        self::assertSame(strlen($first), strlen($second));
        self::assertSame($plain, receipt_v2_decrypt(self::TEST_KEY, '42', $first));
        self::assertSame($plain, receipt_v2_decrypt(self::TEST_KEY, 42, $second));
        $in = fopen('php://memory', 'w+');
        fwrite($in, $plain);
        rewind($in);
        $out = fopen('php://memory', 'w+');
        self::assertTrue(receipt_v2_encrypt_stream(self::TEST_KEY, 42, $in, $out));
        rewind($in);
        $out2 = fopen('php://memory', 'w+');
        self::assertTrue(receipt_v2_encrypt_stream(self::TEST_KEY, 42, $in, $out2));
        rewind($out);
        rewind($out2);
        $third = stream_get_contents($out);
        $fourth = stream_get_contents($out2);
        self::assertNotSame(substr($third, 4, 16), substr($fourth, 4, 16));
        self::assertSame($plain, receipt_v2_decrypt(self::TEST_KEY, '42', $third));
        self::assertSame($plain, receipt_v2_decrypt(self::TEST_KEY, '42', $fourth));
    }

    public function testStreamReportsAFailedWrite()
    {
        $in = fopen('php://memory', 'w+');
        fwrite($in, '%PDF synthetic');
        rewind($in);
        $path = $this->directory . '/read-only.v2.aes';
        touch($path);
        $out = fopen($path, 'rb');
        self::assertFalse(@receipt_v2_encrypt_stream(self::TEST_KEY, 42, $in, $out));
        fclose($out);
        self::assertSame('', file_get_contents($path));
    }

    // Both decryptions reject $file for key and number.
    private function assertRejected($key, $number, $file, $label)
    {
        self::assertFalse(receipt_v2_decrypt($key, $number, $file), $label);
        $path = $this->directory . '/rejected.v2.aes';
        file_put_contents($path, $file);
        self::assertFalse(receipt_v2_open_with($key, $number, $path), $label . ', opened');
    }

    // A file with a valid MAC over $body ("PGR2" or another magic, IV, blocks).
    private static function withMac($body)
    {
        list(, $macKey) = receipt_v2_keys(self::TEST_KEY, '42');
        return $body . hash_hmac('sha256', $body, $macKey, true);
    }

    public function testV2RejectsWrongKeysNumbersAndModifiedFiles()
    {
        $vector = self::vector('pdf document');
        $file = self::file($vector);
        $this->assertRejected(self::OTHER_KEY, '42', $file, 'other key');
        $this->assertRejected(self::TEST_KEY, '43', $file, 'other number');
        $this->assertRejected(self::TEST_KEY, '420', $file, 'longer number');
        foreach (array(0, 3, 4, 19, 20, strlen($file) - 33, strlen($file) - 32, strlen($file) - 1) as $offset) {
            $modified = $file;
            $modified[$offset] = chr(ord($modified[$offset]) ^ 1);
            $this->assertRejected(self::TEST_KEY, '42', $modified, "byte $offset");
        }
        $this->assertRejected(self::TEST_KEY, '42', substr($file, 0, -1), 'truncated');
        $this->assertRejected(self::TEST_KEY, '42', substr($file, 0, -16), 'truncated block');
        $this->assertRejected(self::TEST_KEY, '42', $file . 'x', 'extended');
        $this->assertRejected(self::TEST_KEY, '42', '', 'empty');
        self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '42', false));
        self::assertFalse(receipt_v2_open_with(self::TEST_KEY, '42', $this->directory . '/missing.v2.aes'));

        // A long file modified in a middle chunk.
        $long = self::file(self::vector('three chunks and a bit'));
        $long[100000] = chr(ord($long[100000]) ^ 1);
        $this->assertRejected(self::TEST_KEY, '42', $long, 'long file');

        // The shortest file (empty plain text) is 68 bytes.
        $empty = self::file(self::vector('empty'));
        self::assertSame(68, strlen($empty));
        self::assertSame('', receipt_v2_decrypt(self::TEST_KEY, '42', $empty));
        $this->assertRejected(self::TEST_KEY, '42', substr($empty, 0, 67), '67 bytes');
        // Valid MACs, but another magic, no cipher block, or bad padding.
        $iv = str_repeat("\x01", 16);
        $this->assertRejected(self::TEST_KEY, '42', self::withMac('PGR3' . substr($empty, 4, 32)), 'other magic');
        $this->assertRejected(self::TEST_KEY, '42', self::withMac('PGR2' . $iv), 'no block');
        list($encryptionKey) = receipt_v2_keys(self::TEST_KEY, '42');
        $blocks = array(
            'padding 0' => str_repeat("\0", 16),
            'padding 17' => str_repeat("\x11", 16),
            'inconsistent padding' => str_repeat('a', 14) . "\x01\x02",
        );
        foreach ($blocks as $label => $block) {
            $ciphertext = receipt_v2_stream_cipher($encryptionKey, $iv)->encrypt($block);
            $path = $this->directory . '/padding.v2.aes';
            file_put_contents($path, self::withMac('PGR2' . $iv . $ciphertext));
            self::assertFalse(receipt_v2_open_with(self::TEST_KEY, '42', $path), $label);
        }
        $ciphertext = receipt_v2_stream_cipher($encryptionKey, $iv)->encrypt(str_repeat('a', 15) . "\x01");
        $path = $this->directory . '/padding.v2.aes';
        file_put_contents($path, self::withMac('PGR2' . $iv . $ciphertext));
        $receipt = receipt_v2_open_with(self::TEST_KEY, '42', $path);
        self::assertSame(array(15, str_repeat('a', 15)), array($receipt['length'], $receipt['head']));
        fclose($receipt['handle']);
    }

    public function testWriteFileReplacesTheTargetWithoutLeavingATemporaryFile()
    {
        $path = $this->directory . '/7.v2.aes';
        $inodes = array();
        list($written, $log, $diagnostics) = self::logged(function () use ($path, &$inodes) {
            $first = receipt_write_file($path, 'first');
            clearstatcache();
            $inodes[] = fileinode($path);
            $second = receipt_write_file($path, 'second');
            clearstatcache();
            $inodes[] = fileinode($path);
            return $first && $second;
        });
        self::assertTrue($written);
        self::assertSame('second', file_get_contents($path));
        // A new file was renamed into place; the old one was not rewritten.
        self::assertNotSame($inodes[0], $inodes[1]);
        self::assertSame(array('7.v2.aes'), $this->names());
        self::assertSame('', $log);
        self::assertSame(array(), $diagnostics);
    }

    public function testWriteFileFailures()
    {
        // The temporary file cannot be written: no such directory.
        $missing = $this->directory . '/missing/8.v2.aes';
        // The rename fails: the target is a directory, which is left alone;
        // the temporary file is removed.
        mkdir($this->directory . '/9.v2.aes');
        file_put_contents($this->directory . '/9.v2.aes/content', 'kept');
        $directory = $this->directory . '/9.v2.aes';
        list($results, $log, $diagnostics) = self::logged(function () use ($missing, $directory) {
            return array(receipt_write_file($missing, 'data'), receipt_write_file($directory, 'data'));
        });
        self::assertSame(array(false, false), $results);
        self::assertSame(array('9.v2.aes'), $this->names());
        self::assertSame('kept', file_get_contents($this->directory . '/9.v2.aes/content'));
        self::assertSame('', $log);
        self::assertSame(array(), $diagnostics);
    }

    // The receipt directory and an uploaded file with $content.
    private function upload($content)
    {
        mkdir($this->directory . '/files');
        $source = $this->directory . '/upload.tmp';
        file_put_contents($source, $content);
        return $source;
    }

    public function testStoreEncryptsTheUploadWithTheConfiguredKeyAndTheFileNumber()
    {
        $GLOBALS['key'] = self::TEST_KEY;
        $directory = $this->directory . '/files';
        $pdf = self::plain(self::vector('pdf document'));
        $long = self::plain(self::vector('three chunks and a bit'));
        $source = $this->upload($pdf);
        $longSource = $this->directory . '/long.tmp';
        file_put_contents($longSource, $long);
        list($results, $log, $diagnostics) = self::logged(function () use ($directory, $source, $longSource) {
            return array(receipt_store($directory, '11', $source), receipt_store($directory, 12, $longSource));
        });
        self::assertSame(array(true, true), $results);
        self::assertSame(array('11.v2.aes', '12.v2.aes'), array_values(array_diff(scandir($directory), array('.', '..'))));
        self::assertSame($pdf, receipt_v2_decrypt(self::TEST_KEY, '11', file_get_contents($directory . '/11.v2.aes')));
        self::assertSame($long, receipt_v2_decrypt(self::TEST_KEY, '12', file_get_contents($directory . '/12.v2.aes')));
        self::assertSame('', $log);
        self::assertSame(array(), $diagnostics);
    }

    public function testStoreRefusesWithoutAValidKey()
    {
        $source = $this->upload('%PDF synthetic');
        $directory = $this->directory . '/files';
        foreach (array(null, '', 'CHANGE-ME', 'short-key', 4711) as $key) {
            if ($key === null) {
                unset($GLOBALS['key']);
            } else {
                $GLOBALS['key'] = $key;
            }
            list($stored, $log, $diagnostics) = self::logged(function () use ($directory, $source) {
                return receipt_store($directory, '13', $source);
            });
            self::assertFalse($stored);
            self::assertSame(array('.', '..'), scandir($directory));
            self::assertStringContainsString('pgacc: receipt not stored: no valid receipt key ($key) configured', $log);
            self::assertSame(array(), $diagnostics);
        }
    }

    public function testStoreReportsFailures()
    {
        $GLOBALS['key'] = self::TEST_KEY;
        $source = $this->upload('%PDF');
        $directory = $this->directory . '/files';
        mkdir($directory . '/16.v2.aes');
        $cases = array(
            'no upload' => array($directory, '14', $this->directory . '/missing.tmp'),
            'no directory' => array($this->directory . '/missing', '15', $source),
            'target is a directory' => array($directory, '16', $source),
        );
        foreach ($cases as $label => $case) {
            list($stored, $log, $diagnostics) = self::logged(function () use ($case) {
                return receipt_store($case[0], $case[1], $case[2]);
            });
            self::assertFalse($stored, $label);
            self::assertStringContainsString("pgacc: receipt {$case[1]}.v2.aes could not be written", $log, $label);
            self::assertStringNotContainsString(self::TEST_KEY, $log, $label);
            self::assertSame(array(), $diagnostics, $label);
        }
        // Only the directory that was in the way is left.
        self::assertSame(array('16.v2.aes'), array_values(array_diff(scandir($directory), array('.', '..'))));
        self::assertSame(array('.', '..'), scandir($directory . '/16.v2.aes'));
    }

    public function testOpenV2()
    {
        $vector = self::vector('pdf document');
        $path = $this->directory . '/42.v2.aes';
        file_put_contents($path, self::file($vector));
        $fingerprints = self::fixture();
        $fingerprints = $fingerprints['fingerprints'];

        $GLOBALS['key'] = self::TEST_KEY;
        list($receipt, $log) = self::logged(function () use ($path) {
            return receipt_v2_open($path, '42');
        });
        self::assertSame(strlen(self::plain($vector)), $receipt['length']);
        fclose($receipt['handle']);
        self::assertSame('', $log);

        // Read as another file number (a copied file): rejected and logged.
        list($receipt, $log) = self::logged(function () use ($path) {
            return receipt_v2_open($path, '43');
        });
        self::assertFalse($receipt);
        // Neither the key nor its fingerprint goes to the web server's log.
        self::assertStringContainsString('pgacc: receipt 43.v2.aes could not be decrypted (wrong key or modified file)', $log);
        self::assertStringNotContainsString(self::TEST_KEY, $log);
        self::assertStringNotContainsString($fingerprints[self::TEST_KEY], $log);
        self::assertStringNotContainsString('fingerprint', $log);

        $GLOBALS['key'] = self::OTHER_KEY;
        list($receipt, $log) = self::logged(function () use ($path) {
            return receipt_v2_open($path, '42');
        });
        self::assertFalse($receipt);
        self::assertStringContainsString('pgacc: receipt 42.v2.aes could not be decrypted (wrong key or modified file)', $log);
        self::assertStringNotContainsString($fingerprints[self::OTHER_KEY], $log);

        $GLOBALS['key'] = self::TEST_KEY;
        $missing = $this->directory . '/missing.v2.aes';
        list($receipt, $log, $diagnostics) = self::logged(function () use ($missing) {
            return receipt_v2_open($missing, '44');
        });
        self::assertFalse($receipt);
        self::assertStringContainsString('pgacc: receipt 44.v2.aes could not be decrypted', $log);
        self::assertSame(array(), $diagnostics);

        $GLOBALS['key'] = 'CHANGE-ME';
        list($receipt, $log) = self::logged(function () use ($path) {
            return receipt_v2_open($path, '42');
        });
        self::assertFalse($receipt);
        self::assertStringContainsString('pgacc: receipt 42.v2.aes not decrypted: no valid receipt key ($key) configured', $log);
    }

    public function testMemoryStaysBoundedForA20MegabyteReceipt()
    {
        // Upload and download of a 20 MB receipt in a separate process whose
        // memory_limit (40M) is below what whole-file processing needs.
        $binary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $script = dirname(__DIR__) . '/receipt-memory.php';
        $process = proc_open(escapeshellarg($binary) . ' -d memory_limit=40M ' . escapeshellarg($script) . ' '
            . escapeshellarg($this->directory), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(array(0, ''), array(proc_close($process), $errors), $output);
        self::assertStringStartsWith("20971520 bytes stored and sent; peak memory ", $output);
    }
}
