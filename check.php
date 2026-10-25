<?php

if (PHP_SAPI !== 'cli') {
    // A web request gets a 404; php-cgi run from a shell gets a message.
    isset($_SERVER['REQUEST_METHOD']) ? header('HTTP/1.0 404 Not Found') : fwrite(fopen('php://stderr', 'w'), "check.php must be run with the php command-line binary.\n");
    exit(1);
}
/*
 * Voucher number check (command line only, like migrate-receipt-key.php; the
 * statement above stops any other server API, with a 404 for web requests).
 * Lists for one account whether the vouchers numbered 2 to <max> exist
 * ("Buchung <n> OK" or "FEHLT"):
 *
 *   php check.php <account> <max>
 *
 * <account> must consist of letters, digits and spaces (otherwise account 0
 * is checked); <max> is read with intval(). Bank accounts match comments
 * containing "/<n>" with nine digits, accounts named "Handkassa..." match
 * "Nr.<n>.". A database error prints a message on stderr and exits with
 * status 1.
 */

function check_missing($out, $i, $optional = '')
{
    fwrite($out, "Buchung $i FEHLT$optional<br />\n");
}

// The count of a query; throws RuntimeException if it failed.
function check_count($dbconn, $query)
{
    $result = @pg_query($dbconn, $query);
    if ($result === false) {
        throw new RuntimeException('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
    }
    $line = pg_fetch_array($result, null, PGSQL_ASSOC);
    pg_free_result($result);
    return $line['c'];
}

function check_run($dbconn, $account, $max, $out)
{
    $kto = 0;
    if (preg_match('/^[A-Z0-9 ]+$/i', $account) == 1) {
        $kto = $account;
    }
    $max = intval($max);

    $like = '%/00000%';
    $format = '/%09d';
    if (str_contains($kto, 'Handkassa')) {
        $like = '%Nr.%.%';
        $format = 'Nr.%d.';
    }

    $count = check_count($dbconn, "SELECT COUNT(*) AS c FROM (SELECT voucher_id FROM vouchers WHERE account = '$kto' AND NOT DELETED AND comment LIKE '$like' GROUP BY voucher_id) A");
    fwrite($out, "Habe $count von " . ($max - 2) . ' Buchungen gefunden.<br /><br />');

    $count = check_count($dbconn, "SELECT COUNT(*) AS c FROM (SELECT voucher_id FROM vouchers WHERE account = '$kto' AND NOT DELETED GROUP BY voucher_id) A");
    fwrite($out, "Habe insgesamt $count  Buchungen gefunden.<br /><br />");

    $count = check_count($dbconn, "SELECT COUNT(*) AS c FROM (SELECT voucher_id FROM vouchers WHERE account = '$kto' AND NOT DELETED AND comment NOT LIKE '$like' AND voucher_id NOT IN (SELECT voucher_id FROM vouchers WHERE account = '$kto' AND NOT DELETED AND comment LIKE '$like' GROUP BY voucher_id) GROUP BY voucher_id) A");
    fwrite($out, "Habe $count  Buchungen gefunden die ich nicht zuordnen konnte.<br /><br />");

    for ($i = 2; $i <= $max; $i++) {
        $count = check_count($dbconn, "SELECT COUNT(*) AS c FROM (SELECT voucher_id FROM vouchers WHERE account = '$kto' AND NOT DELETED AND comment LIKE '%" . sprintf("$format", $i) . "%' GROUP BY voucher_id) A");
        if ($count == 1) {
            fwrite($out, "Buchung $i OK<br />\n");
        } else {
            check_missing($out, $i, " ($count results)");
        }
    }
}

// Exit status: 0 done, 1 database error, 2 wrong arguments.
function check_main(array $arguments, $out, $err, $conninfo = 'dbname=accounting')
{
    if (count($arguments) !== 2) {
        fwrite($err, "Usage: php check.php <account> <max>\n");
        return 2;
    }
    // Not closed: pg_connect() may return a link the caller still uses.
    $dbconn = @pg_connect($conninfo);
    if ($dbconn === false) {
        fwrite($err, 'Verbindungsaufbau fehlgeschlagen: ' . (error_get_last()['message'] ?? 'no error message') . "\n");
        return 1;
    }
    try {
        check_run($dbconn, $arguments[0], $arguments[1], $out);
    } catch (RuntimeException $e) {
        fwrite($err, $e->getMessage() . "\n");
        return 1;
    }
    return 0;
}

if (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    exit(check_main(array_slice($_SERVER['argv'], 1), STDOUT, STDERR));
}
