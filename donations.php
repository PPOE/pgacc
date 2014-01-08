<?php
function date_condition($year,$begin = 1)
{
  if ($begin == 0)
    return "AND date < '".(intval($year)+1)."-01-01'";
  else
    return "AND date >= '".$year."-01-01' AND date < '".(intval($year)+1)."-01-01'";
}
// from http://www.php.net/manual/de/function.htmlspecialchars.php
function umlaute($text){
  $returnvalue="";
    for($i=0;$i<strlen($text);$i++){
        $teil=hexdec(rawurlencode(substr($text, $i, 1)));
        if($teil<32||$teil>1114111){
            $returnvalue.=substr($text, $i, 1);
        }else{
            $returnvalue.="&#".$teil.";";
        }
    }
    return $returnvalue;
} 
function page_donations($year)
{
  $captcha_check = true;
  if ($year < 2012)
  {
    $captcha_check = false;
    $year = 2013;
  }
echo "<h1>Spendenliste der Piratenpartei Österreichs $year</h1>\n";
echo '<div class="wiki motd"><h2>';
for ($i = 2012; $i <= intval(date('Y')); $i++)
{
echo "<a href=\"/acc/donations?year=$i\">$i</a> ";
}
echo '</h2>';
  echo 'Gemäß <a href="https://lqfb.piratenpartei.at/initiative/show/1151.html">Beschluss i1151 vom 28.12.2012</a> veröffentlichen wir alle Spenden ab 100€ pro Jahr und Spender.';
echo'</div>';
  if (isset($_POST['captcha_input']) && isset($_POST['captcha']) && intval($_POST['captcha_input']) == intval($_POST['captcha']) + 37)
{
echo '<script type="text/javascript">
function base64_decode (data) {
  // http://kevin.vanzonneveld.net
  // +   original by: Tyler Akins (http://rumkin.com)
  // +   improved by: Thunder.m
  // +      input by: Aman Gupta
  // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
  // +   bugfixed by: Onno Marsman
  // +   bugfixed by: Pellentesque Malesuada
  // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
  // +      input by: Brett Zamir (http://brett-zamir.me)
  // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
  // mozilla has this native
  // - but breaks in 2.0.0.12!
  var b64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";
  var o1, o2, o3, h1, h2, h3, h4, bits, i = 0,
    ac = 0,
    dec = "",
    tmp_arr = [];

  if (!data) {
    return data;
  }

  data += "";

  do { // unpack four hexets into three octets using index points in b64
    h1 = b64.indexOf(data.charAt(i++));
    h2 = b64.indexOf(data.charAt(i++));
    h3 = b64.indexOf(data.charAt(i++));
    h4 = b64.indexOf(data.charAt(i++));

    bits = h1 << 18 | h2 << 12 | h3 << 6 | h4;

    o1 = bits >> 16 & 0xff;
    o2 = bits >> 8 & 0xff;
    o3 = bits & 0xff;

    if (h3 == 64) {
      tmp_arr[ac++] = String.fromCharCode(o1);
    } else if (h4 == 64) {
      tmp_arr[ac++] = String.fromCharCode(o1, o2);
    } else {
      tmp_arr[ac++] = String.fromCharCode(o1, o2, o3);
    }
  } while (i < data.length);

  dec = tmp_arr.join("");

  return dec;
}
</script>
';
block_start();
echo '
<table id="donations" style="min-width: 50%" border="1">
<tr><td><b>Name</b></td><td><b>Spendensumme</b></td></tr>
';
$donation_condition = "AND type = 8";
$query = "SELECT name,SUM(amount) AS sum FROM vouchers WHERE ".eyes()." $donation_condition ".date_condition($year)." GROUP BY name;";
$result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
$count = 0;
while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
if ($line['sum'] <= 10000) { continue; }
//if ($year == 2012 && $line['sum'] <= 0) { continue; }
$donations[] = $line;
$count++;
}
pg_free_result($result);

if ($count > 0)
{
foreach ($donations as $d)
{
$sort_sum[] = $d['sum'];
$sort_name[] = $d['name'];
}

array_multisort($sort_sum, SORT_DESC, $sort_name, SORT_ASC, $donations);
$i = 0;
foreach ($donations as $line)
{
echo "<tr>";
echo '<td id="s'.$i.'"></td>';
echo '<script type="text/javascript">document.getElementById("s'.$i.'").innerHTML = base64_decode("'.base64_encode(htmlentities($line["name"],ENT_COMPAT,'UTF-8')).'");</script>';
echo tag("td", $line["sum"] / 100.0 . '€');
echo "</tr>";
$i++;
}
}
echo '</table>';
block_end();
}
else if ($captcha_check)
{
  block_start();
  $a = rand(1,50);
  $b = rand(1,50);
  $c = $a + $b - 37;
  echo <<<END
Bevor die Spenderliste angezeigt wird, musst du ein Rätsel lösen, um zu beweisen, dass du kein Roboter bist:
<div class="main" id="default"><form class="login" action="/acc/donations?year=$year" method="POST"><div><label for="captcha_input" class="ui_field_label">Was ergibt die Addition $a + $b?</label> <input id="captcha_input" name="captcha_input" value="" type="text"></div><input value="$c" name="captcha" type="hidden"><input value="Bestätigen" name="submit" type="submit"></form></div>
END;
  block_end();
}
echo "<br /><br /><br />";
}
?>
