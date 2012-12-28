<?php
function page_donations()
{
block_start();
echo '
<table style="min-width: 50%" border="1">
<tr><td><b>Name</b></td><td><b>Anschrift</b></td><td><b>Spendensumme</b></td></tr>
';
$donation_condition = "AND type = 8";
$query = "SELECT name,street,plz,city,SUM(amount) AS sum FROM vouchers WHERE NOT deleted AND acknowledged AND NOT member $donation_condition GROUP BY name,street,plz,city;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
if ($line['sum'] <= 0) { continue; }
$line['member_id'] = 0;
$donations[] = $line;
}
pg_free_result($result);

$query = "SELECT member_id,SUM(amount) AS sum FROM vouchers WHERE NOT deleted AND acknowledged AND member $donation_condition GROUP BY member_id;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
if ($line['sum'] <= 0) { continue; }
$line['name'] = 'Name von Mitglied #' . $line['member_id'];
$line['street'] = 'Straße von Mitglied #' . $line['member_id'];
$line['plz'] = '1234';
$line['city'] = 'Ein Ort';
$donations[] = $line;
}
pg_free_result($result);

foreach ($donations as $d)
{
$sort_sum[] = $d['sum'];
$sort_name[] = $d['name'];
}

array_multisort($sort_sum, SORT_DESC, $sort_name, SORT_ASC, $donations);

foreach ($donations as $line)
{
echo "<tr>";
tag("td", $line["name"]);
tag("td", $line["street"] . '<br />' . $line["plz"] . ' ' . $line["city"]);
tag("td", $line["sum"] / 100.0 . '€');
echo "</tr>";
}
echo '</table>';
block_end();
}
?>
