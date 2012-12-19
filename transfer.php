<?php
function page_transfer()
{
echo '<h1>Offene Umbuchungen:</h1><br>';
block_start();
echo '
Die folgende Tabelle summiert die Forderungen der jeweiligen LO bzw. des Bundes gegenüber der LO auf:
<table>
<tr><td><b>Landesorganisation</b></td><td><b>An den Bund</b></td><td><b>Vom Bund an die LO</b></td><td><b>Summe</b></td></tr>
';
$query = "SELECT id,name FROM lo WHERE id < 10 ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td>{$line['name']}</td><td>0 €</td><td>0 €</td><td>0 €</td></tr>\n";
  $query = "SELECT name FROM oo WHERE lo = {$line['id']} ORDER BY id ASC";
}
pg_free_result($result);
echo '
</table>
';
block_end();

}
?>
