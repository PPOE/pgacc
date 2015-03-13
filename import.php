<?php
function convif($do,$data)
{
  if (!$do)
    return $data;
  return iconv('ISO-8859-15','UTF-8',$data);
}
function page_import()
{
  getusers();
  block_start();
  echo "<table>\n";
  $csv_mimetypes = array(
    'text/csv',
    'text/plain',
    'application/csv',
    'text/comma-separated-values',
    'application/excel',
    'application/vnd.ms-excel',
    'application/vnd.msexcel',
    'text/anytext',
    'application/octet-stream',
    'application/txt',
    'application/x-csv'
  );

  for ($i = 0; $i < count($_FILES['uploadcsv']['name']); $i++)
  {
    if (strlen($_FILES['uploadcsv']['name'][$i]) <= 0)
    {
      continue;
    }
    if (strlen($_FILES['uploadcsv']['type'][$i]) <= 0 || (!in_array($_FILES['uploadcsv']['type'][$i],$csv_mimetypes)))
    {
      echo "Datei ".($i+1)." (".$_FILES['uploadcsv']['name'][$i].") hat ein ungültiges Dateiformat ({$_FILES['uploadcsv']['type'][$i]}) und konnte daher nicht importiert werden.<br />";
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
    $csvformat = 'BAWAG';
      if (($handle = fopen($_FILES['uploadcsv']['tmp_name'][$i],"r")) !== FALSE) {
        echo "<h2>Importiere " . $_FILES['uploadcsv']['name'][$i] . "</h2>\n"; 
        $voucher_number = 0;
        while (($data = fgetcsv($handle, 0, ";")) !== FALSE)
        {
          $voucher2 = array();
          $dovoucher2 = false;
          $csvline = implode(";",$data);
          $convert = false;
          if (mb_detect_encoding($csvline, 'UTF-8', true) === false)
            $convert = true;
          $rawcsvline = $csvline;
          $csvline = pg_escape_string(str_replace(array('|',' ',"\n","\t","\r"),array('','','','',''),convif($convert,$csvline)));
          if ($csvline == 'Bezeichnung;Valutadatum;Betrag;Währung;Buchungsdatum;Umsatzzeile1;Zusatztext;Auftraggeber;Kundendaten/Zahlungsreferenz;Umsatzzeile2;Ersterfassungsreferenz;AuftraggeberKontonr./IBAN;AuftraggeberBLZ/BIC;Partnername;PartnerKontonr./IBAN;PartnerBLZ/BIC')
            $csvformat = 'Sparkasse';
          if ($csvline == 'Datum,Zeit,Zeitzone,Name,Art,Status,Währung,Brutto,Gebühr,Netto,VonE-Mail-Adresse,AnE-Mail-Adresse,Transaktionscode,StatusderGegenpartei,Adressstatus,Verwendungszweck,Artikelnummer,BetragfürVersandkosten,Versicherungsbetrag,Umsatzsteuer,Option1-Name,Option1-Wert,Option2-Name,Option2-Wert,Auktions-Site,Käufer-ID,Artikel-URL,Angebotsende,Vorgangs-Nr.,Rechnungs-Nr.,Txn-Referenzkennung,Rechnungsnummer,IndividuelleNummer,Bestätigungsnummer,Guthaben,Adresse,ZusätzlicheAngaben,Ort,Staat/Provinz/Region/Landkreis/Territorium/Präfektur/Republik,PLZ,Land,TelefonnummerderKontaktperson,')
            $csvformat = 'Paypal';
          $handkassacsvline = 'Datum;Betrag;Konto;Text;Kommentar;Fremdkonto;Zweckwidmung;Kostenrückerstattung;Name;Mitgliedsnummer;Lo;Adresse;PLZ;Stadt';
          if ($csvline == $handkassacsvline)
            $csvformat = 'Handkassa';
          $query = "SELECT 1 FROM import WHERE line = '{$csvline}';";
          $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
          if (pg_fetch_array($result, null, PGSQL_ASSOC)) {
             continue;
          }
          pg_free_result($result);
          //echo str_replace("\n","<br />",print_r($data,1));
          if ($csvformat == 'BAWAG')
          {
          $voucher['konto'] = $data[0];
          switch ($voucher['konto'])
          {
            case 'AT606000050110117300':
              $voucher['lo'] = 2;
              break;
            case 'AT596000050110117318':
              $voucher['lo'] = 3;
              break;
            case 'AT376000050110117326':
              $voucher['lo'] = 4;
              break;
            case 'AT686000050110117350':
              $voucher['lo'] = 5;
              break;
            case 'AT406000050110117369':
            case 'AT716000020810070316':
            case 'AT496000020810070324':
              $voucher['lo'] = 6;
              break;
            case 'AT716000050110117393':
              $voucher['lo'] = 8;
              break;
            case 'AT856000010110123642':
              $voucher['lo'] = 9;
              break;
            case 'AT916000050110110437':
              $voucher['lo'] = 10;
              break;
            default:
              echo "Ungültige Buchungszeile in Datei ".($i+1)." ({$_FILES['uploadcsv']['name'][$i]}) <br />\n";
              echo "<pre>".$rawcsvline."</pre><br />\n";
              echo "<pre>".$csvline."</pre><br />\n";
              echo "Das erkannte CSV Format ist $csvformat.<br />\n";
              echo "Falls du eine Handkassaeingabe tätigen möchtest geht das mit folgendem Format:<br />\n";
              echo "<pre>".$handkassacsvline."</pre><br />\n";
              return -1;
          }
          $voucher['comment'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[1])));
          $voucher['date'] = format_date($data[2]);
          $voucher['amount'] = intval(str_replace(array('.', ','), array('',''), $data[4]));
          $voucher['gegenkonto'] = '';
          $voucher['name'] = '';
          }
          elseif ($csvformat == 'Handkassa')
          {
            if (intval(str_replace(array('.', ','), array('',''), $data[1])) == 0)
              continue;
            $voucher['date'] = format_date($data[0]);
            if (strpos($data[1],".") !== false || strpos($data[1],",") !== false)
              $voucher['amount'] = intval(str_replace(array('.', ','), array('',''), $data[1]));
            else
              $voucher['amount'] = intval(str_replace(array('.', ','), array('',''), $data[1])) * 100;
            $voucher['konto'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[2])));
            $voucher['comment'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[3])));
            $voucher['commentgf'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[4])));
            $voucher['gegenkonto'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[5])));
            $voucher['committed'] = ($data[6] == 'Ja' || $data[6] == 'Wahr' || $data[6] == '1') ? 'true' : 'false';
            $voucher['refund'] = ($data[7] == 'Ja' || $data[7] == 'Wahr' || $data[7] == '1') ? 'true' : 'false';
            $voucher['name'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[8])));
            $voucher['mitgliedsnummer'] = intval($data[9]);
            if ($voucher['mitgliedsnummer'] != 0)
            {
              $voucher['mitglied'] = 'true';
              $voucher['person_type'] = 2;
            }
            $voucher['lo'] = intval($data[10]) == 0 ? 10 : intval($data[10]);
            $voucher['street'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[11])));
            $voucher['city'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[13])));
            $voucher['plz'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[12])));
          }
          elseif ($csvformat == 'Sparkasse')
          {
            if (intval(str_replace(array('.', ','), array('',''), $data[2])) == 0)
              continue;
            $voucher['lo'] = 6;
            $voucher['konto'] = '20815 6659403';
            $voucher['comment'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[5] . "; " . $data[6] . "; " . $data[7])));
            $voucher['date'] = format_date($data[4]);
            $voucher['amount'] = intval(str_replace(array('.', ','), array('',''), $data[2]));
            $voucher['gegenkonto'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[14] . " " . $data[15])));
            $voucher['name'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[13])));
          }
          elseif ($csvformat == 'Paypal')
          {
            $data = str_getcsv($data[0]);
            if (intval(str_replace(array('.', ','), array('',''), $data[7])) == 0)
              continue;
            $voucher['lo'] = 10;
            $voucher['konto'] = 'spende@piratenpartei.at';
            $cmt = "";
            if ($data[15] != '')
              $cmt .= convif($convert,$data[15]) . "; ";
            $cmt .= "Transaktion: " . convif($convert,$data[12]) . "; ";
            $cmt .= "Brutto: " . convif($convert,$data[7]) . "€; ";
            $cmt .= "Gebühr: " . convif($convert,$data[8]) . "€; ";
            $cmt .= convif($convert,$data[3]);
            $voucher['comment'] = pg_escape_string($cmt);
            $voucher['date'] = format_date($data[0]);
            $voucher['amount'] = intval(str_replace(array('.', ','), array('',''), $data[7]));
            $voucher['gegenkonto'] = pg_escape_string(str_replace("|","\n",convif($convert,($data[10] == 'spende@piratenpartei.at') ? $data[11] : $data[10])));
            $voucher['name'] = pg_escape_string(str_replace("|","\n",convif($convert,$data[3])));
            $voucher2['lo'] = 10;
            $voucher2['konto'] = 'spende@piratenpartei.at';
            $voucher2['comment'] = pg_escape_string($cmt);
            $voucher2['date'] = format_date($data[0]);
            $voucher2['amount'] = intval(str_replace(array('.', ','), array('',''), $data[8]));
            $voucher2['gegenkonto'] = "";
            $voucher2['name'] = 'Paypal';
            $voucher2['person_type'] = 1;
            $voucher2['member'] = 'false';
            $voucher2['mitgliedsnummer'] = 0;
            $voucher2['type'] = 20;
            $voucher2['street'] = '';
            $voucher2['plz'] = '';
            $voucher2['city'] = '';
            $voucher2['purpose'] = 'false';
            $voucher2['receipt'] = 'false';
            $dovoucher2 = true;
          }
          else
          {
            die("Unbekanntes CSV Format\n");
          }
          $query = "SELECT nextval('voucher_number') AS num;";
          $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
          while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $voucher_number = $line['num'];
          }
          pg_free_result($result);
          $voucher['person_type'] = 1;
          $voucher['member'] = 'false';
          $voucher['mitgliedsnummer'] = 0;
          $voucher['street'] = '';
          $voucher['plz'] = '';
          $voucher['city'] = '';
          $voucher['purpose'] = 'false';
          $voucher['receipt'] = 'false';
          if ($voucher['gegenkonto'] == '' && $voucher['name'] == '')
          {
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
          }
          $voucher['type'] = 14;
          if (strlen($voucher['name']) == 0)
          {
            $csvline_esc = pg_escape_string($csvline);
            $query = "SELECT * FROM ppmembers WHERE '$csvline_esc' ILIKE '%' || name || '%'";
            if ($result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error()))
            {
              if (pg_num_rows($result) == 1)
              {
                $line = pg_fetch_array($result, null, PGSQL_ASSOC);
                $voucher['member'] = 'true';
                $voucher['mitgliedsnummer'] = intval($line['id']);
                $voucher['person_type'] = 2;
                $voucher['type'] = 1;
                $voucher['name'] = $line['name'];
                pg_free_result($result);
                $found = true;
                break;
              }
            }
          }
          if (strpos($voucher['comment'],'MB') !== false ||
              strpos($voucher['comment'],'Mitgliedsbeitrag') !== false)
          {
            $voucher['type'] = 1;
            $voucher['member'] = 'true';
            $voucher['person_type'] = 2;
          }
          if ($voucher['mitgliedsnummer'] == 0 && strlen($voucher['name']) > 0)
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
                  $voucher['person_type'] = 2;
                  $voucher['type'] = 1;
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
              $voucher['type'] = 8;
            }
          }
          if ($voucher['amount'] < 0) { $voucher['type'] = 28; }
          
          echo "Buchung $voucher_number erstellt!<br />\n";
          //print_r($voucher);
          
          $query = "INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES ($voucher_number, '{$voucher['date']}', {$voucher['type']},{$voucher['lo']},{$voucher['member']},{$voucher['mitgliedsnummer']},'{$voucher['gegenkonto']}','{$voucher['name']}','{$voucher['street']}','{$voucher['plz']}','{$voucher['city']}',{$voucher['amount']},'{$voucher['konto']}','{$voucher['comment']}',{$voucher['purpose']},{$voucher['receipt']})";
          $result = pg_query($query) or die('Abfrage ('.$query.') fehlgeschlagen: ' . pg_last_error());
          while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
          }
          pg_free_result($result);

          if ($dovoucher2)
          {
            $query = "INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES ($voucher_number, '{$voucher2['date']}', {$voucher2['type']},{$voucher2['lo']},{$voucher2['member']},{$voucher2['mitgliedsnummer']},'{$voucher2['gegenkonto']}','{$voucher2['name']}','{$voucher2['street']}','{$voucher2['plz']}','{$voucher2['city']}',{$voucher2['amount']},'{$voucher2['konto']}','{$voucher2['comment']}',{$voucher2['purpose']},{$voucher2['receipt']})";
            $result = pg_query($query) or die('Abfrage ('.$query.') fehlgeschlagen: ' . pg_last_error());
            while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            }
            pg_free_result($result);
          }

          $query = "INSERT INTO import (line) VALUES ('{$csvline}');";
          $result = pg_query($query);
          pg_free_result($result);
        }
        fclose($handle);
        echo "Datei ".($i+1)." (".$_FILES['uploadcsv']['name'][$i].") wurde erfolgreich importiert<br />\n";
      }
  }
  echo "</table>\n";
  echo 'Bite wähle die CSV Dateien für den Import:
<form enctype="multipart/form-data" action="index.php?action=import" method="POST">
<input type="hidden" name="MAX_FILE_SIZE" value="1000000" />
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
