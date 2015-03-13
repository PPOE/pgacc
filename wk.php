<?php
function wk_unit_report($prevcond,$cond,$condall,$unit = 10,$year = 2012,$to = '0',$from = '0',$variant = '')
{
global $dbconn,$print_empty;
if ($variant == 'wk')
{
echo '
<table>
';
$query = "SELECT id,name FROM type WHERE income = -1 AND realtype = id ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  if ($unit == -1)
    $unit_s = "";
  else
    $unit_s = " AND orga = " . $unit;
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted {$unit_s} AND realtype = {$line['id']} ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    if (intval($line2['sum']) != 0 || $print_empty)
    {
      echo "<tr><td>{$line['name']}</td><td>";
      echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
    }
  }
  pg_free_result($result2);
}
pg_free_result($result);
echo "<tr><td><b>Summe</b></td><td>";
if ($unit == -1)
  $unit_s = "";
else
  $unit_s = " AND orga = " . $unit;
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted {$unit_s} AND realtype IN (SELECT id FROM type WHERE income = -1) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
}
pg_free_result($result2);
echo '
</table>
';
return;
}
echo '
<table><tr><td>
<h3>Einnahmen *</h3>
<font size=1>* Einnahmen im Wahlkampfzeitraum. Diese beinhalten alle Wahlkampfspenden und Sachleistungen für den Wahlkampf, aber auch alle sonstigen Einnahmen im Wahlkampfzeitraum</font>
<table>
';
$query = "SELECT id,name FROM type WHERE income = 1 AND realtype = id ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  if ($unit == -1)
    $unit_s = "";
  else
    $unit_s = " AND orga = " . $unit;
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted {$unit_s} AND realtype = {$line['id']} ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    if (intval($line2['sum']) != 0 || $print_empty)
    {
  if ($line['id'] == 11 || $line['id'] == 12)
    echo "<tr><td><a href=\"index.php?action=kdonations&type={$line['id']}&from=$from&to=$to&unit=$unit\">{$line['name']}</a></td><td>";
  else
    echo "<tr><td>{$line['name']}</td><td>";
      echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
    }
  }
  pg_free_result($result2);
}
pg_free_result($result);
echo "<tr><td><b>Summe</b></td><td>";
if ($unit == -1)
  $unit_s = "";
else
  $unit_s = " AND orga = " . $unit;
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted {$unit_s} AND realtype IN (SELECT id FROM type WHERE income = 1) ".$cond;
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
$query = "SELECT id,name FROM type WHERE income <= 0 AND realtype = id ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  if ($unit == -1)
    $unit_s = "";
  else
    $unit_s = " AND orga = " . $unit;
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted {$unit_s} AND realtype = {$line['id']} ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    if (intval($line2['sum']) != 0 || $print_empty)
    {
  echo "<tr><td><a href=\"index.php?action=spendings&type={$line['id']}&from=$from&to=$to&unit=$unit\">{$line['name']}</a></td><td>";
    echo sprintf("%1.2f",$line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
    }
  }
  pg_free_result($result2);
}
pg_free_result($result);
echo "<tr><td><b>Summe</b></td><td>";
if ($unit == -1)
  $unit_s = "";
else
  $unit_s = " AND orga = " . $unit;
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted {$unit_s} AND realtype IN (SELECT id FROM type WHERE income <= 0) ".$cond;
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

function page_wk()
{
echo '<h1>Vorläufige Wahlkampfabrechnungen</h1><br>';
echo 'Bei den nachfolgenden Wahlkampfabrechnungen handelt es sich um vorläufige Wahlkampfabrechnungen die sich noch leicht verändern können bis 100% der Buchungen im betroffenen Zeitraum im 4-Augen-Prinzip bestätigt wurden.<br />
<ul>
<li><a href="#g2012">Graz 2012</a></li>
<li><a href="#k2013">Kärnten 2013</a></li>
<li><a href="#n2013">Niederösterreich 2013</a></li>
<li><a href="#nrw2013">Natonalratswahl 2013 (Gesamt)</a></li>
<li><a href="#nrwb2013">Natonalratswahl 2013 (Bund)</a></li>
<li><a href="#nrwl2013">Natonalratswahl 2013 (Länder)</a></li>
</ul>
';
echo '<h1><a name="g2012">Graz 2012</a></h1>';
block_start();
$cond = " AND date < '2013-01-29' AND date >= '2012-09-28' ";
wk_unit_report("",$cond,$cond,6,$year,'2013-01-29','2012-09-28');
block_end();

echo '<h1><a name="k2013">Kärnten 2013</a></h1>';
block_start();
$cond = " AND date < '2013-03-06' AND date >= '2012-12-28' ";
wk_unit_report("",$cond,$cond,2,$year,'2013-03-06','2012-12-28');
block_end();

echo '<h1><a name="n2013">Niederösterreich 2013</a></h1>';
block_start();
$cond = " AND date < '2013-03-06' AND date >= '2013-01-17' ";
wk_unit_report("",$cond,$cond,3,$year,'2013-03-06','2013-01-17');
block_end();

echo '<h1><a name="nrw2013">Nationalratswahl 2013 (Gesamt)</a></h1>';
block_start();
$cond = " AND date < '2013-11-01' AND date >= '2013-06-13' ";
wk_unit_report("",$cond,$cond,-1,$year,'2013-11-01','2013-06-13');
block_end();
echo '<h1><a name="nrwb2013">Nationalratswahl 2013 (Bund)</a></h1>';
block_start();
wk_unit_report("",$cond,$cond,10,$year,'2013-11-01','2013-06-13');
block_end();
echo '<h1><a name="nrwl2013">Nationalratswahl 2013 (Länder)</a></h1>';
block_start();
$query = "SELECT id,name FROM lo WHERE name LIKE '%Piratenpartei%' ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    echo "<br /><h2><a name=\"nrw{$line['name']}2013\" href=\"#{$line['name']}\">{$line['name']}</a></h2>\n";
      wk_unit_report("",$cond,$cond,$line['id'],$year,'2013-11-01','2013-06-13');
      $query2 = "SELECT id,name FROM oo WHERE lo = {$line['id']} AND name LIKE '%Piratenpartei%' ORDER BY id ASC";
      $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
      while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
          echo "<h3><a name=\"{$line2['name']}\">{$line2['name']}</a></h3>\n";
            wk_unit_report("",$cond,$cond,$line2['id'],$year,'2013-11-01','2013-06-13');
      }
      pg_free_result($result2);
}
pg_free_result($result);
block_end();

}
?>
