<?php
// Canary: edit.php:345 / donations.php:116 build an array only inside a
// result loop; with no rows the variable is undefined when counted/sorted.
function canary_rows($result)
{
  while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    $rows[] = $line;
  }
  return count($rows);
}
