<?php

/*
 * check.php, the command-line voucher number check: check_main() in process
 * on synthetic vouchers inside a transaction that is rolled back, and the
 * script as a command (arguments, exit status, stderr). The refusal over
 * HTTP is in tests/http-smoke.php.
 */

require_once dirname(dirname(__DIR__)) . '/check.php';

final class CheckCommandTest extends FunctionsTestCase
{
    private $connection;

    #[PHPUnit\Framework\Attributes\Before]
    public function prepare()
    {
        // check_main() gets the same connection string, so pg_connect()
        // returns this link and sees the uncommitted rows.
        $this->connection = pg_connect(pgacc_test_connection_string());
        pg_query($this->connection, 'BEGIN');
        $rows = [
            // Bank account: number 2 once, number 3 twice, one without a number.
            [9501, 4801, 'SYNTH BANK', 'Synthetic transfer LM/000000002'],
            [9502, 4802, 'SYNTH BANK', 'Synthetic transfer LM/000000003'],
            [9503, 4803, 'SYNTH BANK', 'Synthetic transfer LM/000000003'],
            [9504, 4804, 'SYNTH BANK', 'Synthetic transfer without number'],
            // Cash account: "Nr.<n>." comments.
            [9505, 4805, 'Handkassa SYNTH', 'Synthetic cash Nr.2.'],
        ];
        foreach ($rows as $row) {
            pg_query_params($this->connection, "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, date, person_type, file, vaccount)
                VALUES ($1, $2, 20, 2, false, -100, $3, $4, timestamp '2024-09-15 00:00:00', 1, 0, '')", $row);
        }
        // Deleted vouchers are not counted.
        pg_query($this->connection, "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, date, person_type, file, vaccount, deleted)
            VALUES (9506, 4806, 20, 2, false, -100, 'SYNTH BANK', 'Synthetic transfer LM/000000004', timestamp '2024-09-15 00:00:00', 1, 0, '', true)");
    }

    #[PHPUnit\Framework\Attributes\After]
    public function cleanUp()
    {
        pg_query($this->connection, 'ROLLBACK');
    }

    private static function check(array $arguments, $conninfo = null)
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $status = check_main($arguments, $out, $err, $conninfo ?? pgacc_test_connection_string());
        rewind($out);
        rewind($err);
        return [$status, stream_get_contents($out), stream_get_contents($err)];
    }

    public function testBankAccount()
    {
        self::assertSame([0, 'Habe 3 von 2 Buchungen gefunden.<br /><br />'
            . 'Habe insgesamt 4  Buchungen gefunden.<br /><br />'
            . 'Habe 1  Buchungen gefunden die ich nicht zuordnen konnte.<br /><br />'
            . "Buchung 2 OK<br />\nBuchung 3 FEHLT (2 results)<br />\nBuchung 4 FEHLT (0 results)<br />\n", ''], self::check(['SYNTH BANK', '4']));
    }

    public function testCashAccount()
    {
        self::assertSame([0, 'Habe 1 von 1 Buchungen gefunden.<br /><br />'
            . 'Habe insgesamt 1  Buchungen gefunden.<br /><br />'
            . 'Habe 0  Buchungen gefunden die ich nicht zuordnen konnte.<br /><br />'
            . "Buchung 2 OK<br />\nBuchung 3 FEHLT (0 results)<br />\n", ''], self::check(['Handkassa SYNTH', '3']));
    }

    public function testInvalidAccountAndMax()
    {
        // An account with other characters checks account 0; max is intval().
        self::assertSame([0, 'Habe 0 von 0 Buchungen gefunden.<br /><br />'
            . 'Habe insgesamt 0  Buchungen gefunden.<br /><br />'
            . 'Habe 0  Buchungen gefunden die ich nicht zuordnen konnte.<br /><br />'
            . "Buchung 2 FEHLT (0 results)<br />\n", ''], self::check(["SYNTH BANK' OR ''='", '2x']));
    }

    public function testWrongArgumentCount()
    {
        $usage = "Usage: php check.php <account> <max>\n";
        self::assertSame([2, '', $usage], self::check([]));
        self::assertSame([2, '', $usage], self::check(['SYNTH BANK']));
        self::assertSame([2, '', $usage], self::check(['SYNTH BANK', '3', 'extra']));
    }

    public function testQueryFailure()
    {
        // No vouchers table on this search path: the first query fails.
        [$status, $output, $errors] = self::check(['SYNTH BANK', '3'], pgacc_test_connection_string() . " options='-csearch_path=pgacc_missing'");
        self::assertSame([1, ''], [$status, $output]);
        self::assertStringStartsWith('Abfrage fehlgeschlagen: ERROR:  relation "vouchers" does not exist', $errors);
    }

    public function testConnectionFailure()
    {
        [$status, $output, $errors] = self::check(['SYNTH BANK', '3'], 'host=127.0.0.1 port=1 dbname=accounting connect_timeout=5');
        self::assertSame([1, ''], [$status, $output]);
        self::assertStringStartsWith('Verbindungsaufbau fehlgeschlagen: pg_connect(): Unable to connect to PostgreSQL server:', $errors);
    }

    public function testCommandLine()
    {
        $command = function ($arguments, $environment = '') {
            $process = proc_open(
                $environment . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(dirname(__DIR__)) . '/check.php') . $arguments,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $output, $errors];
        };
        // The command does not see this test's uncommitted rows.
        self::assertSame([0, 'Habe 0 von 1 Buchungen gefunden.<br /><br />'
            . 'Habe insgesamt 0  Buchungen gefunden.<br /><br />'
            . 'Habe 0  Buchungen gefunden die ich nicht zuordnen konnte.<br /><br />'
            . "Buchung 2 FEHLT (0 results)<br />\nBuchung 3 FEHLT (0 results)<br />\n", ''], $command(' SYNTHETIC0 3'));
        self::assertSame([2, '', "Usage: php check.php <account> <max>\n"], $command(''));
        [$status, $output, $errors] = $command(' SYNTHETIC0 3', 'PGHOST=127.0.0.1 PGPORT=1 ');
        self::assertSame([1, ''], [$status, $output]);
        self::assertStringStartsWith('Verbindungsaufbau fehlgeschlagen: ', $errors);
    }
}
