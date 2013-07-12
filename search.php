<?php
function page_search($rights)
{
$member = intval($_GET['member']);
$rightssql = rights2orgasql($rights);
$filtersql = " AND member_id = $member ";
page_listing_header('search');
$sort = getsort();
$query = "SELECT * FROM vouchers WHERE NOT deleted $filtersql $rightssql ORDER BY $sort";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line);
}
pg_free_result($result);
echo '</table>';
block_end();
}
?>
