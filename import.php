<?php
function page_import()
{
  print_r($_FILES['uploadcsv']);
  getusers();
  block_start();
  echo "<table>\n";
  for ($i = 0; $i < count($_FILES['uploadcsv']['name']); $i++)
  {
    if (strlen($_FILES['uploadcsv']['name'][$i]) <= 0)
    {
      continue;
    }
    if (strlen($_FILES['uploadcsv']['type'][$i]) <= 0 || $_FILES['uploadcsv']['type'][$i] != "text/comma-separated-values")
    {
      echo "Datei ".($i+1)." (".$_FILES['uploadcsv']['name'][$i].") hat ein ungültiges Dateiformat und konnte daher nicht importiert werden.<br />";
      continue;
    }
    if ($_FILES['uploadcsv']['error'][$i] != 0)
    {
      echo "Datei ".($i+1)." (".$_FILES['uploadcsv']['name'][$i].") wurde nicht erfolgreich hochgeladen und konnte daher nicht importiert werden.<br />";
      continue;
    }
    if ($_FILES['uploadcsv']['size'][$i] < 10)
    {
      echo "Datei ".($i+1)." (".$_FILES['uploadcsv']['name'][$i].") ist zu klein und konnte daher nicht importiert werden.<br />";
      continue;
    }
      if (($handle = fopen($_FILES['uploadcsv']['tmp_name'][$i],"r")) !== FALSE) {
        echo "<h2>Importiere " . $_FILES['uploadcsv']['name'][$i] . "</h2>\n"; 
        $voucher_number = 0;
        while (($data = fgetcsv($handle, 0, ";")) !== FALSE)
        {
          $csvline = pg_escape_string(iconv('ISO-8859-15','UTF-8',implode(";",$data)));
          $query = "SELECT 1 FROM import WHERE line = '{$csvline}';";
          $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
          if (pg_fetch_array($result, null, PGSQL_ASSOC)) {
             continue;
          }
          pg_free_result($result);
          
          $query = "SELECT nextval('voucher_number') AS num;";
          $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
          while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $voucher_number = $line['num'];
          }
          pg_free_result($result);
          $voucher['konto'] = $data[0];
          switch ($voucher['konto'])
          {
            case '50110117270':
              $voucher['lo'] = 1;
              break;
            case '50110117300':
              $voucher['lo'] = 2;
              break;
            case '50110117318':
              $voucher['lo'] = 3;
              break;
            case '50110117326':
              $voucher['lo'] = 4;
              break;
            case '50110117350':
              $voucher['lo'] = 5;
              break;
            case '50110117369':
              $voucher['lo'] = 6;
              break;
            case '50110117393':
              $voucher['lo'] = 8;
              break;
            case '10110123642':
              $voucher['lo'] = 9;
              break;
            case '50110110437':
              $voucher['lo'] = 10;
              break;
            default:
              echo "Ungültige Buchungszeile in Datei {($i+1)} ({$_FILES['uploadcsv']['name'][$i]}) <br />\n";
              continue;
          }
          $voucher['gegenkonto'] = '';
          $voucher['name'] = '';
          $voucher['member'] = 'false';
          $voucher['mitgliedsnummer'] = 0;
          $voucher['street'] = '';
          $voucher['plz'] = '';
          $voucher['city'] = '';
          $voucher['purpose'] = 'false';
          $voucher['receipt'] = 'false';
          $voucher['comment'] = pg_escape_string(str_replace("|","\n",iconv('ISO-8859-15','UTF-8',$data[1])));
          $c = explode("\n",$voucher['comment']);
          if (count($c) >= 2 && preg_match('/^([A-Z0-9]+ [A-Z][A-Z][0-9]+) (.*[A-Z]+.*)$/i', $c[1], $matches) == 1)
          {
            $voucher['gegenkonto'] = $matches[1];
            $voucher['name'] = $matches[2];
          }
          elseif (count($c) >= 2 && preg_match('/^(.+ .+) ([^\d+] [^\d]+)$/', $c[1], $matches) == 1)
          {
            $voucher['gegenkonto'] = $matches[2];
            $voucher['name'] = $matches[1];
          }
          elseif (count($c) >= 2 && preg_match('/^(\d+ \d+) ([^\d]+ [^\d]+)$/', $c[1], $matches) == 1)
          {
            $voucher['gegenkonto'] = $matches[1];
            $voucher['name'] = $matches[2];
          }
          $voucher['in_type'] = 14;
          if (strlen($voucher['name']) > 0)
          {
            $n = $voucher['name'];
            $n2 = trim(str_replace(array('Mag.','DI (FH)','iur.','Dipl.-Ing.','Dr.','Ing.'),array('','','','','',''),$n));
            $nt = explode(" ",$n,2);
            $nt2 = explode(" ",$n2,2);
            $queries = array();
            $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('$n');";
            if (count($nt) == 2)
              $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('{$nt[1]} {$nt[0]}');";
            if ($n != $n2)
            {
              $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('$n2');";
              if (count($nt2) == 2)
                $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('{$nt2[1]} {$nt2[0]}');";
            }
            $found = false;
            foreach ($queries as $query)
            {
              if ($result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error()))
              {
                if (pg_num_rows($result) == 1)
                {
                  $line = pg_fetch_array($result, null, PGSQL_ASSOC);
                  $voucher['member'] = 'true';
                  $voucher['mitgliedsnummer'] = intval($line['id']);
                  $voucher['lo'] = $line['lo'];
                  $voucher['in_type'] = 1;
                  pg_free_result($result);
                  $found = true;
                  break;
                }
                pg_free_result($result);
              }
            }
            if (!$found)
            {
              // no member!
              $voucher['in_type'] = 8;
            }
          }
          $voucher['date'] = format_date($data[2]);
          $voucher['amount'] = str_replace(',','',$data[4]);
          $voucher['dir'] = $voucher['amount'] > 0 ? 'in' : 'out';
          $voucher['out_type'] = 28;
          $query = "INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES ($voucher_number, '{$voucher['date']}', ".($voucher['dir'] == "in"?$voucher['in_type']:$voucher['out_type']).",{$voucher['lo']},{$voucher['member']},{$voucher['mitgliedsnummer']},'{$voucher['gegenkonto']}','{$voucher['name']}','{$voucher['street']}','{$voucher['plz']}','{$voucher['city']}',{$voucher['amount']},'{$voucher['konto']}','{$voucher['comment']}',{$voucher['purpose']},{$voucher['receipt']})";
          //echo str_replace("\n","<br />",$query)."<br />";
          echo "Buchung $voucher_number erstellt!<br />\n";
          $result = pg_query($query) or die('Abfrage ('.$query.') fehlgeschlagen: ' . pg_last_error());
          while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
          }
          pg_free_result($result);

          $query = "INSERT INTO import (line) VALUES ('{$csvline}');";
          $result = pg_query($query) or die('Abfrage ('.$query.') fehlgeschlagen: ' . pg_last_error());#
          pg_free_result($result);
        }
        fclose($handle);
        echo "Datei ".($i+1)." (".$_FILES['uploadcsv']['name'][$i].") wurde erfolgreich importiert<br />\n";
      }
  }
  echo "</table>\n";
  echo 'Bite wähle die CSV Dateien für den Import:
<form enctype="multipart/form-data" action="index.php?action=import" method="POST">
<input type="hidden" name="MAX_FILE_SIZE" value="100000" />
';
for ($i = 1; $i < 15; $i++)
{
  echo '<div id="f'.$i.'"'.($i > 1?' style="display: none;"':'').'> Datei '.$i.': <input onclick="javascript:document.getElementById(\'f'.($i+1).'\').style.display=\'block\';" name="uploadcsv[]" type="file" /></div><br />';
}
echo '
<input type="submit" value="Importieren" />
</form>';
  block_end();
  $query = 'DROP TABLE IF EXISTS ppmembers;';
  $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  pg_free_result($result);
}
?>
