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
$voucher['bid'] = get_param($part, 'bid', 0, '/^\d+$/');
$voucher['date'] = format_date(get_param($part, 'date', null, '/^((\d|(0|1|2)\d|3(0|1))\.(\d|0\d|1(0|1|2))\.20\d\d)|(20\d\d-(\d|0\d|1(0|1|2))-(\d|(0|1|2)\d|3(0|1)))$/'));
$voucher['dir'] = get_param($part, 'dir', 'in', '/^(in|out|wk)$/');
$voucher['in_type'] = get_param($part, 'in_type', 0, '/^\d+$/');
$voucher['out_type'] = get_param($part, 'out_type', 0, '/^\d+$/');
$voucher['wk_type'] = get_param($part, 'wk_type', 0, '/^\d+$/');
if ($voucher['dir'] == 'wk')
  $voucher['type'] = $voucher['wk_type'];
else if ($voucher['dir'] == 'out')
  $voucher['type'] = $voucher['out_type'];
else
  $voucher['type'] = $voucher['in_type'];
$voucher['person_type'] = get_param($part, 'person_type', 2, '/^\d+$/');
$voucher['lo'] = get_param($part, 'lo', 10, '/^\d+$/');
$voucher['amount'] = round(str_replace(",",".",get_param($part, 'amount', '0.00', '/^-?\d+((\.|,)\d\d?)?$/')) * 100.0);
$voucher['gegenkonto'] = pg_escape_string(get_param($part, 'gegenkonto', '', '/^( |\d|[A-Z]|[ÄÖÜäöü])+$/i'));
$voucher['konto'] = pg_escape_string(get_param($part, 'konto', '', '/^( |\d|[A-Z]|[Ä[ÄÖÜäöü]])+$/i'));
$voucher['comment'] = pg_escape_string(get_param($part, 'comment', '', null));
$voucher['commentgf'] = pg_escape_string(get_param($part, 'commentgf', '', null));
$voucher['purpose'] = get_param_bool($part, 'purpose', 'false', null, 'true');
$voucher['member'] = get_param_bool($part, 'member', 'false', null, 'true');
$voucher['mitgliedsnummer'] = get_param($part, 'mitgliedsnummer', 0, '/^\d+$/');
$voucher['name'] = pg_escape_string(get_param($part, 'name', '', null));
$voucher['street'] = pg_escape_string(get_param($part, 'street', '', null));
$voucher['plz'] = pg_escape_string(get_param($part, 'plz', '', null));
$voucher['city'] = pg_escape_string(get_param($part, 'city', '', null));
$voucher['file'] = get_param($part, 'file', -1, '/^\d+$/');
return $voucher;
}
function page_save_buchung($voucher_number, $part = 0)
{
$voucher = get_voucher($part);
if ($voucher['date'] == null)
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Kein Datum angegeben.</div><br /><br /><br />';
  $voucher['date'] = '1800-01-01';
}
if ($voucher['purpose'] == 'true' && ($voucher['dir'] == 'out' || $voucher['in_type'] != 8))
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Eine Zweckwidmung ist nur bei einer Spende möglich.</div><br /><br /><br />';
  $voucher['purpose'] = 'false';
}

if ($voucher['member'] == 'true')
{
getusers();
if ($voucher['mitgliedsnummer'] == 0)
{
  if (strlen($voucher['name']) > 0)
  {
    $n = $voucher['name'];
    $n2 = trim(str_replace(array('Mag.','DI (FH)','iur.','Dipl.-Ing.','Dr.','Ing.'),array('','','','','',''),$n));
    $nt = explode(" ",$n,2);
    $nt2 = explode(" ",$n2,2);
    $queries = array();
    $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('$n');";
    if (count($nt) == 2)
      $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('{$nt[1]} {$nt[0]}');";
    if ($n != $n2)
    {
      $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('$n2');";
      if (count($nt2) == 2)
        $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('{$nt2[1]} {$nt2[0]}');";
    }
  }
  $found = false;
  foreach ($queries as $query)
  {
    if ($result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error()))
    {
      if (pg_num_rows($result) == 1)
      {
        $line = pg_fetch_array($result, null, PGSQL_ASSOC);
        $voucher['member'] = 'true';
        $voucher['mitgliedsnummer'] = intval($line['id']);
        //$voucher['lo'] = $line['lo'];
        //$voucher['type'] = 1;
        pg_free_result($result);
        $found = true;
        break;
      }
      pg_free_result($result);
    }
  }
}
else
{
  $query = "SELECT name FROM ppmembers WHERE id = {$voucher['mitgliedsnummer']};";
  $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    $voucher['name'] = $line['name'];
  }
  pg_free_result($result);
}

$query = 'DROP TABLE IF EXISTS ppmembers;';
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
pg_free_result($result);
}
if ($voucher['file'] == -1)
  $voucher['file'] = 0;
$query = "INSERT INTO vouchers (voucher_id, date, type, person_type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, commentgf, committed, file) VALUES ($voucher_number, '{$voucher['date']}', ".($voucher['dir'] == "in"?$voucher['in_type']:($voucher['dir'] == "out"?$voucher['out_type']:$voucher['wk_type'])).",{$voucher['person_type']},{$voucher['lo']},{$voucher['member']},{$voucher['mitgliedsnummer']},'{$voucher['gegenkonto']}','{$voucher['name']}','{$voucher['street']}','{$voucher['plz']}','{$voucher['city']}',{$voucher['amount']},'{$voucher['konto']}','{$voucher['comment']}','{$voucher['commentgf']}',{$voucher['purpose']},{$voucher['file']})";
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
function page_new_file($voucher_number)
{
$parts = 1;
if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1)
        $parts = intval($_POST["parts"]);

$bid = -1;
$file = -1;
for ($part = 0; $part < $parts; $part++)
{
  if (isset($_FILES["file$part"]) && isset($_FILES["file$part"]["name"]) && strlen($_FILES["file$part"]["name"]) > 3)
  {
    if (isset($_POST["bid$part"]) && intval($_POST["bid$part"]) > 0)
    {
      $file = $part;
      $bid = intval($_POST["bid$part"]);
      break;
    }
  }
}
if ($file == -1 || $_FILES["file$part"]["size"] > 100000000)
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Datei Upload fehlgeschlagen.</div><br /><br /><br />';
  return;
}
if ($bid == -1)
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Buchungszeile nicht gefunden.</div><br /><br /><br />';
  return;
}
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

$query = "SELECT 1 FROM vouchers WHERE NOT deleted AND ack1 IS NOT NULL AND ack2 IS NOT NULL AND voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo '<div class="slot_error" id="slot_error">FEHLER: Die Buchung wurde bereits bestätigt und kann jetzt nicht mehr verändert werden.</div><br /><br /><br />';
  pg_free_result($result);
  return;
}
pg_free_result($result);

$query = "SELECT nextval('file_number') AS num;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $file_number = $line['num'];
}
pg_free_result($result);

$sourcepath = $_FILES["file$file"]["tmp_name"];
$targetpath = getcwd() . '/files/' . $file_number . ".aes";
$data = file_get_contents($sourcepath);
file_put_contents($targetpath,mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($id . $key . $id), $data, MCRYPT_MODE_CBC, md5($key . $id)));

$query = "UPDATE vouchers SET ack1 = NULL,ack2 = NULL WHERE NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL) AND voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);
$query = "UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND voucher_id = $id $rightssql;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);

echo "<h1>Upload erfolgreich - Buchung Nr. $id - Buchungszeile Nr. $bid</h1>";
block_start();
echo "<a href=\"index.php?action=edit&id=$id&bid=$bid&hint=1\">Zurück zur Buchung</a>";
block_end();
relocate("index.php?action=edit&id=$id&bid=$bid&hint=1");
}
function page_edit_form($part,$voucher,$rights)
{
block_start();
if (intval($voucher['bid']) == 0)
  echo '<h3>Neue Buchungszeile</h3>';
else
  echo '<h3>Buchungszeile '.$voucher['bid'].'</h3>';
echo '
<input type="hidden" name="bid'.$part.'" value="'.$voucher['bid'].'" />
<div>
<label class="ui_field_label" for="date'.$part.'">Datum</label><input type="text" id="date'.$part.'" name="date'.$part.'" value="'.$voucher['date'].'" onchange="clicked();" />
</div>
<div>
<label for="dir'.$part.'" class="ui_field_label">Einnahme/Ausgabe/Wahlkampf
</label>
<select id="dir'.$part.'" name="dir'.$part.'" onchange="clicked();">
<option value="in"'.($voucher['dir'] == 'in'?' selected':'').'>Einnahme</option>
<option value="out"'.($voucher['dir'] == 'out'?' selected':'').'>Ausgabe</option>
<option value="wk"'.($voucher['dir'] == 'wk'?' selected':'').'>Wahlkampfausgabe</option>
</select>
</div>
<div id="in_block'.$part.'"'.($voucher['dir'] == 'in'?'':' style="display: none;"').'>
<label for="in_type'.$part.'" class="ui_field_label">Art der Einnahme</label> 
<select id="in_type'.$part.'" name="in_type'.$part.'" onchange="clicked();">
';
$query = "SELECT id,name FROM type WHERE income = 1 ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo '<option value="'.($voucher['type'] == $line['id']?$line['id'] . '" selected="selected"':$line['id'] . '"').'>'.$line['name'].'</option>
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
$query = "SELECT id,name FROM type WHERE income = 0 ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo '<option value="'.($voucher['type'] == $line['id']?$line['id'] . '" selected="selected"':$line['id'] . '"').'>'.$line['name'].'</option>
';
}
pg_free_result($result);
echo '
</select>
</div>
<div id="wk_block'.$part.'"'.($voucher['dir'] == 'wk'?'':' style="display: none;"').'>
<label for="wk_type'.$part.'" class="ui_field_label">Art der Wahlkampfausgabe</label> 
<select id="wk_type'.$part.'" name="wk_type'.$part.'" onchange="clicked();">
';
$query = "SELECT id,name FROM type WHERE income = -1 ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    echo '<option value="'.($voucher['type'] == $line['id']?$line['id'] . '" selected="selected"':$line['id'] . '"').'>'.$line['name'].'</option>
    ';
}
pg_free_result($result);
echo '
</select>
</div>
<div id="person_type_block'.$part.'">
<label for="person_type'.$part.'" class="ui_field_label">Art des Geschäftspartners</label> 
<select id="person_type'.$part.'" name="person_type'.$part.'" onchange="clicked();">
';
$query = "SELECT id,name FROM person_type ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    echo '<option value="'.($voucher['person_type'] == $line['id']?$line['id'] . '" selected="selected"':$line['id'] . '"').'>'.$line['name'].'</option>
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
$rights2 = explode(",", $rights);
if (in_array('bsm',$rights2) || in_array('bgf',$rights2) || in_array('root',$rights2) || in_array($line['id'], konto2lo($rights2)))
  echo '<option value="'.($line['id'] == $voucher['lo']?$line['id'].'" selected="selected"':$line['id'].'"').'>'.$line['name'].'</option>
';
}
pg_free_result($result);
echo '
</select>
</div>
<div>
<label class="ui_field_label" for="member'.$part.'">Mitglied</label><input type="checkbox" id="member'.$part.'" name="member'.$part.'" value="1" '.($voucher['member']=='true'?' checked="checked"':'').' onchange="clicked();" />'.(intval($voucher['mitgliedsnummer']) > 0 ?' &middot; <a href="https://mitglieder.piratenpartei.at/adm_program/modules/profile/profile.php?user_id='.$voucher['mitgliedsnummer'].'" target="_blank">In MV öffnen</a> &middot; <a href="https://mitglieder.piratenpartei.at/adm_program/modules/profile/profile_save.php?new_user=0&user_id='.$voucher['mitgliedsnummer'].'&usf-25='.date("d.m.Y", strtotime($voucher['date'])).'&usf-26=31.12.2014&usf-27='.($voucher['amount'] / 100.0).'" target="_blank">MB f&uuml;r 2014 in MV übertragen</a>':'').'
</div>
<div id="mitgliedsnummer'.$part.'">
<label class="ui_field_label" for="mitgliedsnummer'.$part.'">Mitgliedsnummer</label><input type="text" name="mitgliedsnummer'.$part.'" value="'.$voucher['mitgliedsnummer'].'" onchange="clicked();" />
</div>
<div id="gegenkonto'.$part.'">
<label class="ui_field_label" for="gegenkonto'.$part.'">Fremdkonto</label><input type="text" name="gegenkonto'.$part.'" value="'.$voucher['gegenkonto'].'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="amount'.$part.'">Betrag (in €)</label><input type="text" id="amount'.$part.'" name="amount'.$part.'" value="'.$voucher['amount'] / 100.0 .'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="konto'.$part.'">Konto</label><input type="text" id="konto'.$part.'" name="konto'.$part.'" value="'.$voucher['konto'].'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="comment'.$part.'">Buchungstext</label><input type="text" id="comment'.$part.'" name="comment'.$part.'" value="'.$voucher['comment'].'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="commentgf'.$part.'">Kommentar</label><input type="text" id="commentgf'.$part.'" name="commentgf'.$part.'" value="'.$voucher['commentgf'].'" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="purpose'.$part.'">Zweckgebunden</label><input type="checkbox" id="purpose'.$part.'" name="purpose'.$part.'" value="1" '.($voucher['purpose']=='true'?' checked="checked"':'').' onchange="clicked();" />
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
<div id="city'.$part.'">
<label class="ui_field_label" for="file'.$part.'">Beleg</label>';
if (intval($voucher['file']) > 0)
{
  echo '<a href="?action=file&id=' . $voucher['bid'] . '" target="_blank">Beleg Anzeigen</a><input type="hidden" name="file'.$part.'" value="'. $voucher['file'] . '" />';
}
else if (intval($voucher['file']) == 0)
{
  echo '
<input type="hidden" name="MAX_FILE_SIZE" value="100000000" />
<input name="file'.$part.'" type="file"  onchange="clicked();" accept="application/pdf"/><br />
<input type="submit" name="fileupload" value="PDF Hochladen (OHNE SPEICHERN)" />
';
}
else
{
  echo 'Die Buchungszeile muss gespeichert werden bevor ein Beleg dazu hochgeladen werden kann.';
}
echo '
</div>
<div style="float: right;">
<a href="javascript:deleteB('.$part.');">Diese Buchungszeile löschen</a>
</div>
<br />
';
block_end();
}

function page_new_buchung($part = 0, $part_to_remove,$rights,$add)
{
if ($part_to_remove != -1 && $part >= $part_to_remove)
	$voucher = get_voucher($part+1);
else if ($add)
  $voucher = get_voucher($part-1);
else
	$voucher = get_voucher($part);

page_edit_form($part,$voucher,$rights);
}

function page_form_header($p)
{

echo '
<script type="text/javascript">
function deleteB(part)
{
  document.getElementById("ack").style.display = "none";
  var max = parseInt(document.getElementById("parts").value);
  if (max == 1)
  {
    alert("Kann letzte Buchungszeile nicht löschen!");
    return;
  }
  if (!confirm("Sicher löschen?"))
    return;
  var e = document.createElement("div");
  e.innerHTML = "<input type=\'hidden\' name=\'remove\' value=\'" + part + "\' />";
  document.mainform.appendChild(e);
  document.mainform.submit();
}
function clicked(init)
{
  if (init !== true)
  {
    try { document.getElementById("ack").style.display = "none"; } catch (e) { }
    try { document.getElementById("belegdiv").style.display = "none"; } catch (e) { }
  }
  
  var max = 1;
  try {
    max = parseInt(document.getElementById("parts").value);
  }
  catch (e)
  {
    alert("Anzahl der Buchungszeilen konnte nicht ermittelt werden.");
  }
  var i = 0;
  while (i < max)
  {
    if (document.getElementById("dir" + i).selectedIndex == 1)
    {
      document.getElementById("in_block" + i).style.display = "none";
      document.getElementById("out_block" + i).style.display = "block";
      document.getElementById("wk_block" + i).style.display = "none";
    }
    else if (document.getElementById("dir" + i).selectedIndex == 2)
    {
      document.getElementById("in_block" + i).style.display = "none";
      document.getElementById("out_block" + i).style.display = "none";
      document.getElementById("wk_block" + i).style.display = "block";
    }
    else
    {
      document.getElementById("in_block" + i).style.display = "block";
      document.getElementById("out_block" + i).style.display = "none";
      document.getElementById("wk_block" + i).style.display = "none";
    }
    if (document.getElementById("member" + i).checked)
    {
      document.getElementById("mitgliedsnummer" + i).style.display = "block";
      document.getElementById("street" + i).style.display = "none";
      document.getElementById("plz" + i).style.display = "none";
      document.getElementById("city" + i).style.display = "none";
      document.getElementById("gegenkonto" + i).style.display = "none";
    }
    else
    {
      document.getElementById("mitgliedsnummer" + i).style.display = "none";
      document.getElementById("street" + i).style.display = "block";
      document.getElementById("plz" + i).style.display = "block";
      document.getElementById("city" + i).style.display = "block";
      document.getElementById("gegenkonto" + i).style.display = "block";
    }
    var i = i + 1;
  }
}
</script>
<form name="mainform" class="vertical" enctype="multipart/form-data" action="index.php?action='.$p.'" method="post">
';
}

function page_new($rights)
{
$parts = 1;
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

page_form_header("new");
echo '
<h1>Buchung erfassen</h1>
';

$add = false;
for ($i = 0; $i < $parts; $i++)
{
  if (isset($_POST["add"]) && $i == $parts-1)
    $add = true;
	page_new_buchung($i, $part_to_remove, $rights, $add);
}

end_of_form($parts,$rights,true,false);
}

function end_of_form($parts,$rights,$open = true,$ack = true)
{
echo '
<input value="'.$parts.'" type="hidden" id="parts" name="parts" />
';

if ($open)
{
block_start();
echo '<input name="speichern" id="speichern" value="Speichern" type="submit" />';
if (strpos($rights,'bgf') !== false && $ack)
  echo '<input name="ack" id="ack" value="Bestätigen (OHNE SPEICHERN)" type="submit" style="margin-left: 5%"/>';
echo '<input value="Buchungszeile hinzufügen" type="submit" name="add" style="margin-left: 15%"/>';
echo '<script type="text/javascript">clicked(true);</script>
';

block_end();
}
echo "</form>";
}

function bsm_block($schatzmeister,$beleg)
{
echo '<div id="belegdiv">';
if ($schatzmeister)
{
block_start();

echo '
<h3>Bundesschatzmeister</h3>
<div>
Der Bundesschatzmeister hat die Aufgabe jede vorläufige Buchung zu überprüfen. Erst damit ist eine Buchung abgeschlossen. Sie kann dann nicht mehr verändert werden.<br />
Ist die vorläufige Buchung korrekt durchgeführt?<br />
Sind alle Belege vorhanden?<br />
<input name="beleg" id="beleg" value="Ja, es ist alles in Ordnung. Ich möchte die Buchung finalisieren." type="submit" /><br /><br />
Wurde die Buchung fehlerhaft durchgeführt und muss korrigiert werden?
<input name="belegfehler" id="belegfehler" value="Es wurden Fehler festgestellt. Die Geschäftsführung muss bei dieser vorläufigen Buchung nachbessern." type="submit" /><br /><br />
</div>
';
block_end();
}
echo '</div>';
}


?>
