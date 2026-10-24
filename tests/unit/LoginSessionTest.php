<?php
/*
 * Login session helpers in functions.php: CSPRNG bytes with their fallback,
 * the session key derived from $key, the users.cookie nonce, the
 * pp_pgacc_login token and its constant-time comparison.
 *
 * Runs on PHP 5.3 (PHPUnit 4.8, tests/php53/phpunit.xml): no "finally".
 */

// Stand-ins for openssl_random_pseudo_bytes() and mcrypt_create_iv().
function login_test_strong_bytes($length, &$strong)
{
    $strong = true;
    return str_repeat('s', $length);
}

function login_test_weak_bytes($length, &$strong)
{
    $strong = false;
    return str_repeat('w', $length);
}

function login_test_short_bytes($length, &$strong)
{
    $strong = true;
    return str_repeat('t', $length - 1);
}

// A source that does not report its strength.
function login_test_silent_bytes($length, &$strong)
{
    return str_repeat('q', $length);
}

function login_test_failing_bytes($length, &$strong)
{
    $strong = true;
    return false;
}

function login_test_mcrypt_bytes($length, $source)
{
    // MCRYPT_DEV_URANDOM is 1.
    return $source === 1 ? str_repeat('m', $length) : 'wrong source';
}

function login_test_mcrypt_short_bytes($length, $source)
{
    return str_repeat('n', $length + 1);
}

final class LoginSessionTest extends FunctionsTestCase
{
    const TEST_KEY = 'synthetic-test-only-encryption-key';

    private $hadKey;
    private $previousKey;

    /**
     * @before
     */
    public function rememberKey()
    {
        $this->hadKey = array_key_exists('key', $GLOBALS);
        $this->previousKey = $this->hadKey ? $GLOBALS['key'] : null;
        $GLOBALS['key'] = self::TEST_KEY;
    }

    /**
     * @after
     */
    public function restoreKey()
    {
        if ($this->hadKey) {
            $GLOBALS['key'] = $this->previousKey;
        } else {
            unset($GLOBALS['key']);
        }
    }

    public function testRandomBytesComeFromTheCsprng()
    {
        $first = login_random_bytes(32);
        $second = login_random_bytes(32);
        self::assertSame(32, strlen($first));
        self::assertSame(32, strlen($second));
        self::assertNotSame($first, $second);
        self::assertSame(4, strlen(login_random_bytes(4)));
    }

    public function testRandomBytesPreferStrongOpenssl()
    {
        self::assertSame('ssss', login_random_bytes(4, 'login_test_strong_bytes', 'login_test_mcrypt_bytes'));
    }

    public function testRandomBytesFallBackToMcryptUrandom()
    {
        self::assertSame('mmmm', login_random_bytes(4, 'login_test_weak_bytes', 'login_test_mcrypt_bytes'));
        self::assertSame('mmmm', login_random_bytes(4, 'login_test_short_bytes', 'login_test_mcrypt_bytes'));
        self::assertSame('mmmm', login_random_bytes(4, 'login_test_failing_bytes', 'login_test_mcrypt_bytes'));
        self::assertSame('mmmm', login_random_bytes(4, 'login_test_silent_bytes', 'login_test_mcrypt_bytes'));
        self::assertSame('mmmm', login_random_bytes(4, 'login_test_missing_openssl', 'login_test_mcrypt_bytes'));
    }

    public function testRandomBytesFailClosed()
    {
        self::assertFalse(login_random_bytes(4, 'login_test_weak_bytes', 'login_test_missing_mcrypt'));
        self::assertFalse(login_random_bytes(4, 'login_test_missing_openssl', 'login_test_missing_mcrypt'));
        self::assertFalse(login_random_bytes(4, 'login_test_missing_openssl', 'login_test_mcrypt_short_bytes'));
    }

    public function testSessionKeyIsDerivedFromAValidKey()
    {
        self::assertSame(hash_hmac('sha256', 'pgacc-session-key', self::TEST_KEY, true), login_session_key());
        $GLOBALS['key'] = '0123456789abcdef';
        self::assertSame(hash_hmac('sha256', 'pgacc-session-key', '0123456789abcdef', true), login_session_key());
    }

    public function testSessionKeyFailsClosedWithoutAValidKey()
    {
        foreach (array('', '0123456789abcde', 'CHANGE-ME-to-a-long-random-key', 'a-long-key-CHANGE-ME', 12345678901234567, null) as $key) {
            $GLOBALS['key'] = $key;
            self::assertFalse(login_session_key(), var_export($key, true));
            self::assertFalse(login_session_token(1, 5, '2026-10-24 10:00:00.123456'), var_export($key, true));
            self::assertFalse(login_new_nonce(), var_export($key, true));
        }
        unset($GLOBALS['key']);
        self::assertFalse(login_session_key());
    }

    public function testNonceUsesThirtyOneBitsAndIsNeverZero()
    {
        self::assertSame(2147483647, login_nonce("\xff\xff\xff\xff"));
        self::assertSame(5, login_nonce("\x80\x00\x00\x05"));
        self::assertSame(0x12345678, login_nonce("\x12\x34\x56\x78"));
        self::assertSame(1, login_nonce("\x00\x00\x00\x00"));
        self::assertSame(1, login_nonce("\x80\x00\x00\x00"));
        self::assertFalse(login_nonce(false));
        self::assertFalse(login_nonce("\x00\x00\x05"));
        self::assertFalse(login_nonce("\x00\x00\x00\x05\x00"));
    }

    public function testNewNoncesAreRandom()
    {
        $nonces = array();
        for ($i = 0; $i < 20; $i++) {
            $nonce = login_new_nonce();
            self::assertTrue(is_int($nonce) && $nonce >= 1 && $nonce <= 2147483647, var_export($nonce, true));
            $nonces[$nonce] = true;
        }
        self::assertGreaterThan(18, count($nonces));
    }

    public function testSessionTokenIsAnHmacOfIdNonceAndLoginTime()
    {
        $login = '2026-10-24 10:00:00.123456';
        $expected = hash_hmac(
            'sha256',
            'pgacc-login|1|5|' . $login,
            hash_hmac('sha256', 'pgacc-session-key', self::TEST_KEY, true)
        );
        $token = login_session_token(1, 5, $login);
        self::assertSame($expected, $token);
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $token));
        self::assertNotSame($token, login_session_token(2, 5, $login));
        self::assertNotSame($token, login_session_token(1, 6, $login));
        self::assertNotSame($token, login_session_token(1, 5, '2026-10-24 10:00:00.123457'));
        self::assertNotSame($token, login_session_token(11, 5, $login));
        self::assertNotSame($token, login_session_token(1, 55, $login));
        $GLOBALS['key'] = 'another-synthetic-test-key';
        self::assertNotSame($token, login_session_token(1, 5, $login));
    }

    public function testCookieHeaders()
    {
        $before = time();
        $header = login_cookie_header('pp_pgacc_login', 'abc', 86400);
        $after = time();
        $expected = array();
        for ($now = $before; $now <= $after; $now++) {
            $expected[] = 'Set-Cookie: pp_pgacc_login=abc; expires=' . gmdate('D, d M Y H:i:s', $now + 86400)
                . ' GMT; Max-Age=86400; secure; HttpOnly; SameSite=Lax';
        }
        self::assertContains($header, $expected);
        self::assertSame(
            'Set-Cookie: pp_pgacc_id=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; secure; HttpOnly; SameSite=Lax',
            login_cookie_header('pp_pgacc_id', 'deleted', 0)
        );
        $short = login_cookie_header('pp_pgacc_id', 7, 1);
        self::assertSame(1, preg_match('/^Set-Cookie: pp_pgacc_id=7; expires=.* GMT; Max-Age=1; secure; HttpOnly; SameSite=Lax$/D', $short));
        self::assertTrue(abs(strtotime(substr($short, strlen('Set-Cookie: pp_pgacc_id=7; expires='), 29)) - time() - 1) <= 1, $short);
    }

    public static function allowedTargets()
    {
        return array('', 'index.php', 'index.php?action=all&filter_date=2024', 'index.php#top', 'index.php?',
            '/', '/index.php?action=all', '/a', '/.', '/~x/%2F', '/!', '/[x]', 'index.php?a=~&b=!');
    }

    public static function rejectedTargets()
    {
        return array('https://evil.example/', 'http://evil.example', '//evil.example', '/\\evil.example',
            '\\\\evil.example', '\\', 'javascript:alert(1)', 'index.php://evil.example', 'index.phpevil',
            'index.php/x', 'index_php', "index.php\r\nSet-Cookie: a=1", "index.php\n", "/x\n", "/\n", "\n",
            ' /x', 'index.php?a=1 b', '/a b', "/\tx", "/x\x7f", "/x\x00", "index.php?\x00", 'evil.php',
            "/\xc3\xa4", "/x\\y", 'index.php?\\', 'index.php#\\x', 'x/index.php', '?a=1', '#x');
    }

    // Targets that are not hex are refused before hex2ascii(), whose hexdec()
    // reports other characters on PHP 7.4+.
    public function testTargetsThatAreNotHexRaiseNoDiagnostics()
    {
        $diagnostics = array();
        set_error_handler(function ($type, $message) use (&$diagnostics) {
            $diagnostics[] = $message;
            return true;
        });
        $results = array();
        foreach (array('"><script>', '2F x', "2F\n", "\n2F", 'x2F', '2Fx') as $hex) {
            $results[] = login_target($hex);
            $results[] = login_form_target($hex);
        }
        restore_error_handler();
        self::assertSame(array(), $diagnostics);
        self::assertSame(array_fill(0, 6, 'index.php'), array_values(array_filter($results)));
    }

    public function testLoginTargets()
    {
        foreach (self::allowedTargets() as $target) {
            self::assertTrue(login_target_allowed($target), var_export($target, true));
            self::assertSame($target, login_target(bin2hex($target)), var_export($target, true));
            self::assertSame(strtoupper(bin2hex($target)), login_form_target(strtoupper(bin2hex($target))));
        }
        foreach (self::rejectedTargets() as $target) {
            self::assertFalse(login_target_allowed($target), var_export($target, true));
            self::assertSame('index.php', login_target(bin2hex($target)), var_export($target, true));
            self::assertSame('', login_form_target(bin2hex($target)), var_export($target, true));
        }
        self::assertSame('index.php?a=1', login_target('69 6e 64 65 78 2E 70 68 70 3F 61 3D 31'));
        self::assertSame('69 6e 64 65 78 2E 70 68 70', login_form_target('69 6e 64 65 78 2E 70 68 70'));
        self::assertSame('index.php', login_target(array('2F')));
        self::assertSame('', login_form_target(array('2F')));
        self::assertFalse(login_target_decode('2F x'));
        self::assertSame('/', login_target_decode('2F'));
        self::assertSame('/', login_target('2F'));
        self::assertSame('index.php', login_target('2F x'));
        self::assertSame('index.php', login_target('"><script>'));
        self::assertSame('index.php', login_target(null));
        self::assertSame('', login_form_target(null));
        // Not hex: refused before hex2ascii().
        self::assertSame('', login_form_target('2F x'));
        self::assertSame('', login_form_target('2Fg'));
        self::assertSame('', login_form_target("2F\n"));
        self::assertSame('', login_form_target('"><script>'));
        // Odd hex decodes to a control character.
        self::assertSame('', login_form_target('ABC'));
        self::assertSame('index.php', login_target('ABC'));
    }

    public function testTokenComparison()
    {
        $token = str_repeat('ab', 32);
        self::assertTrue(login_token_equals($token, $token));
        self::assertTrue(login_token_equals('', ''));
        self::assertFalse(login_token_equals($token, 'bb' . substr($token, 2)));
        self::assertFalse(login_token_equals($token, substr($token, 0, 63) . 'c'));
        self::assertFalse(login_token_equals($token, substr($token, 0, 63)));
        self::assertFalse(login_token_equals($token, $token . 'a'));
        self::assertFalse(login_token_equals('a', 'b'));
        self::assertFalse(login_token_equals('a', 'c'));
        self::assertFalse(login_token_equals(false, ''));
        self::assertFalse(login_token_equals('', false));
        self::assertFalse(login_token_equals(false, false));
        self::assertFalse(login_token_equals('5', 5));
        self::assertFalse(login_token_equals($token, array($token)));
    }
}
