<?php
/*
 * getUsers() (getusers.php) against a synthetic membership-system response.
 * The https:// stream wrapper is replaced for the duration of the test, so the
 * live membership system is never contacted. The ppmembers table is rebuilt
 * inside a transaction that is rolled back.
 *
 * Must stay compatible with PHP 5.6 (PHPUnit 5.7).
 */

require_once dirname(dirname(__DIR__)) . '/getusers.php';
require_once __DIR__ . '/AppExitGuard.php';

final class MembershipStubStream
{
    public static $response = '';
    public static $requested = array();

    /** @var resource|null */
    public $context;

    private $position = 0;

    public function stream_open($path, $mode, $options, &$openedPath)
    {
        self::$requested[] = $path;
        $this->position = 0;
        return true;
    }

    public function stream_read($count)
    {
        $chunk = substr(self::$response, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof()
    {
        return $this->position >= strlen(self::$response);
    }

    public function stream_stat()
    {
        return array();
    }
}

final class GetUsersTest extends FunctionsTestCase
{
    public function testImportsTheDecryptedMemberList()
    {
        $vectors = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/mcrypt/vectors.json'), true);
        $vector = $vectors['getusers'][0];
        self::assertSame('getUsers() export', $vector['name']);

        $datakey = isset($GLOBALS['datakey']) ? $GLOBALS['datakey'] : null;
        $GLOBALS['datakey'] = $vector['datakey'];
        MembershipStubStream::$response = $vector['sData'];
        MembershipStubStream::$requested = array();
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', 'MembershipStubStream');
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
            stream_wrapper_restore('https');
            pg_query($connection, 'ROLLBACK');
            $GLOBALS['datakey'] = $datakey;
        }

        self::assertSame(PGSQL_TRANSACTION_INTRANS, $status);
        self::assertSame(array('https://mitglieder.piratenpartei.at/adm_api/adm_names.php'), MembershipStubStream::$requested);
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
        MembershipStubStream::$response = $response;
        MembershipStubStream::$requested = array();
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', 'MembershipStubStream');
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
            stream_wrapper_restore('https');
            pg_query($connection, 'ROLLBACK');
            $GLOBALS['datakey'] = $datakey;
        }
        return $rows;
    }
}
