<?php
function page_recover($rights)
{
$id = -1;
if (isset($_GET["id"]))
{
  $id = intval($_GET["id"]);
}
else
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
  return;
}
$bid = -1;
if (isset($_GET["bid"]))
{
  $bid = intval($_GET["bid"]);
}
else
{
  echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
  return;
}
if (strpos($rights,'root') === false)
{
  echo '<div class="slot_error" id="slot_error">WARNUNG: Diese Funktion sollte nur von Admins verwendet um Fehlerfälle zu beheben. Bitte kontaktiere einen der Admins.</div><br /><br /><br />';
    return;
}

$doit = false;
if (isset($_GET["doit"]))
{
  $doit = true;
}

if ($doit)
{
echo "<h1>Buchung Nr. $id / Buchungszeile Nr. $bid wiederhergestellt!</h1>";

$query = "UPDATE vouchers SET deleted = false WHERE voucher_id = $id AND id = $bid;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
}
pg_free_result($result);
}
else
{
echo '
<div class="wiki motd">BEACHTE: Das Wiederherstellen von Buchungen bzw. Buchungszeilen kann dazu führen dass Buchungen bzw. Buchungszeilen doppelt im System sind! Benutze diese Funktion nur wenn du dir ganz sicher bist dass deine Buchung überschrieben wurde und daher diese alte Buchung wiederherstellen möchtest!</div>
<br /><br /><br />
<center>
<form action="index.php" method="GET">
<input type="hidden" name="action" value="recover" />
<input type="hidden" name="id" value="'.$id.'" />
<input type="hidden" name="bid" value="'.$bid.'" />
<input type="submit" name="doit" value="Ja, ich weiß was ich tue!" />
</form>
</center>
<br /><br /><br />
';
echo '<br /><br /><br />';
}
}
?>
