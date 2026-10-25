<?php

include "constants.php";
require "vendor/autoload.php";
require "functions.php";

$dbconn = pg_connect("dbname=accounting");
if ($dbconn === false) {
    // No connection: pg_last_error() would throw; log the reason, show a fixed message.
    $pgacc_connect_error = error_get_last();
    error_log('pgacc: pg_connect failed: ' . ($pgacc_connect_error === null ? 'no error message' : $pgacc_connect_error['message']));
    die('Verbindungsaufbau fehlgeschlagen');
}

$paydate = (intval(date('Y')) - 1) . "-10-01";
$query = "SELECT * FROM (SELECT SUM(amount) AS amount,MAX(date) AS date,member_id FROM vouchers WHERE type = 1 AND date >= '$paydate' AND NOT deleted AND ack1 IS NOT NULL GROUP BY member_id ORDER BY member_id) A WHERE amount >= 2000 ORDER BY member_id";
$result = pg_query($dbconn, $query) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
$i = 0;
$text = "";
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    $i++;
    $text .= $line['member_id'] . "\t" . $line['amount'] . "\t" . date('Y') . "-12-31\n";
}
echo sha1($text) . "\n";
$encrypted = base64_encode(membership_encrypt($datakey, $text));
echo $encrypted;
pg_free_result($result);
pg_close($dbconn);
