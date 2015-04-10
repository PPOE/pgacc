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
        page_import2_process_multi_ids($rights, $i, $text);
        continue;
      }
      /*echo "<br /><pre>";
      print_r($text);
      echo "</pre><br />";*/

      $result = preg_match_all("@[A-Z]{2}/\d{9}@", $text, $matches);
      if ($result < 1) {
        echo "Keine IDs gefunden.<br />";
        continue;
      }
      if ($result > 1) {
        echo "Mehrere IDs gefunden.<br />";
        page_import2_process_multi_ids($rights, $i, $text);
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

      page_import2_match_voucher($rights, $i, $id, $date);
    }

  }
  block_end();
}

function page_import2_process_multi_ids($rights, $i, $text)
{
  $text = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $text);

  $result = preg_match("@(vom)[0-9]{2}\.[0-9]{2}\.([0-9]{4})@", $text, $matches);
  if ($result < 1) {
    echo "Keine Jahreszahl gefunden.<br />";
    return;
  }
  $year = $matches[2];
  $result = preg_match("@[A-Z]{2}\d{16,18}@", $text, $matches);
  if ($result < 1) {
    echo "Kein IBAN gefunden.<br />";
    return;
  }
  $iban = $matches[0];

  /* ZinsenHABEN */
  $result = preg_match_all("@([0-9]{2}\.[0-9]{2})(ZinsenHABEN)([A-Z]{2}/\d{9})@", $text, $matches);
  if ($result < 1) {
    echo "Keine IDs and Daten gefunden für Spezialfälle.<br />";
  } else {
    $dates = $matches[1];
    $ids = $matches[3];

    for ($j = 0; $j < count($dates); $j++) {
      $date = format_date($dates[$j] . '.' . $year);
      page_import2_match_voucher($rights, $i, $ids[$j], $date);
    }
  }

  /* EntgeltfürKontoführung|EntgeltfürBuchungspostenundBelege */
  $result = preg_match_all("@([0-9]{2}\.[0-9]{2})(Entgeltf.+r(Kontof.+hrung|BuchungspostenundBelege))@U", $text, $matches);
  if ($result < 1) {
    echo "Keine IDs and Daten gefunden für Spezialfälle.<br />";
    return;
  }
  $dates = $matches[1];

  for ($j = 0; $j < count($dates); $j++) {
    $date = format_date($dates[$j] . '.' . $year);
    page_import2_match_voucher($rights, $i, 'Entgelt für Kontoführung', $date, $iban);
    page_import2_match_voucher($rights, $i, 'Entgelt für Buchungsposten und Belege', $date, $iban);
  }
}

function page_import2_match_voucher($rights, $i, $id, $date, $account = null)
{
  $query = "SELECT * FROM vouchers WHERE comment LIKE '%" . $id . "%' AND date = date '" . $date . " 00:00:00' AND NOT deleted AND (ack1 IS NULL OR ack2 IS NULL)";
  if ($account !== null)
  {
    $query .= "AND account LIKE '%" . $account . "%'";
  }
  $query .= ";";
  $result = pg_query($query) or die('Abfrage ('.$query.') fehlgeschlagen: ' . pg_last_error());
  $lineCount = 0;
  while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    $voucher = $line;
    $lineCount++;
  }
  pg_free_result($result);
  if ($lineCount === 0) {
    echo "Keine Buchung gefunden.<br />";
    return;
  } elseif ($lineCount > 1) {
    echo "Zuviele Buchungen gefunden.<br />";
    return;
  }
  if (intval($voucher['file']) !== 0) {
    echo "Has file attached.<br />";
    return;
  }

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

  vouchers_reset_ack($id, $rightssql);

  $query = "UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND voucher_id = $id $rightssql;";
  $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  }
  pg_free_result($result);

  echo 'done: <a href="index.php?action=edit&id=' . $voucher["voucher_id"] . '&bid='.$voucher["id"].'">' . $voucher["voucher_id"] . "</a><br />";
}

function page_import2($rights)
{
  page_import2_process($rights);

  page_import2_form();
}
?>
