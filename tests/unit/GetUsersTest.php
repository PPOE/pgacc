<?php

/*
 * getUsers() (getusers.php) against synthetic membership-system responses,
 * served by the membership stub of compose.test.yaml over verified HTTPS
 * (MembershipStub), so no membership system is contacted. The settings come
 * from sync-secrets.php (tests/runtime/test-sync-secrets.php). The ppmembers
 * table is rebuilt inside a transaction that is rolled back.
 */

require_once dirname(dirname(__DIR__)) . '/getusers.php';
require_once dirname(dirname(__DIR__)) . '/mb.php';
require_once __DIR__ . '/AppExitGuard.php';
require_once __DIR__ . '/MembershipStub.php';

final class GetUsersTest extends FunctionsTestCase
{
    // getUsers() in a transaction that is rolled back: [its result, the rows
    // of ppmembers or null if there is no table, the transaction status].
    private static function load($file = 'sync-secrets.php')
    {
        // pg_connect() returns the default link that the application code uses.
        $connection = pg_connect(pgacc_test_connection_string());
        pg_query($connection, 'BEGIN');
        try {
            AppExitGuard::enter('getUsers()');
            $error = getUsers($file);
            AppExitGuard::leave();
            $status = pg_transaction_status($connection);
            $result = @pg_query($connection, 'SELECT id, uuid, name, lo, paid FROM ppmembers ORDER BY id');
            $rows = $result === false ? null : pg_fetch_all($result);
        } finally {
            MembershipStub::reset();
            pg_query($connection, 'ROLLBACK');
        }
        return [$error, $rows, $status];
    }

    private static function uuid($id)
    {
        return sprintf('00000000-0000-4000-8000-%012d', $id);
    }

    public function testImportsTheStubListOverHttps()
    {
        // The whole flow: getUsers() fetches the stub's "flow" list
        // (tests/reference/membership/flow.json) over verified HTTPS with
        // the token and loads it.
        MembershipStub::mode('flow');
        [$error, $rows, $status] = self::load();
        self::assertNull($error);
        // Inside the test's transaction getUsers() uses a savepoint and
        // leaves the transaction open.
        self::assertSame(PGSQL_TRANSACTION_INTRANS, $status);
        $expected = [
            // Every organization role of the mapping, and an unknown one (99).
            [9101, 'Anna Fluss', '9', '1'],
            [9102, 'Bernd Fluss', '1', '0'],
            [9103, 'Carla Fluss', '3', '1'],
            [9104, 'Dieter Fluss', '2', '0'],
            [9105, 'Emma Fluss', '4', '1'],
            [9106, 'Franz Fluss', '5', '0'],
            [9107, 'Gerda Fluss', '6', '1'],
            [9108, 'Hannes Fluss', '10', '0'],
            [9109, 'Iris Fluss', '8', '1'],
            [9110, 'Unbekannt Fluss', '10', '0'],
            // UTF-8 names as sent, quotes and backslashes unchanged.
            [9111, "J\xC3\xBCrgen Gr\xC3\xB6\xC3\x9Fe-Wei\xC3\x9F", '2', '1'],
            [9112, "D'Arcy Back\\slash", '1', '0'],
        ];
        for ($i = 0; $i < 20; $i++) {
            // Filler 00 has no organization role (lo null).
            $expected[] = [9120 + $i, sprintf('Synthetic Flow Filler %02d', $i), $i === 0 ? '10' : '2', (string) ($i % 2)];
        }
        foreach ($expected as $index => [$id, $name, $lo, $paid]) {
            $expected[$index] = ['id' => (string) $id, 'uuid' => self::uuid($id), 'name' => $name, 'lo' => $lo, 'paid' => $paid];
        }
        self::assertSame($expected, $rows);
    }

    public function testUuidsAreStoredInLowerCase()
    {
        MembershipStub::serve('[{"id": 2101, "uuid": "ABCDEF01-2345-4678-9ABC-DEF012345678", "name": "Synthetic Upper", "lo": 38, "paid": 1}]');
        [$error, $rows] = self::load();
        self::assertNull($error);
        self::assertSame([['id' => '2101', 'uuid' => 'abcdef01-2345-4678-9abc-def012345678', 'name' => 'Synthetic Upper', 'lo' => '1', 'paid' => '1']], $rows);
    }

    public static function invalidListProvider()
    {
        $row = '{"id": 2101, "uuid": "00000000-0000-4000-8000-000000002101", "name": "Synthetic A", "lo": 38, "paid": 1}';
        $other = '{"id": 2102, "uuid": "00000000-0000-4000-8000-000000002102", "name": "Synthetic B", "lo": null, "paid": 0}';
        $with = fn($search, $replace) => "[$other," . str_replace($search, $replace, $row) . ']';
        return [
            'not JSON' => ["[$row"],
            'an object' => ['{"members": []}'],
            'empty list' => ['[]'],
            'a row that is no object' => ["[$other, 2101]"],
            'a string id (no SQL)' => [$with('"id": 2101', '"id": "2101 OR 1=1"')],
            'id 0' => [$with('"id": 2101', '"id": 0')],
            'duplicate id' => [$with('"id": 2101', '"id": 2102')],
            'duplicate uuid' => [$with('000000002101', '000000002102')],
            'no uuid' => [$with('"uuid": "00000000-0000-4000-8000-000000002101", ', '')],
            'uuid not a UUID' => [$with('00000000-0000-4000-8000-000000002101', 'javascript:alert(1)')],
            'uuid with a trailing newline' => [$with('000000002101"', '000000002101\n"')],
            'uuid with a quote' => [$with('000000002101"', '00000000210\""')],
            'name not a string' => [$with('"name": "Synthetic A"', '"name": 7')],
            'name with a NUL byte' => [$with('"name": "Synthetic A"', '"name": "Synthetic\u0000A"')],
            'no lo' => [$with('"lo": 38, ', '')],
            'lo a string' => [$with('"lo": 38', '"lo": "38"')],
            'paid 2' => [$with('"paid": 1', '"paid": 2')],
            'paid true' => [$with('"paid": 1', '"paid": true')],
            'an extra field' => [$with('"paid": 1', '"paid": 1, "email": "a@example.org"')],
        ];
    }

    // Any malformed row refuses the whole list: the previous list of the
    // connection stays (here: none was loaded, so there is no table).
    #[PHPUnit\Framework\Attributes\DataProvider('invalidListProvider')]
    public function testAnInvalidListIsRefused($body)
    {
        MembershipStub::serve($body);
        [$error, $rows] = self::load();
        self::assertSame('Daten von Mitgliederverwaltung ungültig!', $error);
        self::assertNull($rows);
    }

    public static function refusedFetchProvider()
    {
        return [
            // The stub's own test-CA certificate is not for another name.
            'certificate for another name' => ['wrongname', null],
            'self-signed certificate' => ['selfsigned', null],
            'HTTP 500' => ['error', null],
            'redirect' => ['redirect', null],
            // The stub's certificate is signed by a CA that the system trusts,
            // but members_cafile is another certificate: only that one counts.
            'certificate not from members_cafile' => ['ok', ['members_cafile' => '/etc/pgacc-test-tls/selfsigned.pem']],
            'another token (403)' => ['ok', ['members_token' => 'synthetic-wrong-token']],
            // getusers_fetch() checks the URL itself: each of these would
            // otherwise reach the stub (https on port 443) and get the list.
            'http URL' => ['ok', ['members_url' => 'http://mitglieder.piratenpartei.at/members.php']],
            'user in the URL' => ['ok', ['members_url' => 'https://synthetic@mitglieder.piratenpartei.at/members.php']],
            'password in the URL' => ['ok', ['members_url' => 'https://:synthetic@mitglieder.piratenpartei.at/members.php']],
            'URL without a host' => ['ok', ['members_url' => 'https:/members.php']],
            'URL that does not parse' => ['ok', ['members_url' => 'https:///members.php']],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('refusedFetchProvider')]
    public function testARefusedFetchFailsClosed($mode, $settings)
    {
        $config = getusers_config('sync-secrets.php');
        self::assertNotNull($config);
        MembershipStub::mode($mode);
        try {
            $body = getusers_fetch(array_merge($config, $settings ?? []));
        } finally {
            MembershipStub::reset();
        }
        self::assertNull($body);
    }

    // The body is read up to 4 MiB: one byte more is refused, exactly the
    // limit is returned.
    public function testATooLargeResponseIsRefused()
    {
        $config = getusers_config('sync-secrets.php');
        MembershipStub::serve(str_repeat('x', 4 * 1024 * 1024 + 1));
        try {
            $tooLarge = getusers_fetch($config);
            MembershipStub::serve(str_repeat('x', 4 * 1024 * 1024));
            $atTheLimit = getusers_fetch($config);
        } finally {
            MembershipStub::reset();
        }
        // Lengths only: the bodies are megabytes.
        self::assertNull($tooLarge === null ? null : strlen($tooLarge));
        self::assertSame(4 * 1024 * 1024, strlen((string) $atTheLimit));
    }

    // A response that stops in the middle of the body (the stub's "stall"
    // mode) is refused when the read times out, not returned in part, and
    // at the deadline, not only once the stub closes the connection (after
    // 3 seconds; the Content-Length check would refuse it then too).
    public function testATimedOutResponseIsRefused()
    {
        $config = getusers_config('sync-secrets.php');
        MembershipStub::mode('stall');
        $start = microtime(true);
        try {
            $body = getusers_fetch($config, 1);
            $elapsed = microtime(true) - $start;
        } finally {
            MembershipStub::reset();
        }
        self::assertNull($body === null ? null : strlen($body));
        self::assertLessThan(2.5, $elapsed);
    }

    // A 200 response whose body does not match its Content-Length is
    // refused, not returned: cut short by an early close (no timeout),
    // longer than the header says, or a value that is not 1-18 digits.
    public static function contentLengthProvider()
    {
        return [
            'body cut short by an early close' => ['short'],
            'body longer than Content-Length' => ['long'],
            'Content-Length malformed' => ['badlength'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('contentLengthProvider')]
    public function testABodyNotMatchingContentLengthIsRefused($mode)
    {
        $config = getusers_config('sync-secrets.php');
        MembershipStub::mode($mode);
        $start = microtime(true);
        try {
            $body = getusers_fetch($config, 10);
            $elapsed = microtime(true) - $start;
        } finally {
            MembershipStub::reset();
        }
        self::assertNull($body === null ? null : strlen($body));
        // Refused because of the length, not at the deadline.
        self::assertLessThan(5, $elapsed);
    }

    // The timeout is overall: a response whose headers or body arrive in
    // parts, each well within the timeout, is refused at the deadline.
    public static function trickleProvider()
    {
        return [
            'body' => ['trickle'],
            'headers' => ['tricklehead'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('trickleProvider')]
    public function testATrickledResponseIsRefusedAtTheDeadline($mode)
    {
        $config = getusers_config('sync-secrets.php');
        MembershipStub::mode($mode);
        $start = microtime(true);
        try {
            $body = getusers_fetch($config, 2);
            $elapsed = microtime(true) - $start;
        } finally {
            // Waits until the stub has given up on the response.
            MembershipStub::reset();
        }
        self::assertNull($body === null ? null : strlen($body));
        self::assertLessThan(4, $elapsed);
    }

    public function testTheMembersCafileIsTheTrustAnchor()
    {
        // The self-signed certificate of the stub is accepted when it is the
        // members_cafile (as in production: the membership system's own
        // certificate), with the token.
        $config = getusers_config('sync-secrets.php');
        MembershipStub::mode('selfsigned');
        try {
            $body = getusers_fetch(array_merge($config, ['members_cafile' => '/etc/pgacc-test-tls/selfsigned.pem']));
        } finally {
            MembershipStub::reset();
        }
        self::assertSame(file_get_contents(dirname(__DIR__) . '/reference/membership/members.json'), $body);
    }

    public static function configProvider()
    {
        $valid = ['members_url' => 'https://members.invalid/members.php', 'members_token' => 't', 'members_cafile' => '/c.crt'];
        return [
            'valid' => [$valid, true],
            'http URL' => [array_merge($valid, ['members_url' => 'http://members.invalid/members.php']), false],
            'upper-case scheme' => [array_merge($valid, ['members_url' => 'HTTPS://members.invalid/members.php']), false],
            'empty URL' => [array_merge($valid, ['members_url' => '']), false],
            'empty token' => [array_merge($valid, ['members_token' => '']), false],
            'no token' => [array_diff_key($valid, ['members_token' => 1]), false],
            'no cafile' => [array_diff_key($valid, ['members_cafile' => 1]), false],
            'cafile not a string' => [array_merge($valid, ['members_cafile' => ['/c.crt']]), false],
            'not an array' => [1, false],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('configProvider')]
    public function testConfiguration($config, $accepted)
    {
        $file = tempnam(sys_get_temp_dir(), 'pgacc-sync-secrets');
        file_put_contents($file, '<?php return ' . var_export($config, true) . ';');
        try {
            self::assertSame($accepted ? $config : null, getusers_config($file));
        } finally {
            unlink($file);
        }
    }

    // A sync-secrets.php that does not load (a parse error, an exception)
    // counts as missing: the list fails closed with the usual error instead
    // of a fatal error of the page.
    public static function brokenConfigProvider()
    {
        return [
            'parse error' => ["<?php\nreturn ['members_url' => 'https://members.invalid/members.php',;\n"],
            'throws' => ["<?php\nthrow new RuntimeException('synthetic');\n"],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('brokenConfigProvider')]
    public function testABrokenConfigurationFailsClosed($content)
    {
        $file = tempnam(sys_get_temp_dir(), 'pgacc-sync-secrets');
        file_put_contents($file, $content);
        $log = tempnam(sys_get_temp_dir(), 'pgacc-sync-log');
        $previous = ini_set('error_log', $log);
        try {
            [$error, $rows] = self::load($file);
        } catch (Throwable $e) {
            $error = 'getUsers() let ' . get_class($e) . ' through';
            $rows = null;
        } finally {
            ini_set('error_log', $previous);
            unlink($file);
        }
        $logged = file_get_contents($log);
        unlink($log);
        self::assertSame('Mitgliederliste nicht konfiguriert!', $error);
        self::assertNull($rows);
        // Only the class: the message of a parse error can quote the file.
        self::assertMatchesRegularExpression('/sync-secrets\.php cannot be loaded \((ParseError|RuntimeException)\)$/m', $logged);
        self::assertStringNotContainsString('members.invalid', $logged);
    }

    public function testWithoutTheSettingsTheErrorIsReturned()
    {
        // No die(): the caller decides (the pages stop, a voucher save refuses).
        [$error, $rows] = self::load('/nonexistent/sync-secrets.php');
        self::assertSame('Mitgliederliste nicht konfiguriert!', $error);
        self::assertNull($rows);
    }

    public static function uuidProvider()
    {
        return [
            'lower case' => ['0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', true],
            'upper case' => ['0A1B2C3D-4E5F-4A6B-8C7D-9E0F1A2B3C4D', true],
            'too short' => ['0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4', false],
            'no dashes' => ['0a1b2c3d4e5f4a6b8c7d9e0f1a2b3c4d', false],
            'not hex' => ['0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4g', false],
            'trailing newline' => ["0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d\n", false],
            'markup' => ['"><script>alert(1)</script>', false],
            'null' => [null, false],
            'integer' => [12, false],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('uuidProvider')]
    public function testUuidValidation($uuid, $valid)
    {
        self::assertSame($valid, member_uuid_valid($uuid));
    }

    public function testMemberLinksUseTheUuid()
    {
        // Admidio opens profiles by user_uuid only; an id alone would open
        // the viewer's own profile.
        self::assertSame(
            '<a href="https://mitglieder.piratenpartei.at/modules/profile/profile.php?user_uuid=00000000-0000-4000-8000-000000009001">9001</a>',
            member_link('9001', '00000000-0000-4000-8000-000000009001'),
        );
        // With the member's name, escaped.
        self::assertSame(
            '<a href="https://mitglieder.piratenpartei.at/modules/profile/profile.php?user_uuid=00000000-0000-4000-8000-000000009001">9001 A &lt;b&gt; &quot;&amp;&#039;</a>',
            member_link('9001', '00000000-0000-4000-8000-000000009001', 'A <b> "&\''),
        );
        // Not a UUID: the id (and name) as text, no link.
        self::assertSame('9002', member_link('9002', '"><script>alert(1)</script>'));
        self::assertSame('9002 &lt;i&gt;', member_link('9002', '"><script>alert(1)</script>', '<i>'));
        self::assertSame('9003', member_link('9003', null));
        self::assertSame('0', member_link('x<b>', null));
    }
}
