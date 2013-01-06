<?php
function page_edit_save()
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

$query = "SELECT acknowledged FROM vouchers WHERE NOT deleted AND acknowledged AND voucher_id = $id;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  print_r($line);
  echo '<div class="slot_error" id="slot_error">FEHLER: Die Buchung wurde bereits bestätigt und kann jetzt nicht mehr verändert werden.</div><br /><br /><br />';
  pg_free_result($result);
  return;
}
pg_free_result($result);

echo "<h1>Buchung aktualisiert - Buchung Nr. $id</h1>";

$query = "UPDATE vouchers SET deleted = true WHERE voucher_id = $id;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);

for ($i = 0; $i < $parts; $i++)
{
        page_save_buchung($id, $i, true);
}
}
function page_edit_buchung($vouchers, $part = 0, $part_to_remove = -1)
{
if (isset($_POST["changed"]))
{
if ($part_to_remove != -1 && $part >= $part_to_remove)
        $voucher = get_voucher($part+1);
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
$voucher['dir'] = $line['income'] == 't' ? 'in' : 'out';
}
pg_free_result($result);

$voucher['date'] = format_date($vouchers[$part]['date']);
$voucher['in_type'] = $vouchers[$part]['type'];
$voucher['out_type'] = $vouchers[$part]['type'];
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
$voucher['ack'] = $vouchers[$part]['acknowledged'];
$voucher['receipt'] = $vouchers[$part]['receipt_received'];
$part = $part_o;
}
page_edit_form($part,$voucher);
}

function page_edit()
{
$preview = false;
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
$query = "SELECT * FROM vouchers WHERE NOT deleted AND voucher_id = " . intval($id) . " ORDER BY id ASC;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $vouchers[] = $line;
}
pg_free_result($result);
$parts = count($vouchers);
if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1)
	$parts = $_POST["parts"];
if (isset($_POST["add"]))
	$parts++;
$part_to_remove = -1;
if (isset($_POST["remove"]) && intval($_POST["remove"]) >= 0 && intval($_POST["remove"]) < $parts)
{
        $parts--;
        $part_to_remove = intval($_POST["remove"]);
}

page_form_header("edit");
echo '<input type="hidden" name="id" value="'.intval($id).'" />
<input type="hidden" name="changed" value="1" />
<h1>Buchung bearbeiten</h1>
';
for ($i = 0; $i < $parts; $i++)
{
	page_edit_buchung($vouchers,$i,$part_to_remove);
}

$schatzmeister = true;
$ack = $vouchers[0]['acknowledged'];
if (isset($_POST["ack"]))
        $ack = ' checked="checked"';
$beleg = $vouchers[0]['receipt_received'];
if (isset($_POST["beleg"]))
        $beleg = ' checked="checked"';

bsm_block($schatzmeister,$ack,$beleg);

end_of_form($ack,$parts);}
?>
