<?php
function page_listing_header()
{
block_start();
echo '
<table>
<tr><td><b>Lfd. Nr.</b></td><td><b>Belegnr.</b></td><td><b>Datum</b></td><td><b>Art</b></td><td><b>LO</b></td><td><b>Mitglied</b></td><td><b>Gegenkonto</b></td><td><b>Konto</b></td><td><b>Betrag</b></td><td><b>Text</b></td><td><b>Gewidmet</b></td><td><b>Bestätigt</b></td><td><b>Beleg</b></td><td><b>Name/Adresse</b></td></tr>
';
}

function page_listing_line($line)
{
echo "<tr>";
tag("td", '<a href="index.php?action=edit&id=' . $line["voucher_id"] . '">' . $line["voucher_id"] . "</a>");
tag("td", $line["id"]);
tag("td", $line["date"]);
$query2 = "SELECT name FROM type WHERE id = " . intval($line["type"]);
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
tag("td", $line2["name"]);
}
pg_free_result($result2);
$query2 = "SELECT name FROM lo WHERE id = " . intval($line["orga"]);
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
tag("td", $line2["name"]);
}
pg_free_result($result2);
tag("td", intval($line["member"]) != 'f' ? '<a href="https://mitglieder.piratenpartei.at/adm_program/modules/profile/profile.php?user_id=' . $line["member_id"] . '">' . $line["member_id"] . '</a>' : 'Nein');
tag("td", $line["contra_account"]);
tag("td", $line["account"]);
tag("td", ($line["amount"] / 100.0) . "€");
tag("td", $line["comment"]);
tag("td", $line["committed"] == 't' ? 'Ja' : 'Nein');
tag("td", $line["acknowledged"] == 't' ? 'Ja' : 'Nein');
tag("td", $line["receipt_received"] == 't' ? 'Ja' : 'Nein');
tag("td", $line["name"] . ' ' . $line["street"] . ' ' . $line["plz"] . ' ' . $line["city"]);
echo "</tr>";
}

function page_closed()
{
page_listing_header();
$query = "SELECT * FROM vouchers WHERE NOT deleted AND acknowledged ORDER BY voucher_id,id";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line);
}
pg_free_result($result);
echo '</table>';
block_end();
}
?>
