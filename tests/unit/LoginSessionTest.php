<?php

/*
 * Login session helpers in functions.php: CSPRNG bytes that fail closed,
 * the session key derived from $key, the users.cookie nonce, the
 * pp_pgacc_login token and its constant-time comparison, and the form token
 * (CSRF) with its field, request check and the POST check.
 */

// Stand-ins for random_bytes().
function login_test_random_bytes($length)
{
    return str_repeat('r', $length);
}

function login_test_throwing_random_bytes($length)
{
    throw new Exception('no random source');
}

function login_test_short_random_bytes($length)
{
    return str_repeat('u', $length - 1);
}

function login_test_erroring_random_bytes($length)
{
    throw new Error('broken random source');
}

function login_test_array_random_bytes($length)
{
    return array_fill(0, $length, 'r');
}

final class LoginSessionTest extends FunctionsTestCase
{
    public const TEST_KEY = 'synthetic-test-only-encryption-key';

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

    public function testRandomBytesUseRandomBytes()
    {
        self::assertSame('rrrr', login_random_bytes(4, 'login_test_random_bytes'));
    }

    public function testRandomBytesFailClosed()
    {
        self::assertFalse(login_random_bytes(4, 'login_test_throwing_random_bytes'));
        self::assertFalse(login_random_bytes(4, 'login_test_short_random_bytes'));
        self::assertFalse(login_random_bytes(4, 'login_test_erroring_random_bytes'));
        self::assertFalse(login_random_bytes(4, 'login_test_array_random_bytes'));
    }

    public function testSessionKeyIsDerivedFromAValidKey()
    {
        self::assertSame(hash_hmac('sha256', 'pgacc-session-key', self::TEST_KEY, true), login_session_key());
        $GLOBALS['key'] = '0123456789abcdef';
        self::assertSame(hash_hmac('sha256', 'pgacc-session-key', '0123456789abcdef', true), login_session_key());
    }

    public function testSessionKeyFailsClosedWithoutAValidKey()
    {
        foreach (['', '0123456789abcde', 'CHANGE-ME-to-a-long-random-key', 'a-long-key-CHANGE-ME', 12345678901234567, null] as $key) {
            $GLOBALS['key'] = $key;
            self::assertFalse(login_session_key(), var_export($key, true));
            self::assertFalse(login_session_token(1, 5, '2026-10-24 10:00:00.123456'), var_export($key, true));
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
        $nonces = [];
        for ($i = 0; $i < 20; $i++) {
            $nonce = login_nonce(login_random_bytes(4));
            self::assertTrue(is_int($nonce) && $nonce >= 1 && $nonce <= 2147483647, var_export($nonce, true));
            $nonces[$nonce] = true;
        }
        self::assertGreaterThan(18, count($nonces));
    }

    private static function cookieOf($connection)
    {
        $row = pg_fetch_assoc(pg_query($connection, 'SELECT cookie::text AS cookie FROM users WHERE id = 7'));
        return $row['cookie'];
    }

    /*
     * Runs login_start_session() in a transaction that is rolled back, with
     * error_log() redirected; returns array(result, log text, users.cookie of
     * user 7 before, and after).
     */
    private static function startSession($bytes = null, $failTransaction = false, $userId = 7, $random = 'random_bytes')
    {
        $connection = pg_connect(pgacc_test_connection_string());
        self::assertNotFalse($connection);
        pg_query($connection, 'BEGIN');
        $before = self::cookieOf($connection);
        if ($failTransaction) {
            @pg_query($connection, 'SELECT 1 / 0');
        }
        $log = tempnam(sys_get_temp_dir(), 'pgacc-log-');
        $previous = ini_set('error_log', $log);
        try {
            $result = login_start_session($connection, $userId, $bytes, $random);
        } catch (Exception $exception) {
            ini_set('error_log', $previous);
            unlink($log);
            pg_query($connection, 'ROLLBACK');
            throw $exception;
        }
        ini_set('error_log', $previous);
        $text = file_get_contents($log);
        unlink($log);
        $after = $failTransaction ? $before : self::cookieOf($connection);
        pg_query($connection, 'ROLLBACK');
        return [$result, $text, $before, $after];
    }

    public function testStartSessionStoresANewNonceAndReturnsItsToken()
    {
        $connection = pg_connect(pgacc_test_connection_string());
        self::assertNotFalse($connection);
        pg_query($connection, 'BEGIN');
        $log = tempnam(sys_get_temp_dir(), 'pgacc-log-');
        $previous = ini_set('error_log', $log);
        $token = login_start_session($connection, 7);
        $second = login_start_session($connection, '7', "\x00\x00\x00\x2a");
        ini_set('error_log', $previous);
        $text = file_get_contents($log);
        unlink($log);
        $state = pg_fetch_assoc(pg_query($connection, "SELECT cookie::text AS cookie, login::text AS login, login > now() - '1 minute'::interval AS recent FROM users WHERE id = 7"));
        pg_query($connection, 'ROLLBACK');
        self::assertSame('', $text);
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $token));
        self::assertNotSame($token, $second);
        self::assertSame('42', $state['cookie']);
        self::assertSame('t', $state['recent']);
        self::assertSame(login_session_token(7, 42, $state['login']), $second);
    }

    public function testStartSessionFailsClosed()
    {
        // Without a valid key: nothing is stored.
        $GLOBALS['key'] = 'short';
        list($result, $text, $before, $after) = self::startSession();
        self::assertFalse($result);
        self::assertSame($before, $after);
        self::assertStringContainsString('pgacc: login refused: no valid session key', $text);
        self::assertStringNotContainsString('short', str_replace('pgacc: login refused: no valid session key ($key in constants.php)', '', $text));
        $GLOBALS['key'] = self::TEST_KEY;

        // Without random bytes: the source throws.
        list($result, $text, $before, $after) = self::startSession(null, false, 7, 'login_test_throwing_random_bytes');
        self::assertFalse($result);
        self::assertSame($before, $after);
        self::assertStringContainsString('pgacc: login refused: no secure random source', $text);

        // The session cannot be stored: a failing query, or no such user.
        list($result, $text) = self::startSession("\x00\x00\x00\x2a", true);
        self::assertFalse($result);
        self::assertStringContainsString('pgacc: login refused: the session of user 7 could not be stored', $text);
        list($result, $text) = self::startSession("\x00\x00\x00\x2a", false, 987654);
        self::assertFalse($result);
        self::assertStringContainsString('pgacc: login refused: the session of user 987654 could not be stored', $text);
    }

    public function testSessionTokenIsAnHmacOfIdNonceAndLoginTime()
    {
        $login = '2026-10-24 10:00:00.123456';
        $expected = hash_hmac(
            'sha256',
            'pgacc-login|1|5|' . $login,
            hash_hmac('sha256', 'pgacc-session-key', self::TEST_KEY, true),
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
        $expected = [];
        for ($now = $before; $now <= $after; $now++) {
            $expected[] = 'Set-Cookie: pp_pgacc_login=abc; expires=' . gmdate('D, d M Y H:i:s', $now + 86400)
                . ' GMT; Max-Age=86400; secure; HttpOnly; SameSite=Lax';
        }
        self::assertContains($header, $expected);
        self::assertSame(
            'Set-Cookie: pp_pgacc_id=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; secure; HttpOnly; SameSite=Lax',
            login_cookie_header('pp_pgacc_id', 'deleted', 0),
        );
        $short = login_cookie_header('pp_pgacc_id', 7, 1);
        self::assertSame(1, preg_match('/^Set-Cookie: pp_pgacc_id=7; expires=.* GMT; Max-Age=1; secure; HttpOnly; SameSite=Lax$/D', $short));
        self::assertTrue(abs(strtotime(substr($short, strlen('Set-Cookie: pp_pgacc_id=7; expires='), 29)) - time() - 1) <= 1, $short);
    }

    public static function allowedTargets()
    {
        return ['index.php', 'index.php?action=all&filter_date=2024', 'index.php#top', 'index.php?',
            '/', '/index.php?action=all', '/a', '/.', '/~x/%2F', '/!', '/[x]', 'index.php?a=~&b=!'];
    }

    public static function rejectedTargets()
    {
        return ['https://evil.example/', 'http://evil.example', '//evil.example', '/\\evil.example',
            '\\\\evil.example', '\\', 'javascript:alert(1)', 'index.php://evil.example', 'index.phpevil',
            'index.php/x', 'index_php', "index.php\r\nSet-Cookie: a=1", "index.php\n", "/x\n", "/\n", "\n",
            ' /x', 'index.php?a=1 b', '/a b', "/\tx", "/x\x7f", "/x\x00", "index.php?\x00", 'evil.php',
            "/\xc3\xa4", "/x\\y", 'index.php?\\', 'index.php#\\x', 'x/index.php', '?a=1', '#x', ''];
    }

    // Targets that are not hex are refused before hex2ascii(), whose hexdec()
    // reports other characters on PHP 7.4+.
    public function testTargetsThatAreNotHexRaiseNoDiagnostics()
    {
        $diagnostics = [];
        set_error_handler(function ($type, $message) use (&$diagnostics) {
            $diagnostics[] = $message;
            return true;
        });
        $results = [];
        foreach (['"><script>', '2F x', "2F\n", "\n2F", 'x2F', '2Fx'] as $hex) {
            $results[] = login_target($hex);
            $results[] = login_form_target($hex);
        }
        restore_error_handler();
        self::assertSame([], $diagnostics);
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
        self::assertSame('index.php', login_target(['2F']));
        self::assertSame('', login_form_target(['2F']));
        self::assertFalse(login_target_decode('2F x'));
        self::assertSame('/', login_target_decode('2F'));
        self::assertSame('/', login_target('2F'));
        self::assertSame('index.php', login_target('2F x'));
        self::assertSame('index.php', login_target('"><script>'));
        self::assertSame('index.php', login_target(null));
        // The login form posts an empty target by default: no empty Location.
        self::assertFalse(login_target_decode(''));
        self::assertSame('index.php', login_target(''));
        self::assertSame('', login_form_target(''));
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
        self::assertFalse(login_token_equals($token, [$token]));
    }

    public function testFormTokenIsAnHmacOfTheSessionUnderItsOwnSubkey()
    {
        $login = '2026-10-24 10:00:00.123456';
        $expected = hash_hmac(
            'sha256',
            'pgacc-csrf|1|5|' . $login,
            hash_hmac('sha256', 'pgacc-csrf-key', self::TEST_KEY, true),
        );
        $token = csrf_session_token(1, 5, $login);
        self::assertSame($expected, $token);
        self::assertNotSame(login_session_token(1, 5, $login), $token);
        self::assertNotSame($token, csrf_session_token(2, 5, $login));
        self::assertNotSame($token, csrf_session_token(1, 6, $login));
        self::assertNotSame($token, csrf_session_token(1, 5, '2026-10-24 10:00:00.123457'));
        self::assertNotSame($token, csrf_session_token(11, 5, $login));
        self::assertNotSame($token, csrf_session_token(1, 55, $login));
        $GLOBALS['key'] = 'another-synthetic-test-key';
        self::assertNotSame($token, csrf_session_token(1, 5, $login));
        foreach (['', '0123456789abcde', 'CHANGE-ME-to-a-long-random-key', null] as $key) {
            $GLOBALS['key'] = $key;
            self::assertFalse(csrf_session_token(1, 5, $login), var_export($key, true));
        }
    }

    public function testFormTokenFieldAndCheck()
    {
        $hadToken = array_key_exists('csrf_token', $GLOBALS);
        $previousToken = $hadToken ? $GLOBALS['csrf_token'] : null;
        $previousPost = $_POST;
        $token = str_repeat('ab', 32);
        $GLOBALS['csrf_token'] = $token;
        self::assertSame('<input type="hidden" name="csrf_token" value="' . $token . '" />', csrf_field());
        $_POST = ['csrf_token' => $token];
        self::assertTrue(csrf_request_valid());
        foreach ([[], ['csrf_token' => ''], ['csrf_token' => 'cd' . substr($token, 2)],
            ['csrf_token' => $token . 'a'], ['csrf_token' => [$token]], ['token' => $token]] as $post) {
            $_POST = $post;
            self::assertFalse(csrf_request_valid(), var_export($post, true));
        }
        // Without a session (checklogin() leaves false) nothing is valid,
        // not even an empty field.
        foreach ([false, null, ''] as $none) {
            $GLOBALS['csrf_token'] = $none;
            self::assertSame('<input type="hidden" name="csrf_token" value="" />', csrf_field());
            foreach ([[], ['csrf_token' => ''], ['csrf_token' => $token]] as $post) {
                $_POST = $post;
                self::assertFalse(csrf_request_valid(), var_export([$none, $post], true));
            }
        }
        unset($GLOBALS['csrf_token']);
        $_POST = ['csrf_token' => ''];
        self::assertFalse(csrf_request_valid());
        $_POST = $previousPost;
        if ($hadToken) {
            $GLOBALS['csrf_token'] = $previousToken;
        }
    }

    public function testRequestIsPost()
    {
        $hadMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
        $previousMethod = $hadMethod ? $_SERVER['REQUEST_METHOD'] : null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        self::assertTrue(request_is_post());
        foreach (['GET', 'HEAD', 'post', 'PUT', ''] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            self::assertFalse(request_is_post(), $method);
        }
        unset($_SERVER['REQUEST_METHOD']);
        self::assertFalse(request_is_post());
        if ($hadMethod) {
            $_SERVER['REQUEST_METHOD'] = $previousMethod;
        }
    }
}
