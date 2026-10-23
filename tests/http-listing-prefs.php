<?php
/*
 * Ledger listing (closed.php page_listing_header()/page_listing_line(), used
 * by action=all) with hidden columns from the users.hide preference.
 *
 * synthetic-admin has no hidden columns and gives the reference output.
 * synthetic-hide-odd and synthetic-hide-even (tests/fixtures/data.sql) hide
 * the odd and even columns, so each of the 18 columns is checked hidden once
 * and shown once, in the header, the filter row, every voucher row, and the
 * CSV export. ?hide=-N / ?hide=N then show and hide a stored column again.
 *
 * Must stay compatible with PHP 5.6.
 */
require __DIR__ . '/http-test-helpers.php';

define('LISTING_COLUMNS', 18);
define('LISTING_PATH', '/index.php?action=all&filter_date=2024');

function listing_columns($hidden)
{
    $columns = array();
    for ($col = 1; $col <= LISTING_COLUMNS; $col++) {
        $columns[$col] = in_array($col, $hidden, true);
    }
    return $columns;
}

function odd_columns()
{
    return range(1, LISTING_COLUMNS, 2);
}

function even_columns()
{
    return range(2, LISTING_COLUMNS, 2);
}

function listing_part($body, $start, $end, $label)
{
    $from = strpos($body, $start);
    if ($from === false) {
        fail_test("$label: missing " . var_export($start, true));
    }
    $from += strlen($start);
    $to = strpos($body, $end, $from);
    if ($to === false) {
        fail_test("$label: missing " . var_export($end, true));
    }
    return substr($body, $from, $to - $from);
}

// The 18 header cells, each "<td>...</td>\n".
function header_cells($body, $label)
{
    $row = listing_part($body, '<table><tr>', "</tr>\n<form", "$label header");
    $cells = explode("</td>\n", $row);
    if (count($cells) !== LISTING_COLUMNS + 1 || end($cells) !== '') {
        fail_test("$label header has " . (count($cells) - 1) . ' cells');
    }
    array_pop($cells);
    return array_combine(range(1, LISTING_COLUMNS), $cells);
}

// The 18 filter-row segments between "</td><td>" separators. The row is
// echoed from CRLF source lines of closed.php.
function filter_cells($body, $label)
{
    $row = listing_part(
        $body,
        '<form action="index.php" method="GET"> <tr>' . "\r\n<td>",
        "\r\n</td>\r\n</tr></form>",
        "$label filter row"
    );
    $cells = explode('</td><td>', $row);
    if (count($cells) !== LISTING_COLUMNS) {
        fail_test("$label filter row has " . count($cells) . ' cells');
    }
    return array_combine(range(1, LISTING_COLUMNS), $cells);
}

// Every voucher row as a list of 18 cells.
function voucher_rows($body, $label)
{
    $rows = explode('</tr><tr>', listing_part($body, "</tr></form><tr>", '</tr></table>', "$label rows"));
    $result = array();
    foreach ($rows as $index => $row) {
        $cells = explode("</td>\n", $row);
        if (count($cells) !== LISTING_COLUMNS + 1 || end($cells) !== '') {
            fail_test("$label row $index has " . (count($cells) - 1) . ' cells');
        }
        array_pop($cells);
        $result[] = array_combine(range(1, LISTING_COLUMNS), $cells);
    }
    return $result;
}

function filter_without_input($cell)
{
    $count = 0;
    $cell = preg_replace(
        '/<(input|select) type="(text|checkbox)" name="filter_[a-z_]+" value="[^"]*" size="\d+" \/>/',
        '',
        $cell,
        -1,
        $count
    );
    if ($count !== 1) {
        fail_test("reference filter cell has $count filter inputs: $cell");
    }
    return $cell;
}

function assert_same_text($actual, $expected, $label)
{
    if ($actual !== $expected) {
        fail_test("$label was " . var_export($actual, true) . ', expected ' . var_export($expected, true));
    }
}

function assert_html_listing($body, $reference, $hidden, $label)
{
    assert_no_fatal_error($body, $label);
    $columns = listing_columns($hidden);

    $headers = header_cells($body, $label);
    $referenceHeaders = header_cells($reference, "$label reference");
    $filters = filter_cells($body, $label);
    $referenceFilters = filter_cells($reference, "$label reference");
    foreach ($columns as $col => $isHidden) {
        if ($isHidden) {
            $expectedHeader = '<td><a href="index.php?action=all&hide=-' . $col
                . '"><img class="icon" src="icons/add.png"></a>';
            $expectedFilter = filter_without_input($referenceFilters[$col]);
        } else {
            $expectedHeader = $referenceHeaders[$col];
            $expectedFilter = $referenceFilters[$col];
        }
        assert_same_text($headers[$col], $expectedHeader, "$label header column $col");
        assert_same_text($filters[$col], $expectedFilter, "$label filter column $col");
    }

    $rows = voucher_rows($body, $label);
    $referenceRows = voucher_rows($reference, "$label reference");
    if (count($rows) !== count($referenceRows)) {
        fail_test("$label has " . count($rows) . ' rows, expected ' . count($referenceRows));
    }
    foreach ($referenceRows as $index => $referenceRow) {
        foreach ($columns as $col => $isHidden) {
            $expected = $isHidden ? '<td>' : $referenceRow[$col];
            assert_same_text($rows[$index][$col], $expected, "$label row $index column $col");
        }
    }
}

function assert_csv_listing($body, $reference, $hidden, $label)
{
    $bom = "\xEF\xBB\xBF";
    if (strpos($body, $bom) !== 0 || strpos($reference, $bom) !== 0) {
        fail_test("$label: CSV does not start with the UTF-8 BOM");
    }
    $expectedLines = array();
    $rows = 0;
    foreach (explode("\n", substr($reference, strlen($bom))) as $line) {
        $fields = explode("\t", $line);
        if (count($fields) === LISTING_COLUMNS + 1 && end($fields) === '') {
            $kept = '';
            for ($col = 1; $col <= LISTING_COLUMNS; $col++) {
                if (!in_array($col, $hidden, true)) {
                    $kept .= $fields[$col - 1] . "\t";
                }
            }
            $line = $kept;
            $rows++;
        }
        $expectedLines[] = $line;
    }
    // Header line plus the seven 2024 voucher rows.
    if ($rows !== 8) {
        fail_test("$label: reference CSV has $rows listing lines, expected 8");
    }
    assert_same_text(substr($body, strlen($bom)), implode("\n", $expectedLines), "$label CSV");
}

function listing($cookies, $suffix = '')
{
    $response = http_request(LISTING_PATH . $suffix, 'GET', array(), $cookies);
    assert_status($response, 200, 'listing ' . LISTING_PATH . $suffix);
    return $response['body'];
}

$adminCookies = login_user('synthetic-admin', 'correct-password');
$referenceHtml = listing($adminCookies);
$referenceCsv = listing($adminCookies, '&format=csv');
assert_contains($referenceHtml, '<td>Closed donation</td>', 'reference listing');
assert_html_listing($referenceHtml, $referenceHtml, array(), 'reference listing');
assert_csv_listing($referenceCsv, $referenceCsv, array(), 'reference listing');

$users = array(
    'odd' => array('synthetic-hide-odd', 'hide-odd-password', odd_columns()),
    'even' => array('synthetic-hide-even', 'hide-even-password', even_columns()),
);
foreach ($users as $name => $user) {
    list($login, $password, $hidden) = $user;
    $cookies = login_user($login, $password);
    assert_html_listing(listing($cookies), $referenceHtml, $hidden, "$name-column listing");
    assert_csv_listing(listing($cookies, '&format=csv'), $referenceCsv, $hidden, "$name-column listing");
    $users[$name][] = $cookies;
}

// Showing a stored hidden column again stores it as 0 for this request; it
// must then be shown. Hiding it again restores the stored preference.
list(, , $oddHidden, $oddCookies) = $users['odd'];
$shown = array_values(array_diff($oddHidden, array(3)));
assert_html_listing(listing($oddCookies, '&hide=-3'), $referenceHtml, $shown, 'odd-column listing after hide=-3');
assert_html_listing(listing($oddCookies), $referenceHtml, $shown, 'odd-column listing, stored without 3');
assert_csv_listing(listing($oddCookies, '&format=csv'), $referenceCsv, $shown, 'odd-column listing, stored without 3');
assert_html_listing(listing($oddCookies, '&hide=3'), $referenceHtml, $oddHidden, 'odd-column listing after hide=3');

list(, , $evenHidden, $evenCookies) = $users['even'];
$moreHidden = array_merge($evenHidden, array(1));
assert_html_listing(listing($evenCookies, '&hide=1'), $referenceHtml, $moreHidden, 'even-column listing after hide=1');
assert_csv_listing(listing($evenCookies, '&format=csv'), $referenceCsv, $moreHidden, 'even-column listing, stored with 1');
assert_html_listing(listing($evenCookies, '&hide=-1'), $referenceHtml, $evenHidden, 'even-column listing after hide=-1');

assert_no_unexpected_diagnostics();
echo "Listing column preference tests passed\n";
