<?php
/*
 * Receipt storage helpers in functions.php: key validation and fingerprint,
 * the file names (legacy files/<n>.aes, files/<n>.v2.aes), the v2 format
 * (contract vectors from the openssl extension in
 * tests/fixtures/receipt-v2/vectors.json), atomic writes, storing new
 * receipts and reading <n>.v2.aes files.
 *
 * Also runs on PHP 5.3 (PHPUnit 4.8): no PHP 5.4+ syntax or functions
 * (hex2bin() comes from tests/php53/bootstrap.php there).
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
        $cases = array();
        foreach (self::fixture() as $group => $vectors) {
            if ($group === 'vectors') {
                foreach ($vectors as $vector) {
                    $cases[$vector['name']] = array($vector);
                }
            }
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
        self::assertTrue(receipt_equals('', ''));
        self::assertTrue(receipt_equals("a\0b", "a\0b"));
        self::assertFalse(receipt_equals('abc', 'abd'));
        self::assertFalse(receipt_equals('abc', 'xbc'));
        self::assertFalse(receipt_equals('abc', 'ab'));
        self::assertFalse(receipt_equals('ab', 'abc'));
    }

    /**
     * @dataProvider v2Provider
     */
    public function testV2FormatMatchesTheVectors(array $vector)
    {
        $plain = hex2bin($vector['plain']);
        $file = receipt_v2_encrypt($vector['key'], $vector['number'], $plain, hex2bin($vector['iv']));
        self::assertSame($vector['file'], bin2hex($file));
        self::assertSame($plain, receipt_v2_decrypt($vector['key'], $vector['number'], hex2bin($vector['file'])));
        self::assertSame(68 + 16 * intval(floor(strlen($plain) / 16)), strlen($file));
    }

    public function testV2UsesARandomIv()
    {
        $vector = self::vector('pdf document');
        $plain = hex2bin($vector['plain']);
        $first = receipt_v2_encrypt(self::TEST_KEY, '42', $plain);
        $second = receipt_v2_encrypt(self::TEST_KEY, 42, $plain);
        self::assertSame('PGR2', substr($first, 0, 4));
        self::assertNotSame(substr($first, 4, 16), substr($second, 4, 16));
        self::assertSame(strlen($first), strlen($second));
        self::assertSame($plain, receipt_v2_decrypt(self::TEST_KEY, '42', $first));
        self::assertSame($plain, receipt_v2_decrypt(self::TEST_KEY, 42, $second));
    }

    public function testV2RejectsWrongKeysNumbersAndModifiedFiles()
    {
        $vector = self::vector('pdf document');
        $file = hex2bin($vector['file']);
        self::assertSame(hex2bin($vector['plain']), receipt_v2_decrypt(self::TEST_KEY, '42', $file));
        self::assertFalse(receipt_v2_decrypt(self::OTHER_KEY, '42', $file));
        self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '43', $file));
        self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '420', $file));
        foreach (array(0, 3, 4, 19, 20, strlen($file) - 33, strlen($file) - 32, strlen($file) - 1) as $offset) {
            $modified = $file;
            $modified[$offset] = chr(ord($modified[$offset]) ^ 1);
            self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '42', $modified), "byte $offset");
        }
        self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '42', substr($file, 0, -1)));
        self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '42', substr($file, 0, -16)));
        self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '42', $file . 'x'));
        self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '42', false));
        self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '42', ''));
        // The shortest file (empty plain text) is 68 bytes.
        $emptyVector = self::vector('empty');
        $empty = hex2bin($emptyVector['file']);
        self::assertSame(68, strlen($empty));
        self::assertSame('', receipt_v2_decrypt(self::TEST_KEY, '42', $empty));
        self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '42', substr($empty, 0, 67)));
        // A file with a valid MAC is rejected without the magic bytes.
        list(, $macKey) = receipt_v2_keys(self::TEST_KEY, '42');
        $body = 'PGR3' . substr($empty, 4, -32);
        self::assertFalse(receipt_v2_decrypt(self::TEST_KEY, '42', $body . hash_hmac('sha256', $body, $macKey, true)));
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

    public function testStoreEncryptsWithTheConfiguredKeyAndTheFileNumber()
    {
        $pdf = self::vector('pdf document');
        $jpeg = self::vector('jpeg with trailing NUL bytes');
        $GLOBALS['key'] = self::TEST_KEY;
        $directory = $this->directory;
        list($results, $log, $diagnostics) = self::logged(function () use ($directory, $pdf, $jpeg) {
            return array(
                receipt_store($directory, '11', hex2bin($pdf['plain'])),
                receipt_store($directory, 12, hex2bin($jpeg['plain'])),
            );
        });
        self::assertSame(array(true, true), $results);
        self::assertSame(array('11.v2.aes', '12.v2.aes'), $this->names());
        self::assertSame(hex2bin($pdf['plain']),
            receipt_v2_decrypt(self::TEST_KEY, '11', file_get_contents($this->directory . '/11.v2.aes')));
        self::assertSame(hex2bin($jpeg['plain']),
            receipt_v2_decrypt(self::TEST_KEY, '12', file_get_contents($this->directory . '/12.v2.aes')));
        self::assertSame('', $log);
        self::assertSame(array(), $diagnostics);
    }

    public function testStoreRefusesWithoutAValidKey()
    {
        $directory = $this->directory;
        foreach (array(null, '', 'CHANGE-ME', 'short-key', 4711) as $key) {
            if ($key === null) {
                unset($GLOBALS['key']);
            } else {
                $GLOBALS['key'] = $key;
            }
            list($stored, $log, $diagnostics) = self::logged(function () use ($directory) {
                return receipt_store($directory, '13', '%PDF synthetic');
            });
            self::assertFalse($stored);
            self::assertSame(array(), $this->names());
            self::assertStringContainsString('pgacc: receipt not stored: no valid receipt key ($key) configured', $log);
            self::assertSame(array(), $diagnostics);
        }
    }

    public function testStoreReportsAFailedWrite()
    {
        $GLOBALS['key'] = self::TEST_KEY;
        $directory = $this->directory . '/missing';
        list($stored, $log, $diagnostics) = self::logged(function () use ($directory) {
            return receipt_store($directory, '14', '%PDF');
        });
        self::assertFalse($stored);
        self::assertSame(array(), $this->names());
        self::assertStringContainsString('pgacc: receipt 14.v2.aes could not be written', $log);
        self::assertStringNotContainsString(self::TEST_KEY, $log);
        self::assertSame(array(), $diagnostics);
    }

    public function testReadV2()
    {
        $vector = self::vector('pdf document');
        $path = $this->directory . '/42.v2.aes';
        file_put_contents($path, hex2bin($vector['file']));
        $fingerprints = self::fixture();
        $fingerprints = $fingerprints['fingerprints'];

        $GLOBALS['key'] = self::TEST_KEY;
        list($plain, $log) = self::logged(function () use ($path) {
            return receipt_read_v2($path, '42');
        });
        self::assertSame(hex2bin($vector['plain']), $plain);
        self::assertSame('', $log);

        // Read as another file number (a copied file): rejected and logged.
        list($plain, $log) = self::logged(function () use ($path) {
            return receipt_read_v2($path, '43');
        });
        self::assertFalse($plain);
        // Neither the key nor its fingerprint goes to the web server's log.
        self::assertStringContainsString('pgacc: receipt 43.v2.aes could not be decrypted (wrong key or modified file)', $log);
        self::assertStringNotContainsString(self::TEST_KEY, $log);
        self::assertStringNotContainsString($fingerprints[self::TEST_KEY], $log);
        self::assertStringNotContainsString('fingerprint', $log);

        $GLOBALS['key'] = self::OTHER_KEY;
        list($plain, $log) = self::logged(function () use ($path) {
            return receipt_read_v2($path, '42');
        });
        self::assertFalse($plain);
        self::assertStringContainsString('pgacc: receipt 42.v2.aes could not be decrypted (wrong key or modified file)', $log);
        self::assertStringNotContainsString($fingerprints[self::OTHER_KEY], $log);

        $GLOBALS['key'] = self::TEST_KEY;
        $missing = $this->directory . '/missing.v2.aes';
        list($plain, $log, $diagnostics) = self::logged(function () use ($missing) {
            return receipt_read_v2($missing, '44');
        });
        self::assertFalse($plain);
        self::assertStringContainsString('pgacc: receipt 44.v2.aes could not be decrypted', $log);
        self::assertSame(array(), $diagnostics);

        $GLOBALS['key'] = 'CHANGE-ME';
        list($plain, $log) = self::logged(function () use ($path) {
            return receipt_read_v2($path, '42');
        });
        self::assertFalse($plain);
        self::assertStringContainsString('pgacc: receipt 42.v2.aes not decrypted: no valid receipt key ($key) configured', $log);
        self::assertStringNotContainsString('fingerprint', $log);
    }
}
