<?php
function page_import2_form()
{
  block_start();
  ?>
  Bitte wähle die Beleg Dateien für den Import:
  <form enctype="multipart/form-data" action="index.php?action=import2" method="POST">
    <input type="hidden" name="MAX_FILE_SIZE" value="10000000" />
    <input name="upload[]" type="file" multiple="multiple" /><br />
    <br />
    <input type="submit" value="Importieren" />
  </form>
  <?php
  block_end();
}

function page_import2_process($rights)
{
  $mimetypes_whitelist = array(
    'application/pdf'
  );

  block_start();
  for($i=0; $i < count($_FILES['upload']['name']); $i++)
  {
    echo "Datei ".($i+1)." (".$_FILES['upload']['name'][$i]."):<br />";
    if (strlen($_FILES['upload']['name'][$i]) <= 0)
    {
      continue;
    }
    if (strlen($_FILES['upload']['type'][$i]) <= 0 || (!in_array($_FILES['upload']['type'][$i], $mimetypes_whitelist)))
    {
      echo "Hat ein ungültiges Dateiformat ({$_FILES['upload']['type'][$i]}) und konnte daher nicht importiert werden.<br />";
      continue;
    }
    if ($_FILES['upload']['error'][$i] != 0)
    {
      echo "Wurde nicht erfolgreich hochgeladen und konnte daher nicht importiert werden.<br />";
      continue;
    }
    if ($_FILES['upload']['size'][$i] < 10)
    {
      echo "Iist zu klein und konnte daher nicht importiert werden.<br />";
      continue;
    }

    if (($handle = fopen($_FILES['upload']['tmp_name'][$i], "r")) !== FALSE)
    {
      $contents = fread($handle, filesize($_FILES['upload']['tmp_name'][$i]));
      fclose($handle);

      $parser = new \Smalot\PdfParser\Parser();
      $pdf = $parser->parseContent($contents);
      $text = $pdf->getText();
      $text = str_replace(' ', '', $text);

      $result = preg_match("@NeuerKontostandzuIhrenGunsten@", $text, $matches);
      if ($result > 0) {
        echo "Ist ein Übersichtsdeckblatt.<br />";
        continue;
      }

      $result = preg_match_all("@[A-Z]{2}/\d{9}@", $text, $matches);
      if ($result !== 1) {
        echo "Keine oder mehrere IDs gefunden.<br />";
        continue;
      }
      $id = $matches[0][0];
      echo "Eine ID ({$id}) gefunden.<br />";

      $result = preg_match_all("@[0-9]{2}\.[0-9]{2}\.[0-9]{4}@", $text, $matches);
      if ($result == 0) {
        echo "Kein Datum gefunden.<br />";
        continue;
      }
      if ($result > 2) {
        echo "Zuviele Daten gefunden gefunden.<br />";
        continue;
      }
      $date = $matches[0][intval($result) - 1];
      $date = format_date($date);

      echo "Ein Datum ({$date}) gefunden.<br />";

      /*echo "<br /><pre>";
      print_r($text);
      echo "</pre><br />";*/

      $query = "SELECT * FROM vouchers WHERE comment LIKE '%" . $id . "%' AND date = date '" . $date . " 00:00:00' AND NOT deleted AND (ack1 IS NULL OR ack2 IS NULL);";
      $result = pg_query($query) or die('Abfrage ('.$query.') fehlgeschlagen: ' . pg_last_error());
      $lineCount = 0;
      while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        $voucher = $line;
        $lineCount++;
      }
      pg_free_result($result);
      if ($lineCount === 0) {
        echo "Keine Buchung gefunden.<br />";
        continue;
      } elseif ($lineCount > 1) {
        echo "Zuviele Buchungen gefunden.<br />";
        continue;
      }
      if (intval($voucher['file']) !== 0) {
        echo "Has file attached.<br />";
        continue;
      }
      /*echo "<br /><pre>";
      print_r($voucher);
      echo "</pre><br />";*/

      $id = $voucher["voucher_id"];
      $bid = $voucher["id"];

      $rightssql = rights2orgasql($rights);

      $query = "SELECT nextval('file_number') AS num;";
      $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
      while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        $file_number = $line['num'];
      }
      pg_free_result($result);

      $sourcepath = $_FILES["upload"]["tmp_name"][$i];
      $targetpath = getcwd() . '/files/' . $file_number . ".aes";
      $data = file_get_contents($sourcepath);
      file_put_contents($targetpath,mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($id . $key . $id), $data, MCRYPT_MODE_CBC, md5($key . $id)));

      $query = "UPDATE vouchers SET ack1 = NULL,ack2 = NULL WHERE NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL) AND voucher_id = $id $rightssql;";
      $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
      while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
      }
      pg_free_result($result);
      $query = "UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND voucher_id = $id $rightssql;";
      $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
      while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
      }
      pg_free_result($result);

      echo 'done: <a href="index.php?action=edit&id=' . $voucher["voucher_id"] . '&bid='.$voucher["id"].'">' . $voucher["voucher_id"] . "</a><br />";
    }

  }
  block_end();
}

function page_import2($rights)
{
  page_import2_process($rights);

  page_import2_form();
}
?>
