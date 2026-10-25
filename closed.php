<?php

function sortlink($action, $sort, $text)
{
    global $make_csv;
    if ($make_csv) {
        return $text;
    }
    $filters = "";
    if (isset($_GET['filter_id']) && strlen($_GET['filter_id']) > 0) {
        $filters .= "&filter_id=" . rawurlencode($_GET['filter_id']);
    }
    if (isset($_GET['filter_bid']) && strlen($_GET['filter_bid']) > 0) {
        $filters .= "&filter_bid=" . rawurlencode($_GET['filter_bid']);
    }
    if (isset($_GET['filter_date']) && strlen($_GET['filter_date']) > 0) {
        $filters .= "&filter_date=" . rawurlencode($_GET['filter_date']);
    }
    if (isset($_GET['filter_type']) && strlen($_GET['filter_type']) > 0) {
        $filters .= "&filter_type=" . rawurlencode($_GET['filter_type']);
    }
    if (isset($_GET['filter_lo']) && strlen($_GET['filter_lo']) > 0) {
        $filters .= "&filter_lo=" . rawurlencode($_GET['filter_lo']);
    }
    if (isset($_GET['filter_member_id']) && strlen($_GET['filter_member_id']) > 0) {
        $filters .= "&filter_member_id=" . rawurlencode($_GET['filter_member_id']);
    }
    if (isset($_GET['filter_gk']) && strlen($_GET['filter_gk']) > 0) {
        $filters .= "&filter_gk=" . rawurlencode($_GET['filter_gk']);
    }
    if (isset($_GET['filter_k']) && strlen($_GET['filter_k']) > 0) {
        $filters .= "&filter_k=" . rawurlencode($_GET['filter_k']);
    }
    if (isset($_GET['filter_vk']) && strlen($_GET['filter_vk']) > 0) {
        $filters .= "&filter_vk=" . rawurlencode($_GET['filter_vk']);
    }
    if (isset($_GET['filter_amount']) && strlen($_GET['filter_amount']) > 0) {
        $filters .= "&filter_amount=" . rawurlencode($_GET['filter_amount']);
    }
    if (isset($_GET['filter_text']) && strlen($_GET['filter_text']) > 0) {
        $filters .= "&filter_text=" . rawurlencode($_GET['filter_text']);
    }
    if (isset($_GET['filter_comment']) && strlen($_GET['filter_comment']) > 0) {
        $filters .= "&filter_comment=" . rawurlencode($_GET['filter_comment']);
    }
    if (isset($_GET['filter_comm']) && strlen($_GET['filter_comm']) > 0) {
        $filters .= "&filter_comm=" . rawurlencode($_GET['filter_comm']);
    }
    if (isset($_GET['filter_ack']) && strlen($_GET['filter_ack']) > 0) {
        $filters .= "&filter_ack=" . rawurlencode($_GET['filter_ack']);
    }
    if (isset($_GET['filter_ack_old']) && strlen($_GET['filter_ack_old']) > 0) {
        $filters .= "&filter_ack_old=" . rawurlencode($_GET['filter_ack_old']);
    }
    if (isset($_GET['filter_refund']) && strlen($_GET['filter_refund']) > 0) {
        $filters .= "&filter_refund=" . rawurlencode($_GET['filter_refund']);
    }
    if (isset($_GET['filter_bel']) && strlen($_GET['filter_bel']) > 0) {
        $filters .= "&filter_bel=" . rawurlencode($_GET['filter_bel']);
    }
    if (isset($_GET['filter_name']) && strlen($_GET['filter_name']) > 0) {
        $filters .= "&filter_name=" . rawurlencode($_GET['filter_name']);
    }
    return "<a href=\"" . html_escape("index.php?action=$action&sort=" . getoppsort($sort) . $filters) . "\">$text</a>";
}
// The button that hides (N) or shows again (-N) listing column $tabc: a POST
// form with the form token (index.php stores users.hide).
function listing_column_form($action, $hide, $icon)
{
    return '<form class="inline" action="index.php?action=' . $action . '" method="post">' . csrf_field() . '<button class="link" type="submit" name="hide" value="' . $hide . '"><img class="icon" src="icons/' . $icon . '.png"></button></form>';
}
// Whether the user hid listing column $col (users.hide, POST hide=N). Columns
// without a stored preference are shown.
function listing_column_hidden($col)
{
    global $user_prefs_hide;
    return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;
}
function gentab($tabc, $action, $s, $t)
{
    global $user_prefs_hide, $make_csv;
    if ($make_csv) {
        if (listing_column_hidden($tabc)) {
            return "";
        } else {
            return "$t\t";
        }
    }
    if (listing_column_hidden($tabc)) {
        return tag("td", listing_column_form($action, -$tabc, 'add'));
    } else {
        return tag("td", tag("b", sortlink($action, $s, $t) . listing_column_form($action, $tabc, 'delete')));
    }
}
function page_listing_header($action)
{
    global $make_csv;
    block_start();
    if (!$make_csv) {
        echo '<table>';
    }
    $tabs = "";
    $tabc = 1;
    $tabs .= gentab($tabc++, $action, 'idd', 'Buchung');
    $tabs .= gentab($tabc++, $action, 'bida', 'Buchungszeile');
    $tabs .= gentab($tabc++, $action, 'datea', 'Datum');
    $tabs .= gentab($tabc++, $action, 'typea', 'Art');
    $tabs .= gentab($tabc++, $action, 'loa', 'LO');
    $tabs .= gentab($tabc++, $action, 'membera', 'Mitglied');
    $tabs .= gentab($tabc++, $action, 'gka', 'Fremdkonto');
    $tabs .= gentab($tabc++, $action, 'ka', 'Konto');
    $tabs .= gentab($tabc++, $action, 'vka', 'Virt.Konto');
    $tabs .= gentab($tabc++, $action, 'ama', 'Betrag');
    $tabs .= gentab($tabc++, $action, 'texta', 'Text');
    $tabs .= gentab($tabc++, $action, 'commenta', 'Kommentar');
    $tabs .= gentab($tabc++, $action, 'comma', 'Gewidmet');
    $tabs .= gentab($tabc++, $action, 'acka', 'Bestätigt');
    $tabs .= gentab($tabc++, $action, 'acka_old', 'Best. alt');
    $tabs .= gentab($tabc++, $action, 'refunda', 'Rück.');
    $tabs .= gentab($tabc++, $action, 'bela', 'Datei');
    $tabs .= gentab($tabc++, $action, 'namea', 'Name/Adresse');
    echo tag("tr", $tabs);
    if (!$make_csv) {
        echo '<form action="index.php" method="GET"> <tr>
<td><input type="hidden" name="action" value="' . $action . '" />
';
        if (isset($_GET['sort'])) {
            echo '<input type="hidden" name="sort" value="' . html_escape($_GET['sort']) . '" />';
        }
        $tabc = 1;
        global $user_prefs_hide;
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_id" value="' . (isset($_GET['filter_id']) ? html_escape($_GET['filter_id']) : '') . '" size="1" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_bid" value="' . (isset($_GET['filter_bid']) ? html_escape($_GET['filter_bid']) : '') . '" size="1" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_date" value="' . (isset($_GET['filter_date']) ? html_escape($_GET['filter_date']) : '') . '" size="4" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_type" value="' . (isset($_GET['filter_type']) ? html_escape($_GET['filter_type']) : '') . '" size="5" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_lo" value="' . (isset($_GET['filter_lo']) ? html_escape($_GET['filter_lo']) : '') . '" size="5" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_member_id" value="' . (isset($_GET['filter_member_id']) ? html_escape($_GET['filter_member_id']) : '') . '" size="1" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<select type="text" name="filter_gk" value="' . (isset($_GET['filter_gk']) ? html_escape($_GET['filter_gk']) : '') . '" size="1" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_k" value="' . (isset($_GET['filter_k']) ? html_escape($_GET['filter_k']) : '') . '" size="7" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_vk" value="' . (isset($_GET['filter_vk']) ? html_escape($_GET['filter_vk']) : '') . '" size="7" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_amount" value="' . (isset($_GET['filter_amount']) ? html_escape($_GET['filter_amount']) : '') . '" size="1" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_text" value="' . (isset($_GET['filter_text']) ? html_escape($_GET['filter_text']) : '') . '" size="15" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_comment" value="' . (isset($_GET['filter_comment']) ? html_escape($_GET['filter_comment']) : '') . '" size="15" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="checkbox" name="filter_comm" value="' . (isset($_GET['filter_comm']) ? html_escape($_GET['filter_comm']) : '') . '" size="1" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_ack" value="' . (isset($_GET['filter_ack']) ? html_escape($_GET['filter_ack']) : '') . '" size="3" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_ack_old" value="' . (isset($_GET['filter_ack_old']) ? html_escape($_GET['filter_ack_old']) : '') . '" size="3" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="checkbox" name="filter_refund" value="' . (isset($_GET['filter_refund']) ? html_escape($_GET['filter_refund']) : '') . '" size="1" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="checkbox" name="filter_bel" value="' . (isset($_GET['filter_bel']) ? html_escape($_GET['filter_bel']) : '') . '" size="1" />';
        }
        echo '</td><td>';
        if (!listing_column_hidden($tabc++)) {
            echo '<input type="text" name="filter_name" value="' . (isset($_GET['filter_name']) ? html_escape($_GET['filter_name']) : '') . '" size="1" />';
        }
        echo '<input style="position: absolute; visibility: hidden;" value="Filtern" type="submit" />
</td>
</tr></form>';
    }
}

function emptytag($tag)
{
    global $make_csv;
    if (!$make_csv) {
        echo tag($tag, '');
    }
}

function page_listing_line($line, $action = "edit")
{
    global $user_prefs_hide,$make_csv,$dbconn;
    if (!$make_csv) {
        echo "<tr>";
    }
    $tabc = 1;
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        if ($make_csv) {
            echo tag("td", $line["voucher_id"]);
        } else {
            echo tag("td", '<a href="index.php?action=' . $action . '&id=' . $line["voucher_id"] . '&bid=' . $line["id"] . '">' . $line["voucher_id"] . "</a>");
        }
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", $line["id"]);
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", format_date($line["date"]));
    }
    $query2 = "SELECT name FROM type WHERE id = " . intval($line["type"]);
    $result2 = db_query($dbconn, $query2);
    while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
        if (listing_column_hidden($tabc++)) {
            echo emptytag("td");
        } else {
            echo tag("td", listing_text($line2["name"]));
        }
    }
    pg_free_result($result2);
    $query2 = "SELECT name FROM lo WHERE id = " . intval($line["orga"]);
    $result2 = db_query($dbconn, $query2);
    while ($line2 = pg_fetch_array($result2, null, PGSQL_ASSOC)) {
        if (listing_column_hidden($tabc++)) {
            echo emptytag("td");
        } else {
            echo tag("td", listing_text($line2["name"]));
        }
    }
    pg_free_result($result2);
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        if ($make_csv) {
            echo tag("td", $line["member"] == 't' ? $line["member_id"] : 'Nein');
        } else {
            echo tag("td", $line["member"] == 't' ? '<a href="https://mitglieder.piratenpartei.at/adm_program/modules/profile/profile.php?user_id=' . $line["member_id"] . '">' . $line["member_id"] . '</a>' : 'Nein');
        }
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", listing_text($line["contra_account"]));
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", listing_text($line["account"]));
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", listing_text($line["vaccount"]));
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", sprintf("%1.2f", $line["amount"] / 100.0) . "€");
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", listing_text($line["comment"]));
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", listing_text($line["commentgf"]));
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", $line["committed"] == 't' ? 'Ja' : 'Nein');
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", listing_text($line["ack1"] . " " . $line["ack2"]));
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", "<del>" . listing_text($line["ack1_old"]) . "</del> <del>" . listing_text($line["ack2_old"]) . "</del>");
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", $line["refund"] == 't' ? 'Ja' : 'Nein');
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", $line["file"] != 0 ? 'Ja' : 'Nein');
    }
    if (listing_column_hidden($tabc++)) {
        echo emptytag("td");
    } else {
        echo tag("td", listing_text($line["name"] . ' ' . $line["street"] . ' ' . $line["plz"] . ' ' . $line["city"]));
    }
    if ($make_csv) {
        echo "\n";
    } else {
        echo "</tr>";
    }
}

function page_closed($rights)
{
    global $dbconn;
    page_listing_header('closed');
    $rightssql = rights2orgasql($rights);
    $filter = getfilter();
    $sort = getsort();
    $query = "SELECT anyfile AS file, vouchers.id, vouchers.voucher_id, vouchers.type, vouchers.orga, vouchers.member, vouchers.member_id, vouchers.contra_account, vouchers.name, vouchers.street, vouchers.plz, vouchers.city, vouchers.amount, vouchers.account, vouchers.vaccount, vouchers.comment, vouchers.committed, vouchers.receipt_received, vouchers.deleted, vouchers.date, vouchers.ack1, vouchers.ack2, vouchers.ack1_old, vouchers.ack2_old, vouchers.person_type, vouchers.save_date, vouchers.save_user, vouchers.commentgf, vouchers.refund, lo.name AS lo_name, type.name AS type_name FROM vouchers LEFT JOIN lo ON vouchers.orga = lo.id LEFT JOIN type ON vouchers.type = type.id LEFT JOIN (SELECT voucher_id,1 as anyfile FROM vouchers F WHERE F.file != 0 AND F.ack1 IS NOT NULL AND F.ack2 IS NOT NULL GROUP BY voucher_id) F ON F.voucher_id = vouchers.voucher_id WHERE NOT deleted AND ack1 IS NOT NULL AND ack2 IS NOT NULL $rightssql $filter ORDER BY $sort";
    $result = db_query($dbconn, $query);
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        page_listing_line($line);
    }
    pg_free_result($result);
    global $make_csv;
    if (!$make_csv) {
        echo '</table>';
    }
    block_end();
    csv_download_link();
}
