<?php

/*
 * Internal sync endpoint helpers in src/sync-api.php: the configuration
 * sync.php, the peer and token check, and the payment selection (in a
 * transaction that is rolled back). The requests themselves:
 * tests/http-sync.php.
 */

require_once dirname(__DIR__, 2) . '/src/sync-api.php';

final class SyncApiTest extends FunctionsTestCase
{
    private const CONFIG = ['token' => 'synthetic-sync-token', 'peers' => ['192.0.2.20', '192.0.2.21']];

    private $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);
    }

    private function configFile($content)
    {
        $file = tempnam(sys_get_temp_dir(), 'pgacc-sync');
        file_put_contents($file, $content);
        $this->files[] = $file;
        return $file;
    }

    public function testConfigReturnsAValidConfiguration()
    {
        $file = $this->configFile("<?php\nreturn " . var_export(self::CONFIG, true) . ";\n");

        self::assertSame(self::CONFIG, sync_api_config($file));
    }

    public static function invalidConfigProvider()
    {
        return [
            'no return value' => ["<?php\n\$token = 'x';\n"],
            'not an array' => ["<?php\nreturn 'synthetic-sync-token';\n"],
            'no token' => ["<?php\nreturn ['peers' => ['192.0.2.20']];\n"],
            'empty token' => ["<?php\nreturn ['token' => '', 'peers' => ['192.0.2.20']];\n"],
            'token not a string' => ["<?php\nreturn ['token' => 12345678, 'peers' => ['192.0.2.20']];\n"],
            'no peers' => ["<?php\nreturn ['token' => 'synthetic-sync-token'];\n"],
            'empty peers' => ["<?php\nreturn ['token' => 'synthetic-sync-token', 'peers' => []];\n"],
            'peers not a list' => ["<?php\nreturn ['token' => 'synthetic-sync-token', 'peers' => '192.0.2.20'];\n"],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('invalidConfigProvider')]
    public function testConfigRefusesAnInvalidConfiguration($content)
    {
        self::assertNull(sync_api_config($this->configFile($content)));
    }

    public function testConfigRefusesAMissingFile()
    {
        self::assertNull(sync_api_config(sys_get_temp_dir() . '/pgacc-sync-missing.php'));
    }

    public static function requestProvider()
    {
        $good = ['REMOTE_ADDR' => '192.0.2.21', 'HTTP_AUTHORIZATION' => 'Bearer synthetic-sync-token'];

        return [
            'peer and token' => [self::CONFIG, $good, true],
            'no configuration' => [null, $good, false],
            'another peer' => [self::CONFIG, ['REMOTE_ADDR' => '192.0.2.22'] + $good, false],
            'no peer address' => [self::CONFIG, ['HTTP_AUTHORIZATION' => $good['HTTP_AUTHORIZATION']], false],
            'no token' => [self::CONFIG, ['REMOTE_ADDR' => '192.0.2.21'], false],
            'wrong token' => [self::CONFIG, ['HTTP_AUTHORIZATION' => 'Bearer synthetic-sync-tokeN'] + $good, false],
            'token prefix' => [self::CONFIG, ['HTTP_AUTHORIZATION' => 'Bearer synthetic-sync-toke'] + $good, false],
            'token without the scheme' => [self::CONFIG, ['HTTP_AUTHORIZATION' => 'synthetic-sync-token'] + $good, false],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('requestProvider')]
    public function testAllowedNeedsAPeerAndTheToken($config, $server, $expected)
    {
        self::assertSame($expected, sync_api_allowed($config, $server));
    }

    public function testPaymentsSelectTheAcknowledgedFeesSinceOctober()
    {
        $dbconn = $GLOBALS['dbconn'];
        pg_query($dbconn, 'BEGIN');
        // Only the rows of this test count.
        pg_query($dbconn, 'DELETE FROM vouchers');
        $fees = [
            // member, cents, date, ack1, deleted, type
            [11, 2000, '2030-10-01 00:00:00', 'reviewer', false, 1],
            [12, 1999, '2031-03-01', 'reviewer', false, 1],
            [13, 1500, '2031-03-01', 'reviewer', false, 1],
            [13, 500, '2031-12-31 23:59:59', 'reviewer', false, 1],
            [14, 5000, '2030-09-30 23:59:59', 'reviewer', false, 1],
            [14, 1000, '2031-03-01', 'reviewer', false, 1],
            [15, 5000, '2031-03-01', null, false, 1],
            [16, 5000, '2031-03-01', 'reviewer', true, 1],
            [17, 5000, '2031-03-01', 'reviewer', false, 2],
            [null, 5000, '2031-03-01', 'reviewer', false, 1],
            [10, 123456, '2031-01-01', 'reviewer', false, 1],
        ];
        foreach ($fees as $index => $fee) {
            pg_query_params(
                $dbconn,
                "INSERT INTO vouchers (id, voucher_id, type, orga, member, member_id, amount, comment, date, ack1, deleted)
                 VALUES ($1, $1, $2, 1, true, $3, $4, 'Synthetic fee', $5, $6, $7)",
                [99100 + $index, $fee[5], $fee[0], $fee[1], $fee[2], $fee[3], $fee[4] ? 't' : 'f'],
            );
        }

        $payments = sync_api_payments($dbconn, 2031);
        pg_query($dbconn, 'ROLLBACK');

        self::assertSame([
            ['id' => 10, 'amount_cents' => 123456, 'paid_until' => '2031-12-31'],
            ['id' => 11, 'amount_cents' => 2000, 'paid_until' => '2031-12-31'],
            ['id' => 13, 'amount_cents' => 2000, 'paid_until' => '2031-12-31'],
        ], $payments);
    }

    public function testPaymentsAreEmptyWithoutFees()
    {
        $dbconn = $GLOBALS['dbconn'];
        pg_query($dbconn, 'BEGIN');
        pg_query($dbconn, 'DELETE FROM vouchers');

        $payments = sync_api_payments($dbconn, 2031);
        pg_query($dbconn, 'ROLLBACK');

        self::assertSame([], $payments);
    }

    public function testPaymentsAreNullIfTheQueryFails()
    {
        $dbconn = $GLOBALS['dbconn'];
        pg_query($dbconn, 'BEGIN');
        // No vouchers table on this search path.
        pg_query($dbconn, 'SET LOCAL search_path TO pg_catalog');

        $payments = @sync_api_payments($dbconn, 2031);
        pg_query($dbconn, 'ROLLBACK');

        self::assertNull($payments);
    }
}
