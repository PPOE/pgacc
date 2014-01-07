<?php
function page_mb($rights)
{
$rightssql = rights2orgasql($rights);
getusers();
$query = "SELECT id,paid FROM ppmembers WHERE paid = 1;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
$ids = array();
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $ids[$line['id']] = $line['paid'];
}
pg_free_result($result);

$query = "SELECT * FROM ppmembers LEFT JOIN (SELECT SUM(amount) AS amount,member_id FROM vouchers LEFT JOIN lo ON orga = lo.id WHERE type = 1 AND date >= '2013-10-01' AND NOT deleted $rightssql GROUP BY member_id ORDER BY member_id) A ON member_id = id WHERE amount >= 2000 OR paid = 1 ORDER BY id";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
echo "<table><tr><td>#</td><td>Member</td><td>Amount</td><td>Paid</td></tr>";
$i = 0;
$count = 0;
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $i++;
  if ($line['amount'] < 2000 && $line['paid'] == 1)
    $count++;
  echo "<tr><td>$i</td>
  <td><a href=\"https://mitglieder.piratenpartei.at/adm_program/modules/profile/profile.php?user_id={$line['id']}\">{$line['id']}</a></td>
  <td>".($line['amount']/100.0)."</td>
  <td>".intval($line['paid'])."</td></tr>";
}
echo "</table>";
pg_free_result($result);
echo "<p>$count inconsistencies.</p>\n<h3>Darlehen</h3>";
$query = "SELECT * FROM (SELECT A.voucher_id,A.amount + COALESCE(B.amount,0) AS amount,A.name FROM vouchers A LEFT JOIN vouchers B ON (A.name = B.name OR A.member_id = B.member_id) AND B.type = 23 AND NOT B.deleted WHERE A.type = 13 AND NOT A.deleted) X WHERE amount > 0;";
echo "<table><tr><td>Voucher</td><td>Amount</td><td>Name</td></tr>";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td><a href=\"index.php?action=edit&id={$line['voucher_id']}\">{$line['voucher_id']}</a></td>
  <td>".($line['amount']/100.0)."</td>
  <td>".intval($line['name'])."</td></tr>";
}
echo "</table>";
pg_free_result($result);

$query = 'DROP TABLE IF EXISTS ppmembers;';
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
pg_free_result($result);
}
?>
