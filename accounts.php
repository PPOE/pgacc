<?php
function page_accounts($rights)
{
 // Read once: an own password change ends the session (users.cookie), and
 // the page still lists the own account afterwards.
 $ownId = checklogin('id');
 if (isset($_POST['submit']))
  {
    $parts = explode(" ", $_POST['submit']);
    if (count($parts) == 3)
    {
      $id = intval($parts[1]);
      $rights = write_rights($rights);
      if ($rights === false || $id != $ownId && !has_right($rights, 'root'))
      {
        echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
        return;
      }
      $pass = isset($_POST["password$id"]) ? pg_escape_string($_POST["password$id"]) : '';
      $name = isset($_POST["name$id"]) ? pg_escape_string($_POST["name$id"]) : '';
      $nrights = isset($_POST["rights$id"]) ? pg_escape_string($_POST["rights$id"]) : '';
      if (has_right($rights, 'root') && $id == -1)
        if ($pass != "******")
          $query = "INSERT INTO users (name,hash,rights) VALUES ('$name', crypt('$pass', gen_salt('md5')), '$nrights');";
        else
          $query = "SELECT 1";
      else if (!has_right($rights, 'root') && $pass != "******")
        $query = "UPDATE users SET hash = crypt('$pass', gen_salt('md5')), cookie = NULL WHERE id = $id";
      else if (has_right($rights, 'root')) 
        $query = "UPDATE users SET rights = '$nrights'".($pass != "******" ?", hash = crypt('$pass', gen_salt('md5')), cookie = NULL":"")." WHERE id = $id";
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
if (has_right($rights, 'root'))
{
  echo '<p>1: Burgenland, 2: Kärnten, 3: Niederösterreich, 4: Oberösterreich, 5: Salzburg, 6: Steiermark, 8: Vorarlberg, 9: Wien, 10: Bund (only), bgf: Alles</p>';
  echo '<p>Ein vorgestelltes R erzeugt einen read only Zugang. Beispiel: Rbgf. root hat Möglichkeit Buchungen zu mergen, sowie gelöschte Buchungszeilen wiederherzustellen</p>';
}
  block_start();
echo '<form action="index.php?action=accounts" method="POST"><table>
<tr><td><b>Name</b></td><td><b>Passwort</b></td>'.(has_right($rights, 'root') ? '<td><b>Rechte</b></td>':'').'</tr>
';
$id = $ownId;
$restrict = "";
if (!has_right($rights, 'root'))
{
  $restrict = " WHERE id = $id ";
}
$query = "SELECT * FROM users $restrict ORDER BY rights DESC,id ASC";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  $i = $line['id'];
  echo '<tr><td>'.html_escape($line['name']).'</td><td><input type="password" name="password'.$i.'" value="******"></td>'.(has_right($rights, 'root') ? '<td><input type="text" name="rights'.$i.'" value="'.html_escape($line['rights']).'" /></td>':'').'<td><input type="submit" name="submit" value="Benutzer '.$i.' aktualisieren" /></td></tr>'."\n";
}
pg_free_result($result);
if (has_right($rights, 'root'))
  echo '<tr><td><input type="text" name="name-1" value="" /></td><td><input type="password" name="password-1" value="******"></td><td><input type="text" name="rights-1" value="" /></td><td><input type="submit" name="submit" value="Benutzer -1 erstellen" /></td></tr>'."\n";
echo '
</table></form>
';
block_end();

}
?>
