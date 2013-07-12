<?php
function page_all($rights)
{
$rightssql = rights2orgasql($rights);
page_listing_header('all');
$filter = getfilter();
$sort = getsort();
$query = "SELECT * FROM vouchers WHERE NOT deleted $rightssql $filter ORDER BY $sort";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line);
}
pg_free_result($result);
echo '</table>';
block_end();
}
?>
