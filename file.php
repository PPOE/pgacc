<?php
function download_file($rights)
{
$file = 0;
$rightssql = rights2orgasql($rights);
$bid = intval($_GET['id']);
$query = "SELECT file,voucher_id FROM vouchers WHERE NOT deleted AND id = $bid $rightssql ORDER BY id ASC;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $file = $line['file'];
  $vid = $line['voucher_id'];
}
pg_free_result($result);

if ($file == 0)
  return false;

// <n>.v2.aes (configured key) if it exists, else the legacy <n>.aes.
$path = receipt_v2_path(getcwd() . '/files', $file);
if (file_exists($path))
  $data = receipt_decrypt_v2($vid, file_get_contents($path), "$file.v2.aes");
else
{
  $path = receipt_legacy_path(getcwd() . '/files', $file);
  $data = receipt_decrypt($vid, '', file_get_contents($path)); // legacy files: empty key part, see tests/COMPATIBILITY_PLAN.md
}
if ($data === false)
{
  header('Content-Type: text/plain; charset=UTF-8', true, 500);
  echo 'FEHLER: Der Beleg konnte nicht entschlüsselt werden.';
  return true;
}
if (substr($data,0,4) == '%PDF')
{
  header("Content-Type: application/pdf");
  header("Content-Disposition: inline; filename=\"$file.pdf\"");
  header('Content-Transfer-Encoding: binary');
  header('Accept-Ranges: bytes');
  header('Content-Length: ' . filesize($path));
  echo $data;
}
else
{
  header("Content-Type: image/jpeg");
  header('Content-Transfer-Encoding: binary');
  header('Content-Length: ' . filesize($path));
  header('Accept-Ranges: bytes');
  echo $data;
}


return true;
}
?>
