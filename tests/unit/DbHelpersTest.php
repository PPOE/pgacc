<?php

/*
 * Database helpers in src/db.php: db_query() returns the result, db_exec()
 * returns the affected rows, db_next_value() the next sequence value, and a
 * failed statement ends the request with the pages' message (checked in a
 * separate process, since die() would end PHPUnit).
 */

final class DbHelpersTest extends FunctionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pg_query($GLOBALS['dbconn'], 'CREATE TEMP TABLE db_helpers_rows (n integer)');
        pg_query($GLOBALS['dbconn'], 'CREATE TEMP SEQUENCE db_helpers_sequence START 41');
    }

    protected function tearDown(): void
    {
        pg_query($GLOBALS['dbconn'], 'DROP TABLE db_helpers_rows');
        pg_query($GLOBALS['dbconn'], 'DROP SEQUENCE db_helpers_sequence');
    }

    public function testDbQueryReturnsTheResult()
    {
        $result = db_query($GLOBALS['dbconn'], 'SELECT 7 AS n UNION ALL SELECT 8 ORDER BY 1');

        self::assertSame([['n' => '7'], ['n' => '8']], pg_fetch_all($result));
    }

    public function testDbExecReturnsTheAffectedRows()
    {
        self::assertSame(3, db_exec($GLOBALS['dbconn'], 'INSERT INTO db_helpers_rows VALUES (1), (2), (3)'));
        self::assertSame(2, db_exec($GLOBALS['dbconn'], 'UPDATE db_helpers_rows SET n = 0 WHERE n > 1'));
        self::assertSame(0, db_exec($GLOBALS['dbconn'], 'DELETE FROM db_helpers_rows WHERE n > 1'));
        $rows = pg_fetch_all(pg_query($GLOBALS['dbconn'], 'SELECT n FROM db_helpers_rows ORDER BY n'));
        self::assertSame([['n' => '0'], ['n' => '0'], ['n' => '1']], $rows);
    }

    public function testDbNextValueReturnsTheNextValue()
    {
        self::assertSame('41', db_next_value($GLOBALS['dbconn'], 'db_helpers_sequence'));
        self::assertSame('42', db_next_value($GLOBALS['dbconn'], 'db_helpers_sequence'));
    }

    public static function failureProvider()
    {
        $missing = 'SELECT * FROM db_helpers_missing';

        return [
            'db_query' => ["db_query(\$c, '$missing');", $missing, 'Abfrage fehlgeschlagen: '],
            'db_query with a message' => ["db_query(\$c, '$missing', 'Abfrage (x) fehlgeschlagen: ');", $missing, 'Abfrage (x) fehlgeschlagen: '],
            'db_exec' => ["db_exec(\$c, '$missing');", $missing, 'Abfrage fehlgeschlagen: '],
            'db_exec with a message' => ["db_exec(\$c, '$missing', 'Abfrage (y) fehlgeschlagen: ');", $missing, 'Abfrage (y) fehlgeschlagen: '],
            'db_next_value' => ["db_next_value(\$c, 'db_helpers_missing');", "SELECT nextval('db_helpers_missing') AS num;", 'Abfrage fehlgeschlagen: '],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('failureProvider')]
    public function testFailureEndsTheRequestWithTheMessage($call, $sql, $message)
    {
        // The error text as PostgreSQL reports it for the same statement.
        self::assertFalse(@pg_query($GLOBALS['dbconn'], $sql));
        $expected = $message . pg_last_error($GLOBALS['dbconn']);

        $root = dirname(dirname(__DIR__));
        $code = 'require ' . var_export($root . '/tests/db-helpers.php', true) . ';'
            . ' require ' . var_export($root . '/src/db.php', true) . ';'
            . ' $c = pg_connect(pgacc_test_connection_string());'
            . ' ' . $call . ' echo "not ended";';
        $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -r ' . escapeshellarg($code);
        $output = shell_exec($command . '; printf "\n%d" "$?"');

        self::assertSame($expected . "\n0", $output);
    }
}
