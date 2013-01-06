<?php
function page_import()
{
  block_start();
  echo "<table>\n";
  $path = getcwd() . '/csv/';
  if ($handledir = opendir($path)) {
    while (false !== ($file = readdir($handledir))) {
      if (preg_match("/^.*\.csv$/",$file) > 0 && ($handle = fopen($path . $file,"r")) !== FALSE) {
        $voucher_number = 0;
        while (($data = fgetcsv($handle, 0, ";")) !== FALSE)
        {
        /*$query = "SELECT nextval('voucher_number') AS num;";
        $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
          while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $voucher_number = $line['num'];
          }
          pg_free_result($result);*/
          $voucher_number++;
          $voucher['konto'] = $data[0];
//        $voucher['comment'] = pg_escape_string(mb_convert_encoding(str_replace('|',"\n",$data[1]),'ISO-8859-15','UTF-8'));
          $voucher['comment'] = pg_escape_string(str_replace("|","\n",iconv('ISO-8859-15','UTF-8',$data[1])));
          $voucher['date'] = format_date($data[2]);
          $voucher['amount'] = intval(str_replace(',','',$data[4]));
          $voucher['dir'] = $voucher['amount'] > 0 ? 'in' : 'out';
          $voucher['in_type'] = 0;
          $voucher['out_type'] = 28;
          switch ($voucher['konto'])
          {
            case '50110117270':
              $voucher['lo'] = 1;
            case '50110117300':
              $voucher['lo'] = 2;
            case '50110117318':
              $voucher['lo'] = 3;
            case '50110117326':
              $voucher['lo'] = 4;
            case '50110117350':
              $voucher['lo'] = 5;
            case '50110117369':
              $voucher['lo'] = 6;
            case '50110117393':
              $voucher['lo'] = 8;
            case '10110123642':
              $voucher['lo'] = 9;
            default:
              $voucher['lo'] = 10;
          }
          $voucher['member'] = 'false';
          $voucher['mitgliedsnummer'] = 0;
          $voucher['gegenkonto'] = 0;
          $voucher['name'] = 'TODO';
          $voucher['street'] = 'TODO';
          $voucher['plz'] = 'TODO';
          $voucher['city'] = 'TODO';
          $voucher['purpose'] = 'false';
          $voucher['ack'] = 'false';
          $voucher['receipt'] = 'false';
          $query = "INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, acknowledged, receipt_received) VALUES ($voucher_number, '{$voucher['date']}', ".($voucher['dir'] == "in"?$voucher['in_type']:$voucher['out_type']).",{$voucher['lo']},{$voucher['member']},{$voucher['mitgliedsnummer']},{$voucher['gegenkonto']},'{$voucher['name']}','{$voucher['street']}','{$voucher['plz']}','{$voucher['city']}',{$voucher['amount']},{$voucher['konto']},'{$voucher['comment']}',{$voucher['purpose']},{$voucher['ack']},{$voucher['receipt']})";
          echo str_replace("\n","<br />",$query)."<br />";
          /*$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
          while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
          }
          pg_free_result($result);*/

        }
        fclose($handle);
      }
      break;
    }
    closedir($handledir);
  }
  echo "</table>\n";
  block_end();
}
?>
