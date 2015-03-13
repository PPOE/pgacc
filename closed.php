<?php
function sortlink($action,$sort,$text)
{
  global $make_csv;
  if ($make_csv)
    return $text;
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
  if (isset($_GET['filter_vk']) && strlen($_GET['filter_vk']) > 0)
    $filters .= "&filter_vk=" . $_GET['filter_vk'];
  if (isset($_GET['filter_amount']) && strlen($_GET['filter_amount']) > 0)
    $filters .= "&filter_amount=" . $_GET['filter_amount'];
  if (isset($_GET['filter_text']) && strlen($_GET['filter_text']) > 0)
    $filters .= "&filter_text=" . $_GET['filter_text'];
  if (isset($_GET['filter_comment']) && strlen($_GET['filter_comment']) > 0)
    $filters .= "&filter_comment=" . $_GET['filter_comment'];
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
function gentab($tabc,$action,$s,$t)
{
  global $user_prefs_hide, $make_csv;
  if ($make_csv)
  {
    if ($user_prefs_hide[$tabc] == 1)
      return "";
    else
      return "$t\t";
  }
  if ($user_prefs_hide[$tabc] == 1)
    return tag("td",'<a href="index.php?action='.$action.'&hide=-'.$tabc.'"><img class="icon" src="icons/add.png"></a>');
  else
    return tag("td",tag("b",sortlink($action,$s,$t) . '<a href="index.php?action='.$action.'&hide='.$tabc.'"><img class="icon" src="icons/delete.png"></a>'));
}
function page_listing_header($action)
{
global $make_csv;
block_start();
if (!$make_csv)
  echo '<table>';
$tabs = "";
$tabc = 1;
$tabs .= gentab($tabc++,$action,'idd','Buchung');
$tabs .= gentab($tabc++,$action,'bida','Buchungszeile');
$tabs .= gentab($tabc++,$action,'datea','Datum');
$tabs .= gentab($tabc++,$action,'typea','Art');
$tabs .= gentab($tabc++,$action,'loa','LO');
$tabs .= gentab($tabc++,$action,'membera','Mitglied');
$tabs .= gentab($tabc++,$action,'gka','Fremdkonto');
$tabs .= gentab($tabc++,$action,'ka','Konto');
$tabs .= gentab($tabc++,$action,'vka','Virt.Konto');
$tabs .= gentab($tabc++,$action,'ama','Betrag');
$tabs .= gentab($tabc++,$action,'texta','Text');
$tabs .= gentab($tabc++,$action,'commenta','Kommentar');
$tabs .= gentab($tabc++,$action,'comma','Gewidmet');
$tabs .= gentab($tabc++,$action,'acka','Bestätigt');
$tabs .= gentab($tabc++,$action,'refunda','Rück.');
$tabs .= gentab($tabc++,$action,'bela','Datei');
$tabs .= gentab($tabc++,$action,'namea','Name/Adresse');
echo tag("tr",$tabs);
if (!$make_csv)
{
  echo '<form action="index.php" method="GET"> <tr>
<td><input type="hidden" name="action" value="'.$action.'" />
';
if (isset($_GET['sort']))
  echo '<input type="hidden" name="sort" value="'.$_GET['sort'].'" />';
$tabc = 1;
global $user_prefs_hide;
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_id" value="'.(isset($_GET['filter_id'])?$_GET['filter_id']:'').'" size="1" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_bid" value="'.(isset($_GET['filter_bid'])?$_GET['filter_bid']:'').'" size="1" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_date" value="'.(isset($_GET['filter_date'])?$_GET['filter_date']:'').'" size="4" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_type" value="'.(isset($_GET['filter_type'])?$_GET['filter_type']:'').'" size="5" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_lo" value="'.(isset($_GET['filter_lo'])?$_GET['filter_lo']:'').'" size="5" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_member_id" value="'.(isset($_GET['filter_member_id'])?$_GET['filter_member_id']:'').'" size="1" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<select type="text" name="filter_gk" value="'.(isset($_GET['filter_gk'])?$_GET['filter_gk']:'').'" size="1" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_k" value="'.(isset($_GET['filter_k'])?$_GET['filter_k']:'').'" size="7" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_vk" value="'.(isset($_GET['filter_vk'])?$_GET['filter_vk']:'').'" size="7" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_amount" value="'.(isset($_GET['filter_amount'])?$_GET['filter_amount']:'').'" size="1" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_text" value="'.(isset($_GET['filter_text'])?$_GET['filter_text']:'').'" size="15" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_comment" value="'.(isset($_GET['filter_comment'])?$_GET['filter_comment']:'').'" size="15" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="checkbox" name="filter_comm" value="'.(isset($_GET['filter_comm'])?$_GET['filter_comm']:'').'" size="1" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_ack" value="'.(isset($_GET['filter_ack'])?$_GET['filter_ack']:'').'" size="3" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="checkbox" name="filter_refund" value="'.(isset($_GET['filter_refund'])?$_GET['filter_refund']:'').'" size="1" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="checkbox" name="filter_bel" value="'.(isset($_GET['filter_bel'])?$_GET['filter_bel']:'').'" size="1" />';
echo '</td><td>';
if ($user_prefs_hide[$tabc++] != 1)
  echo '<input type="text" name="filter_name" value="'.(isset($_GET['filter_name'])?$_GET['filter_name']:'').'" size="1" />';
echo '<input style="position: absolute; visibility: hidden;" value="Filtern" type="submit" />
</td>
</tr></form>';
}
}

function emptytag($tag)
{
  global $make_csv;
  if (!$make_csv)
    echo tag($tag);
}

function page_listing_line($line, $action = "edit")
{
global $user_prefs_hide,$make_csv;
if (!$make_csv)
echo "<tr>";
$tabc = 1;
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
{
  if ($make_csv)
    echo tag("td", $line["voucher_id"]);
  else
    echo tag("td", '<a href="index.php?action='.$action.'&id=' . $line["voucher_id"] . '&bid='.$line["id"].'">' . $line["voucher_id"] . "</a>");
}
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["id"]);
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", format_date($line["date"]));
$query2 = "SELECT name FROM type WHERE id = " . intval($line["type"]);
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line2["name"]);
}
pg_free_result($result2);
$query2 = "SELECT name FROM lo WHERE id = " . intval($line["orga"]);
$result2 = pg_query($query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line2["name"]);
}
pg_free_result($result2);
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
{
  if ($make_csv)
    echo tag("td", $line["member"] == 't' ? $line["member_id"] : 'Nein');
  else
    echo tag("td", $line["member"] == 't' ? '<a href="https://mitglieder.piratenpartei.at/adm_program/modules/profile/profile.php?user_id=' . $line["member_id"] . '">' . $line["member_id"] . '</a>' : 'Nein');
}
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["contra_account"]);
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["account"]);
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["vaccount"]);
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", sprintf("%1.2f",$line["amount"] / 100.0) . "€");
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["comment"]);
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["commentgf"]);
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["committed"] == 't' ? 'Ja' : 'Nein');
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["ack1"] . " " . $line["ack2"]);
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["refund"] == 't' ? 'Ja' : 'Nein');
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["file"] != 0 ? 'Ja' : 'Nein');
if ($user_prefs_hide[$tabc++] == 1)
  echo emptytag("td");
else
  echo tag("td", $line["name"] . ' ' . $line["street"] . ' ' . $line["plz"] . ' ' . $line["city"]);
if ($make_csv)
  echo "\n";
else
  echo "</tr>";
}

function page_closed($rights)
{
page_listing_header('closed');
$rightssql = rights2orgasql($rights);
$filter = getfilter();
$sort = getsort();
$query = "SELECT anyfile AS file, vouchers.id, vouchers.voucher_id, vouchers.type, vouchers.orga, vouchers.member, vouchers.member_id, vouchers.contra_account, vouchers.name, vouchers.street, vouchers.plz, vouchers.city, vouchers.amount, vouchers.account, vouchers.comment, vouchers.committed, vouchers.receipt_received, vouchers.deleted, vouchers.date, vouchers.ack1, vouchers.ack2, vouchers.person_type, vouchers.save_date, vouchers.save_user, vouchers.commentgf, vouchers.refund, lo.name AS lo_name, type.name AS type_name FROM vouchers LEFT JOIN lo ON vouchers.orga = lo.id LEFT JOIN type ON vouchers.type = type.id LEFT JOIN (SELECT voucher_id,1 as anyfile FROM vouchers F WHERE F.file != 0 AND F.ack1 IS NOT NULL AND F.ack2 IS NOT NULL GROUP BY voucher_id) F ON F.voucher_id = vouchers.voucher_id WHERE NOT deleted AND ack1 IS NOT NULL AND ack2 IS NOT NULL $rightssql $filter ORDER BY $sort";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line);
}
pg_free_result($result);
global $make_csv;
if (!$make_csv)
echo '</table>';
block_end();
csv_download_link();
}
?>
