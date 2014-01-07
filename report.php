<?php
function unit_report($prevcond,$cond,$condall,$unit = 10,$year = 2012,$variant = '')
{
global $dbconn;
if ($variant == 'wk')
{
echo '
<table>
';
$query = "SELECT id,name FROM type WHERE income = -1 AND realtype = id ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td>{$line['name']}</td><td>";
  if ($unit == -1)
    $unit_s = "";
  else
    $unit_s = " AND orga = " . $unit;
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND ".eyes()." {$unit_s} AND realtype = {$line['id']} ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo ($line2['sum'] / 100.0) . " €</td></tr>\n";
  }
  pg_free_result($result2);
}
pg_free_result($result);
echo "<tr><td><b>Summe</b></td><td>";
if ($unit == -1)
  $unit_s = "";
else
  $unit_s = " AND orga = " . $unit;
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND ".eyes()." {$unit_s} AND realtype IN (SELECT id FROM type WHERE income = -1) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo ($line2['sum'] / 100.0) . " €</td></tr>\n";
}
pg_free_result($result2);
echo '
</table>
';
return;
}
echo '
<table><tr><td>
<h3>Einnahmen</h3>
<table>
';
$query = "SELECT id,name FROM type WHERE income = 1 AND realtype = id ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td>{$line['name']}</td><td>";
  if ($unit == -1)
    $unit_s = "";
  else
    $unit_s = " AND orga = " . $unit;
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND ".eyes()." {$unit_s} AND realtype = {$line['id']} ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo ($line2['sum'] / 100.0) . " €</td></tr>\n";
  }
  pg_free_result($result2);
}
pg_free_result($result);
echo "<tr><td><b>Summe</b></td><td>";
if ($unit == -1)
  $unit_s = "";
else
  $unit_s = " AND orga = " . $unit;
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND ".eyes()." {$unit_s} AND realtype IN (SELECT id FROM type WHERE income = 1) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo ($line2['sum'] / 100.0) . " €</td></tr>\n";
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
  echo "<tr><td>{$line['name']}</td><td>";
  if ($unit == -1)
    $unit_s = "";
  else
    $unit_s = " AND orga = " . $unit;
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND ".eyes()." {$unit_s} AND realtype = {$line['id']} ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo ($line2['sum'] / 100.0) . " €</td></tr>\n";
  }
  pg_free_result($result2);
}
pg_free_result($result);
echo "<tr><td><b>Summe</b></td><td>";
if ($unit == -1)
  $unit_s = "";
else
  $unit_s = " AND orga = " . $unit;
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND ".eyes()." {$unit_s} AND realtype IN (SELECT id FROM type WHERE income <= 0) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo ($line2['sum'] / 100.0) . " €</td></tr>\n";
}
pg_free_result($result2);
echo '
</table>
</td>
</tr>
</table>
';
}

function page_report($year = 2012)
{
$months = "";
$last = "31.12.$year";
$prev = "31.12." . ($year-1);
$prev_cond = "AND date < '".(intval($year))."-01-01'";
$cond = date_condition($year,1);
$condall = date_condition($year,0);
  if ($year == intval(date('Y')))
    $last = date('d.m.Y');
echo '<div class="wiki motd">';
for ($i = 2012; $i <= intval(date('Y')); $i++)
{
echo "<a href=\"index.php?year=$i\">$i</a> ";
}
echo'</div>';
echo '<h1>Rechenschaftsbericht der Piratenpartei Österreichs '.$year.$months.'</h1><br>';
echo '
<h2>Finanzübersicht (ohne Sachspenden, ohne interne Umbuchungen)</h2>
<table id="financeOverview"><th><td width="150px">Einnahmen</td><td width="150px">Ausgaben</td></th>
<tr><td>Bund (inkl. Länder)</td>
';
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12,29,30) AND ".eyes()." AND realtype IN (SELECT id FROM type WHERE income = 1) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo "<td>" . ($line2['sum'] / 100.0) . " €</td>\n";
}
pg_free_result($result2);
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12,29,30) AND ".eyes()." AND realtype IN (SELECT id FROM type WHERE income <= 0) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());      
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {            
    echo "<td>" . ($line2['sum'] / 100.0) . " €</td>\n";              
}                
pg_free_result($result2);
echo "</tr>\n";
$query = "SELECT id,name FROM lo WHERE name LIKE '%Piratenpartei%' ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td><a href=\"#{$line['name']}\">{$line['name']}</a></td>\n";
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12,29,30) AND ".eyes()." AND orga = {$line['id']} AND realtype IN (SELECT id FROM type WHERE income = 1) ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo "<td>" . ($line2['sum'] / 100.0) . " €</td>\n";
  }
  pg_free_result($result2);
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12,29,30) AND ".eyes()." AND orga = {$line['id']} AND realtype IN (SELECT id FROM type WHERE income <= 0) ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo "<td>" . ($line2['sum'] / 100.0) . " €</td>\n";
  }
  pg_free_result($result2);
  echo "</tr>\n";
}
pg_free_result($result);

$prev_date_r = explode(".",$prev);
$prev_alternative = $prev;
$query2 = "SELECT COUNT(*) AS c FROM balance WHERE date = '{$prev_date_r[2]}-{$prev_date_r[1]}-{$prev_date_r[0]}'";
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_prev_error());
if ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  if (intval($line2['c']) > 0)
  {
    $prev_date_r = "{$prev_date_r[2]}-{$prev_date_r[1]}-{$prev_date_r[0]}";
  }
  else
  {
  $query2 = "SELECT date FROM balance ORDER BY date DESC LIMIT 1";
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_prev_error());
  if ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    $prev_date_r = explode(" ",$line2['date']);
    $prev_date_r = $prev_date_r[0];
    $lar = explode("-",$prev_date_r);
    $prev_alternative = "{$lar[2]}.{$lar[1]}.{$lar[0]}";
  }
  }
}

$last_date_r = explode(".",$last);
$last_alternative = $last;
$query2 = "SELECT COUNT(*) AS c FROM balance WHERE date = '{$last_date_r[2]}-{$last_date_r[1]}-{$last_date_r[0]}'";
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  if (intval($line2['c']) > 0)
  {
    $last_date_r = "{$last_date_r[2]}-{$last_date_r[1]}-{$last_date_r[0]}";
  }
  else
  {
  $query2 = "SELECT date FROM balance ORDER BY date DESC LIMIT 1";
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  if ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    $last_date_r = explode(" ",$line2['date']);
    $last_date_r = $last_date_r[0];
    $lar = explode("-",$last_date_r);
    $last_alternative = "{$lar[2]}.{$lar[1]}.{$lar[0]}";
  }
  }
}
echo '
</table>
<p>&nbsp;</p>
<h2>Finanzübersicht (ohne Sachspenden, mit internen Umbuchungen)</h2>
<table id="financeOverview"><th><td width="150px">Kontostand '.$prev_alternative.'</td><td width="150px">Einnahmen</td><td width="150px">Ausgaben</td><td width="150px">Saldo '.$last.'</td><td width="150px">Kontostand '.$last_alternative.'</td></th>
<tr><td>Bund (inkl. Länder)</td>
';
$query2 = "SELECT SUM(value) AS value FROM balance WHERE date = '$prev_date_r'";
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
      echo "<td>" . ($line2['value'] / 100.0) . " €</td>";
}
else
{
      echo "<td>unbekannt</td>";
}
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND ".eyes()." AND realtype IN (SELECT id FROM type WHERE income = 1) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo "<td>" . ($line2['sum'] / 100.0) . " €</td>\n";
}
pg_free_result($result2);
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND ".eyes()." AND realtype IN (SELECT id FROM type WHERE income <= 0) ".$cond;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());      
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {            
    echo "<td>" . ($line2['sum'] / 100.0) . " €</td>\n";              
}                
pg_free_result($result2);
$query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND ".eyes() . $condall;
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo "<td>" . ($line2['sum'] / 100.0) . " €</td>";
}
$query2 = "SELECT SUM(value) AS value FROM balance WHERE date = '$last_date_r'";
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo "<td>" . ($line2['value'] / 100.0) . " €</td>";
}
else
{
    echo "<td>unbekannt</td>";
}
echo "</tr>\n";
$query = "SELECT id,name FROM lo WHERE name LIKE '%Piratenpartei%' ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td><a href=\"#{$line['name']}\">{$line['name']}</a></td>\n";
/*  $query = "SELECT name FROM oo WHERE lo = {$line['id']} AND name LIKE '%Piratenpartei%' ORDER BY id ASC";
  $result2 = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  if (pg_num_rows($result2) > 0)
    echo "\n<ul>\n";

  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo "<li><a href=\"#{$line2['name']}\">{$line2['name']}</a></li>\n";
  }

  if (pg_num_rows($result2) > 0)
    echo "\n</ul>\n";

  pg_free_result($result2);*/
$query2 = "SELECT SUM(value) AS value FROM balance WHERE orga = {$line['id']} AND date = '$prev_date_r'";
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
        echo "<td>" . ($line2['value'] / 100.0) . " €</td>";
}
else
{
        echo "<td>unbekannt</td>";
}
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND ".eyes()." AND orga = {$line['id']} AND realtype IN (SELECT id FROM type WHERE income = 1) ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo "<td>" . ($line2['sum'] / 100.0) . " €</td>\n";
  }
  pg_free_result($result2);
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND ".eyes()." AND orga = {$line['id']} AND realtype IN (SELECT id FROM type WHERE income <= 0) ".$cond;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo "<td>" . ($line2['sum'] / 100.0) . " €</td>\n";
  }
  pg_free_result($result2);
  $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND ".eyes()." AND orga = {$line['id']} ".$condall;
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo "<td>" . ($line2['sum'] / 100.0) . " €</td>\n";
  }
$query2 = "SELECT value FROM balance WHERE orga = {$line['id']} AND date = '$last_date_r'";
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
      echo "<td>" . ($line2['value'] / 100.0) . " €</td>";
}
else
{
      echo "<td>unbekannt</td>";
}
  echo "</tr>\n";
}
pg_free_result($result);
echo '
</table>
Die Unterorganisationen haben keine eigene Rechtspersönlichkeit.<br />
<h1>Inhaltsübersicht</h1>
<ul>
<li><a href="#eabi">Einnahmen und Ausgaben Bund (inkl. Länder)</a></li>
<li><a href="#eabe">Einnahmen und Ausgaben Bund (exkl. Länder)</a></li>
<li><a href="#eal">Einnahmen und Ausgaben Länder</a></li>
<li><a href="#awbi">Ausgaben für die Wahlwerbung Bund (inkl. Länder)</a></li>
<li><a href="#awbe">Ausgaben für die Wahlwerbung Bund (exkl. Länder)</a></li>
<li><a href="#awl">Ausgaben für die Wahlwerbung Länder</a></li>
<li><a href="#lups">Liste jener Unternehmen, an denen die Partei Stimmrechte hält</a></li>
<li><a href="#anlagen">Anlagen</a></li>
</ul>
<h1><a name="eabi">Einnahmen und Ausgaben Bund (inkl. Länder)</a></h1>
';
block_start();
unit_report($prevcond,$cond,$condall,-1,$year);
block_end();
echo '
<br />
<h1><a name="eabe">Einnahmen und Ausgaben Bund (exkl. Länder)</a></h1>
';
block_start();
unit_report($prevcond,$cond,$condall,10,$year);
block_end();
echo'
<br />
<h1><a name="eal">Einnahmen und Ausgaben Länder</a></h1>
';
block_start();
$query = "SELECT id,name FROM lo WHERE name LIKE '%Piratenpartei%' ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<br /><h2><a name=\"{$line['name']}\" href=\"#{$line['name']}\">{$line['name']}</a></h2>\n";
  unit_report($prevcond,$cond,$condall,$line['id'],$year);
$query2 = "SELECT id,name FROM oo WHERE lo = {$line['id']} AND name LIKE '%Piratenpartei%' ORDER BY id ASC";
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
  echo "<h3><a name=\"{$line2['name']}\">{$line2['name']}</a></h3>\n";
  unit_report($prevcond,$cond,$condall,$line2['id'],$year);
}
pg_free_result($result2);
}
pg_free_result($result);
block_end();
echo'
<br />
<h1><a name="awbi">Ausgaben für die Wahlwerbung Bund (inkl. Länder)</a></h1>
';
block_start();
unit_report($prevcond,$cond,$condall,-1,$year,'wk');
block_end();
echo '
<br />
<h1><a name="awbe">Ausgaben für die Wahlwerbung Bund (exkl. Länder)</a></h1>
';
block_start();
unit_report($prevcond,$cond,$condall,10,$year,'wk');
block_end();
echo'
<br />
<h1><a name="awl">Ausgaben für die Wahlwerbung Länder</a></h1>
';
block_start();
$query = "SELECT id,name FROM lo WHERE name LIKE '%Piratenpartei%' ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<br /><h2><a name=\"{$line['name']}\" href=\"#{$line['name']}\">{$line['name']}</a></h2>\n";
  unit_report($prevcond,$cond,$condall,$line['id'],$year, 'wk');
  $query2 = "SELECT id,name FROM oo WHERE lo = {$line['id']} AND name LIKE '%Piratenpartei%' ORDER BY id ASC";
  $result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo "<h3><a name=\"{$line2['name']}\">{$line2['name']}</a></h3>\n";
    unit_report($prevcond,$cond,$condall,$line2['id'],$year,'wk');
  }
  pg_free_result($result2);
}
pg_free_result($result);

block_end();
echo'
<br />
<h1><a name="lups">Liste jener Unternehmen, an denen die Partei Stimmrechte hält</a></h1>
';
block_start();
echo '
Die Piratenpartei Österreichs hält keine Stimmrechte an Unternehmen.
';
block_end();
echo'
<br />
<h1><a name="anlagen">Anlagen</a></h1>
';
block_start();
$ptypes = array();
$query = "SELECT * FROM person_type WHERE public";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
      $ptypes[] = $line;
}
pg_free_result($result);
echo '<h2>Anlage A (nach § 6 (2) und (3))</h2>
<table>
<tr><td></td><td><b>Spenden an die Partei</b></td><td><b>Spenden an nahestehende Organisationen</b></td><td><b>Spenden an Abgeordnete und Wahlwerber</b></td></tr>
';
$ptypes2[0] = $ptypes[1];
$ptypes2[1] = $ptypes[0];
$ptypes2[2] = $ptypes[3];
$ptypes2[3] = $ptypes[2];
foreach ($ptypes2 as $ptype)
{
  $amount = 0;
  $query = "SELECT SUM(amount) AS sum FROM vouchers WHERE NOT deleted AND type = 8 AND person_type = {$ptype['id']} AND ".eyes() . $cond;
  $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        $amount = $line['sum'];
  }
  pg_free_result($result);
  $amount = $amount / 100.0;
  echo "<tr><td><b>{$ptype['description']}</b></td><td>$amount €</td><td>0 €</td><td>0 €</td></tr>\n";
}
echo '</table>';
block_end();
block_start();
echo "<h2>Anlage B: Einnahmen aus Sponsoring</h2>";
echo "Die Piratenpartei Österreichs hat keine Einnahmen aus Sponsoring.";
block_end();

}
?>
