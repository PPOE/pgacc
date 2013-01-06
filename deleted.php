<?php
function page_deleted()
{
echo '
<div class="wiki motd">Hier finden sich gelöschte Buchungszeilen. Buchungszeilen können zwar nicht gelöscht werden jedoch geändert. Da Änderungen aber nachvollziehbar sein müssen werden bei einer Änderung alte Revisionen nicht verworfen sondern nur als &quot;gelöscht&quot; markiert.</div>
';
page_listing_header();
$query = "SELECT * FROM vouchers WHERE deleted AND NOT acknowledged ORDER BY voucher_id DESC,id DESC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line,"recover");
}
pg_free_result($result);
echo '</table>';
block_end();
}
?>
