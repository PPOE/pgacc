<?php
function page_search($rights)
{
$member = intval($_GET['member']);
$rightssql = rights2orgasql($rights);
$filtersql = " AND member_id = $member ";
page_listing_header('search');
$sort = getsort();
$query = "SELECT vouchers.*,lo.name,type.name FROM vouchers LEFT JOIN lo ON orga = lo.id LEFT JOIN type ON type = type.id WHERE NOT deleted $filtersql $rightssql ORDER BY $sort";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line);
}
pg_free_result($result);
global $make_csv;
if (!$make_csv)
echo '</table>';
block_end();
csv_download_link();
}
?>
