<?php
function get_param($part, $name, $default, $regex)
{
$value = $default;
if (isset($_POST[$name . $part]) && (!$regex || preg_match($regex, $_POST[$name . $part]) == 1))
        $value = $_POST[$name . $part];
return $value;
}
function get_param_bool($part, $name, $default, $regex, $default_if_true)
{
$value = $default;
if (isset($_POST[$name . $part]) && (!$regex || preg_match($regex, $_POST[$name . $part]) == 1))
        $value = $default_if_true;
return $value;
}
function get_voucher($part)
{
$voucher['date'] = get_param($part, 'date', null, '/^(\d|(0|1|2)\d|3(0|1))\.(\d|0\d|1(0|1|2))\.20\d\d$/');
$voucher['dir'] = get_param($part, 'dir', 'in', '/^(in|out)$/');
$voucher['in_type'] = get_param($part, 'in_type', 0, '/^\d+$/');
$voucher['out_type'] = get_param($part, 'out_type', 0, '/^\d+$/');
$voucher['lo'] = get_param($part, 'lo', 10, '/^\d+$/');
$voucher['amount'] = intval(floatval(get_param($part, 'amount', '0.00', '/^-?\d+((\.|,)\d\d)?$/')) * 100);
$voucher['gegenkonto'] = intval(get_param($part, 'gegenkonto', '', '/^\d+$/'));
$voucher['konto'] = intval(get_param($part, 'konto', '', '/^\d+$/'));
$voucher['comment'] = pg_escape_string(get_param($part, 'comment', '', null));
$voucher['purpose'] = get_param_bool($part, 'purpose', 'false', null, 'true');
$voucher['member'] = get_param_bool($part, 'member', 'false', null, 'true');
$voucher['mitgliedsnummer'] = get_param($part, 'mitgliedsnummer', 0, '/^\d+$/');
$voucher['name'] = pg_escape_string(get_param($part, 'name', '', null));
$voucher['street'] = pg_escape_string(get_param($part, 'street', '', null));
$voucher['plz'] = pg_escape_string(get_param($part, 'plz', '', null));
$voucher['city'] = pg_escape_string(get_param($part, 'city', '', null));
$voucher['ack'] = get_param_bool('', 'ack', 'false', null, 'true');
$voucher['receipt'] = get_param_bool('', 'beleg', 'false', null, 'true');
return $voucher;
}
function page_save_buchung($voucher_number, $part = 0)
{
$voucher = get_voucher($part);
if ($voucher['date'] == null)
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Kein Datum angegeben.</div><br /><br /><br />';
  return;
}

$query = "INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, acknowledged, receipt_received) VALUES ($voucher_number, '{$voucher['date']}', ".($voucher['dir'] == "in"?$voucher['in_type']:$voucher['out_type']).",{$voucher['lo']},{$voucher['member']},{$voucher['mitgliedsnummer']},{$voucher['gegenkonto']},'{$voucher['name']}','{$voucher['street']}','{$voucher['plz']}','{$voucher['city']}',{$voucher['amount']},{$voucher['konto']},'{$voucher['comment']}',{$voucher['purpose']},{$voucher['ack']},{$voucher['receipt']})";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);
return $voucher_number;
}
function page_new_save()
{
$parts = 1;
if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1)
        $parts = $_POST["parts"];

$query = "SELECT nextval('voucher_number') AS num;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
$voucher_number = $line['num'];
}
pg_free_result($result);

echo "<h1>Buchung erfasst - Buchung Nr. $voucher_number - AUF BELEG NOTIEREN!</h1>";

for ($i = 0; $i < $parts; $i++)
{
        page_save_buchung($voucher_number, $i);
}
}

function page_edit_form($part,$voucher)
{
block_start();
echo '
<div>
<label class="ui_field_label" for="date'.$part.'">Datum</label><input type="text" id="date'.$part.'" name="date'.$part.'" value="'.$voucher['date'].'" onchange="clicked();" />
</div>
<div>
<label for="dir'.$part.'" class="ui_field_label">Einnahme/Ausgabe
</label>
<select id="dir'.$part.'" name="dir'.$part.'" onchange="clicked();">
<option value="in"'.($voucher['dir'] == 'in'?' selected':'').'>Einnahme</option>
<option value="out"'.($voucher['dir'] == 'out'?' selected':'').'>Ausgabe</option>
</select>
</div>
<div id="in_block'.$part.'"'.($voucher['dir'] == 'in'?'':' style="display: none;"').'>
<label for="in_type'.$part.'" class="ui_field_label">Art der Einnahme</label> 
<select id="in_type'.$part.'" name="in_type'.$part.'" onchange="clicked();">
';
$query = "SELECT id,name FROM type WHERE income = true ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo '<option value="'.($voucher['in_type'] == $line['id']?$line['id'] . '" selected="selected"':$line['id'] . '"').'>'.$line['name'].'</option>
';
}
pg_free_result($result);
echo '
</select>
</div>
<div id="out_block'.$part.'"'.($voucher['dir'] == 'out'?'':' style="display: none;"').'>
<label for="out_type'.$part.'" class="ui_field_label">Art der Ausgabe</label> 
<select id="out_type'.$part.'" name="out_type'.$part.'" onchange="clicked();">
';
$query = "SELECT id,name FROM type WHERE income = false ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo '<option value="'.($voucher['out_type'] == $line['id']?$line['id'] . '" selected="selected"':$line['id'] . '"').'>'.$line['name'].'</option>
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
  echo '<option value="'.($line['id'] == $voucher['lo']?$line['id'].'" selected="selected"':$line['id'].'"').'>'.$line['name'].'</option>
';
}
pg_free_result($result);
echo '
</select>
</div>
<div>
<label class="ui_field_label" for="member'.$part.'">Mitglied</label><input type="checkbox" id="member'.$part.'" name="member'.$part.'" value="1" '.$voucher['member'].' onchange="clicked();" />
</div>
<div id="mitgliedsnummer'.$part.'">
<label class="ui_field_label" for="mitgliedsnummer'.$part.'">Mitgliedsnummer</label><input type="text" name="mitgliedsnummer'.$part.'" value="'.$voucher['mitgliedsnummer'].'" onchange="clicked();" />
</div>
<div id="gegenkonto'.$part.'">
<label class="ui_field_label" for="gegenkonto'.$part.'">Gegenkonto</label><input type="text" name="gegenkonto'.$part.'" value="'.$voucher['gegenkonto'].'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="amount'.$part.'">Betrag (in €)</label><input type="text" id="amount'.$part.'" name="amount'.$part.'" value="'.$voucher['amount'].'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="konto'.$part.'">Konto</label><input type="text" id="konto'.$part.'" name="konto'.$part.'" value="'.$voucher['konto'].'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="comment'.$part.'">Buchungstext</label><input type="text" id="comment'.$part.'" name="comment'.$part.'" value="'.$voucher['comment'].'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="purpose'.$part.'">Zweckgebunden</label><input type="checkbox" id="purpose'.$part.'" name="purpose'.$part.'" value="1" '.$voucher['purpose'].' onchange="clicked();" />
</div>
<div id="name'.$part.'">
<label class="ui_field_label" for="name'.$part.'">Name</label><input type="text" name="name'.$part.'" value="'.$voucher['name'].'" onchange="clicked();" />
</div>
<div id="street'.$part.'">
<label class="ui_field_label" for="street'.$part.'">Straße</label><input type="text" name="street'.$part.'" value="'.$voucher['street'].'" onchange="clicked();" />
</div>
<div id="plz'.$part.'">
<label class="ui_field_label" for="plz'.$part.'">PLZ</label><input type="text" name="plz'.$part.'" value="'.$voucher['plz'].'" onchange="clicked();" />
</div>
<div id="city'.$part.'">
<label class="ui_field_label" for="city'.$part.'">Ort</label><input type="text" name="city'.$part.'" value="'.$voucher['city'].'" onchange="clicked();" />
</div>
';
block_end();
}

function page_new_buchung($part = 0)
{
$voucher = get_voucher($part);

page_edit_form($part,$voucher);
}

function page_form_header($p)
{

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
<form class="vertical" action="index.php?action='.$p.'" method="post">
';
}

function page_new()
{
$preview = true;
if (isset($_POST["preview"]))
        $preview = false;
$parts = 1;
if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1)
	$parts = $_POST["parts"];
if (isset($_POST["add"]))
	$parts++;


page_form_header("new");
echo '
<h1>Buchung erfassen</h1>
';

for ($i = 0; $i < $parts; $i++)
{
	page_new_buchung($i);
}

$schatzmeister = true;
$ack = '';
if (isset($_POST["ack"]))
        $ack = ' checked="checked"';
$beleg = '';
if (isset($_POST["beleg"]))
        $beleg = ' checked="checked"';
bsm_block($schatzmeister,$ack,$beleg);

end_of_form('1',$parts);
}

function end_of_form($ack,$parts)
{
echo '
<input value="'.$parts.'" type="hidden" id="parts" name="parts" />
';

if ($ack != '')
{
block_start();
echo '
<br />

<input value="Weitere Buchung zu DIESER Transaktion" type="submit" name="add" />
<br />
<br />
<input value="Vorschau" type="submit" name="preview" />
<input name="speichern" id="speichern" value="Speichern" type="submit" />
<script type="text/javascript">clicked(true);</script>
';

block_end();
}
}

function bsm_block($schatzmeister,$ack,$beleg)
{
if ($schatzmeister)
{
block_start();

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
}


?>
