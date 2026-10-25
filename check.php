<?php
function fail($i, $optional = "")
{
    echo "Buchung $i FEHLT$optional<br />\n";
}
require("constants.php");
$dbconn = pg_connect("dbname=accounting");
if ($dbconn === false) {
    // No connection: pg_last_error() would throw; log the reason, show a fixed message.
    $pgacc_connect_error = error_get_last();
    error_log('pgacc: pg_connect failed: ' . ($pgacc_connect_error === null ? 'no error message' : $pgacc_connect_error['message']));
    die('Verbindungsaufbau fehlgeschlagen');
}

$kto = 0;
if (preg_match('/^[A-Z0-9 ]+$/i', $_GET['kto']) == 1)
    $kto = $_GET['kto'];
$max = intval($_GET['max']);

$like = '%/00000%';
$format = '/%09d';
if (str_contains($kto, "Handkassa")) {
    $like = '%Nr.%.%';
    $format = 'Nr.%d.';
}

$query = "SELECT COUNT(*) AS c FROM (SELECT voucher_id FROM vouchers WHERE account = '$kto' AND NOT DELETED AND comment LIKE '$like' GROUP BY voucher_id) A";
$result = pg_query($dbconn, $query) or fail($i);
if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    echo "Habe " . $line['c'] . " von " . ($max - 2) . " Buchungen gefunden.<br /><br />";
}
pg_free_result($result);

$query = "SELECT COUNT(*) AS c FROM (SELECT voucher_id FROM vouchers WHERE account = '$kto' AND NOT DELETED GROUP BY voucher_id) A";
$result = pg_query($dbconn, $query) or fail($i);
if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    echo "Habe insgesamt " . $line['c'] . "  Buchungen gefunden.<br /><br />";
}
pg_free_result($result);

$query = "SELECT COUNT(*) AS c FROM (SELECT voucher_id FROM vouchers WHERE account = '$kto' AND NOT DELETED AND comment NOT LIKE '$like' AND voucher_id NOT IN (SELECT voucher_id FROM vouchers WHERE account = '$kto' AND NOT DELETED AND comment LIKE '$like' GROUP BY voucher_id) GROUP BY voucher_id) A";
$result = pg_query($dbconn, $query) or fail($i);
if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    echo "Habe " . $line['c'] . "  Buchungen gefunden die ich nicht zuordnen konnte.<br /><br />";
}
pg_free_result($result);

for ($i = 2; $i <= $max; $i++) {
    $query = "SELECT COUNT(*) AS c FROM (SELECT voucher_id FROM vouchers WHERE account = '$kto' AND NOT DELETED AND comment LIKE '%" . sprintf("$format", $i) . "%' GROUP BY voucher_id) A";
    $result = pg_query($dbconn, $query) or fail($i);
    if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        if ($line['c'] == 1) {
            echo "Buchung $i OK<br />\n";
        } else {
            fail($i, " ({$line['c']} results)");
        }
    }
    pg_free_result($result);
}


pg_close($dbconn);
?>

