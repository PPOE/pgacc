<?php
function page_mb($rights)
{
$rightssql = rights2orgasql($rights);
if (strpos($rights,"bgf") === false)
  return;
$year = date('Y');
$previousYear = $year - 1;
getusers();
$query = "SELECT id,paid FROM ppmembers WHERE paid = 1;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
$ids = array();
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $ids[$line['id']] = $line['paid'];
}
pg_free_result($result);
$Samount = 0.0;
$Scount = 0;
$query = "SELECT name,COUNT(member_id) AS count,SUM(amount) AS amount FROM (SELECT lo.name,member_id,SUM(amount) AS amount FROM vouchers LEFT JOIN ppmembers ON ppmembers.id = vouchers.member_id LEFT JOIN lo ON lo.id = ppmembers.lo WHERE NOT deleted AND type = 1 AND date >= '" . $previousYear . "-10-01' GROUP BY lo.name,member_id) A GROUP BY name;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
echo "<h2>MB Zahlungen nach LO</h2><table><tr><td>Teilorganisation</td><td>Anzahl</td><td>Summe</td><td>Durchschnitt</td></tr>";
$i = 0;
$count = 0;
$errline = null;
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $i++;
  if ($line['name'] == '')
  {
    $errline = $line;
    continue;
  }
  else if ($line['name'] == 'Keine')
  {
    $line['count'] += $errline['count'];
    $line['amount'] += $errline['amount'];
  }
  $avg = sprintf("%1.2f €",$line['amount'] / 100.0 / $line['count']);
  $lo = $line['name'];
  $Samount += $line['amount'];
  $amount = sprintf("%1.2f €",$line['amount'] / 100.0);
  $count = $line['count'];
  $Scount += $count;
  echo "<tr><td>$lo</td><td>$count</td><td>$amount</td><td>$avg</td></tr>";
}
$Savg = sprintf("%1.2f €",$Samount / 100.0 / $Scount);
$Samount = sprintf("%1.2f €",$Samount / 100.0);
echo "<tr><td>Summe</td><td>$Scount</td><td>$Samount</td><td>$Savg</td></tr>";
echo "</table><br />";

$query = "SELECT lo.name,SUM(amount) AS amount FROM vouchers LEFT JOIN lo ON orga = lo.id WHERE NOT deleted AND type = 1 AND date >= '" . $previousYear . "-10-01' GROUP BY lo.name ORDER BY lo.name;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
echo "<h2>MB Zahlungen nach Konto</h2><table><tr><td>Konto der Teilorganisation</td><td>Summe</td></tr>";
$i = 0;
$count = 0;
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $i++;
  $lo = $line['name'];
  $amount = sprintf("%1.2f €",$line['amount'] / 100.0);
  echo "<tr><td>$lo</td><td>$amount</td></tr>";
}
echo "</table><br />";

$query = "SELECT * FROM ppmembers LEFT JOIN (SELECT SUM(amount) AS amount,member_id FROM vouchers LEFT JOIN lo ON orga = lo.id WHERE type = 1 AND date >= '" . $previousYear . "-10-01' AND NOT deleted $rightssql GROUP BY member_id ORDER BY member_id) A ON member_id = id WHERE amount >= 2000 OR paid = 1 ORDER BY id";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
echo "<h2>Inkonsistenzen bei Zahlungsstatus</h2><table><tr><td>#</td><td>Member</td><td>Name</td><td>Amount</td><td>Paid</td></tr>";
$i = 0;
$count = 0;
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $i++;
  if (($line['amount'] < 2000 && $line['paid'] == 1) || ($line['amount'] >= 2000 && $line['paid'] == 0))
  {
    $count++;
  echo "<tr><td>$count</td>
  <td><a href=\"https://mitglieder.piratenpartei.at/adm_program/modules/profile/profile.php?user_id={$line['id']}\">{$line['id']}</a> (<a href=\"https://finanzen.piratenpartei.at/index.php?action=all&filter_member_id={$line['id']}\">Buchungszeilen</a>)</td>
  <td>".($line['name'])."</td>
  <td>".($line['amount']/100.0)."</td>
  <td>".intval($line['paid'])."</td></tr>";
  }
}
echo "</table><br />";
pg_free_result($result);

$query = "SELECT * FROM ppmembers LEFT JOIN (SELECT SUM(amount) AS amount,member_id FROM vouchers LEFT JOIN lo ON orga = lo.id WHERE type = 1 AND date >= '" . $previousYear . "-10-01' AND NOT deleted $rightssql GROUP BY member_id ORDER BY member_id) A ON member_id = id WHERE amount >= 2000 OR paid = 1 ORDER BY id";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
echo "<h2>Mitglieder mit konsistentem Zahlungsstatus</h2><table><tr><td>#</td><td>Member</td><td>Name</td><td>Amount</td><td>Paid</td></tr>";
$i = 0;
$count = 0;
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $i++;
  if (($line['amount'] >= 2000 && $line['paid'] == 1) || ($line['amount'] < 2000 && $line['paid'] == 0))
  {
    $count++;
  echo "<tr><td>$count</td>
  <td><a href=\"https://mitglieder.piratenpartei.at/adm_program/modules/profile/profile.php?user_id={$line['id']}\">{$line['id']}</a> (<a href=\"https://finanzen.piratenpartei.at/index.php?action=all&filter_member_id={$line['id']}\">Buchungszeilen</a>)</td>
  <td>".($line['name'])."</td>
  <td>".($line['amount']/100.0)."</td>
  <td>".intval($line['paid'])."</td></tr>";
  }
}
echo "</table>";
pg_free_result($result);

echo "<h2>Offene Darlehen</h2>";
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
