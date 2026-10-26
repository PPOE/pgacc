<?php

function spendings_page_listing_header($action)
{
    block_start();
    global $make_csv;
    if (!$make_csv) {
        echo '<table>';
    }
    echo tag("tr", tag("td", tag("b", sortlink($action, 'idd', 'Buchung')))
    . tag("td", tag("b", sortlink($action, 'datea', 'Datum')))
    . tag("td", tag("b", sortlink($action, 'typea', 'Art')))
    . tag("td", tag("b", sortlink($action, 'loa', 'LO')))
    // removed for legal reasons.
    //tag("td",tag("b",sortlink($action,'texta','Text'))) .
    . tag("td", tag("b", sortlink($action, 'ama', 'Betrag')))
    . tag("td", tag("b", sortlink($action, 'ama', 'Name'))));
}

function spendings_page_listing_line($line)
{
    global $make_csv,$dbconn;
    if (!$make_csv) {
        echo "<tr>";
    }
    echo tag("td", $line["voucher_id"]);
    echo tag("td", format_date($line["date"]));
    $query2 = "SELECT name FROM type WHERE id = " . intval($line["type"]);
    $result2 = db_query($dbconn, $query2);
    while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
        echo tag("td", listing_text(str_replace(['Spenden (mit Ausnahme der von lebenden Subventionen und Sachspenden)'], ['Spenden'], $line2["name"])));
    }
    pg_free_result($result2);
    $query2 = "SELECT name FROM lo WHERE id = " . intval($line["orga"]);
    $result2 = db_query($dbconn, $query2);
    while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
        echo tag("td", listing_text($line2["name"]));
    }
    pg_free_result($result2);
    // removed for legal reasons
    //echo tag("td", ($line["refund"] != 't') ? preg_replace(array('/((BG|FE|ZE|VB|OG|IG)\/\d{9}|\d{5}.\d+)/'),array(''),preg_replace(array('/((BG|FE|ZE|VB|OG|IG)\/\d+(\s\d+)+)|(((BG|FE|ZE|VB|OG|IG)\/\d+)?[A-Z]{8} [A-Z]{2}\d+)/'),array(" <i>Kontodaten</i> "),$line["comment"])) : '<i>Kostenrückerstattung</i>');

    echo tag("td", format_amount($line["amount"], "€"));
    echo tag("td", ($line["refund"] != 't' && $line["type"] != "1029") ? listing_text($line["name"]) : "<i>Privatperson</i>");
    if ($make_csv) {
        echo "\n";
    } else {
        echo "</tr>";
    }
}

function page_spendings()
{
    global $dbconn;
    echo "<h1>Ausgabenliste der Piratenpartei Österreichs</h1>\n";
    $type = -1;
    if (isset($_GET["type"]) && preg_match('/^\d+$/', $_GET["type"]) == 1) {
        $type = $_GET["type"];
    }
    $unit = -1;
    if (isset($_GET["unit"]) && preg_match('/^-?\d+$/', $_GET["unit"]) == 1) {
        $unit = $_GET["unit"];
    }
    $from = -1;
    if (isset($_GET["from"]) && preg_match('/^\d+-\d+-\d+$/', $_GET["from"]) == 1) {
        $from = $_GET["from"];
    }
    $to = -1;
    if (isset($_GET["to"]) && preg_match('/^\d+-\d+-\d+$/', $_GET["to"]) == 1) {
        $to = $_GET["to"];
    }

    $where = "";
    if ($type != -1) {
        $where .= " AND type = $type";
    }
    if ($unit != -1) {
        $where .= " AND orga = $unit";
    }
    if ($from != -1) {
        $where .= " AND date >= '$from'";
    }
    if ($to != -1) {
        $where .= " AND date < '$to'";
    }
    spendings_page_listing_header('spendings');
    $sort = getsort();
    $query = "SELECT * FROM vouchers WHERE " . eyes() . /*" AND (SELECT SUM(amount) FROM vouchers B WHERE ".eyes()." AND B.voucher_id = vouchers.voucher_id) < 0*/" AND amount < 0 $where ORDER BY $sort";
    $result = db_query($dbconn, $query);
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        spendings_page_listing_line($line);
    }
    pg_free_result($result);
    global $make_csv;
    if (!$make_csv) {
        echo '</table>';
    }
    block_end();
    csv_download_link();
}
