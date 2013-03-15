<?php
function page_accounts($rights)
{
 if (isset($_POST['submit']))
  {
    $parts = explode(" ", $_POST['submit']);
    if (count($parts) == 3)
    {
      $id = intval($parts[1]);
      if ($id != checklogin('id') && strpos($rights,'root') === false)
      {
        echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
        return;
      }
      $pass = isset($_POST["password$id"]) ? pg_escape_string($_POST["password$id"]) : '';
      $name = isset($_POST["name$id"]) ? pg_escape_string($_POST["name$id"]) : '';
      $nrights = isset($_POST["rights$id"]) ? pg_escape_string($_POST["rights$id"]) : '';
      if (strpos($rights,'root') !== false && $id == -1)
        if ($pass != "******")
          $query = "INSERT INTO users (name,hash,rights) VALUES ('$name', crypt('$pass', gen_salt('md5')), '$rights');";
        else
          $query = "SELECT 1";
      else if (strpos($rights,'root') === false && $pass != "******")
        $query = "UPDATE users SET hash = crypt('$pass', gen_salt('md5')) WHERE id = $id";
      else if (strpos($rights,'root') !== false) 
        $query = "UPDATE users SET rights = '$nrights'".($pass != "******" ?", hash = crypt('$pass', gen_salt('md5'))":"")." WHERE id = $id";
      else
      {
        echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
        return;
      }
      $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error() . $query);
      while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
      }
      pg_free_result($result);

    }
  }

  echo '<h1>Benutzerverwaltung:</h1><br>';
  block_start();
echo '<form action="index.php?action=accounts" method="POST"><table>
<tr><td><b>Name</b></td><td><b>Passwort</b></td>'.(strpos($rights,'root') !== false ? '<td><b>Rechte</b></td>':'').'</tr>
';
$id = checklogin("id");
$restrict = "";
$i = 0;
if (strpos($rights,'root') === false)
{
  $restrict = " WHERE id = $id ";
  $i = intval($id) - 1;
}
$query = "SELECT * FROM users $restrict ORDER BY id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $i++;
  echo '<tr><td>'.$line['name'].'</td><td><input type="password" name="password'.$i.'" value="******"></td>'.(strpos($rights,'root') !== false ? '<td><input type="text" name="rights'.$i.'" value="'.$line['rights'].'" /></td>':'').'<td><input type="submit" name="submit" value="Benutzer '.$i.' aktualisieren" /></td></tr>'."\n";
}
pg_free_result($result);
if (strpos($rights,'root') !== false)
  echo '<tr><td><input type="text" name="name-1" value="" /></td><td><input type="password" name="password-1" value="******"></td><td><input type="text" name="rights-1" value="" /></td><td><input type="submit" name="submit" value="Benutzer -1 erstellen" /></td></tr>'."\n";
echo '
</table></form>
';
block_end();

}
?>
