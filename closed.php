<?php
function sortlink($action,$sort,$text)
{
  return "<a href=\"index.php?action=$action&sort=".getoppsort($sort)."\">$text</a>";
}
function page_listing_header($action,$rights)
{
block_start();
echo '<table>';
echo tag("tr",tag("td",tag("b",sortlink($action,'idd','Lfd. Nr.'))) . 
tag("td",tag("b",sortlink($action,'bida','Belegnr.'))) . 
tag("td",tag("b",sortlink($action,'datea','Datum'))) . 
tag("td",tag("b",sortlink($action,'typea','Art'))) . 
tag("td",tag("b",sortlink($action,'loa','LO'))) . 
tag("td",tag("b",sortlink($action,'membera','Mitglied'))) . 
tag("td",tag("b",sortlink($action,'gka','Gegenkonto'))) . 
tag("td",tag("b",sortlink($action,'ka','Konto'))) . 
tag("td",tag("b",sortlink($action,'ama','Betrag'))) . 
tag("td",tag("b",sortlink($action,'texta','Text'))) . 
tag("td",tag("b",sortlink($action,'comma','Gewidmet'))) . 
tag("td",tag("b",sortlink($action,'acka','Bestätigt'))) . 
tag("td",tag("b",sortlink($action,'bela','Beleg'))) . 
tag("td",tag("b",sortlink($action,'namea','Name/Adresse'))));
}

function page_listing_line($line, $action = "edit")
{
echo "<tr>";
echo tag("td", '<a href="index.php?action='.$action.'&id=' . $line["voucher_id"] . '&bid='.$line["id"].'">' . $line["voucher_id"] . "</a>");
echo tag("td", $line["id"]);
echo tag("td", format_date($line["date"]));
$query2 = "SELECT name FROM type WHERE id = " . intval($line["type"]);
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
echo tag("td", $line2["name"]);
}
pg_free_result($result2);
$query2 = "SELECT name FROM lo WHERE id = " . intval($line["orga"]);
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
echo tag("td", $line2["name"]);
}
pg_free_result($result2);
echo tag("td", $line["member"] == 't' ? '<a href="https://mitglieder.piratenpartei.at/adm_program/modules/profile/profile.php?user_id=' . $line["member_id"] . '">' . $line["member_id"] . '</a>' : 'Nein');
echo tag("td", $line["contra_account"]);
echo tag("td", $line["account"]);
echo tag("td", ($line["amount"] / 100.0) . "€");
echo tag("td", $line["comment"]);
echo tag("td", $line["committed"] == 't' ? 'Ja' : 'Nein');
echo tag("td", $line["ack1"] . " " . $line["ack2"]);
echo tag("td", $line["receipt_received"] == 't' ? 'Ja' : 'Nein');
echo tag("td", $line["name"] . ' ' . $line["street"] . ' ' . $line["plz"] . ' ' . $line["city"]);
echo "</tr>";
}

function page_closed($rights)
{
page_listing_header('closed');
$rightssql = rights2orgasql($rights);
$sort = getsort();
$query = "SELECT * FROM vouchers WHERE NOT deleted AND ack1 IS NOT NULL AND ack2 IS NOT NULL $rightssql ORDER BY $sort";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line);
}
pg_free_result($result);
echo '</table>';
block_end();
}
?>
