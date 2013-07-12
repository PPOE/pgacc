<?php
function sortlink($action,$sort,$text)
{
  $filters = "";
  if (isset($_GET['filter_id']) && strlen($_GET['filter_id']) > 0)
    $filters .= "&filter_id=" . $_GET['filter_id'];
  if (isset($_GET['filter_bid']) && strlen($_GET['filter_bid']) > 0)
    $filters .= "&filter_bid=" . $_GET['filter_bid'];
  if (isset($_GET['filter_date']) && strlen($_GET['filter_date']) > 0)
    $filters .= "&filter_date=" . $_GET['filter_date'];
  if (isset($_GET['filter_type']) && strlen($_GET['filter_type']) > 0)
    $filters .= "&filter_type=" . $_GET['filter_type'];
  if (isset($_GET['filter_lo']) && strlen($_GET['filter_lo']) > 0)
    $filters .= "&filter_lo=" . $_GET['filter_lo'];
  if (isset($_GET['filter_member_id']) && strlen($_GET['filter_member_id']) > 0)
    $filters .= "&filter_member_id=" . $_GET['filter_member_id'];
  if (isset($_GET['filter_gk']) && strlen($_GET['filter_gk']) > 0)
    $filters .= "&filter_gk=" . $_GET['filter_gk'];
  if (isset($_GET['filter_k']) && strlen($_GET['filter_k']) > 0)
    $filters .= "&filter_k=" . $_GET['filter_k'];
  if (isset($_GET['filter_amount']) && strlen($_GET['filter_amount']) > 0)
    $filters .= "&filter_amount=" . $_GET['filter_amount'];
  if (isset($_GET['filter_text']) && strlen($_GET['filter_text']) > 0)
    $filters .= "&filter_text=" . $_GET['filter_text'];
  if (isset($_GET['filter_comm']) && strlen($_GET['filter_comm']) > 0)
    $filters .= "&filter_comm=" . $_GET['filter_comm'];
  if (isset($_GET['filter_ack']) && strlen($_GET['filter_ack']) > 0)
    $filters .= "&filter_ack=" . $_GET['filter_ack'];
  if (isset($_GET['filter_bel']) && strlen($_GET['filter_bel']) > 0)
    $filters .= "&filter_bel=" . $_GET['filter_bel'];
  if (isset($_GET['filter_name']) && strlen($_GET['filter_name']) > 0)
    $filters .= "&filter_name=" . $_GET['filter_name'];
  return "<a href=\"index.php?action=$action&sort=".getoppsort($sort)."$filters\">$text</a>";
}
function page_listing_header($action,$rights)
{
block_start();
echo '<table>';
echo tag("tr",tag("td",tag("b",sortlink($action,'idd','Buchung'))) . 
tag("td",tag("b",sortlink($action,'bida','Buchungszeile'))) . 
tag("td",tag("b",sortlink($action,'datea','Datum'))) . 
tag("td",tag("b",sortlink($action,'typea','Art'))) . 
tag("td",tag("b",sortlink($action,'loa','LO'))) . 
tag("td",tag("b",sortlink($action,'membera','Mitglied'))) . 
tag("td",tag("b",sortlink($action,'gka','Fremdkonto'))) . 
tag("td",tag("b",sortlink($action,'ka','Konto'))) . 
tag("td",tag("b",sortlink($action,'ama','Betrag'))) . 
tag("td",tag("b",sortlink($action,'texta','Text'))) . 
tag("td",tag("b",sortlink($action,'comma','Gewidmet'))) . 
tag("td",tag("b",sortlink($action,'acka','Bestätigt'))) . 
tag("td",tag("b",sortlink($action,'bela','Finalisiert'))) . 
tag("td",tag("b",sortlink($action,'namea','Name/Adresse'))));
echo '<form action="index.php" method="GET"> <tr>
<td><input type="hidden" name="action" value="'.$action.'" />
';
if (isset($_GET['sort']))
  echo '<input type="hidden" name="sort" value="'.$_GET['sort'].'" />';
echo '
<input type="text" name="filter_id" value="'.(isset($_GET['filter_id'])?$_GET['filter_id']:'').'" size="1" /></td>
<td><input type="text" name="filter_bid" value="'.(isset($_GET['filter_bid'])?$_GET['filter_bid']:'').'" size="1" /></td>
<td><input type="text" name="filter_date" value="'.(isset($_GET['filter_date'])?$_GET['filter_date']:'').'" size="1" /></td>
<td><input type="text" name="filter_type" value="'.(isset($_GET['filter_type'])?$_GET['filter_type']:'').'" size="1" /></td>
<td><input type="text" name="filter_lo" value="'.(isset($_GET['filter_lo'])?$_GET['filter_lo']:'').'" size="1" /></td>
<td><input type="text" name="filter_member_id" value="'.(isset($_GET['filter_member_id'])?$_GET['filter_member_id']:'').'" size="1" /></td>
<td><input type="text" name="filter_gk" value="'.(isset($_GET['filter_gk'])?$_GET['filter_gk']:'').'" size="1" /></td>
<td><input type="text" name="filter_k" value="'.(isset($_GET['filter_k'])?$_GET['filter_k']:'').'" size="1" /></td>
<td><input type="text" name="filter_amount" value="'.(isset($_GET['filter_amount'])?$_GET['filter_amount']:'').'" size="1" /></td>
<td><input type="text" name="filter_text" value="'.(isset($_GET['filter_text'])?$_GET['filter_text']:'').'" size="1" /></td>
<td><input type="checkbox" name="filter_comm" value="'.(isset($_GET['filter_comm'])?$_GET['filter_comm']:'').'" size="1" /></td>
<td><input type="text" name="filter_ack" value="'.(isset($_GET['filter_ack'])?$_GET['filter_ack']:'').'" size="1" /></td>
<td><input type="checkbox" name="filter_bel" value="'.(isset($_GET['filter_bel'])?$_GET['filter_bel']:'').'" size="1" /></td>
<td><input type="text" name="filter_name" value="'.(isset($_GET['filter_name'])?$_GET['filter_name']:'').'" size="1" /><input style="display: none;" value="Filtern" type="submit" /></td>
</tr></form>';
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
$filter = getfilter();
$sort = getsort();
$query = "SELECT * FROM vouchers WHERE NOT deleted AND ack1 IS NOT NULL AND ack2 IS NOT NULL $rightssql $filter ORDER BY $sort";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line);
}
pg_free_result($result);
echo '</table>';
block_end();
}
?>
