<?php
function page_new_buchung($part = 0)
{
block_start();
$dir = 'in';
if (isset($_POST["dir" . $part]) && preg_match('/^(in|out)$/', $_POST["dir" . $part]) == 1)
	$dir = $_POST["dir" . $part];
$in_type = '0';
if (isset($_POST["in_type" . $part]) && preg_match('/^\d+$/', $_POST["in_type" . $part]) == 1)
        $in_type = $_POST["in_type" . $part];
$out_type = '0';
if (isset($_POST["out_type" . $part]) && preg_match('/^\d+$/', $_POST["out_type" . $part]) == 1)
        $out_type = $_POST["out_type" . $part];
$lo = '10';
if (isset($_POST["lo" . $part]) && preg_match('/^\d+$/', $_POST["lo" . $part]) == 1)
        $lo = $_POST["lo" . $part];
$amount = '0.00';
if (isset($_POST["amount" . $part]) && preg_match('/^-?\d+((\.|,)\d\d)?$/', $_POST["amount" . $part]) == 1)
        $amount = $_POST["amount" . $part];
$gegenkonto = '';
if (isset($_POST["gegenkonto" . $part]) && preg_match('/^\d+$/', $_POST["gegenkonto" . $part]) == 1)
        $gegenkonto = $_POST["gegenkonto" . $part];
$konto = '';
if (isset($_POST["konto" . $part]) && preg_match('/^\d+$/', $_POST["konto" . $part]) == 1)
        $konto = $_POST["konto" . $part];
$comment = '';
if (isset($_POST["comment" . $part]))
        $comment = $_POST["comment" . $part];
$purpose = '';
if (isset($_POST["purpose" . $part]))
        $purpose = ' checked="checked"';
$member = '';
if (isset($_POST["member" . $part]))
        $member = ' checked="checked"';
$mitgliedsnummer = '0';
if (isset($_POST["mitgliedsnummer" . $part]) && preg_match('/^\d+$/', $_POST["mitgliedsnummer" . $part]) == 1)
        $mitgliedsnummer = $_POST["mitgliedsnummer" . $part];
$name = '';
if (isset($_POST["name" . $part]))
        $name = $_POST["name" . $part];
$street = '';
if (isset($_POST["street" . $part]))
        $street = $_POST["street" . $part];
$plz = '';
if (isset($_POST["plz" . $part]))
        $plz = $_POST["plz" . $part];
$city = '';
if (isset($_POST["city" . $part]))
        $city = $_POST["city" . $part];

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
$id = rand(0,100);
if (isset($_POST["id"]) && preg_match('/^\d+$/', $_POST["id"]) == 1)
        $id = $_POST["id"];

echo '
<script type="text/javascript">
function clicked()
{
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
<form class="vertical" action="index.php?action=new" method="post">
<h1>Buchungsnummer '.$id.' - Auf Beleg notieren!</h1>
<input value="'.$id.'" type="hidden" name="id" id="id" />
';

for ($i = 0; $i < $parts; $i++)
{
	page_new_buchung($i);
}

$schatzmeister = true;
if ($schatzmeister)
{
block_start();
$ack = '';
if (isset($_POST["ack"]))
        $ack = ' checked="checked"';
$beleg = '';
if (isset($_POST["beleg"]))
        $beleg = ' checked="checked"';
echo '
<h3>Bundesschatzmeister</h3>
<div>
<label class="ui_field_label" for="ack">Bestätigt</label><input type="checkbox" name="ack" value="1"/>
</div>
<div>
<label class="ui_field_label" for="beleg">Beleg erhalten</label><input type="checkbox" name="beleg" value="1"/>
</div>
';
block_end();
}

block_start();
echo '
<br />
<input value="Weitere Buchung zu DIESER Transaktion" type="submit" name="add" />
<input value="'.$parts.'" type="hidden" id="parts" name="parts" />
<br />
<br />
<input value="Vorschau" type="submit" name="preview" />
<input id="speichern" value="Speichern" type="submit" />
';

block_end();
echo '</form><script type="text/javascript">clicked();</script>';
}
?>
