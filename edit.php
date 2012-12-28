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

$query = "SELECT acknowledged FROM vouchers WHERE NOT deleted AND voucher_id = $id;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
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
function page_edit_buchung($vouchers, $part = 0)
{
if (isset($_POST["changed"]))
{
$dir = get_param($part, 'dir', 'in', '/^(in|out)$/');
$in_type = get_param($part, 'in_type', 0, '/^\d+$/');
$out_type = get_param($part, 'out_type', 0, '/^\d+$/');
$lo = get_param($part, 'lo', 10, '/^\d+$/');
$amount = get_param($part, 'amount', '0.00', '/^-?\d+((\.|,)\d\d)?$/');
$gegenkonto = get_param($part, 'gegenkonto', '', '/^\d+$/');
$konto = get_param($part, 'konto', '', '/^\d+$/');
$comment = get_param($part, 'comment', '', null);
$purpose = get_param_bool($part, 'purpose', '', null, ' checked="checked"');
$member = get_param_bool($part, 'member', '', null, ' checked="checked"');
$mitgliedsnummer = get_param($part, 'mitgliedsnummer', 0, '/^\d+$/');
$name = get_param($part, 'name', '', null);
$street = get_param($part, 'street', '', null);
$plz = get_param($part, 'plz', '', null);
$city = get_param($part, 'city', '', null);
}
else
{

$dir = 'in';
$in_type = $vouchers[$part]['type'];
$out_type = $vouchers[$part]['type'];
$lo = $vouchers[$part]['orga'];
$amount = $vouchers[$part]['amount'] / 100.0;
$gegenkonto = $vouchers[$part]['contra_account'];
$konto = $vouchers[$part]['account'];
$comment = $vouchers[$part]['comment'];
$purpose = $vouchers[$part]['committed'] == 't' ? ' checked="checked" ' : '';
$member = $vouchers[$part]['member'] == 't' ? ' checked="checked" ' : '';
$mitgliedsnummer = $vouchers[$part]['member_id'];
$name = $vouchers[$part]['name'];
$street = $vouchers[$part]['street'];
$plz = $vouchers[$part]['plz'];
$city = $vouchers[$part]['city'];
}
block_start();
echo '
<div>
<label for="dir'.$part.'" class="ui_field_label">Einnahme/Ausgabe
</label>
<select id="dir'.$part.'" name="dir'.$part.'" onchange="clicked();">
<option value="in"'.($dir == 'in'?' selected':'').'>Einnahme</option>
<option value="out"'.($dir == 'out'?' selected':'').'>Ausgabe</option>
</select>
</div>
<div id="in_block'.$part.'"'.($dir == 'in'?'':' style="display: none;"').'>
<label for="in_type'.$part.'" class="ui_field_label">Art der Einnahme</label> 
<select id="in_type'.$part.'" name="in_type'.$part.'" onchange="clicked();">
';
$query = "SELECT id,name FROM type WHERE income = true ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo '<option value="'.($in_type == $line['id']?$line['id'] . '" selected="selected"':$line['id'] . '"').'>'.$line['name'].'</option>
';
}
pg_free_result($result);
echo '
</select>
</div>
<div id="out_block'.$part.'"'.($dir == 'out'?'':' style="display: none;"').'>
<label for="out_type'.$part.'" class="ui_field_label">Art der Ausgabe</label> 
<select id="out_type'.$part.'" name="out_type'.$part.'" onchange="clicked();">
';
$query = "SELECT id,name FROM type WHERE income = false ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo '<option value="'.($out_type == $line['id']?$line['id'] . '" selected="selected"':$line['id'] . '"').'>'.$line['name'].'</option>
';
}
pg_free_result($result);
echo '
</select>
</div>
<div>
<label for="lo'.$part.'" class="ui_field_label">Teilorganisation</label> 
<select id="lo'.$part.'" name="lo'.$part.'" onchange="clicked();">
';
$query = "SELECT id,name FROM lo ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo '<option value="'.($line['id'] == $lo?$line['id'].'" selected="selected"':$line['id'].'"').'>'.$line['name'].'</option>
';
}
pg_free_result($result);
echo '
</select>
</div>
<div>
<label class="ui_field_label" for="member'.$part.'">Mitglied</label><input type="checkbox" id="member'.$part.'" name="member'.$part.'" value="1" '.$member.' onchange="clicked();" />
</div>
<div id="mitgliedsnummer'.$part.'">
<label class="ui_field_label" for="mitgliedsnummer'.$part.'">Mitgliedsnummer</label><input type="text" name="mitgliedsnummer'.$part.'" value="'.$mitgliedsnummer.'" onchange="clicked();" />
</div>
<div id="gegenkonto'.$part.'">
<label class="ui_field_label" for="gegenkonto'.$part.'">Gegenkonto</label><input type="text" name="gegenkonto'.$part.'" value="'.$gegenkonto.'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="amount'.$part.'">Betrag (in €)</label><input type="text" id="amount'.$part.'" name="amount'.$part.'" value="'.$amount.'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="konto'.$part.'">Konto</label><input type="text" id="konto'.$part.'" name="konto'.$part.'" value="'.$konto.'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="comment'.$part.'">Buchungstext</label><input type="text" id="comment'.$part.'" name="comment'.$part.'" value="'.$comment.'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="purpose'.$part.'">Zweckgebunden</label><input type="checkbox" id="purpose'.$part.'" name="purpose'.$part.'" value="1" '.$purpose.' onchange="clicked();" />
</div>
<div id="name'.$part.'">
<label class="ui_field_label" for="name'.$part.'">Name</label><input type="text" name="name'.$part.'" value="'.$name.'" onchange="clicked();" />
</div>
<div id="street'.$part.'">
<label class="ui_field_label" for="street'.$part.'">Straße</label><input type="text" name="street'.$part.'" value="'.$street.'" onchange="clicked();" />
</div>
<div id="plz'.$part.'">
<label class="ui_field_label" for="plz'.$part.'">PLZ</label><input type="text" name="plz'.$part.'" value="'.$plz.'" onchange="clicked();" />
</div>
<div id="city'.$part.'">
<label class="ui_field_label" for="city'.$part.'">Ort</label><input type="text" name="city'.$part.'" value="'.$city.'" onchange="clicked();" />
</div>
';
block_end();
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

echo '
<script type="text/javascript">
function clicked(init)
{
  if (!init)
    document.getElementById("speichern").style.display = "none";
  var max = parseInt(document.getElementById("parts").value);
  var i = 0;
  while (i < max)
  {
    if (document.getElementById("dir" + i).selectedIndex == 1)
    {
      document.getElementById("in_block" + i).style.display = "none";
      document.getElementById("out_block" + i).style.display = "block";
    }
    else
    {
      document.getElementById("in_block" + i).style.display = "block";
      document.getElementById("out_block" + i).style.display = "none";
    }
    if (document.getElementById("member" + i).checked)
    {
      document.getElementById("mitgliedsnummer" + i).style.display = "block";
      document.getElementById("name" + i).style.display = "none";
      document.getElementById("street" + i).style.display = "none";
      document.getElementById("plz" + i).style.display = "none";
      document.getElementById("city" + i).style.display = "none";
      document.getElementById("gegenkonto" + i).style.display = "none";
    }
    else
    {
      document.getElementById("mitgliedsnummer" + i).style.display = "none";
      document.getElementById("name" + i).style.display = "block";
      document.getElementById("street" + i).style.display = "block";
      document.getElementById("plz" + i).style.display = "block";
      document.getElementById("city" + i).style.display = "block";
      document.getElementById("gegenkonto" + i).style.display = "block";
    }
    i++;
  }
}
</script>
<form class="vertical" action="index.php?action=edit" method="post">
<input type="hidden" name="id" value="'.intval($id).'" />
<input type="hidden" name="changed" value="1" />
<h1>Buchung bearbeiten</h1>
';
for ($i = 0; $i < $parts; $i++)
{
	page_edit_buchung($vouchers,$i);
}

$schatzmeister = true;
if ($schatzmeister)
{
block_start();
$ack = $vouchers[0]['acknowledged'];
if (isset($_POST["ack"]))
        $ack = ' checked="checked"';
$beleg = $vouchers[0]['receipt_received'];
if (isset($_POST["beleg"]))
        $beleg = ' checked="checked"';

echo '
<h3>Bundesschatzmeister</h3>
<div>
<label class="ui_field_label" for="ack">Bestätigt</label><input type="checkbox" name="ack" '.$ack.' value="1"/>
</div>
<div>
<label class="ui_field_label" for="beleg">Beleg erhalten</label><input type="checkbox" name="beleg" '.$beleg.' value="1"/>
</div>
';
block_end();
}
echo '
<input value="'.$parts.'" type="hidden" id="parts" name="parts" />
';

if (!$ack)
{
block_start();
echo '
<br />

<input value="Weitere Buchung zu DIESER Transaktion" type="submit" name="add" />
<br />
<br />
<input value="Vorschau" type="submit" name="preview" />
<input name="speichern" id="speichern" value="Speichern" type="submit" />
';

block_end();
}
echo '</form><script type="text/javascript">clicked(true);</script>';
}
?>
