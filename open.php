<?php
function page_open($rights)
{
$rightssql = rights2orgasql($rights);
page_listing_header('open');
$sort = getsort();
$query = "SELECT * FROM vouchers WHERE NOT deleted AND (ack1 IS NULL OR ack2 IS NULL) $rightssql ORDER BY $sort";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line);
}
pg_free_result($result);
echo '</table>';
block_end();
}
?>
