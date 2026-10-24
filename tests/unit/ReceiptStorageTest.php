<?php
/*
 * Receipt storage helpers in functions.php: the configured key, the file
 * names (legacy files/<n>.aes, files/<n>.v2.aes), atomic writes, storing new
 * receipts and reading <n>.v2.aes files. The byte-exact ciphertexts are the
 * mcrypt vectors (tests/fixtures/mcrypt/vectors.json).
 *
 * Must stay compatible with PHP 5.6 (PHPUnit 5.7).
 */

final class ReceiptStorageTest extends FunctionsTestCase
{
    const TEST_KEY = 'synthetic-test-only-encryption-key';

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

    public function testConfiguredKeyIsTheGlobalKey()
    {
        // index.php requires constants.php in global scope; the receipt code
        // runs inside functions, so it must read $GLOBALS['key'].
        $GLOBALS['key'] = self::TEST_KEY;
        self::assertSame(self::TEST_KEY, receipt_configured_key());
        $GLOBALS['key'] = '';
        self::assertSame('', receipt_configured_key());
        $GLOBALS['key'] = 12345;
        self::assertSame('', receipt_configured_key());
        $GLOBALS['key'] = array(self::TEST_KEY);
        self::assertSame('', receipt_configured_key());
        unset($GLOBALS['key']);
        self::assertSame('', receipt_configured_key());
    }

    public function testFileNames()
    {
        self::assertSame('/srv/files/42.v2.aes', receipt_v2_path('/srv/files', 42));
        self::assertSame('/srv/files/42.aes', receipt_legacy_path('/srv/files', '42'));
    }

    public function testKnownTypes()
    {
        self::assertTrue(receipt_has_known_type("%PDF-1.4\n"));
        self::assertTrue(receipt_has_known_type('%PDF'));
        self::assertTrue(receipt_has_known_type("\xFF\xD8\xFF\xE0\x00\x10JFIF"));
        self::assertTrue(receipt_has_known_type("\xFF\xD8\xFF"));
        self::assertFalse(receipt_has_known_type('%PD'));
        self::assertFalse(receipt_has_known_type("\xFF\xD8"));
        self::assertFalse(receipt_has_known_type("\xFF\xD8\xFE"));
        self::assertFalse(receipt_has_known_type(' %PDF'));
        self::assertFalse(receipt_has_known_type("\x89PNG\r\n"));
        self::assertFalse(receipt_has_known_type(''));
        self::assertFalse(receipt_has_known_type(str_repeat("\0", 32)));
    }

    public function testWriteFileReplacesTheTargetWithoutLeavingATemporaryFile()
    {
        $path = $this->directory . '/7.v2.aes';
        list($written, $log, $diagnostics) = self::logged(function () use ($path) {
            return receipt_write_file($path, 'first') && receipt_write_file($path, 'second');
        });
        self::assertTrue($written);
        self::assertSame('second', file_get_contents($path));
        self::assertSame(array('7.v2.aes'), $this->names());
        self::assertSame('', $log);
        self::assertSame(array(), $diagnostics);
    }

    public function testWriteFileFailures()
    {
        // The temporary file cannot be written (a directory has its name).
        mkdir($this->directory . '/8.v2.aes.tmp');
        $path = $this->directory . '/8.v2.aes';
        // The rename fails: the target is a directory; the temporary file is removed.
        mkdir($this->directory . '/9.v2.aes');
        $other = $this->directory . '/9.v2.aes';
        list($results, $log, $diagnostics) = self::logged(function () use ($path, $other) {
            return array(receipt_write_file($path, 'data'), receipt_write_file($other, 'data'));
        });
        self::assertSame(array(false, false), $results);
        self::assertSame(array('8.v2.aes.tmp', '9.v2.aes'), $this->names());
        self::assertSame(array('.', '..'), scandir($this->directory . '/9.v2.aes'));
        self::assertSame('', $log);
        self::assertSame(array(), $diagnostics);
    }

    public function testStoreEncryptsWithTheConfiguredKey()
    {
        $pdf = self::vector('pdf document, test-constants key');
        $jpeg = self::vector('jpeg with trailing NUL bytes, test-constants key');
        self::assertSame(self::TEST_KEY, $pdf['key']);
        self::assertSame(self::TEST_KEY, $jpeg['key']);
        $GLOBALS['key'] = self::TEST_KEY;
        $directory = $this->directory;
        list($results, $log, $diagnostics) = self::logged(function () use ($directory, $pdf, $jpeg) {
            return array(
                receipt_store($directory, '11', $pdf['id'], hex2bin($pdf['plain'])),
                receipt_store($directory, 12, $jpeg['id'], hex2bin($jpeg['plain'])),
            );
        });
        self::assertSame(array(true, true), $results);
        self::assertSame(array('11.v2.aes', '12.v2.aes'), $this->names());
        self::assertSame($pdf['cipher'], bin2hex(file_get_contents($this->directory . '/11.v2.aes')));
        self::assertSame($jpeg['cipher'], bin2hex(file_get_contents($this->directory . '/12.v2.aes')));
        self::assertSame('', $log);
        self::assertSame(array(), $diagnostics);
    }

    public function testStoreRefusesWithoutAConfiguredKey()
    {
        $vector = self::vector('pdf document');
        $directory = $this->directory;
        foreach (array(null, '', 4711) as $key) {
            if ($key === null) {
                unset($GLOBALS['key']);
            } else {
                $GLOBALS['key'] = $key;
            }
            list($stored, $log, $diagnostics) = self::logged(function () use ($directory, $vector) {
                return receipt_store($directory, '13', $vector['id'], hex2bin($vector['plain']));
            });
            self::assertFalse($stored);
            self::assertSame(array(), $this->names());
            self::assertStringContainsString('pgacc: receipt not stored: no receipt key ($key) configured', $log);
            self::assertSame(array(), $diagnostics);
        }
    }

    public function testStoreReportsAFailedWrite()
    {
        $GLOBALS['key'] = self::TEST_KEY;
        mkdir($this->directory . '/14.v2.aes.tmp');
        $directory = $this->directory;
        list($stored, $log, $diagnostics) = self::logged(function () use ($directory) {
            return receipt_store($directory, '14', '4711', '%PDF');
        });
        self::assertFalse($stored);
        self::assertSame(array('14.v2.aes.tmp'), $this->names());
        self::assertStringContainsString('pgacc: receipt 14.v2.aes could not be written', $log);
        self::assertStringNotContainsString(self::TEST_KEY, $log);
        self::assertSame(array(), $diagnostics);
    }

    public function testDecryptV2WithTheConfiguredKey()
    {
        $GLOBALS['key'] = self::TEST_KEY;
        foreach (array('pdf document, test-constants key', 'jpeg with trailing NUL bytes, test-constants key') as $name) {
            $vector = self::vector($name);
            list($plain, $log) = self::logged(function () use ($vector) {
                return receipt_decrypt_v2($vector['id'], hex2bin($vector['cipher']), '15.v2.aes');
            });
            self::assertSame($vector['decrypted'], bin2hex($plain), $name);
            self::assertSame('', $log, $name);
        }
    }

    public function testDecryptV2FallsBackToTheLegacySchemeAndLogsIt()
    {
        $GLOBALS['key'] = self::TEST_KEY;
        foreach (array('pdf document', 'jpeg with trailing NUL bytes') as $name) {
            $vector = self::vector($name);
            self::assertSame('', $vector['key']);
            list($plain, $log) = self::logged(function () use ($vector) {
                return receipt_decrypt_v2($vector['id'], hex2bin($vector['cipher']), '16.v2.aes');
            });
            self::assertSame($vector['decrypted'], bin2hex($plain), $name);
            self::assertStringContainsString('pgacc: receipt 16.v2.aes decrypted with the legacy scheme', $log);
            self::assertStringNotContainsString(self::TEST_KEY, $log);
        }
    }

    public function testDecryptV2FailsForOtherContent()
    {
        $GLOBALS['key'] = self::TEST_KEY;
        $configured = self::vector('pdf document, test-constants key');
        $cases = array(
            // Configured-key ciphertext of another voucher.
            'other voucher' => array('815', hex2bin($configured['cipher'])),
            'zero bytes' => array('4711', str_repeat("\0", 64)),
            // A file that could not be read.
            'unreadable' => array('4711', false),
        );
        foreach ($cases as $label => $case) {
            list($plain, $log) = self::logged(function () use ($case) {
                return receipt_decrypt_v2($case[0], $case[1], '17.v2.aes');
            });
            self::assertFalse($plain, $label);
            self::assertStringContainsString('pgacc: receipt 17.v2.aes could not be decrypted', $log, $label);
            self::assertStringNotContainsString('legacy scheme', $log, $label);
            self::assertStringNotContainsString(self::TEST_KEY, $log, $label);
        }
    }

    public function testDecryptV2WithoutAConfiguredKeyOnlyReadsLegacyContent()
    {
        unset($GLOBALS['key']);
        $configured = self::vector('pdf document, test-constants key');
        list($plain, $log) = self::logged(function () use ($configured) {
            return receipt_decrypt_v2($configured['id'], hex2bin($configured['cipher']), '18.v2.aes');
        });
        self::assertFalse($plain);
        self::assertStringContainsString('pgacc: receipt 18.v2.aes could not be decrypted', $log);
    }
}
