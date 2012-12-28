<?php
function page_open()
{
page_listing_header();
$query = "SELECT * FROM vouchers WHERE NOT deleted AND NOT acknowledged ORDER BY voucher_id,id";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
page_listing_line($line);
}
pg_free_result($result);
echo '</table>';
block_end();
}
?>
