<?php
function tiny_report($konto,$date_begin,$date_end)
{
  global $realtype_req;
  $cond = " AND account LIKE '$konto%' ";
  if (strpos($konto,",") !== false)
  {
    $konto = "'" . implode("','",explode(",",$konto)) . "'";
    $cond = " AND account IN ($konto)";
  }
  if ($date_begin)
    $cond .= " AND date >= '$date_begin' ";
  if ($date_end)
    $cond .= " AND date <= '$date_end' ";
echo '
<table><tr><td>
<h3>Einnahmen</h3>
<table>
';
$query = "SELECT id,name FROM type WHERE income = 1 $realtype_req ORDER BY realtype ASC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td>{$line['name']}</td><td>";
  if ($unit == -1)
    $unit_s = "";
  else
    $unit_s = " AND orga = " . $unit;
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = {$line['id']} ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
  }
  pg_free_result($result2);
}
pg_free_result($result);
echo "<tr><td><b>Summe</b></td><td>";
if ($unit == -1)
  $unit_s = "";
else
  $unit_s = " AND orga = " . $unit;
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype IN (SELECT id FROM type WHERE income = 1) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
}
pg_free_result($result2);
echo '
</table>
</td>
<td>
<h3>Ausgaben</h3>
<table>
';
$query = "SELECT id,name FROM type WHERE income <= 0 $realtype_req ORDER BY realtype ASC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td>{$line['name']}</td><td>";
  if ($unit == -1)
    $unit_s = "";
  else
    $unit_s = " AND orga = " . $unit;
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = {$line['id']} ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
  }
  pg_free_result($result2);
}
pg_free_result($result);
echo "<tr><td><b>Summe</b></td><td>";
if ($unit == -1)
  $unit_s = "";
else
  $unit_s = " AND orga = " . $unit;
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype IN (SELECT id FROM type WHERE income <= 0) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
}
pg_free_result($result2);
echo '
</table>
</td>
</tr>
</table>
';
}

function page_tinyreport($rights)
{
  if (strlen($rights) > 0)
  {
$query2 = "SELECT account FROM vouchers WHERE NOT deleted AND amount != 0 GROUP BY account ORDER BY account";
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
echo "<ul>";
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  $k = $line2['account'];
  if ($rights == '' && is_numeric(str_replace(array(" ","AT"),array("",""),$k)))
  {
    $k = substr($line2['account'],-3,3);
  }
  echo "<li><a href=\"tinyreport?konto=$k\">Konto '".$k."'</a></li>";
}
echo "</ul>";
pg_free_result($result2);
}
  $konto = "";
  $date_begin = "";
  $date_end = "";
  if (isset($_GET["konto"]) && preg_match('/^[ A-Z0-9-,]+$/i', $_GET["konto"]) == 1)
    $konto = $_GET["konto"];
  if (isset($_GET["date_begin"]) && preg_match('/^[0-9]+-[0-9]+-[0-9]+$/i', $_GET["date_begin"]) == 1)
    $date_begin = $_GET["date_begin"];
  if (isset($_GET["date_end"]) && preg_match('/^[0-9]+-[0-9]+-[0-9]+$/i', $_GET["date_end"]) == 1)
    $date_end = $_GET["date_end"];
echo "<h1>Bericht Konto $konto</h1><br>";
block_start();
tiny_report($konto,$date_begin,$date_end);
block_end();

}
?>
