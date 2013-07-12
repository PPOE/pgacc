<?php
function relocate($loc)
{
  global $dbconn;
  pg_close($dbconn);
  header("Location: " . $loc);
}
function page_edit_finalize($rights)
{
$parts = 1;
if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1)
        $parts = $_POST["parts"];

$id = -1;
if (isset($_GET["id"]))
{
  $id = $_GET["id"];
}
elseif (isset($_POST["id"]))
{
  $id = $_POST["id"];
}
else
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
  return;
}

echo "<h1>Buchung finalisiert - Buchung Nr. $id</h1>";
block_start();
echo "<a href=\"index.php?action=edit&id=$id&hint=2\">Zurück zur Buchung</a>";
block_end();
$rightssql = rights2orgasql($rights);

$name = pg_escape_string(checklogin('name'));
$query = "UPDATE vouchers SET receipt_received = true WHERE voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);
relocate("index.php?action=edit&id=$id&hint=2");
}
function page_edit_merge($rights)
{
$bid1 = 0;
$bid2 = 0;
if (isset($_GET["bid1"]))
  $bid1 = intval($_GET["bid1"]);
if (isset($_GET["bid2"]))
  $bid2 = intval($_GET["bid2"]);
if ($bid1 == 0 || $bid2 == 0)
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Buchungen nicht gefunden.</div><br /><br /><br />';
  return;
}
$rightssql = rights2orgasql($rights);

$name = pg_escape_string(checklogin('name'));
$query = "UPDATE vouchers SET voucher_id = $bid1 WHERE voucher_id = $bid2 $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);
relocate("index.php?action=edit&id=$bid1&hint=6");
}
function page_edit_drop_acks($rights)
{
$parts = 1;
if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1)
        $parts = $_POST["parts"];

$id = -1;
if (isset($_GET["id"]))
{
  $id = $_GET["id"];
}
elseif (isset($_POST["id"]))
{
  $id = $_POST["id"];
}
else
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
  return;
}

echo "<h1>Bestätigungen wurden zurückgesetzt - Buchung Nr. $id</h1>";
block_start();
echo "<a href=\"index.php?action=edit&id=$id&hint=3\">Zurück zur Buchung</a>";
block_end();
$rightssql = rights2orgasql($rights);

$name = pg_escape_string(checklogin('name'));
$query = "UPDATE vouchers SET ack1 = NULL, ack2 = NULL WHERE voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);
relocate("index.php?action=edit&id=$id&hint=3");
}
function page_edit_ack($rights)
{
$parts = 1;
if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1)
        $parts = $_POST["parts"];

$id = -1;
if (isset($_GET["id"]))
{
  $id = $_GET["id"];
}
elseif (isset($_POST["id"]))
{
  $id = $_POST["id"];
}
else
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
  return;
}

$query = "SELECT 1 FROM vouchers WHERE NOT deleted AND receipt_received AND voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    echo '<div class="slot_error" id="slot_error">FEHLER: Die Buchung wurde bereits finalisiert und kann jetzt nicht mehr verändert werden.</div><br /><br /><br />';
      pg_free_result($result);
        return;
}
pg_free_result($result);

echo "<h1>Buchung bestätigt - Buchung Nr. $id</h1>";
block_start();
echo "<a href=\"index.php?action=edit&id=$id&hint=4\">Zurück zur Buchung</a>";
block_end();
$rightssql = rights2orgasql($rights);

$name = pg_escape_string(checklogin('name'));
$query = "UPDATE vouchers SET ack1 = '$name' WHERE ack1 IS NULL AND (ack2 IS NULL OR ack2 != '$name') AND voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);

$query = "UPDATE vouchers SET ack2 = '$name' WHERE ack2 IS NULL AND (ack1 IS NULL OR ack1 != '$name') AND voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);
relocate("index.php?action=edit&id=$id&hint=4");
}
function page_edit_save($rights)
{
$parts = 1;
if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1)
        $parts = $_POST["parts"];

$id = -1;
if (isset($_GET["id"]))
{
  $id = $_GET["id"];
}
elseif (isset($_POST["id"]))
{
  $id = $_POST["id"];
}
else
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
  return;
}
$rightssql = rights2orgasql($rights);

$query = "SELECT 1 FROM vouchers WHERE NOT deleted AND receipt_received AND voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo '<div class="slot_error" id="slot_error">FEHLER: Die Buchung wurde bereits finalisiert und kann jetzt nicht mehr verändert werden.</div><br /><br /><br />';
  pg_free_result($result);
  return;
}
pg_free_result($result);

$query = "SELECT 1 FROM vouchers WHERE NOT deleted AND ack1 IS NOT NULL AND ack2 IS NOT NULL AND voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo '<div class="slot_error" id="slot_error">FEHLER: Die Buchung wurde bereits bestätigt und kann jetzt nicht mehr verändert werden.</div><br /><br /><br />';
  pg_free_result($result);
  return;
}
pg_free_result($result);

echo "<h1>Buchung aktualisiert - Buchung Nr. $id</h1>";
block_start();
echo "<a href=\"index.php?action=edit&id=$id&hint=5\">Zurück zur Buchung</a>";
block_end();

$query = "UPDATE vouchers SET deleted = true WHERE voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);

for ($i = 0; $i < $parts; $i++)
{
        page_save_buchung($id, $i, true);
}
relocate("index.php?action=edit&id=$id&hint=5");
}
function page_edit_buchung($rights, $vouchers, $part = 0, $part_to_remove = -1, $new_part = false)
{
if (isset($_POST["changed"]))
{
if ($part_to_remove != -1 && $part >= $part_to_remove)
        $voucher = get_voucher($part+1);
else if ($new_part)
{
        $voucher = get_voucher($part-1);
        $voucher['file'] = -1;
        $voucher['bid'] = 0;
}
else
        $voucher = get_voucher($part);
}
else
{
$part_o = $part;
if ($part_to_remove != -1 && $part >= $part_to_remove)
	$part++;
$query = "SELECT income FROM type WHERE id = " . $vouchers[$part]['type'];
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
$voucher['dir'] = $line['income']==1?'in':($line['income']==0?'out':'wk');
}
pg_free_result($result);
$voucher['bid'] = $vouchers[$part]['id'];
$voucher['date'] = format_date($vouchers[$part]['date']);
$voucher['type'] = $vouchers[$part]['type'];
$voucher['person_type'] = $vouchers[$part]['person_type'];
$voucher['lo'] = $vouchers[$part]['orga'];
$voucher['amount'] = $vouchers[$part]['amount'];
$voucher['gegenkonto'] = $vouchers[$part]['contra_account'];
$voucher['konto'] = $vouchers[$part]['account'];
$voucher['comment'] = $vouchers[$part]['comment'];
$voucher['purpose'] = $vouchers[$part]['committed'] == 't' ? 'true' : 'false';
$voucher['member'] = $vouchers[$part]['member'] == 't' ? 'true' : 'false';
$voucher['mitgliedsnummer'] = $vouchers[$part]['member_id'];
$voucher['name'] = $vouchers[$part]['name'];
$voucher['street'] = $vouchers[$part]['street'];
$voucher['plz'] = $vouchers[$part]['plz'];
$voucher['city'] = $vouchers[$part]['city'];
$voucher['file'] = $vouchers[$part]['file'];
$part = $part_o;
}
page_edit_form($part,$voucher,$rights);
}

function page_edit($rights)
{
$id = -1;
if (isset($_GET["id"]))
{
  $id = $_GET["id"];
}
elseif (isset($_POST["id"]))
{
  $id = $_POST["id"];
}
else
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
  return;
}
$bid = -1;
if (isset($_GET["bid"]))
{
    $bid = $_GET["bid"];
}
elseif (isset($_POST["bid"]))
{
    $bid = $_POST["bid"];
}
$hint = 0;
if (isset($_GET["hint"]))
  $hint = intval($_GET["hint"]);
switch ($hint)
{
  case 1:
    echo '<div class="slot_notice" id="slot_notice"><h1>Upload erfolgreich - Buchung Nr. '.$id.' - Buchungszeile Nr. '.$bid.'</h1></div>';
    break;
  case 2:
    echo '<div class="slot_notice" id="slot_notice"><h1>Buchung finalisiert - Buchung Nr. '.$id.'</h1></div>';
    break;
  case 3:
    echo '<div class="slot_notice" id="slot_notice"><h1>Bestätigungen wurden zurückgesetzt - Buchung Nr. '.$id.'</h1></div>';
    break;
  case 4:
    echo '<div class="slot_notice" id="slot_notice"><h1>Buchung bestätigt - Buchung Nr. '.$id.'</h1></div>';
    break;
  case 5:
    echo '<div class="slot_notice" id="slot_notice"><h1>Buchung aktualisiert - Buchung Nr. '.$id.'</h1></div>';
    break;
  case 6:
    echo '<div class="slot_notice" id="slot_notice"><h1>Buchungen zusammengeführt - Buchung Nr. '.$id.'</h1></div>';
    break;
}
$rightssql = rights2orgasql($rights);
$query = "SELECT * FROM vouchers WHERE NOT deleted AND voucher_id = " . intval($id) . " $rightssql ORDER BY id ASC;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $vouchers[] = $line;
}
pg_free_result($result);
$parts = count($vouchers);
if ($parts == 0 && strpos($rights, 'bsm') === false && strpos($rights, 'bgf') === false)
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
  return;
}
if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1)
	$parts = $_POST["parts"];
$part_to_remove = -1;
if (isset($_POST["remove"]) && intval($_POST["remove"]) >= 0 && intval($_POST["remove"]) < $parts)
{
        $parts--;
        $part_to_remove = intval($_POST["remove"]);
}
$acks = 0;
if ($vouchers[0]['ack1'] != null && strlen($vouchers[0]['ack1']) > 3)
  $acks++;
if ($vouchers[0]['ack2'] != null && strlen($vouchers[0]['ack2']) > 3)
  $acks++;
page_form_header("edit");
echo '<input type="hidden" name="id" value="'.intval($id).'" />
<input type="hidden" name="acks" value="'.intval($acks).'" />
<input type="hidden" name="changed" value="1" />
<h1>Buchung '.$id.' bearbeiten</h1>
';
if ($acks > 0)
{
  echo '<h4>Bestätigt von: '.$vouchers[0]['ack1'].' '.$vouchers[0]['ack2'].'</h4>';
}
for ($i = 0; $i < $parts; $i++)
{
	page_edit_buchung($rights, $vouchers,$i,$part_to_remove);
}
if (isset($_POST["add"]))
{
  page_edit_buchung($rights, $vouchers,$parts,$part_to_remove,true);
  $parts++;
}

$user_name = checklogin('name');
$schatzmeister = strpos($rights, 'bsm') !== false && ($acks == 2) && ($vouchers[0]['receipt_received'] != 't');
$beleg = $vouchers[0]['receipt_received'] == 't' ? ' checked="checked"':'';
if (isset($_POST["beleg"]))
        $beleg = ' checked="checked"';

bsm_block($schatzmeister,$beleg);

end_of_form($parts,$rights);
}
?>
