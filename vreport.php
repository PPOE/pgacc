<?php

function v_graph($konto, $date_begin, $date_end)
{
    global $dbconn;
    if ($konto === "") {
        return;
    }

    echo '<script src="js/chart.umd.min.js"></script>' . "\n";
    echo '<script src="js/vreport-chart.js"></script>' . "\n";

    $cond = " AND vaccount LIKE '$konto%' ";
    if (strpos($konto, ",") !== false) {
        $konto = "'" . implode("','", explode(",", $konto)) . "'";
        $cond = " AND vaccount IN ($konto) ";
    }
    if ($date_begin)
        $cond .= " AND date >= '$date_begin' ";
    if ($date_end)
        $cond .= " AND date <= '$date_end' ";

    $query = "SELECT extract(epoch FROM date) * 1000 AS timestamp, SUM(amount) / 100.0, (SELECT SUM(amount) / 100.0 FROM vouchers WHERE date <= v.date AND NOT deleted $cond ) AS runningtotal FROM vouchers v WHERE NOT deleted $cond GROUP BY date ORDER BY date;";
    $result = pg_query($dbconn, $query) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
    //$array = pg_fetch_all($result);
    $array = [];
    while ($line = pg_fetch_array($result)) {
        $array[] = [$line['timestamp'], $line['runningtotal']];
    }
    echo '<script type="application/json" id="graph-data">' . json_encode($array, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>' . "\n";
    echo '<div id="graph" style="position: relative; width: 100%; height: 400px;"><canvas id="graph-canvas"></canvas></div>';
}

function v_report($konto, $date_begin, $date_end)
{
    global $realtype_req,$dbconn;
    $cond = " AND vaccount LIKE '$konto%' ";
    if (strpos($konto, ",") !== false) {
        $konto = "'" . implode("','", explode(",", $konto)) . "'";
        $cond = " AND vaccount IN ($konto)";
    }
    if ($date_begin)
        $cond .= " AND date >= '$date_begin' ";
    if ($date_end)
        $cond .= " AND date <= '$date_end' ";
    echo '
<table><tr><td>
<h3>Einnahmen</h3>
<table>
';
    $query = "SELECT id,name FROM type WHERE income = 1 $realtype_req ORDER BY realtype ASC,id ASC";
    $result = pg_query($dbconn, $query) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        echo "<tr><td>" . html_escape($line['name']) . "</td><td>";
        $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = {$line['id']} " . $cond;
        $result2 = pg_query($dbconn, $query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
        while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
            echo sprintf("%1.2f", $line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
        }
        pg_free_result($result2);
    }
    pg_free_result($result);
    echo "<tr><td><b>Summe</b></td><td>";
    $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype IN (SELECT id FROM type WHERE income = 1) " . $cond;
    $result2 = pg_query($dbconn, $query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
    while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
        echo sprintf("%1.2f", $line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
    }
    pg_free_result($result2);
    echo '
</table>
</td>
<td>
<h3>Ausgaben</h3>
<table>
';
    $query = "SELECT id,name FROM type WHERE income <= 0 $realtype_req ORDER BY realtype ASC,id ASC";
    $result = pg_query($dbconn, $query) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        echo "<tr><td>" . html_escape($line['name']) . "</td><td>";
        $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = {$line['id']} " . $cond;
        $result2 = pg_query($dbconn, $query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
        while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
            echo sprintf("%1.2f", $line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
        }
        pg_free_result($result2);
    }
    pg_free_result($result);
    echo "<tr><td><b>Summe</b></td><td>";
    $query2 = "SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype IN (SELECT id FROM type WHERE income <= 0) " . $cond;
    $result2 = pg_query($dbconn, $query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
    while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
        echo sprintf("%1.2f", $line2['sum'] / 100.0) . "&nbsp;€</td></tr>\n";
    }
    pg_free_result($result2);
    echo '
</table>
</td>
</tr>
</table>
';
}

function page_vreport($rights)
{
    global $dbconn;
    //if (strlen($rights) > 0)
    //{
    $query2 = "SELECT vaccount FROM vouchers WHERE NOT deleted AND amount != 0 GROUP BY vaccount ORDER BY vaccount";
    $result2 = pg_query($dbconn, $query2) or die('Abfrage fehlgeschlagen: ' . pg_last_error($dbconn));
    echo "<ul>";
    while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
        $k = $line2['vaccount'] === null ? '' : $line2['vaccount'];
        if ($rights == '' && is_numeric(str_replace([" ","AT"], ["",""], $k))) {
            $k = substr($line2['vaccount'], -3, 3);
        }
        echo "<li><a href=\"" . html_escape("vreport?konto=" . rawurlencode($k)) . "\">Konto '" . html_escape($k) . "'</a></li>";
    }
    echo "</ul>";
    pg_free_result($result2);
    //}
    $konto = "";
    $date_begin = "";
    $date_end = "";
    if (isset($_GET["konto"]) && preg_match('/^[ A-Z0-9-,]+$/i', $_GET["konto"]) == 1)
        $konto = $_GET["konto"];
    if (isset($_GET["date_begin"]) && preg_match('/^[0-9]+-[0-9]+-[0-9]+$/i', $_GET["date_begin"]) == 1)
        $date_begin = $_GET["date_begin"];
    if (isset($_GET["date_end"]) && preg_match('/^[0-9]+-[0-9]+-[0-9]+$/i', $_GET["date_end"]) == 1)
        $date_end = $_GET["date_end"];
    echo "<h1>Bericht Konto $konto</h1><br>";
    block_start();
    v_graph($konto, $date_begin, $date_end);
    v_report($konto, $date_begin, $date_end);
    block_end();

}
?>
