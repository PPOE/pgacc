<?php

function spendings_page_listing_header($action)
{
    block_start();
    global $make_csv;
    if (!$make_csv) {
        echo '<table>';
    }
    echo tag_raw("tr", tag_raw("td", tag_raw("b", sortlink($action, 'idd', 'Buchung')))
    . tag_raw("td", tag_raw("b", sortlink($action, 'datea', 'Datum')))
    . tag_raw("td", tag_raw("b", sortlink($action, 'typea', 'Art')))
    . tag_raw("td", tag_raw("b", sortlink($action, 'loa', 'LO')))
    // The text column was removed for legal reasons.
    . tag_raw("td", tag_raw("b", sortlink($action, 'ama', 'Betrag')))
    . tag_raw("td", tag_raw("b", sortlink($action, 'ama', 'Name'))));
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
    // One cell also without a matching row (empty cell).
    $line2 = pg_fetch_array($result2, null, PGSQL_ASSOC) ?: ['name' => ''];
    echo tag("td", str_replace(['Spenden (mit Ausnahme der von lebenden Subventionen und Sachspenden)'], ['Spenden'], $line2["name"]));
    pg_free_result($result2);
    $query2 = "SELECT name FROM lo WHERE id = " . intval($line["orga"]);
    $result2 = db_query($dbconn, $query2);
    $line2 = pg_fetch_array($result2, null, PGSQL_ASSOC) ?: ['name' => ''];
    echo tag("td", $line2["name"]);
    pg_free_result($result2);
    // The text column was removed for legal reasons.

    echo tag("td", format_amount($line["amount"], "€"));
    if ($line["refund"] != 't' && $line["type"] != "1029") {
        echo tag("td", $line["name"]);
    } else {
        echo tag_raw("td", wrap("i", "Privatperson"));
    }
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
