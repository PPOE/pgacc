<?php
function page_deleted($rights)
{
echo '
<div class="wiki motd">Hier finden sich alte Revisionen von Buchungszeilen. Buchungszeilen können zwar nicht gelöscht werden jedoch geändert, sofern sie noch nicht mittels Bestätigungen abgeschlossen wurden. Da Änderungen aber nachvollziehbar sein müssen werden bei einer Änderung alte Revisionen nicht verworfen sondern nur als &quot;gelöscht&quot; markiert. Abgeschlossene Buchungen können nicht verändert oder gelöscht werden.</div>
';
$rightssql = rights2orgasql($rights);
page_listing_header('deleted');
$filter = getfilter();
$sort = getsort();
$query = "SELECT * FROM vouchers WHERE deleted AND ack1 IS NULL AND ack2 IS NULL $rightssql $filter ORDER BY $sort";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line,"recover");
}
pg_free_result($result);
echo '</table>';
block_end();
}
?>
