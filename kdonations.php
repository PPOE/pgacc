<?php
function kdonations_page_listing_header($action)
{
block_start();
global $make_csv;
if (!$make_csv)
  echo '<table>';
echo tag("tr",tag("td",tag("b",sortlink($action,'datea','Datum'))) . 
tag("td",tag("b",sortlink($action,'typea','Art'))) . 
tag("td",tag("b",sortlink($action,'loa','LO'))) . 
tag("td",tag("b",sortlink($action,'texta','Text'))) .
tag("td",tag("b",sortlink($action,'ama','Wert'))) .
tag("td",tag("b",sortlink($action,'namea','Name'))));
}

function kdonations_page_listing_line($line)
{
global $make_csv;
if (!$make_csv)
echo "<tr>";
echo tag("td", format_date($line["date"]));
$query2 = "SELECT name FROM type WHERE id = " . intval($line["type"]);
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
echo tag("td",str_replace(array('Spenden (mit Ausnahme der von lebenden Subventionen und Sachspenden)'),array('Spenden'),$line2["name"]));
}
pg_free_result($result2);
$query2 = "SELECT name FROM lo WHERE id = " . intval($line["orga"]);
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
echo tag("td", $line2["name"]);
}
pg_free_result($result2);
echo tag("td", preg_replace(array('/((BG|FE|ZE|VB|OG|IG)\/\d{9}|\d{5}.\d+)/'),array(''),preg_replace(array('/((BG|FE|ZE|VB|OG|IG)\/\d+(\s\d+)+)|(((BG|FE|ZE|VB|OG|IG)\/\d+)?[A-Z]{8} [A-Z]{2}\d+)/'),array(" <i>Kontodaten</i> "),$line["comment"])));
echo tag("td", ($line["amount"] / 100.0) . "€");
echo tag("td", (intval($line["amount"]) <= 350000) ? "" : $line["name"]);
if ($make_csv)
  echo "\n";
else
  echo "</tr>";
}

function page_kdonations()
{
  echo "<h1>Sachspendenliste der Piratenpartei Österreichs</h1>\n";
  $type = -1;
  if (isset($_GET["type"]) && preg_match('/^\d+$/', $_GET["type"]) == 1)
    $type = $_GET["type"];
  $unit = -1;
  if (isset($_GET["unit"]) && preg_match('/^-?\d+$/', $_GET["unit"]) == 1)
    $unit = $_GET["unit"];
  $from = -1;
  if (isset($_GET["from"]) && preg_match('/^\d+-\d+-\d+$/', $_GET["from"]) == 1)
    $from = $_GET["from"];
  $to = -1;
  if (isset($_GET["to"]) && preg_match('/^\d+-\d+-\d+$/', $_GET["to"]) == 1)
    $to = $_GET["to"];

$where = "";
if ($type != -1)
  $where .= " AND type = $type";
if ($unit != -1)
  $where .= " AND orga = $unit";
if ($from != -1)
  $where .= " AND date >= '$from'";
if ($to != -1)
  $where .= " AND date < '$to'";

kdonations_page_listing_header('kdonations');
$sort = getsort();
$query = "SELECT * FROM vouchers WHERE NOT deleted AND type IN (11,12) AND ".eyes()." AND (SELECT SUM(amount) FROM vouchers B WHERE ".eyes()." AND B.voucher_id = vouchers.voucher_id) > 0 AND amount > 0 $where ORDER BY $sort";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
kdonations_page_listing_line($line);
}
pg_free_result($result);
global $make_csv;
if (!$make_csv)
echo '</table>';
block_end();
csv_download_link();
}
?>
