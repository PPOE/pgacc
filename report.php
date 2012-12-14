<?php
function unit_report($unit = 0)
{
global $dbconn;
echo '
<table><tr><td>
<h3>Einnahmen</h3>
<table>
';
$query = "SELECT name FROM type WHERE income = true ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td>{$line['name']}</td><td>0 €</td></tr>\n";
}
pg_free_result($result);
echo '
</table>
</td>
<td>
<h3>Ausgaben</h3>
<table>
';
$query = "SELECT name FROM type WHERE income = true ORDER BY used DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td>{$line['name']}</td><td>0 €</td></tr>\n";
}
pg_free_result($result);
echo '
</table>
</td>
</tr>
</table>
';
}

function page_report()
{
$year = date('Y');
echo '<h1>Rechenschaftsbericht der Piratenpartei Österreichs '.$year.'</h1><br>';
block_start();
echo '
Die Piratenpartei Österreichs hat die folgenden Unterorganisationen:
<ul>
';
$query = "SELECT id,name FROM lo ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<li>{$line['name']}";
  $query = "SELECT name FROM oo WHERE lo = {$line['id']} ORDER BY id ASC";
  $result2 = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  if (pg_num_rows($result2) > 0)
    echo "\n<ul>\n";

  while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
    echo "<li>{$line2['name']}</li>\n";
  }

  if (pg_num_rows($result2) > 0)
    echo "\n</ul>\n";

  pg_free_result($result2);
}
pg_free_result($result);
echo '
</ul>
Die Unterorganisationen haben keine eigene Rechtspersönlichkeit.

<h1>Einnahmen und Ausgaben Bund</h1>
';
unit_report(0);
echo'
<h1>Einnahmen und Ausgaben Länder</h1>
';
$query = "SELECT id,name FROM lo ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<h2>{$line['name']}</h2>\n";
  unit_report($line['id']);
}
pg_free_result($result);
echo'
<h2>Ausgaben Wahlwerbung Gemeinderatswahl Graz 2012</h2>
TODO
';
echo '
<h2>Liste jener Unternehmen, an denen die Partei Stimmrechte hält</h2>
Die Piratenpartei Österreichs hält keine Stimmrechte an Unternehmen.
';
block_end();
block_start();
echo '<h2>Anlage A (nach § 6 (2))</h2>
<table>
<tr><td><b>Spenden an die Partei</b></td><td>0 €</td></tr>
<tr><td><b>Spenden an nahestehende Organisationen</b></td><td>0 €</td></tr>
<tr><td><b>Spenden an Abgeordnete und Wahlwerber</b></td><td>0 €</td></tr>
</table>';
block_end();
block_start();
echo '<h2>Anlage B (nach § 6 (3))</h2>
<table>
';
$query = "SELECT id,name FROM donation_type ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  echo "<tr><td><b>{$line['name']}</b></td><td>0 €</td></tr>\n";
}
pg_free_result($result);
echo '
</table>';
block_end();
block_start();
echo "<h2>Anlage C: Einnahmen aus Sponsoring</h2>";
echo "Die Piratenpartei Österreichs hat keine Einnahmen aus Sponsoring.";
block_end();

}
?>
