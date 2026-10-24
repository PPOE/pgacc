<?php
function getUsers()
{
global $datakey,$dbconn;
// Certificate verification stays on (PHP defaults). The member list is
// replaced only by a complete, valid response, in one transaction; on any
// error the previous ppmembers table stays as it was. ignore_errors: an HTTP
// error status is checked here instead of raising a warning (a replaced
// stream wrapper, as in the unit tests, sends no headers).
$context = stream_context_create(array('http' => array('ignore_errors' => true)));
$sData = @file_get_contents("https://mitglieder.piratenpartei.at/adm_api/adm_names.php", false, $context);
$headers = http_get_last_response_headers();
if ($sData === false || (is_array($headers) && !preg_match('#^HTTP/\S+ 200 #', $headers[0])))
  die('Mitgliederliste konnte nicht abgerufen werden!');
if (strlen($datakey) == 0)
  die('Kein Passwort!');

if (strlen($sData) < 1000)
  die('Daten von Mitgliederverwaltung zu kurz!');

$decrypted = rtrim(membership_decrypt($datakey, base64_decode($sData)), "\0");
$query = 'INSERT INTO ppmembers (id, name, lo, paid) VALUES ';
$lines = explode("\n",$decrypted);
$count = 0;
foreach ($lines as $line)
{
  if (strlen($line) < 5) { continue; }
  $values = explode("\t",$line);
  // A line without the five fields means a damaged or undecryptable list.
  if (count($values) < 5)
    die('Daten von Mitgliederverwaltung ungültig!');
  $id = intval($values[1]);
  $name = stripslashes(iconv('ISO-8859-15','UTF-8',$values[2]));
  $lo = $values[3];
  $paid = intval($values[4]);
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
  $query .= "($id,'".pg_escape_string($dbconn, $name)."',$lo_num,$paid)";
  $count++;
}
if ($count == 0)
  die('Daten von Mitgliederverwaltung ungültig!');
$query .= ';';

// Inside a caller's transaction (the unit tests) a savepoint, so that the
// caller's transaction is neither committed nor ended.
$nested = pg_transaction_status($dbconn) != PGSQL_TRANSACTION_IDLE;
$queries = array(
  $nested ? 'SAVEPOINT getusers;' : 'BEGIN;',
  'DROP TABLE IF EXISTS ppmembers;',
  'CREATE TABLE ppmembers (id integer, name text, lo integer, paid integer);',
  $query,
  $nested ? 'RELEASE SAVEPOINT getusers;' : 'COMMIT;',
);
// pg_send_query(): an SQL error is reported with die() below, not as a
// warning.
foreach ($queries as $query)
{
  pg_send_query($dbconn, $query);
  $result = pg_get_result($dbconn);
  // One statement, one result: this ends the query.
  pg_get_result($dbconn);
  if (pg_result_status($result) == PGSQL_FATAL_ERROR)
  {
    $error = pg_result_error($result);
    pg_query($dbconn, $nested ? 'ROLLBACK TO SAVEPOINT getusers;' : 'ROLLBACK;');
    die('Abfrage fehlgeschlagen: ' . $error);
  }
  pg_free_result($result);
}
 
}
?>
