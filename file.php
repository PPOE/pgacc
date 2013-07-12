<?
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

header("Content-Type: application/pdf");
$data = file_get_contents(getcwd() . "/files/$file.aes");
echo mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($vid . $key . $vid), $data, MCRYPT_MODE_CBC, md5($key . $vid));

return true;
}
?>
