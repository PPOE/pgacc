<?php

function page_statistics() {
?>

<h1>Statistiken</h1>

<h2>Buchungssystem Fortschritt Monatlich</h2>

<?php

$startYear = 2012;
$endYear = date('Y');
for ($currentYear = $startYear; $currentYear <= $endYear; $currentYear++) {
for ($month = 1; $month <= 12; $month++)
{
  /*$query = "SELECT COUNT(*) AS total, COUNT(ack1) AS inprogress, COUNT(ack2) AS finished FROM vouchers WHERE date >= '" . $currentYear . '-01-01' . "' AND  date < '" . ($currentYear + 1) . '-01-01' . "' AND NOT deleted;";
  $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    echo  'date: ' . $currentYear . '-' . $month . ', total: ' . intval($line['total']) . '<br />';
  }
  pg_free_result($result);
  if ($monat == 12) {
    $currentYear++;
  }*/
}
}


?>




<?php
}

?>
