<?
function getUsers()
{
global $datakey;
$sData = file_get_contents("http://mitglieder.piratenpartei.at/adm_api/adm_names.php");
if (strlen($datakey) == 0)
  die('Kein Passwort!');

if (strlen($sData) < 1000)
  die('Daten von Mitgliederverwaltung zu kurz!');

$query = 'DROP TABLE IF EXISTS ppmembers;';
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
pg_free_result($result);

$query = 'CREATE TABLE ppmembers (id integer, name text, lo integer);';
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
pg_free_result($result);

$decrypted = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($datakey), base64_decode($sData), MCRYPT_MODE_CBC, md5(md5($datakey))), "\0");
$query = 'INSERT INTO ppmembers (id, name, lo) VALUES ';
$lines = explode("\n",$decrypted);
$count = 0;
foreach ($lines as $line)
{
  if (strlen($line) < 5) { continue; }
  $values = explode("\t",$line);
  $id = $values[1];
  $name = stripslashes(iconv('ISO-8859-15','UTF-8',$values[2]));
  $lo = $values[3];
  switch ($lo)
  {
    case 38: $lo_num = 1; break;
    case 40: $lo_num = 2; break;
    case 39: $lo_num = 3; break;
    case 41: $lo_num = 4; break;
    case 42: $lo_num = 5; break;
    case 43: $lo_num = 6; break;
    case 44: $lo_num = 10; break;
    case 45: $lo_num = 8; break;
    case 37: $lo_num = 9; break;
    default: $lo_num = 10; break;
  }
  //echo "insert: id=$id, name=$name, lo=$lo_num\n";
  if ($count > 0)
    $query .= ',';
  $query .= "($id,'".pg_escape_string($name)."',$lo_num)";
  $count++;
}
if ($count > 0)
{
  $query .= ';';
  $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  pg_free_result($result);
}
 
}
?>
