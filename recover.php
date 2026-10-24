<?php
function page_recover($rights)
{
global $dbconn;
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
if (!has_right($rights, 'root'))
{
  echo '<div class="slot_error" id="slot_error">WARNUNG: Diese Funktion sollte nur von Admins verwendet um Fehlerfälle zu beheben. Bitte kontaktiere einen der Admins.</div><br /><br /><br />';
    return;
}

// Only the confirmation form recovers (POST with the form token, checked in
// index.php); a GET request with doit shows the form again.
$doit = false;
if (request_is_post() && isset($_POST["doit"]))
{
  $doit = true;
}

if ($doit)
{
echo "<h1>Buchung Nr. $id / Buchungszeile Nr. $bid wiederhergestellt!</h1>";

$query = "UPDATE vouchers SET deleted = false WHERE voucher_id = $id AND id = $bid;";
$result = pg_query($dbconn, $query) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
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
<form action="index.php?action=recover&amp;id='.$id.'&amp;bid='.$bid.'" method="post">
'.csrf_field().'
<input type="submit" name="doit" value="Ja, ich weiß was ich tue!" />
</form>
</center>
<br /><br /><br />
';
echo '<br /><br /><br />';
}
}
?>
