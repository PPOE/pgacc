<?php

// Only a year from 1 to 9999 (an int or its digits) builds a condition, with
// four-digit years (PostgreSQL reads '1-01-01' as 2001); any other value
// matches nothing instead of building invalid SQL.
function date_condition($year, $begin = 1)
{
    if (!(is_int($year) || is_string($year) && preg_match('/^\d+$/D', $year) == 1) || intval($year) < 1 || intval($year) > 9999) {
        return ' AND false';
    }
    if ($begin == 0) {
        return sprintf("AND date < '%04d-01-01'", intval($year) + 1);
    } else {
        return sprintf(" AND date >= '%04d-01-01' AND date < '%04d-01-01'", intval($year), intval($year) + 1);
    }
}
// from http://www.php.net/manual/de/function.htmlspecialchars.php
function umlaute($text)
{
    $returnvalue = "";
    for ($i = 0;$i < strlen($text);$i++) {
        $teil = hexdec(rawurlencode(substr($text, $i, 1)));
        if ($teil < 32 || $teil > 1114111) {
            $returnvalue .= substr($text, $i, 1);
        } else {
            $returnvalue .= "&#" . $teil . ";";
        }
    }
    return $returnvalue;
}
function page_donations($year)
{
    global $dbconn;
    $captcha_check = true;
    if ($year < 2012 || $year > 9999) {
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
        block_start();
        echo '
<table id="donations" style="min-width: 50%" border="1">
<tr><td><b>Name</b></td><td><b>Spendensumme</b></td></tr>
';
        $donation_condition = "AND type = 8";
        $query = "SELECT name,SUM(amount) AS sum FROM vouchers WHERE " . eyes() . " $donation_condition " . date_condition($year) . " GROUP BY name;";
        $result = db_query($dbconn, $query);
        // The donor list is not published for legal reasons; no row is shown.
        pg_free_result($result);
        echo '</table>';
        block_end();
    } elseif ($captcha_check) {
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
