<?php
function spendings_page_listing_header($action)
{
block_start();
global $make_csv;
if (!$make_csv)
  echo '<table>';
echo tag("tr",tag("td",tag("b",sortlink($action,'idd','Buchung'))) . 
tag("td",tag("b",sortlink($action,'bida','Buchungszeile'))) . 
tag("td",tag("b",sortlink($action,'datea','Datum'))) . 
tag("td",tag("b",sortlink($action,'typea','Art'))) . 
tag("td",tag("b",sortlink($action,'loa','LO'))) . 
tag("td",tag("b",sortlink($action,'ka','Konto'))) . 
tag("td",tag("b",sortlink($action,'texta','Text'))) .
tag("td",tag("b",sortlink($action,'ama','Betrag'))));
}

function spendings_page_listing_line($line)
{
global $make_csv;
if (!$make_csv)
echo "<tr>";
echo tag("td", $line["voucher_id"]);
echo tag("td", $line["id"]);
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
echo tag("td", str_replace(array(
'50110117369',
'10110123642',
'50110117318',
'50110110437',
'50110117393',
'50110117300',
'50110117326',
'50110117350',
'50110117377',
'50110117270'
),array(
'Piratenpartei Steiermark',
'Piratenpartei Wien',
'Piratenpartei Niederösterreich',
'Piratenpartei Österreichs',
'Piratenpartei Vorarlberg',
'Piratenpartei Kärnten',
'Piratenpartei Oberösterreich',
'Piratenpartei Salzburg',
'Piratenpartei Tirol',
'Piratenpartei Burgenland'
),$line["account"]));
echo tag("td", preg_replace(array('/((BG|FE|ZE|VB|OG|IG)\/\d{9}|\d{5}.\d+)/'),array(''),preg_replace(array('/((BG|FE|ZE|VB|OG|IG)\/\d+(\s\d+)+)|(((BG|FE|ZE|VB|OG|IG)\/\d+)?[A-Z]{8} [A-Z]{2}\d+)/'),array(" <i>Kontodaten</i> "),$line["comment"])));
echo tag("td", ($line["amount"] / 100.0) . "€");
if ($make_csv)
  echo "\n";
else
  echo "</tr>";
}

function page_spendings()
{
spendings_page_listing_header('spendings');
$sort = getsort();
$query = "SELECT * FROM vouchers WHERE ".eyes()." AND (SELECT SUM(amount) FROM vouchers B WHERE ".eyes()." AND B.voucher_id = vouchers.voucher_id) < 0 AND amount < 0 ORDER BY $sort";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
spendings_page_listing_line($line);
}
pg_free_result($result);
global $make_csv;
if (!$make_csv)
echo '</table>';
block_end();
csv_download_link();
}
?>
