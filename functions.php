<?php

function hex2ascii($hex)
{
    $ascii = '';
    $hex = str_replace(" ", "", $hex);
    for ($i = 0; $i < strlen($hex); $i = $i + 2) {
        $ascii .= chr(hexdec(substr($hex, $i, 2)));
    }
    return($ascii);
}

function ascii2hex($ascii)
{
    $hex = '';
    for ($i = 0; $i < strlen($ascii); $i++) {
        $byte = strtoupper(dechex(ord($ascii[$i])));
        $byte = str_repeat('0', 2 - strlen($byte)) . $byte;
        $hex .= $byte . " ";
    }
    $hex = str_replace(" ", "", $hex);
    return $hex;
}

function csv_download_link($i = -1)
{
    global $make_csv;
    if ($make_csv) {
        return;
    }
    $link = $_SERVER['REQUEST_URI'] . (str_contains($_SERVER['REQUEST_URI'], '?') ? "&" : "?") . "format=csv";
    echo "<br style=\"clear: both;\" /><center>";
    if ($i >= 0) {
        echo "$i Umsätze gefunden<br /><br />";
    }
    echo "<a href=\"" . html_escape($link) . "\">Als CSV Datei herunterladen</a></center>";
}
function getfilter()
{
    $filter = "";
    if (is_string($_GET['filter_id'] ?? null) && preg_match('/^\d+$/', $_GET['filter_id']) == 1) {
        $filter .= " AND voucher_id = " . $_GET['filter_id'];
    }
    if (is_string($_GET['filter_bid'] ?? null) && preg_match('/^\d+$/', $_GET['filter_bid']) == 1) {
        $filter .= " AND id = " . $_GET['filter_bid'];
    }
    if (is_string($_GET['filter_date'] ?? null)) {
        if (preg_match('/^\d\d\d\d$/', $_GET['filter_date']) == 1) {
            $filter .= " AND date >= '" . $_GET['filter_date'] . "-01-01' AND date <= '" . $_GET['filter_date'] . "-12-31' ";
        } elseif (preg_match('/^\d\d\d\d-\d\d$/', $_GET['filter_date']) == 1) {
            $filter .= " AND date >= '" . $_GET['filter_date'] . "-01' AND date < TIMESTAMP '" . $_GET['filter_date'] . "-01' + INTERVAL '1 month' ";
        } elseif (preg_match('/^\d\d\d\d-\d\d-\d\d$/', $_GET['filter_date']) == 1) {
            $filter .= " AND date = '" . $_GET['filter_date'] . "' ";
        }
    }
    if (is_string($_GET['filter_type'] ?? null) && preg_match('/^\d+$/', $_GET['filter_type']) == 1) {
        $filter .= " AND type = " . $_GET['filter_type'];
    } elseif (is_string($_GET['filter_type'] ?? null) && preg_match('/^[äöüÄÖÜa-z0-9,. ]+$/i', $_GET['filter_type']) == 1) {
        $filter .= " AND lower(type.name) LIKE lower('%" . $_GET['filter_type'] . "%')";
    }
    if (is_string($_GET['filter_lo'] ?? null) && preg_match('/^\d+$/', $_GET['filter_lo']) == 1) {
        $filter .= " AND orga = " . $_GET['filter_lo'];
    }
    if (is_string($_GET['filter_lo'] ?? null) && preg_match('/^[äöüÄÖÜa-z0-9,. ]+$/i', $_GET['filter_lo']) == 1) {
        $filter .= " AND lower(lo.name) LIKE lower('%" . $_GET['filter_lo'] . "%')";
    }
    if (isset($_GET['filter_member'])) {
        $filter .= " AND member ";
    }
    if (is_string($_GET['filter_member_id'] ?? null) && preg_match('/^\d+$/', $_GET['filter_member_id']) == 1) {
        $filter .= " AND member_id = " . $_GET['filter_member_id'];
    }
    if (is_string($_GET['filter_gk'] ?? null) && preg_match('/^[@äöüÄÖÜa-z0-9,. ]+$/i', $_GET['filter_gk']) == 1) {
        $filter .= " AND contra_account LIKE '" . $_GET['filter_gk'] . "%'";
    }
    if (is_string($_GET['filter_amount'] ?? null) && preg_match('/^(>|>=|=|<|<=) *-?\d+?$/', $_GET['filter_amount']) == 1) {
        $filter .= " AND amount " . $_GET['filter_amount'] . "00";
    } elseif (is_string($_GET['filter_amount'] ?? null) && preg_match('/^(>|>=|=|<|<=) *-?\d+((\.|,)(\d\d)?)?$/', $_GET['filter_amount']) == 1) {
        $filter .= " AND amount " . str_replace([".",","], ["",""], $_GET['filter_amount']);
    } elseif (is_string($_GET['filter_amount'] ?? null) && preg_match('/^*-?\d+((\.|,)(\d\d)?)?$/', $_GET['filter_amount']) == 1) {
        $filter .= " AND amount = " . str_replace([".",","], ["",""], $_GET['filter_amount']);
    }
    if (is_string($_GET['filter_k'] ?? null) && preg_match('/^[@äöüÄÖÜa-z0-9,. ]+$/i', $_GET['filter_k']) == 1) {
        $filter .= " AND account LIKE '" . $_GET['filter_k'] . "%'";
    }
    if (is_string($_GET['filter_vk'] ?? null) && preg_match('/^[@äöüÄÖÜa-z0-9,. ]+$/i', $_GET['filter_vk']) == 1) {
        $filter .= " AND vaccount LIKE '" . $_GET['filter_vk'] . "%'";
    }
    if (is_string($_GET['filter_text'] ?? null) && preg_match('/^[@äöüÄÖÜa-z0-9,. ]+$/i', $_GET['filter_text']) == 1) {
        $filter .= " AND lower(comment) LIKE lower('%" . $_GET['filter_text'] . "%')";
    }
    if (is_string($_GET['filter_comment'] ?? null) && preg_match('/^[äöüÄÖÜa-z0-9,. ]+$/i', $_GET['filter_comment']) == 1) {
        $filter .= " AND lower(commentgf) LIKE lower('%" . $_GET['filter_comment'] . "%')";
    }
    if (isset($_GET['filter_comm'])) {
        $filter .= " AND committed ";
    }
    if (is_string($_GET['filter_ack'] ?? null) && preg_match('/^[äöüÄÖÜa-z0-9 ]+$/i', $_GET['filter_ack']) == 1) {
        $filter .= " AND (ack1 LIKE '%" . $_GET['filter_ack'] . "%' OR ack2 LIKE '%" . $_GET['filter_ack'] . "%') ";
    }
    if (is_string($_GET['filter_ack_old'] ?? null) && preg_match('/^[äöüÄÖÜa-z0-9 ]+$/i', $_GET['filter_ack_old']) == 1) {
        $filter .= " AND (ack1_old LIKE '%" . $_GET['filter_ack_old'] . "%' OR ack2_old LIKE '%" . $_GET['filter_ack_old'] . "%') ";
    }
    if (isset($_GET['filter_refund'])) {
        $filter .= " AND refund ";
    }
    if (isset($_GET['filter_bel'])) {
        $filter .= " AND file != 0 ";
    }
    if (is_string($_GET['filter_name'] ?? null) && preg_match('/^[äöüÄÖÜa-z0-9 ]+$/i', $_GET['filter_name']) == 1) {
        $filter .= " AND lower(vouchers.name) LIKE lower('%" . $_GET['filter_name'] . "%')";
    }
    return $filter;
}
// A listing cell or element: $text is plain text, encoded for HTML (the CSV
// export writes it unchanged apart from the cell rules in tag_raw()).
function tag($tag, $text)
{
    return tag_raw($tag, listing_text($text));
}
// Like tag(), but $text is markup (links, forms, nested tags) and is written
// as is; the caller encodes every value in it.
function tag_raw($tag, $text)
{
    global $make_csv;
    if ($make_csv) {
        $text = str_replace(["\r\n", "\n", "\r"], [' / ', ' / ', ' / '], $text ?? '');
        // A tab would split the tab-separated cell.
        if ($tag == 'td') {
            $text = str_replace(["\t"], [' '], $text);
        }
        if (preg_match('/^-?\d+(\.\d+)?€$/', $text) == 1) {
            $text = str_replace(["."], [","], $text);
        }
        // A cell that a spreadsheet would read as a formula or a quoted field (not
        // a number), also after leading whitespace, starts with a single quote.
        elseif ($tag == 'td' && preg_match('/^[ \t\n\r\f\x0B]*[=+\-@"]/', $text) == 1 && preg_match('/^-?\d+([.,]\d+)?$/', $text) != 1) {
            $text = "'" . $text;
        }
        if ($tag == 'tr') {
            return "$text\n";
        } elseif ($tag == 'td') {
            return "$text\t";
        } else {
            return "$text";
        }
    }
    return "<$tag>$text</$tag>\n";
}
// Text and attribute values in HTML: & < > " ' as entities. ISO-8859-1 makes
// htmlspecialchars() byte-transparent for the UTF-8 pages on every PHP
// version (UTF-8 would drop invalid input on PHP 5.3), so umlauts and all
// other text stay unchanged.
function html_escape($text)
{
    return htmlspecialchars(strval($text), ENT_QUOTES, 'ISO-8859-1');
}
// Listing cells, shared by the HTML page and the CSV export (no entities).
function listing_text($text)
{
    global $make_csv;
    if ($make_csv) {
        return $text;
    }
    return html_escape($text);
}
function acc_header($dbconn, $page = "index", $year = 2015, $navYear = null)
{
    echo '
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
';
    switch ($page) {
        case "donations":
            echo '<title>Spenden - Piratenpartei Österreichs</title>
  <meta name="description" content="Aktuelle Spendenlisten der Piratenpartei Österreichs" />
  <meta name="keywords" content="spenden, spendenliste" />';
            break;
        case "kdonations":
            echo '<title>Sachspenden - Piratenpartei Österreichs</title>
  <meta name="description" content="Liste aller Sachspenden welche die Piratenpartei Österreichs erhalten hat" />
  <meta name="keywords" content="sachspenden, spenden, spendenliste, sachspendenliste" />';
            break;
        case "spendings":
            echo '<title>Ausgaben - Piratenpartei Österreichs</title>
  <meta name="description" content="Liste aller Ausgaben welche die Piratenpartei Österreichs getätigt hat" />
  <meta name="keywords" content="ausgaben, ausgabenliste" />';
            break;
        case "wk":
            echo '<title>Wahlkampfkosten - Piratenpartei Österreichs</title>
  <meta name="description" content="Wahlkampfabrechnungen der Piratenpartei Österreichs" />
  <meta name="keywords" content="wahlkampf, wahlkampfkosten, wahlkampfabrechnung" />';
            break;
        case "transactions":
            echo '<title>Kontobewegungen - Piratenpartei Österreichs</title>
  <meta name="description" content="Liste aller Kontobewegungen der Piratenpartei Österreichs" />
  <meta name="keywords" content="konto, einsicht, kontenbewegungen, transaktionen" />';
            break;
        default:
            echo '<title>Rechenschaftsbericht - Piratenpartei Österreichs</title>
  <meta name="description" content="Der vorläufige Rechenschaftsbericht der Piratenpartei Österreichs" />
  <meta name="keywords" content="einnahmen, ausgaben, rechenschaftsbericht, spenden, spendenliste, sachspenden, wahlkampf, wahlkampfabrechnung, piratenpartei, österreichs, österreich" />';
            break;
    }
    echo '
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<link rel="stylesheet" type="text/css" media="screen" href="style.css?v=' . PGACC_VERSION . '" />
</head>
<body>
<div id="content">
<div class="wiki motd">Die Daten werden sukzessive eingearbeitet und in einem 4-Augen-Prinzip bestätigt. Die Angaben im Rechenschaftsbericht können also derzeit noch geringfügig variieren.' . percent_of_bookings() . '</div>
';
    $rights = checklogin('rights', false);
    // The links to the report and the donation list keep the year of these
    // pages ($navYear, set by index.php once a year was chosen).
    $yearQuery = $navYear === null ? '' : '?year=' . intval($navYear);
    echo '
<div class="page ' . $page . '">
<div class="main" id="default">
<div class="slot_default" id="slot_default"><div class="ui_tabs"><div class="ui_tabs_links">
<a href="/' . $yearQuery . '"' . ($page == "index" ? ' class="selected"' : '') . '>Rechenschaftsbericht</a>';
    echo '<a href="donations' . $yearQuery . '"' . ($page == "donations" ? ' class="selected"' : '') . '>Spenden</a>
<a href="kdonations"' . ($page == "kdonations" ? ' class="selected"' : '') . '>Sachspenden</a>
<a href="spendings"' . ($page == "spendings" ? ' class="selected"' : '') . '>Ausgaben</a>
<a href="wk"' . ($page == "wk" ? ' class="selected"' : '') . '>Wahlkampf</a>
<a href="transactions"' . ($page == "transactions" ? ' class="selected"' : '') . '>Kontobeweg.</a>
<a href="vreport"' . ($page == "vreport" ? ' class="selected"' : '') . '>Virt. Konten</a>
';/*<a href="index.php?action=statistics"'.($page == "statistics"?' class="selected"':'').'>Statistiken</a>
';*/
    if (strlen($rights ?? '') > 0) {
        echo '<br />';
        if (write_rights($rights) !== false) {
            echo '<a href="index.php?action=new"' . ($page == "new" ? ' class="selected"' : '') . '>Erfassen</a>';
            echo '<a href="index.php?action=import"' . ($page == "import" ? ' class="selected"' : '') . '>Import</a>';
            echo '<a href="index.php?action=import2"' . ($page == "import2" ? ' class="selected"' : '') . '>Belege Import</a>';
        }
        echo '<a href="index.php?action=open&filter_date=' . $year . '"' . ($page == "open" ? ' class="selected"' : '') . '>Offen</a>';
        echo '<a href="index.php?action=closed&filter_date=' . $year . '"' . ($page == "closed" ? ' class="selected"' : '') . '>Abgeschlossen</a>';
        echo '<a href="index.php?action=all&filter_date=' . $year . '"' . ($page == "all" ? ' class="selected"' : '') . '>Alles</a>';
        echo '<a href="index.php?action=mb"' . ($page == "mb" ? ' class="selected"' : '') . '>Mitgliedsbeiträge</a>';
        echo '<a href="index.php?action=tinyreport"' . ($page == "tinyreport" ? ' class="selected"' : '') . '>Konten</a>';
        //echo '<a href="index.php?action=vreport"'.($page == "vreport"?' class="selected"':'').'>Virt. Konten</a>';
        if (has_right($rights, 'bgf') || has_right($rights, 'Rbgf') || has_right($rights, 'root')) {
            echo '<a href="index.php?action=deleted&filter_date=' . $year . '"' . ($page == "deleted" ? ' class="selected"' : '') . '>Alte Rev.</a>';
        }
        //  if (strpos($rights,'root') !== false)
        echo '<a href="index.php?action=accounts"' . ($page == "accounts" ? ' class="selected"' : '') . '>Benutzer</a>';
    }
    echo '</div><br />
';
}

function block_start($p = "")
{
    global $make_csv;
    if ($make_csv) {
        return;
    }
    echo '
<br style="clear: both;" />
<div class="wiki use_terms' . $p . '">
';
}
function block_end()
{
    global $make_csv;
    if ($make_csv) {
        return;
    }
    echo '
</div>
';
}

function acc_footer()
{
    $rights = checklogin('rights', false);
    echo '
</div></div>
<div class="slot_default" id="slot_default"><div class="ui_tabs">
<div class="ui_tabs"><div class="ui_tabs_links">
      <a href="index.php?action=impressum">Impressum</a>
';
    if (strlen($rights ?? '') == 0) {
        echo '<a href="index.php?action=login">Login</a>';
    }
    if (strlen($rights ?? '') != 0) {
        echo '<form class="inline" action="index.php?action=logout" method="post">' . csrf_field() . '<button class="link" type="submit">Logout</button></form>';
    }
    echo '      </div>
    </div>
    </div>
    </div>
    </div>
    </div>
    </div>
  </body>
</html>
';
}
// The year selector of the report and the donations page: a link to the
// previous year, a drop-down (<details>, no JavaScript) with every year from
// the current one down to 2012 and a link to the next year. $urlForYear($year)
// returns the (unescaped) link of a year; with $allowAll the list starts with
// "Alle Jahre", linked as $urlForYear(null) and selected for $selected null.
// The previous and next links stay within 2012 and the current year;
// $selected is read as an integer.
function year_nav($selected, $urlForYear, $allowAll = false)
{
    $first = 2012;
    $last = intval(date('Y'));
    $all = $allowAll && $selected === null;
    $selected = intval($selected);
    $label = $all ? 'Alle Jahre' : $selected;
    $step = function ($class, $text, $label, $year) use ($urlForYear) {
        if ($year === null) {
            return "<span class=\"year_nav_step $class\" role=\"link\" aria-disabled=\"true\" aria-label=\"$label\">$text</span>";
        }
        $title = "$label: $year";
        return "<a class=\"year_nav_step $class\" href=\"" . html_escape($urlForYear($year)) . "\" aria-label=\"$title\" title=\"$title\">$text</a>";
    };
    // With "Alle Jahre" selected, $selected is 0: no year is marked and there
    // is no previous year.
    $prev = $selected > $first ? min($selected - 1, $last) : null;
    $next = !$all && $selected < $last ? max($selected + 1, $first) : null;
    $items = [];
    if ($allowAll) {
        $items[] = [null, 'Alle Jahre', $all];
    }
    for ($i = $last; $i >= $first; $i--) {
        $items[] = [$i, $i, $selected === $i];
    }
    $html = '<nav class="year_nav" aria-label="Jahr">' . $step('year_nav_prev', '&lsaquo;', 'Vorheriges Jahr', $prev)
        . '<details class="year_nav_menu"><summary aria-label="Jahr wählen: ' . $label . '">' . $label . '</summary><ul>';
    foreach ($items as $item) {
        $current = $item[2] ? ' aria-current="page"' : '';
        $html .= '<li><a href="' . html_escape($urlForYear($item[0])) . "\"$current>" . $item[1] . '</a></li>';
    }
    return $html . '</ul></details>' . $step('year_nav_next', '&rsaquo;', 'Nächstes Jahr', $next) . '</nav>';
}
// Whether the comma-separated rights contain exactly this right ("bgf" is
// not contained in "Rbgf").
function has_right($rights, $right)
{
    return in_array($right, rights_tokens($rights), true);
}
// The comma-separated rights, without surrounding whitespace.
function rights_tokens($rights)
{
    return array_map(trim(...), explode(",", $rights ?? ''));
}
// Whether the rights may use this organization for a voucher: any for the
// exact bgf right, otherwise only the numeric organizations of the rights,
// compared as numbers like the database does ("03" is 3). This is the scope
// that rights2orgasql() lets the user read (root is no organization right
// there either), so users write only what they can see.
function organization_allowed($lo, $rights)
{
    if (has_right($rights, 'bgf')) {
        return true;
    }
    $lo = strval($lo);
    if (preg_match('/^\d+$/D', $lo) != 1) {
        return false;
    }
    foreach (rights_tokens($rights) as $right) {
        if (preg_match('/^\d+$/D', $right) == 1 && ltrim($right, '0') === ltrim($lo, '0')) {
            return true;
        }
    }
    return false;
}
// Rights for changing data. The read-only right "Rbgf" (see accounts.php)
// does not count: false for a user who has no other right, otherwise the
// other rights. Rights without "Rbgf" are returned unchanged.
function write_rights($rights)
{
    $tokens = rights_tokens($rights);
    if (!in_array('Rbgf', $tokens, true)) {
        return $rights;
    }
    $rights_w = [];
    foreach ($tokens as $right) {
        if ($right !== 'Rbgf' && $right !== '') {
            $rights_w[] = $right;
        }
    }
    if (count($rights_w) == 0) {
        return false;
    }
    return implode(",", $rights_w);
}
function rights2orgasql($rights)
{
    $rights_r = rights_tokens($rights);
    if (!in_array('bgf', $rights_r) && !in_array('Rbgf', $rights_r)) {
        $rights2 = [];
        foreach ($rights_r as $right) {
            if (preg_match('/^\d+$/', $right) == 1) {
                $rights2[] = "'" . $right . "'";
            }
        }
        if (count($rights2) > 0) {
            return " AND orga IN (" . implode(",", $rights2) . ") ";
        }
        return " AND false ";
    }
    return "";
}
function konto2lo($konto)
{
    switch ($konto) {
        case '50110117270':
            return 1;
        case '50110117300':
            return 2;
        case '50110117318':
            return 3;
        case '50110117326':
            return 4;
        case '50110117350':
            return 5;
        case '50110117369':
            return 6;
        case '50110117393':
            return 10;
        case '10110123642':
            return 9;
        case '50110110437':
            return 10;
        default:
            return $konto;
    }
}
function getsort()
{
    $sort = "date ASC, voucher_id DESC, vouchers.id ASC";
    if (isset($_GET["sort"])) {
        switch ($_GET["sort"]) {
            case 'ida':
                $sort = "voucher_id ASC, vouchers.id ASC";
                break;
            case 'idd':
                $sort = "voucher_id DESC, vouchers.id ASC";
                break;
            case 'bida':
                $sort = "vouchers.id ASC, voucher_id ASC";
                break;
            case 'bidd':
                $sort = "vouchers.id DESC, voucher_id ASC";
                break;
            case 'dated':
                $sort = "date DESC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'datea':
                $sort = "date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'typea':
                $sort = "type ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'typed':
                $sort = "type DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'loa':
                $sort = "orga ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'lod':
                $sort = "orga DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'membera':
                $sort = "member_id ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'memberd':
                $sort = "member_id DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'gka':
                $sort = "contra_account DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'gkd':
                $sort = "contra_account ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'ka':
                $sort = "account ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'kd':
                $sort = "account DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'ama':
                $sort = "amount ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'amd':
                $sort = "amount DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'commenta':
                $sort = "commentgf ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'commentd':
                $sort = "commentgf DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'texta':
                $sort = "comment ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'textd':
                $sort = "comment DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'comma':
                $sort = "committed DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'commd':
                $sort = "committed ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'acka':
                $sort = "ack1 ASC, ack2 ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'ackd':
                $sort = "ack1 DESC, ack2 DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'refunda':
                $sort = "refund ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'refundd':
                $sort = "refund DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'bela':
                $sort = "file ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'beld':
                $sort = "file DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'namea':
                $sort = "name ASC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            case 'named':
                $sort = "name DESC, date ASC, voucher_id DESC, vouchers.id ASC";
                break;
            default:
        }
    }
    return $sort;
}
function getoppsort($sortn)
{
    if (isset($_GET["sort"])) {
        if ($_GET["sort"] == $sortn) {
            $sortdir = substr($sortn ?? '', -1, 1);
            switch ($sortdir) {
                case 'a':
                    return substr($sortn, 0, -1) . 'd';
                case 'd':
                    return substr($sortn, 0, -1) . 'a';
            }
        }
    }
    return $sortn;
}
function percent_of_bookings()
{
    global $dbconn;
    $rows = [];
    $startYear = 2012;
    $endYear = date('Y') + 1;
    for ($currentYear = $startYear; $currentYear < $endYear + 1; $currentYear++) {
        $query = "SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '" . $currentYear . "-01-01' AND date < '" . ($currentYear + 1) . "-01-01' AND NOT deleted;";
        $result = db_query($dbconn, $query);
        while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $c = intval($line['c']);
            if ($c <= 0) {
                continue;
            }
            $b = intval($line['b']) - intval($line['a']);
            $bp = round($b * 100 / (1.0 * $c), 2);
            $a = intval($line['a']);
            $ap = round($a * 100 / (1.0 * $c), 2);
            $d = $c - $b - $a;
            $dp = round($d * 100 / (1.0 * $c), 2);
            $rows[$currentYear] = '<tr><th scope="row">' . html_escape($currentYear) . '</th><td>' . html_escape($c) . '</td>'
                . '<td>' . html_escape($d) . ' <span class="percent">(' . html_escape("$dp %") . ')</span></td>'
                . '<td>' . html_escape($b) . ' <span class="percent">(' . html_escape("$bp %") . ')</span></td>'
                . '<td>' . html_escape($a) . ' <span class="percent">(' . html_escape("$ap %") . ')</span></td>'
                . '<td class="bar"><span aria-hidden="true" title="'
                . html_escape("$currentYear: $dp % unbearbeitet, $bp % bearbeitet, $ap % abgeschlossen") . '">'
                . '<span class="open" style="width: ' . html_escape("$dp%") . '"></span>'
                . '<span class="edited" style="width: ' . html_escape("$bp%") . '"></span>'
                . '<span class="closed" style="width: ' . html_escape("$ap%") . '"></span></span></td></tr>';
        }
        pg_free_result($result);
    }
    if ($rows === []) {
        return "";
    }
    // Newest first. Shown: the current and the previous year, at least the
    // two most recent years with bookings; older years behind <details>.
    $rows = array_reverse($rows, true);
    $shown = 0;
    foreach (array_keys($rows) as $year) {
        if ($year >= $endYear - 2) {
            $shown++;
        }
    }
    $shown = max($shown, min(2, count($rows)));
    $older = array_slice($rows, $shown, null, true);
    $text = progress_table('Bearbeitungsstand der Buchungszeilen je Jahr', '', array_slice($rows, 0, $shown, true));
    if ($older !== []) {
        $years = array_keys($older);
        $range = html_escape(end($years));
        if (count($years) > 1) {
            $range .= '&ndash;' . html_escape($years[0]);
        }
        $text .= '<details class="more"><summary>Weitere Jahre anzeigen (' . $range . ')</summary>'
            . progress_table('Bearbeitungsstand der Buchungszeilen, weitere Jahre', ' class="older"', $older)
            . '</details>';
    }
    return '<div class="progress">' . $text . '</div>';
}

// One table of the booking progress. Both tables have the same columns and
// widths; the older years' table hides its caption and head visually.
function progress_table($caption, $attributes, $rows)
{
    return '<table' . $attributes . '><caption>' . $caption . '</caption>'
        . '<colgroup><col class="year" /><col span="4" /><col class="bar" /></colgroup>'
        . '<thead><tr><th scope="col">Jahr</th><th scope="col">Buchungs&shy;zeilen</th>'
        . '<th scope="col"><span class="open" aria-hidden="true"></span>unbe&shy;arbeitet</th>'
        . '<th scope="col"><span class="edited" aria-hidden="true"></span>bear&shy;beitet</th>'
        . '<th scope="col"><span class="closed" aria-hidden="true"></span>abge&shy;schlossen</th>'
        . '<th class="bar" aria-hidden="true"></th></tr></thead>'
        . '<tbody>' . implode('', $rows) . '</tbody></table>';
}

function eyes()
{
    if (isset($_GET["noeyes"])) {
        return " NOT deleted ";
    } else {
        return " NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL) ";
    }
}

/**
 * Return a subset of the parameter array with only keys of the whitelist.
 * Only checks keys, not values.
 *
 * @author meisterluk
 * @author Peter Grassberger <petertheone@gmail.com>
 *
 * @param $array array to select from
 * @param $whitelist array of keys to select
 * @return array a subset of array
 */
function whitelist($array, $whitelist)
{
    $new_array = [];
    foreach ($array as $key => $value) {
        if (isset($whitelist[$key])) {
            $new_array[$key] = $value;
        }
    }
    return $new_array;
}

function vouchers_reset_ack($id, $rightssql)
{
    global $dbconn;
    $query = "
    UPDATE vouchers
    SET
      ack1_old = ack1,
      ack2_old = ack2,
      ack1 = NULL,
      ack2 = NULL
    WHERE
      NOT deleted AND
      (ack1 IS NOT NULL OR ack2 IS NOT NULL) AND
      voucher_id = $id $rightssql;
  ";
    db_exec($dbconn, $query);
}

/*
 * Replacement for the removed mcrypt extension, byte-compatible with
 * mcrypt_encrypt()/mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $key, $data,
 * MCRYPT_MODE_CBC, $iv): Rijndael with a 256-bit block (not AES), CBC mode,
 * data zero-padded to whole 32-byte blocks (at least one), padding kept on
 * decryption. Keys and IVs are 32-byte md5 hex strings. phpseclib uses the
 * mcrypt extension where it is loaded and its own implementation elsewhere;
 * both give the same bytes.
 * Contract vectors: tests/fixtures/mcrypt/vectors.json.
 */
function rijndael256_cbc($key, $iv)
{
    $cipher = new \phpseclib\Crypt\Rijndael(\phpseclib\Crypt\Base::MODE_CBC);
    $cipher->setBlockLength(256);
    $cipher->setKey($key);
    $cipher->setIV($iv);
    $cipher->disablePadding();
    return $cipher;
}

function rijndael256_zero_pad($data)
{
    $blocks = max(1, intval(ceil(strlen($data) / 32)));
    return str_pad($data, $blocks * 32, "\0");
}

function rijndael256_encrypt($key, $data, $iv)
{
    return rijndael256_cbc($key, $iv)->encrypt(rijndael256_zero_pad($data));
}

function rijndael256_decrypt($key, $data, $iv)
{
    return rijndael256_cbc($key, $iv)->decrypt(rijndael256_zero_pad($data));
}

/*
 * Receipt files, keyed by the voucher ID: key and IV, shared by encryption
 * and decryption. See the mcrypt and receipt key items in
 * tests/COMPATIBILITY_PLAN.md before changing the key derivation.
 */
function receipt_cipher_params($id, $key)
{
    return [md5($id . $key . $id), md5($key . $id)];
}

function receipt_encrypt($id, $key, $data)
{
    [$cipherKey, $iv] = receipt_cipher_params($id, $key);
    return rijndael256_encrypt($cipherKey, $data, $iv);
}

function receipt_decrypt($id, $key, $data)
{
    [$cipherKey, $iv] = receipt_cipher_params($id, $key);
    return rijndael256_decrypt($cipherKey, $data, $iv);
}

/*
 * Receipt storage in files/. New receipts are <n>.v2.aes: keyed on the file
 * number <n> and the configured $key of constants.php, AES-256-CBC with a
 * random IV, authenticated with HMAC-SHA256 (see receipt_v2_encrypt()).
 * Legacy receipts are <n>.aes: keyed on the voucher ID with an empty key part
 * (receipt_encrypt(); the callers never read the configured key).
 * migrate-receipt-key.php re-encrypts legacy files. See the receipt key item
 * in tests/COMPATIBILITY_PLAN.md.
 */

// At least 16 characters and not the CHANGE-ME placeholder of the README.
function receipt_key_is_valid($key)
{
    return is_string($key) && strlen($key) >= 16 && !str_contains($key, 'CHANGE-ME');
}

// The configured key, or '' without a valid one (receipts then fail closed).
function receipt_configured_key()
{
    // constants.php is required in global scope (index.php).
    if (isset($GLOBALS['key']) && receipt_key_is_valid($GLOBALS['key'])) {
        return $GLOBALS['key'];
    }
    return '';
}

// Identifies a key (the migration's --key-fingerprint, shown to root on the
// user administration page) without revealing it: Argon2id with a fixed salt
// (the first 16 bytes of SHA-256("pgacc-key-fingerprint")), opslimit 2 and
// memlimit 64 MiB (libsodium's interactive limits), first 8 hex digits. The
// slow derivation keeps a fingerprint that was seen from being a fast test
// for guessed keys.
function receipt_key_fingerprint($key)
{
    $salt = substr(hash('sha256', 'pgacc-key-fingerprint', true), 0, 16);
    return substr(bin2hex(sodium_crypto_pwhash(16, $key, $salt, 2, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13)), 0, 8);
}

// The receipt key paragraph of the user administration page (root only):
// the fingerprint of the configured key, and a warning for a short one.
function receipt_key_notice($key)
{
    if (!receipt_key_is_valid($key)) {
        return '<p>Fingerabdruck des Beleg-Schlüssels (für migrate-receipt-key.php --key-fingerprint): kein gültiger Schlüssel ($key) konfiguriert</p>';
    }
    return '<p>Fingerabdruck des Beleg-Schlüssels (für migrate-receipt-key.php --key-fingerprint): ' . html_escape(receipt_key_fingerprint($key)) . '</p>'
        . (strlen($key) < 32 ? '<p>Warnung: Der Beleg-Schlüssel ($key) hat weniger als 32 Zeichen. Er darf nicht mehr geändert werden, sobald Belege damit verschlüsselt sind (UPGRADING.md).</p>' : '');
}

function receipt_v2_path($directory, $number)
{
    return $directory . '/' . $number . '.v2.aes';
}

function receipt_legacy_path($directory, $number)
{
    return $directory . '/' . $number . '.aes';
}

// PDF, JPEG, PNG and GIF by their magic bytes; anything else is sent as a
// download. A PDF header may also follow white-space (PDF's, NUL included)
// within the first 1024 bytes, as PDF readers accept it. ISO 32000 requires
// %PDF- as the first line, and Acrobat and Poppler accept the header after
// any bytes; only white-space is accepted here, to keep false positives
// (a wrong voucher ID in the migration, other file types) rare.
function receipt_content_type($plain)
{
    if (preg_match('/^[\0\t\n\f\r ]+%PDF-/', substr($plain, 0, 1024))) {
        return 'application/pdf';
    }
    $types = [
        '%PDF' => 'application/pdf',
        "\xFF\xD8\xFF" => 'image/jpeg',
        "\x89PNG\r\n\x1A\n" => 'image/png',
        'GIF87a' => 'image/gif',
        'GIF89a' => 'image/gif',
    ];
    foreach ($types as $magic => $type) {
        if (str_starts_with($plain, $magic)) {
            return $type;
        }
    }
    return 'application/octet-stream';
}

function receipt_has_known_type($plain)
{
    return receipt_content_type($plain) !== 'application/octet-stream';
}

// RFC 5869 HKDF-SHA256 (hash_hkdf() needs PHP 7.1.2). An empty salt is the
// RFC's default (HMAC treats it like HashLen zero bytes).
function receipt_hkdf_extract($salt, $ikm)
{
    return hash_hmac('sha256', $ikm, $salt, true);
}

function receipt_hkdf_expand($prk, $info, $length)
{
    $okm = '';
    $block = '';
    for ($counter = 1; strlen($okm) < $length; $counter++) {
        $block = hash_hmac('sha256', $block . $info . chr($counter), $prk, true);
        $okm .= $block;
    }
    return substr($okm, 0, $length);
}

// Encryption and MAC keys of one <n>.v2.aes file: HKDF-SHA256 of the
// configured key, with the purpose and the file number in the info.
function receipt_v2_keys($key, $number)
{
    $prk = receipt_hkdf_extract('', $key);
    return [
        receipt_hkdf_expand($prk, 'pgacc-receipt-v2|enc|' . $number, 32),
        receipt_hkdf_expand($prk, 'pgacc-receipt-v2|mac|' . $number, 32),
    ];
}

function receipt_v2_cipher($encryptionKey, $iv)
{
    $cipher = new \phpseclib\Crypt\AES(\phpseclib\Crypt\Base::MODE_CBC);
    $cipher->setKey($encryptionKey);
    $cipher->setIV($iv);
    return $cipher;
}

// A CBC cipher without padding that continues across calls (chunks).
function receipt_v2_stream_cipher($encryptionKey, $iv)
{
    $cipher = receipt_v2_cipher($encryptionKey, $iv);
    $cipher->disablePadding();
    $cipher->enableContinuousBuffer();
    return $cipher;
}

// Byte strings compared in constant time.
function receipt_equals($known, $given)
{
    return hash_equals($known, $given);
}

/*
 * The bytes of files/<number>.v2.aes: "PGR2", a 16-byte IV (random unless
 * given, for test vectors), the AES-256-CBC ciphertext with PKCS#7 padding,
 * and the HMAC-SHA256 of everything before it (encrypt-then-MAC).
 * receipt_v2_encrypt_stream() writes the same bytes in chunks.
 */
function receipt_v2_encrypt($key, $number, $data, $iv = null)
{
    $iv ??= \phpseclib\Crypt\Random::string(16);
    [$encryptionKey, $macKey] = receipt_v2_keys($key, $number);
    $ciphertext = receipt_v2_cipher($encryptionKey, $iv)->encrypt($data);
    $mac = hash_init('sha256', HASH_HMAC, $macKey);
    hash_update($mac, 'PGR2' . $iv);
    hash_update($mac, $ciphertext);
    return 'PGR2' . $iv . $ciphertext . hash_final($mac, true);
}

// The plain text of <number>.v2.aes bytes, or false: the MAC is checked first,
// so a wrong key, another file number or a modified file is rejected.
function receipt_v2_decrypt($key, $number, $file)
{
    if (!is_string($file) || strlen($file) < 68 || !str_starts_with($file, 'PGR2')) {
        return false;
    }
    [$encryptionKey, $macKey] = receipt_v2_keys($key, $number);
    $ciphertext = substr($file, 20, -32);
    $mac = hash_init('sha256', HASH_HMAC, $macKey);
    hash_update($mac, substr($file, 0, 20));
    hash_update($mac, $ciphertext);
    if (!receipt_equals(hash_final($mac, true), substr($file, -32))) {
        return false;
    }
    return receipt_v2_cipher($encryptionKey, substr($file, 4, 16))->decrypt($ciphertext);
}

// Encrypts stream $in to stream $out as <number>.v2.aes bytes, 64 KiB at a
// time, so memory does not grow with the receipt. Short reads are fine; a
// read that returns nothing before the end, or a failed write, returns false.
function receipt_v2_encrypt_stream($key, $number, $in, $out, $iv = null)
{
    $iv ??= \phpseclib\Crypt\Random::string(16);
    [$encryptionKey, $macKey] = receipt_v2_keys($key, $number);
    $cipher = receipt_v2_stream_cipher($encryptionKey, $iv);
    $mac = hash_init('sha256', HASH_HMAC, $macKey);
    hash_update($mac, 'PGR2' . $iv);
    $written = fwrite($out, 'PGR2' . $iv) === 20;
    $pending = '';
    while (true) {
        $chunk = fread($in, 65536);
        if (feof($in)) {
            $pending .= (string) $chunk;
            break;
        }
        if ((string) $chunk === '') {
            return false;
        }
        $pending .= $chunk;
        // Whole blocks now; the rest (and the padding) with the last chunk. An
        // empty encrypt() would reset phpseclib's continuous IV.
        $whole = strlen($pending) - strlen($pending) % 16;
        if ($whole === 0) {
            continue;
        }
        $ciphertext = $cipher->encrypt(substr($pending, 0, $whole));
        $pending = (string) substr($pending, $whole);
        hash_update($mac, $ciphertext);
        $written = fwrite($out, $ciphertext) === strlen($ciphertext) && $written;
    }
    $padding = 16 - strlen($pending) % 16;
    $ciphertext = $cipher->encrypt($pending . str_repeat(chr($padding), $padding));
    hash_update($mac, $ciphertext);
    $written = fwrite($out, $ciphertext) === strlen($ciphertext) && $written;
    return fwrite($out, hash_final($mac, true)) === 32 && $written;
}

// A temporary file name next to $path, unique per process and call.
function receipt_temp_path($path)
{
    return $path . '.tmp-' . getmypid() . '-' . str_replace('.', '', uniqid('', true));
}

// Writes a temporary file next to $path and renames it over $path, so readers
// never see a partly written file. $prepare (optional) is called with the
// temporary file's path before the rename and must return true. Only the
// temporary file is ever removed.
function receipt_write_file($path, $bytes, $prepare = null)
{
    $temp = receipt_temp_path($path);
    if (@file_put_contents($temp, $bytes) === strlen($bytes) && ($prepare === null || $prepare($temp)) && @rename($temp, $path)) {
        return true;
    }
    @unlink($temp);
    return false;
}

// Stores the uploaded file $source as <number>.v2.aes, encrypted in chunks
// and renamed into place. Fails closed: without a valid configured key
// nothing is written.
function receipt_store($directory, $number, $source)
{
    $receiptKey = receipt_configured_key();
    if ($receiptKey === '') {
        error_log('pgacc: receipt not stored: no valid receipt key ($key) configured');
        return false;
    }
    $path = receipt_v2_path($directory, $number);
    $temp = receipt_temp_path($path);
    $in = @fopen($source, 'rb');
    $out = $in === false ? false : @fopen($temp, 'xb');
    $stored = $out !== false && receipt_v2_encrypt_stream($receiptKey, $number, $in, $out);
    if ($in !== false) {
        fclose($in);
    }
    if ($out !== false) {
        $stored = fclose($out) && $stored && @rename($temp, $path);
    }
    if ($stored) {
        return true;
    }
    @unlink($temp);
    error_log("pgacc: receipt $number.v2.aes could not be written");
    return false;
}

/*
 * Opens files/<number>.v2.aes ($path) for sending, in two passes over one
 * open file, 64 KiB at a time: the MAC is checked over the whole file first;
 * then the last block gives the plain text length and the first 1024 bytes
 * the type. Returns [what receipt_v2_send() needs, null], or [false, why not]
 * for the caller's log line (it names neither the key nor its fingerprint).
 */
function receipt_v2_open($path, $number)
{
    $receiptKey = receipt_configured_key();
    if ($receiptKey === '') {
        return [false, 'not decrypted: no valid receipt key ($key) configured'];
    }
    // Missing (e.g. deleted after the check), unreadable or not a regular file:
    // not a wrong key.
    $in = receipt_open_regular($path);
    if ($in === false) {
        return [false, 'could not be opened'];
    }
    $receipt = receipt_v2_open_handle($receiptKey, $number, $in);
    return [$receipt, $receipt === false ? 'could not be decrypted (wrong key or modified file)' : null];
}

// The whole content of the regular file $path, or false if it cannot be
// opened or is not read completely (a read error returns the bytes so far).
function receipt_read_file($path)
{
    $in = receipt_open_regular($path);
    if ($in === false) {
        return false;
    }
    $stat = fstat($in);
    $data = @stream_get_contents($in);
    fclose($in);
    return is_string($data) && strlen($data) === $stat['size'] ? $data : false;
}

// A stored receipt file opened for reading, or false if it is missing,
// unreadable or not a regular file (a directory opens on Linux, but its reads
// raise a notice), without a PHP warning.
function receipt_open_regular($path)
{
    return is_file($path) ? @fopen($path, 'rb') : false;
}

function receipt_v2_open_with($key, $number, $path)
{
    $in = receipt_open_regular($path);
    if ($in === false) {
        return false;
    }
    return receipt_v2_open_handle($key, $number, $in);
}

// receipt_v2_open_with() for a file opened as $in (closed unless returned).
function receipt_v2_open_handle($key, $number, $in)
{
    $stat = fstat($in);
    $length = $stat['size'] - 52;
    $header = (string) fread($in, 20);
    [$encryptionKey, $macKey] = receipt_v2_keys($key, $number);
    $mac = hash_init('sha256', HASH_HMAC, $macKey);
    hash_update($mac, $header);
    for ($remaining = $length; $remaining > 0; $remaining -= 65536) {
        hash_update($mac, (string) fread($in, min(65536, $remaining)));
    }
    $valid = $length >= 16 && str_starts_with($header, 'PGR2')
      && receipt_equals(hash_final($mac, true), (string) fread($in, 32));
    $iv = substr($header, 4, 16);
    $tail = '';
    $head = '';
    if ($valid) {
        // The last block decrypts with the block before it (or the IV) as IV.
        fseek($in, $stat['size'] - 64);
        $tail = (string) fread($in, 32);
        $last = receipt_v2_stream_cipher($encryptionKey, substr($tail, 0, 16))->decrypt(substr($tail, 16));
        $padding = ord(substr($last, -1));
        // PKCS#7; also rejects 0 (the whole block is compared with '') and above 16.
        $valid = substr($last, -$padding) === str_repeat(chr($padding), $padding);
        $length -= $padding;
        fseek($in, 20);
        $head = receipt_v2_stream_cipher($encryptionKey, $iv)->decrypt((string) fread($in, min(1024, $stat['size'] - 52)));
    }
    if (!$valid) {
        fclose($in);
        return false;
    }
    return ['handle' => $in, 'key' => $encryptionKey, 'iv' => $iv, 'length' => $length,
        'head' => substr($head, 0, min(1024, $length)), 'number' => $number, 'stat' => receipt_v2_identity($stat)];
}

// Size and change times of an open file. Receipts are only replaced by rename,
// so a difference means an in-place change after the MAC check; same-size
// edits within a second go unnoticed (known limitation, COMPATIBILITY_PLAN).
function receipt_v2_identity($stat)
{
    return [$stat['size'], $stat['mtime'], $stat['ctime']];
}

/*
 * Decrypts an opened <n>.v2.aes in 64 KiB chunks and passes each plain text
 * chunk to $emit; closes the file. Returns false, and stops before passing
 * on anything else, if its size or change times differ since the MAC check or a read
 * came back short (the bytes would not be the checked ones).
 */
function receipt_v2_decrypt_chunks($receipt, $emit)
{
    $in = $receipt['handle'];
    $complete = receipt_v2_identity(fstat($in)) === $receipt['stat'];
    $cipher = receipt_v2_stream_cipher($receipt['key'], $receipt['iv']);
    fseek($in, 20);
    $ciphertext = $receipt['stat'][0] - 52;
    for ($left = $receipt['length']; $complete && $left > 0; $left -= 65536) {
        $expected = min(65536, $ciphertext);
        $ciphertext -= $expected;
        $chunk = (string) fread($in, $expected);
        $complete = strlen($chunk) === $expected;
        if ($complete) {
            call_user_func($emit, substr($cipher->decrypt($chunk), 0, min(65536, $left)));
        }
    }
    $complete = $complete && receipt_v2_identity(fstat($in)) === $receipt['stat'];
    fclose($in);
    return $complete;
}

// The whole plain text of files/<number>.v2.aes ($path) through the same
// chunked path as downloads, or false.
function receipt_v2_read_with($key, $number, $path)
{
    $receipt = receipt_v2_open_with($key, $number, $path);
    if ($receipt === false) {
        return false;
    }
    $chunks = [];
    $complete = receipt_v2_decrypt_chunks($receipt, function ($chunk) use (&$chunks) {
        $chunks[] = $chunk;
    });
    return $complete ? implode('', $chunks) : false;
}

function receipt_v2_echo($chunk)
{
    echo $chunk;
}

// Echoes the plain text of an opened <n>.v2.aes in 64 KiB chunks; closes it.
// The headers are already sent, so a failure can only stop the body (the
// client sees fewer bytes than Content-Length) and be logged.
function receipt_v2_send($receipt)
{
    if (!receipt_v2_decrypt_chunks($receipt, 'receipt_v2_echo')) {
        error_log("pgacc: receipt {$receipt['number']}.v2.aes changed or could not be read while it was sent; "
          . 'the download is incomplete');
    }
}

// Membership-system member list (getusers.php): key and IV.
function membership_cipher_params($datakey)
{
    return [md5($datakey), md5(md5($datakey))];
}

function membership_decrypt($datakey, $data)
{
    [$cipherKey, $iv] = membership_cipher_params($datakey);
    return rijndael256_decrypt($cipherKey, $data, $iv);
}

/*
 * Login sessions. users.cookie (an integer column) holds a random nonce that
 * every login replaces and logout clears. The pp_pgacc_login cookie is an
 * HMAC of the user id, that nonce and users.login under a session key derived
 * from the configured $key of constants.php: without a valid $key nobody can
 * log in, and changing $key ends every session.
 */

// $length bytes from random_bytes(), or false when the system has no random
// source. $random: the function to call (for the tests).
function login_random_bytes($length, $random = 'random_bytes')
{
    try {
        $bytes = $random($length);
    } catch (Throwable) {
        return false;
    }
    return is_string($bytes) && strlen($bytes) === $length ? $bytes : false;
}

// The session key, or false without a valid $key: the same key and check
// as the receipts (receipt_configured_key()).
function login_session_key()
{
    $key = receipt_configured_key();
    if ($key === '') {
        return false;
    }
    return hash_hmac('sha256', 'pgacc-session-key', $key, true);
}

// The users.cookie nonce (1 to 2147483647) for four random bytes, else false.
function login_nonce($bytes)
{
    if (strlen($bytes) != 4) {
        return false;
    }
    $value = unpack('N', $bytes);
    return max(1, $value[1] & 0x7FFFFFFF);
}

// Starts a session of user $id: stores a new nonce and the login time and
// returns the pp_pgacc_login token. Fails closed (false, logged without key
// material) without a valid $key or a random source, or when the session
// cannot be stored. $bytes: the random bytes of the nonce, $random: their
// source (both for the tests).
function login_start_session($connection, $id, $bytes = null, $random = 'random_bytes')
{
    if (login_session_key() === false) {
        error_log('pgacc: login refused: no valid session key ($key in constants.php)');
        return false;
    }
    $nonce = login_nonce($bytes ?? login_random_bytes(4, $random));
    if ($nonce === false) {
        error_log('pgacc: login refused: no secure random source');
        return false;
    }
    $id = intval($id);
    $session = @pg_query($connection, "UPDATE users SET cookie = $nonce, login = now() WHERE id = $id RETURNING login");
    if ($session === false || pg_num_rows($session) != 1) {
        error_log("pgacc: login refused: the session of user $id could not be stored");
        return false;
    }
    return login_session_token($id, $nonce, pg_fetch_result($session, 0, 0));
}

// The pp_pgacc_login cookie of a session (64 hex digits), or false without a
// valid $key.
function login_session_token($id, $nonce, $login)
{
    $sessionKey = login_session_key();
    if ($sessionKey === false) {
        return false;
    }
    return hash_hmac('sha256', 'pgacc-login|' . $id . '|' . $nonce . '|' . $login, $sessionKey);
}

// Whether $known and $given are the same string, in a time that does not
// depend on where they differ (receipt_equals()); false for other types.
function login_token_equals($known, $given)
{
    return is_string($known) && is_string($given) && receipt_equals($known, $given);
}

// The form token (CSRF) of a session (64 hex digits), or false without a
// valid $key: an HMAC of the session data of the pp_pgacc_login cookie under
// its own subkey of $key. It changes with every login, stops working when
// logout or a password change clears users.cookie, and does not reveal the
// HttpOnly cookie when a page shows it.
function csrf_session_token($id, $nonce, $login)
{
    $key = receipt_configured_key();
    if ($key === '') {
        return false;
    }
    return hash_hmac('sha256', 'pgacc-csrf|' . $id . '|' . $nonce . '|' . $login, hash_hmac('sha256', 'pgacc-csrf-key', $key, true));
}

// The hidden form field with the form token of the session that checklogin()
// accepted in this request (an empty value without one).
function csrf_field()
{
    global $csrf_token;
    return '<input type="hidden" name="csrf_token" value="' . (is_string($csrf_token) ? $csrf_token : '') . '" />';
}

// Whether the POST field csrf_token is the form token of the session that
// checklogin() accepted (constant-time); false without a session.
function csrf_request_valid()
{
    global $csrf_token;
    return isset($_POST['csrf_token']) && is_string($csrf_token) && $csrf_token !== '' && login_token_equals($csrf_token, $_POST['csrf_token']);
}

// Whether this is a POST request: only those may change data.
function request_is_post()
{
    return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
}

// The Set-Cookie header of a login cookie: secure, HttpOnly and SameSite=Lax
// (setcookie() of PHP 5.3 has neither SameSite nor Max-Age), lasting $maxAge
// seconds. $maxAge 0 deletes the cookie, as setcookie() of PHP 5.5+ does.
function login_cookie_header($name, $value, $maxAge)
{
    $expires = $maxAge > 0 ? time() + $maxAge : 1;
    return 'Set-Cookie: ' . $name . '=' . $value . '; expires=' . gmdate('D, d M Y H:i:s', $expires) . ' GMT; Max-Age=' . $maxAge . '; secure; HttpOnly; SameSite=Lax';
}

// Login targets (hex, see ascii2hex()) must be pages of this application:
// index.php with an optional query or fragment, or an absolute path
// (checklogin() sends the request URI). Never empty (the form's default:
// blank page), no scheme or host ("//", backslashes), controls or spaces.
function login_target_allowed($target)
{
    return preg_match('/^(index\.php([?#][\x21-\x5b\x5d-\x7e]*)?|\/([\x21-\x2e\x30-\x5b\x5d-\x7e][\x21-\x5b\x5d-\x7e]*)?)$/D', $target) == 1;
}

// The decoded target if $hex is hex (digits and spaces, see hex2ascii()) of
// an allowed target, else false. Other characters are refused before
// hex2ascii(), whose hexdec() reports them on PHP 7.4+.
function login_target_decode($hex)
{
    if (!is_string($hex) || strspn($hex, '0123456789ABCDEFabcdef ') != strlen($hex)) {
        return false;
    }
    $target = hex2ascii($hex);
    return login_target_allowed($target) ? $target : false;
}

// The login target for the Location header, else index.php.
function login_target($hex)
{
    $target = login_target_decode($hex);
    return $target === false ? 'index.php' : $target;
}

// The hidden target of the login form: $hex if it is an allowed target,
// else empty.
function login_form_target($hex)
{
    return login_target_decode($hex) === false ? '' : $hex;
}
