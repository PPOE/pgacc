<?php

/*
 * getUsers() (getusers.php) against synthetic membership-system responses,
 * served by the membership stub of compose.test.yaml over verified HTTPS
 * (MembershipStub), so the live membership system is never contacted. The ppmembers table is rebuilt
 * inside a transaction that is rolled back.
 *
 * Must stay compatible with PHP 5.6 (PHPUnit 5.7).
 */

require_once dirname(dirname(__DIR__)) . '/getusers.php';
require_once __DIR__ . '/AppExitGuard.php';
require_once __DIR__ . '/MembershipStub.php';

final class GetUsersTest extends FunctionsTestCase
{
    public function testImportsTheDecryptedMemberList()
    {
        $vectors = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/mcrypt/vectors.json'), true);
        $vector = $vectors['getusers'][0];
        self::assertSame('getUsers() export', $vector['name']);

        $datakey = isset($GLOBALS['datakey']) ? $GLOBALS['datakey'] : null;
        $GLOBALS['datakey'] = $vector['datakey'];
        MembershipStub::serve($vector['sData']);
        // pg_connect() returns the default link that the application code uses.
        $connection = pg_connect(pgacc_test_connection_string());
        pg_query($connection, 'BEGIN');
        try {
            // getUsers() dies on a short response, an empty key or an SQL error.
            AppExitGuard::enter('getUsers()');
            getUsers();
            // Inside the test's transaction getUsers() uses a savepoint and
            // leaves the transaction open.
            $status = pg_transaction_status($connection);
            $result = pg_query($connection, 'SELECT id, name, lo, paid FROM ppmembers ORDER BY id');
            $rows = pg_fetch_all($result);
            pg_free_result($result);
        } finally {
            // die() skips finally blocks, so this only runs when the call returned.
            AppExitGuard::leave();
            MembershipStub::reset();
            pg_query($connection, 'ROLLBACK');
            $GLOBALS['datakey'] = $datakey;
        }

        self::assertSame(PGSQL_TRANSACTION_INTRANS, $status);
        self::assertCount(32, $rows);
        self::assertSame(array('id' => '2001', 'name' => 'Synthetic Member 00', 'lo' => '9', 'paid' => '0'), $rows[0]);
        $organizations = array();
        foreach (array_slice($rows, 0, 10) as $row) {
            $organizations[] = $row['lo'];
        }
        // Membership-system codes 37-45 and an unknown code (99).
        self::assertSame(array('9', '1', '3', '2', '4', '5', '6', '10', '8', '10'), $organizations);
        self::assertSame(array('id' => '2030', 'name' => 'Synthetic Member 29', 'lo' => '10', 'paid' => '1'), $rows[29]);
        // stripslashes() and ISO-8859-15 to UTF-8.
        self::assertSame(array('id' => '2031', 'name' => "O'Synthetic \xE2\x82\xACuro", 'lo' => '1', 'paid' => '1'), $rows[30]);
        // The last line before the zero padding, which rtrim() removes.
        self::assertSame(array('id' => '2032', 'name' => 'Last Synthetic', 'lo' => '2', 'paid' => '0'), $rows[31]);
    }

    public function testImportsTheStubListOverHttps()
    {
        // The whole flow: getUsers() fetches the stub's "flow" list (tests/reference/membership/flow.tsv,
        // format in server.php) over verified HTTPS, decrypts and parses it.
        // Needs the membership stub of compose.test.yaml.
        $datakey = isset($GLOBALS['datakey']) ? $GLOBALS['datakey'] : null;
        // The stub's key (tests/runtime/test-constants.php).
        $GLOBALS['datakey'] = 'synthetic-reference-membership-key';
        MembershipStub::mode('flow');
        $connection = pg_connect(pgacc_test_connection_string());
        pg_query($connection, 'BEGIN');
        try {
            AppExitGuard::enter('getUsers()');
            getUsers();
            $result = pg_query($connection, 'SELECT id, name, lo, paid FROM ppmembers ORDER BY id');
            $rows = pg_fetch_all($result);
            pg_free_result($result);
        } finally {
            AppExitGuard::leave();
            pg_query($connection, 'ROLLBACK');
            MembershipStub::reset();
            $GLOBALS['datakey'] = $datakey;
        }
        $expected = array(
            // Every organization code of the mapping, and an unknown one (99).
            array('9101', 'Anna Fluss', '9', '1'),
            array('9102', 'Bernd Fluss', '1', '0'),
            array('9103', 'Carla Fluss', '3', '1'),
            array('9104', 'Dieter Fluss', '2', '0'),
            array('9105', 'Emma Fluss', '4', '1'),
            array('9106', 'Franz Fluss', '5', '0'),
            array('9107', 'Gerda Fluss', '6', '1'),
            array('9108', 'Hannes Fluss', '10', '0'),
            array('9109', 'Iris Fluss', '8', '1'),
            array('9110', 'Unbekannt Fluss', '10', '0'),
            // ISO-8859-15 umlauts and sharp s, converted to UTF-8.
            array('9111', "J\xC3\xBCrgen Gr\xC3\xB6\xC3\x9Fe-Wei\xC3\x9F", '2', '1'),
            // stripslashes(): \' and \\ in the list become ' and \.
            array('9112', "D'Arcy Back\\slash", '1', '0'),
        );
        // The short line "x<TAB>1" and the empty last line are skipped.
        for ($i = 0; $i < 20; $i++) {
            $expected[] = array((string) (9120 + $i), sprintf('Synthetic Flow Filler %02d', $i), '2', (string) ($i % 2));
        }
        foreach ($expected as $index => $row) {
            $expected[$index] = array_combine(array('id', 'name', 'lo', 'paid'), $row);
        }
        self::assertSame($expected, $rows);
    }

    public function testMemberIdsAreNumbers()
    {
        // A member id that is not a number (second-order input from the
        // membership system) is inserted as intval(), not as SQL.
        $datakey = 'synthetic-getusers-test-key';
        $plain = "1\t2101 OR 1=1\tSynthetic Odd Id\t38\t1\n";
        for ($i = 2; $i < 40; $i++) {
            $plain .= "$i\t" . (2100 + $i) . "\tSynthetic Padding Member $i\t40\t0\n";
        }
        $rows = self::importMembers($datakey, base64_encode(membership_encrypt($datakey, $plain)));
        self::assertCount(39, $rows);
        self::assertSame(array('id' => '2101', 'name' => 'Synthetic Odd Id', 'lo' => '1', 'paid' => '1'), $rows[0]);
    }

    private static function importMembers($key, $response)
    {
        $datakey = isset($GLOBALS['datakey']) ? $GLOBALS['datakey'] : null;
        $GLOBALS['datakey'] = $key;
        MembershipStub::serve($response);
        $connection = pg_connect(pgacc_test_connection_string());
        pg_query($connection, 'BEGIN');
        try {
            AppExitGuard::enter('getUsers()');
            getUsers();
            $result = pg_query($connection, 'SELECT id, name, lo, paid FROM ppmembers ORDER BY id');
            $rows = pg_fetch_all($result);
            pg_free_result($result);
        } finally {
            AppExitGuard::leave();
            MembershipStub::reset();
            pg_query($connection, 'ROLLBACK');
            $GLOBALS['datakey'] = $datakey;
        }
        return $rows;
    }
}
