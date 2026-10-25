<?php

function date_condition($year, $begin = 1)
{
    if ($begin == 0)
        return "AND date < '" . (intval($year) + 1) . "-01-01'";
    else return " AND date >= '" . $year . "-01-01' AND date < '" . (intval($year) + 1) . "-01-01'";
}
function page_donations($year)
{
    global $dbconn;
    $captcha_check = true;
    if ($year < 2012) {
        $captcha_check = false;
        $year = 2013;
    }
    echo "<h1>Spendenliste der Piratenpartei Österreichs $year</h1>\n";
    echo '<div class="wiki motd"><h2>';
    for ($i = 2012; $i <= intval(date('Y')); $i++) {
        echo "<a href=\"donations?year=$i\">$i</a> ";
    }
    echo '</h2>';
    //echo 'Gemäß <a href="https://lqfb.piratenpartei.at/initiative/show/1151.html">Beschluss i1151 vom 28.12.2012</a> veröffentlichen wir alle Spenden ab 100€ pro Jahr und Spender.';
    echo 'Aus rechtlichen Gründen werden Spenden nur mit Zustimmung Veröffentlicht.';
    echo'</div>';
    if (checklogin('id', false) > 0 || (isset($_POST['captcha_input']) && isset($_POST['captcha']) && intval($_POST['captcha_input']) == intval($_POST['captcha']) + 37)) {
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
        $query = "SELECT name,SUM(amount) AS sum FROM vouchers WHERE " . eyes() . " $donation_condition " . date_condition($year) . " GROUP BY name;";
        $result = pg_query($dbconn, $query) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
        // The donor list is not published for legal reasons; no row is shown.
        pg_free_result($result);
        echo '</table>';
        block_end();
    } else if ($captcha_check) {
        block_start();
        $a = rand(1, 50);
        $b = rand(1, 50);
        $c = $a + $b - 37;
        echo <<<END
Bevor die Spenderliste angezeigt wird, musst du ein Rätsel lösen, um zu beweisen, dass du kein Roboter bist:
<div class="main" id="default"><form class="login" action="donations?year=$year" method="POST"><div><label for="captcha_input" class="ui_field_label">Was ergibt die Addition $a + $b?</label> <input id="captcha_input" name="captcha_input" value="" type="text"></div><input value="$c" name="captcha" type="hidden"><input value="Bestätigen" name="submit" type="submit"></form></div>
END;
        block_end();
    }
    echo "<br /><br /><br />";
}
?>
