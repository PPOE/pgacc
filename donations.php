<?php
function date_condition($year,$begin = 1)
{
  if ($begin == 0)
    return "AND date < '".(intval($year)+1)."-01-01'";
  else
    return "AND date >= '".$year."-01-01' AND date < '".(intval($year)+1)."-01-01'";
}
function page_donations($year = 2012)
{
echo "<h1>Spenden-Einnahmen der Piratenpartei Österreichs $year</h1>\n";
echo '<div class="wiki motd">';
for ($i = 2012; $i <= intval(date('Y')); $i++)
{
echo "<a href=\"index.php?action=donations&year=$i\">$i</a> ";
}
echo '<br /><br />';
if ($year == 2012)
{
  echo 'Gemäß <a href="https://wiki.piratenpartei.at/wiki/BGV2012-01/Protokoll#Antrag_FO01">Beschluss FO01 der Bundesgeneralversammlung in Wien am 1.4.2012</a> veröffentlichen wir alle Spenden im Jahr 2012.';
}
else
{
  echo 'Gemäß <a href="https://lqfb.piratenpartei.at/initiative/show/1151.html">Beschluss i1151 vom 28.12.2012</a> veröffentlichen wir alle Spenden ab 100€ pro Jahr und Spender.';
}
echo'</div>';
block_start();
echo '
<table id="donations" style="min-width: 50%" border="1">
<tr><td><b>Name</b></td><td><b>Spendensumme</b></td></tr>
';
$donation_condition = "AND type = 8";
$query = "SELECT name,SUM(amount) AS sum FROM vouchers WHERE ".eyes()." AND NOT member $donation_condition ".date_condition($year)." GROUP BY name,street,plz,city;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
if ($year != 2012 && $line['sum'] <= 10000) { continue; }
if ($year == 2012 && $line['sum'] <= 0) { continue; }
$line['member_id'] = 0;
$donations[] = $line;
}
pg_free_result($result);

$query = "SELECT member_id,name,SUM(amount) AS sum FROM vouchers WHERE ".eyes()." AND member $donation_condition ".date_condition($year)." GROUP BY member_id,name;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
$count = 0;
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
if ($year != 2012 && $line['sum'] <= 10000) { continue; }
if ($year == 2012 && $line['sum'] <= 0) { continue; }
$donations[] = $line;
$count++;
}
pg_free_result($result);

if ($count > 0)
{
foreach ($donations as $d)
{
$sort_sum[] = $d['sum'];
$sort_name[] = $d['name'];
}

array_multisort($sort_sum, SORT_DESC, $sort_name, SORT_ASC, $donations);

foreach ($donations as $line)
{
echo "<tr>";
echo tag("td", $line["name"]);
echo tag("td", $line["sum"] / 100.0 . '€');
echo "</tr>";
}
}
echo '</table>';
block_end();
}
?>
