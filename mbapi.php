<?php
include "constants.php";

$dbconn = pg_connect("dbname=accounting")
  or die('Verbindungsaufbau fehlgeschlagen: ' . pg_last_error());

$paydate = (intval(date('Y')) - 1) . "-10-01";
$query = "SELECT * FROM (SELECT SUM(amount) AS amount,MAX(date) AS date,member_id FROM vouchers WHERE type = 1 AND date >= '$paydate' AND NOT deleted AND ack1 IS NOT NULL GROUP BY member_id ORDER BY member_id) A WHERE amount >= 2000 ORDER BY member_id";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
$i = 0;
$text = "";
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $i++;
  $text .= $line['member_id']."\t".$line['amount']."\t".date('Y')."-12-31\n";
}
echo sha1($text)."\n";
$encrypted = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($datakey), $text, MCRYPT_MODE_CBC, md5(md5($datakey))));
echo $encrypted;
pg_free_result($result);
pg_close($dbconn);
?>
