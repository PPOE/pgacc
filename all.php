<?php
function page_all($rights)
{
$rightssql = rights2orgasql($rights);
page_listing_header('all');
$filter = getfilter();
$sort = getsort();
$query = "SELECT vouchers.*,lo.name AS lo_name,type.name AS type_name FROM vouchers LEFT JOIN lo ON orga = lo.id LEFT JOIN type ON type = type.id WHERE NOT deleted $rightssql $filter ORDER BY $sort";
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
