<?php
/*
 * Output encoding of database and request values in the HTML pages, and
 * formula neutralization in the CSV export (html_escape(), listing_text(),
 * html_escape_url() and tag() in functions.php, and their call sites).
 *
 * Synthetic rows with markup, quotes, "&" and umlauts in every displayed text
 * column must appear as text: "<s>field</s> & "Ö" 'x'" is shown as
 * "&lt;s&gt;field&lt;/s&gt; &amp; &quot;Ö&quot; &#039;x&#039;", never as
 * markup, while umlauts and ordinary values stay unchanged. The CSV export
 * keeps the raw text (no HTML entities) and prefixes cells that start with
 * = + - @ with a single quote; tabs and carriage returns inside a cell
 * become spaces, so they cannot split cells.
 *
 * Runs last on the lane's database and removes its rows again.
 * Must stay compatible with PHP 5.6.
 */
require __DIR__ . '/http-test-helpers.php';

$database = @pg_connect(pgacc_test_connection_string());
if ($database === false) {
    fail_test('could not connect to the fixture database');
}

function escaping_query($database, $query, $parameters = array())
{
    $result = @pg_query_params($database, $query, $parameters);
    if ($result === false) {
        fail_test("query failed: $query: " . pg_last_error($database));
    }
    return $result;
}

function payload($field)
{
    return "<s>$field</s> & \"Ö\" 'x'";
}

function escaped($field)
{
    return "&lt;s&gt;$field&lt;/s&gt; &amp; &quot;Ö&quot; &#039;x&#039;";
}

function assert_escaped($body, $field, $label)
{
    assert_contains($body, escaped($field), "$label ($field)");
    assert_not_contains($body, "<s>$field</s>", "$label ($field)");
}

function page($path, $cookies = array(), $method = 'GET', $form = array())
{
    $response = http_request($path, $method, $form, $cookies);
    assert_status($response, 200, "$method $path");
    assert_no_fatal_error($response['body'], "$method $path");
    return $response['body'];
}

// Pages that raise known, documented diagnostics of code outside this test's
// scope (tests/COMPATIBILITY_PLAN.md): they are attributed to $id, must all
// match $expected (file, message part), and must still occur.
function with_expected_diagnostics($id, $expected, $callback)
{
    $before = count($GLOBALS['pgacc_test_diagnostics']);
    $result = with_known_failure($id, $callback);
    $seen = 0;
    for ($i = $before; $i < count($GLOBALS['pgacc_test_diagnostics']); $i++) {
        $record = $GLOBALS['pgacc_test_diagnostics'][$i];
        if ($record['known_failure'] !== $id) {
            continue;
        }
        $matched = false;
        foreach ($expected as $diagnostic) {
            if ($record['file'] === $diagnostic[0] && strpos($record['message'], $diagnostic[1]) !== false) {
                $matched = true;
            }
        }
        if (!$matched) {
            fail_test("$id: unexpected diagnostic {$record['file']}:{$record['line']} {$record['message']}");
        }
        $seen++;
    }
    if ($seen === 0) {
        fail_test("$id no longer occurs: turn it into a regular check");
    }
    return $result;
}

function body_part($body, $start, $end, $label)
{
    $from = strpos($body, $start);
    if ($from === false) {
        fail_test("$label: missing " . var_export($start, true));
    }
    $to = strpos($body, $end, $from);
    if ($to === false) {
        fail_test("$label: missing " . var_export($end, true));
    }
    return substr($body, $from, $to - $from);
}

$voucherColumns = 'id, voucher_id, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account,'
    . ' comment, committed, receipt_received, deleted, date, ack1, ack2, person_type, file, save_user, commentgf, refund,'
    . ' vaccount, ack1_old, ack2_old';
function insert_voucher($database, $columns, $values)
{
    $placeholders = array();
    for ($i = 1; $i <= count($values); $i++) {
        $placeholders[] = '$' . $i;
    }
    pg_free_result(escaping_query($database, "INSERT INTO vouchers ($columns) VALUES (" . implode(', ', $placeholders) . ')', $values));
}

// Reference data and users with markup in their names. The organization and
// suborganization names contain "Piratenpartei" so that the reports list them.
pg_free_result(escaping_query($database, 'INSERT INTO lo (id, name) VALUES (90, $1)', array('Piratenpartei ' . payload('lo'))));
pg_free_result(escaping_query($database, 'INSERT INTO oo (id, name, lo) VALUES (90, $1, 90)', array('Piratenpartei ' . payload('oo'))));
pg_free_result(escaping_query($database, 'INSERT INTO type (id, name, used, income, realtype) VALUES'
    . ' (90, $1, 0, 1, 90), (91, $2, 0, 0, 91), (92, $3, 0, -1, 92)', array(payload('type-in'), payload('type-out'), payload('type-wk'))));
pg_free_result(escaping_query($database, 'INSERT INTO users (id, name, hash, rights, hide) VALUES (90, $1, NULL, $2, \'\')', array(payload('user'), payload('rights'))));
$saved = pg_fetch_assoc(escaping_query($database, 'SELECT (SELECT name FROM type WHERE id = 11) AS type11,'
    . ' (SELECT name FROM lo WHERE id = 2) AS lo2, (SELECT name FROM person_type WHERE id = 1) AS ptname,'
    . ' (SELECT description FROM person_type WHERE id = 1) AS ptdescription'));
pg_free_result(escaping_query($database, 'UPDATE type SET name = $1 WHERE id = 11', array(payload('type11'))));
pg_free_result(escaping_query($database, 'UPDATE lo SET name = $1 WHERE id = 2', array(payload('lo2'))));
pg_free_result(escaping_query($database, 'UPDATE person_type SET name = $1, description = $2 WHERE id = 1', array(payload('ptname'), payload('ptdescription'))));

// An approved income line with markup in every text column, an approved
// expense, and an expense with ordinary text and umlauts.
insert_voucher($database, $voucherColumns, array(9001, 9001, 90, 90, 'false', null, payload('contra'), payload('name'),
    payload('street'), payload('plz'), payload('city'), 1234, payload('account'), payload('comment'), 'false', 'false',
    'false', '2024-07-01', payload('ack1'), payload('ack2'), 1, 0, 1, payload('commentgf'), 'false', payload('vaccount'),
    payload('ackold1'), payload('ackold2')));
insert_voucher($database, $voucherColumns, array(9002, 9002, 91, 90, 'false', null, '', payload('spendname'),
    '', '', '', -200, 'TEST-ESCAPING', 'Escaping expense', 'false', 'false',
    'false', '2024-07-02', 'reviewer-a', 'reviewer-b', 1, 0, 1, '', 'false', '', null, null));
insert_voucher($database, $voucherColumns, array(9003, 9003, 91, 90, 'false', null, 'AT12 3456', 'Jürgen Müller-Größ',
    'Hauptstraße 1', '8010', 'Graz-Ödön', -300, 'TEST-ESCAPING', 'Büromaterial für Österreich', 'false', 'false',
    'false', '2024-07-03', 'reviewer-a', 'reviewer-b', 1, 0, 1, 'Grüße', 'false', 'TEST-ÄÖÜ', null, null));
// CSV cells that a spreadsheet would read as formulas.
insert_voucher($database, $voucherColumns, array(9004, 9004, 91, 90, 'false', null, '-2+3', '+cmd|x',
    "\tTab", "\rCR", '@SUM(A1)', -400, 'TEST-ESCAPING', '=HYPERLINK("http://example.invalid")', 'false', 'false',
    'false', '2024-07-04', 'reviewer-a', 'reviewer-b', 1, 0, 1, '=1+1', 'false', "\"A\tB", null, null));

$admin = login_user('synthetic-admin', 'correct-password');

// Ledger listing (closed.php page_listing_line() for all, open, closed and
// deleted).
$listing = page('/index.php?action=all&filter_date=2024', $admin);
$row = body_part($listing, '<tr><td><a href="index.php?action=edit&id=9001&bid=9001">', '</tr>', 'listing row');
$expectedRow = '<tr><td><a href="index.php?action=edit&id=9001&bid=9001">9001</a></td>' . "\n"
    . "<td>9001</td>\n<td>2024-07-01</td>\n<td>" . escaped('type-in') . "</td>\n<td>Piratenpartei " . escaped('lo') . "</td>\n"
    . "<td>Nein</td>\n<td>" . escaped('contra') . "</td>\n<td>" . escaped('account') . "</td>\n<td>" . escaped('vaccount') . "</td>\n"
    . "<td>12.34€</td>\n<td>" . escaped('comment') . "</td>\n<td>" . escaped('commentgf') . "</td>\n<td>Nein</td>\n"
    . '<td>' . escaped('ack1') . ' ' . escaped('ack2') . "</td>\n"
    . '<td><del>' . escaped('ackold1') . '</del> <del>' . escaped('ackold2') . "</del></td>\n<td>Nein</td>\n<td>Nein</td>\n"
    . '<td>' . escaped('name') . ' ' . escaped('street') . ' ' . escaped('plz') . ' ' . escaped('city') . "</td>\n";
if ($row !== $expectedRow) {
    fail_test('listing row: ' . var_export($row, true));
}
foreach (array('type-in', 'lo', 'contra', 'account', 'vaccount', 'comment', 'commentgf', 'ack1', 'ack2', 'ackold1', 'ackold2', 'name', 'street', 'plz', 'city') as $field) {
    assert_escaped($listing, $field, 'listing');
}
assert_contains($listing, "<td>Büromaterial für Österreich</td>\n<td>Grüße</td>", 'listing with umlauts');
assert_contains($listing, '<td>Jürgen Müller-Größ Hauptstraße 1 8010 Graz-Ödön</td>', 'listing with umlauts');
assert_contains($listing, "<td>=HYPERLINK(&quot;http://example.invalid&quot;)</td>\n<td>=1+1</td>", 'listing formulas in HTML');

// CSV export: raw text without entities, formulas neutralized.
$csv = page('/index.php?action=all&filter_date=2024&format=csv', $admin);
$csvRow = "9001\t9001\t2024-07-01\t" . payload('type-in') . "\tPiratenpartei " . payload('lo') . "\tNein\t" . payload('contra') . "\t"
    . payload('account') . "\t" . payload('vaccount') . "\t12,34€\t" . payload('comment') . "\t" . payload('commentgf') . "\tNein\t"
    . payload('ack1') . ' ' . payload('ack2') . "\t<del>" . payload('ackold1') . '</del> <del>' . payload('ackold2') . "</del>\tNein\tNein\t"
    . payload('name') . ' ' . payload('street') . ' ' . payload('plz') . ' ' . payload('city') . "\t\n";
assert_contains($csv, "\n" . $csvRow, 'CSV row with markup');
assert_not_contains($csv, '&lt;', 'CSV export');
assert_not_contains($csv, '&quot;', 'CSV export');
assert_contains($csv, "\n9003\t9003\t2024-07-03\t" . payload('type-out') . "\tPiratenpartei " . payload('lo') . "\tNein\tAT12 3456\tTEST-ESCAPING\tTEST-ÄÖÜ\t-3,00€\tBüromaterial für Österreich\tGrüße\tNein\treviewer-a reviewer-b\t<del></del> <del></del>\tNein\tNein\tJürgen Müller-Größ Hauptstraße 1 8010 Graz-Ödön\t\n", 'CSV row with umlauts');
assert_contains($csv, "\n9004\t9004\t2024-07-04\t" . payload('type-out') . "\tPiratenpartei " . payload('lo') . "\tNein\t'-2+3\tTEST-ESCAPING\t'\"A B\t-4,00€\t'=HYPERLINK(\"http://example.invalid\")\t'=1+1\tNein\treviewer-a reviewer-b\t<del></del> <del></del>\tNein\tNein\t'+cmd|x  Tab  / CR @SUM(A1)\t\n", 'CSV row with formulas');

// Filter values are reflected in the sort links and the filter inputs.
$filters = array('filter_id', 'filter_bid', 'filter_date', 'filter_type', 'filter_lo', 'filter_member_id', 'filter_gk', 'filter_k',
    'filter_vk', 'filter_amount', 'filter_text', 'filter_comment', 'filter_comm', 'filter_ack', 'filter_ack_old', 'filter_refund',
    'filter_bel', 'filter_name');
$query = 'action=all&sort=' . rawurlencode(payload('sort'));
foreach ($filters as $filter) {
    $query .= "&$filter=" . rawurlencode(payload($filter));
}
$filtered = with_expected_diagnostics('KF-AMOUNT-FILTER-REGEX', array(array('functions.php', 'preg_match()')), function () use ($query, $admin) {
    return page('/index.php?' . $query, $admin);
});
assert_contains($filtered, '<input type="hidden" name="sort" value="' . escaped('sort') . '" />', 'sort input');
$sortFilters = '';
foreach ($filters as $filter) {
    $sortFilters .= "&amp;$filter=" . rawurlencode(payload($filter));
    $value = 'name="' . $filter . '" value="' . escaped($filter) . '"';
    assert_contains($filtered, $value, "$filter input");
    assert_escaped($filtered, $filter, 'filtered listing');
}
assert_contains($filtered, '<a href="index.php?action=all&amp;sort=idd' . $sortFilters . '">Buchung</a>', 'sort link');
assert_escaped($filtered, 'sort', 'filtered listing');
// Sort links carry the filters URL-encoded, so &, #, % and + survive.
$amount = page('/index.php?action=all&filter_amount=' . rawurlencode('>0') . '&filter_text=' . rawurlencode('Büro') . '&filter_name=' . rawurlencode('a&b#c%d+e'), $admin);
assert_contains($amount, '<a href="index.php?action=all&amp;sort=idd&amp;filter_amount=%3E0&amp;filter_text=B%C3%BCro&amp;filter_name=a%26b%23c%25d%2Be">Buchung</a>', 'amount filter link');
assert_contains($amount, 'name="filter_amount" value="&gt;0"', 'amount filter input');
assert_contains($amount, 'name="filter_name" value="a&amp;b#c%d+e"', 'name filter input');
$deleted = page('/index.php?action=deleted&filter_text=' . rawurlencode(payload('deleted-filter')), $admin);
assert_contains($deleted, 'name="filter_text" value="' . escaped('deleted-filter') . '"', 'deleted listing');
assert_escaped($deleted, 'deleted-filter', 'deleted listing');

// The CSV link repeats the request URI in an attribute.
$uri = page('/index.php?action=all&filter_date=2024&x="><s>uri</s>\'', $admin);
assert_contains($uri, '<a href="/index.php?action=all&amp;filter_date=2024&amp;x=&quot;&gt;&lt;s&gt;uri&lt;/s&gt;&#039;&amp;format=csv">Als CSV Datei herunterladen</a>', 'CSV link');
assert_not_contains($uri, '<s>uri</s>', 'CSV link');

// Edit form (new.php page_edit_form() and edit.php page_edit()).
$edit = page('/index.php?action=edit&id=9001', $admin);
foreach (array('ack1', 'ack2') as $field) {
    assert_contains($edit, '<input type="hidden" name="' . $field . '" value="' . escaped($field) . '" />', "edit hidden $field");
}
assert_contains($edit, '<input type="hidden" name="ack1_old" value="' . escaped('ackold1') . '" />', 'edit hidden ack1_old');
assert_contains($edit, '<input type="hidden" name="ack2_old" value="' . escaped('ackold2') . '" />', 'edit hidden ack2_old');
assert_contains($edit, '<h4>Bestätigt von: ' . escaped('ack1') . ' ' . escaped('ack2') . '<del>' . escaped('ackold1') . ' ' . escaped('ackold2') . '</del></h4>', 'edit approvals');
$fields = array('gegenkonto0' => 'contra', 'konto0' => 'account', 'comment0' => 'comment', 'commentgf0' => 'commentgf',
    'vkonto0' => 'vaccount', 'name0' => 'name', 'street0' => 'street', 'plz0' => 'plz', 'city0' => 'city');
foreach ($fields as $input => $field) {
    assert_contains($edit, 'name="' . $input . '" value="' . escaped($field) . '" onchange="clicked();" />', "edit $input");
}
foreach (array('type-in' => 90, 'type-out' => 91, 'type-wk' => 92, 'ptname' => 1, 'lo' => 90) as $field => $id) {
    $text = $field === 'lo' ? 'Piratenpartei ' . escaped($field) : escaped($field);
    assert_contains($edit, '"' . ($field === 'type-in' || $field === 'ptname' || $field === 'lo' ? ' selected="selected"' : '') . '>' . $text . '</option>', "edit option $field");
}
foreach (array('ack1', 'ack2', 'ackold1', 'ackold2', 'contra', 'account', 'comment', 'commentgf', 'vaccount', 'name', 'street', 'plz', 'city', 'type-in', 'type-out', 'type-wk', 'ptname', 'lo') as $field) {
    assert_escaped($edit, $field, 'edit');
}

// The new-voucher form reads the undefined $bgfandack (end_of_form()).
function new_form($admin, $form)
{
    return with_expected_diagnostics('KF-NEW-UNDEFINED-BGFANDACK', array(array('new.php', 'Undefined variable')), function () use ($admin, $form) {
        return page('/index.php?action=new', $admin, 'POST', $form);
    });
}
// Forms re-rendered from the posted values show them as entered: a quote
// and a backslash are not doubled by the SQL escaping of get_voucher().
$entered = "O'Brien \\ Ö & <b>";
$enteredHtml = 'O&#039;Brien \\ Ö &amp; &lt;b&gt;';
$posted = array('parts' => '1', 'add' => 'Buchungszeile hinzufügen', 'name0' => $entered, 'street0' => $entered, 'plz0' => $entered,
    'city0' => $entered, 'comment0' => $entered, 'commentgf0' => $entered);
$newForm = new_form($admin, $posted);
$editForm = page('/index.php?action=edit&id=9001', $admin, 'POST', array_merge($posted, array('changed' => '1', 'id' => '9001')));
foreach (array('new' => $newForm, 'edit' => $editForm) as $label => $form) {
    foreach (array('name0', 'street0', 'plz0', 'city0', 'comment0', 'commentgf0') as $input) {
        assert_contains($form, 'name="' . $input . '" value="' . $enteredHtml . '" onchange="clicked();" />', "$label form $input");
    }
    assert_contains($form, 'name="name1" value="' . $enteredHtml . '" onchange="clicked();" />', "$label form added part");
}

// A removed part shows the next part's posted values.
$removedPost = array('parts' => '2', 'remove' => '0', 'name1' => $entered);
$newRemoved = new_form($admin, $removedPost);
$editRemoved = page('/index.php?action=edit&id=9001', $admin, 'POST', array_merge($removedPost, array('changed' => '1', 'id' => '9001')));
foreach (array('new' => $newRemoved, 'edit' => $editRemoved) as $label => $form) {
    assert_contains($form, 'name="name0" value="' . $enteredHtml . '" onchange="clicked();" />', "$label form after removing a part");
}
// Saving still escapes the values for SQL: they are stored as entered.
$savedForm = page('/index.php?action=new', $admin, 'POST', array('parts' => '1', 'speichern' => 'Speichern', 'date0' => '2024-07-06',
    'dir0' => 'in', 'in_type0' => '90', 'lo0' => '90', 'amount0' => '1,00', 'name0' => $entered, 'comment0' => $entered,
    'commentgf0' => $entered, 'street0' => $entered, 'plz0' => $entered, 'city0' => $entered));
assert_contains($savedForm, '<h1>Buchung erfasst', 'saved voucher');
$stored = pg_fetch_assoc(escaping_query($database, 'SELECT name, comment, commentgf, street, plz, city FROM vouchers WHERE NOT deleted AND date = $1 AND orga = 90 AND amount = 100',
    array('2024-07-06')));
if ($stored === false || count(array_unique(array_values($stored))) !== 1 || $stored['name'] !== $entered) {
    fail_test('saved voucher: ' . var_export($stored, true));
}

// User administration (accounts.php) for root.
$accounts = page('/index.php?action=accounts', $admin);
assert_contains($accounts, '<tr><td>' . escaped('user') . '</td><td><input type="password" name="password90" value="******"></td><td><input type="text" name="rights90" value="' . escaped('rights') . '" /></td>', 'accounts row');
assert_escaped($accounts, 'user', 'accounts');
assert_escaped($accounts, 'rights', 'accounts');

// Public pages.
$report = page('/index.php?year=2024&print_empty=1');
foreach (array('type-in', 'type-out', 'type-wk', 'lo', 'oo', 'ptdescription') as $field) {
    assert_escaped($report, $field, 'report');
}
$lo = 'Piratenpartei ' . escaped('lo');
$overview = body_part($report, '<table id="financeOverview">', '<h1>Inhaltsübersicht</h1>', 'report overview');
$overviews = explode('<table id="financeOverview">', $overview);
if (count($overviews) !== 3) {
    fail_test('report overview tables: ' . count($overviews));
}
assert_contains($overviews[1], "<tr><td><a href=\"#$lo\">$lo</a></td>\n", 'report first overview');
assert_contains($overviews[2], "<tr><td><a href=\"#$lo\">$lo</a></td>\n", 'report second overview');
$units = body_part($report, '<h1><a name="eal">', '<h1><a name="awbi">', 'report units');
assert_contains($units, "<br /><h2><a name=\"$lo\" href=\"#$lo\">$lo</a></h2>\n", 'report unit heading');
assert_contains($units, '<h3><a name="Piratenpartei ' . escaped('oo') . '">Piratenpartei ' . escaped('oo') . "</a></h3>\n", 'report suborganization heading');
assert_contains($units, '<tr><td>' . escaped('type-in') . '</td><td>', 'report income type');
assert_contains($units, '<tr><td>' . escaped('type-out') . '</td><td>', 'report expense type');
$campaign = body_part($report, '<h1><a name="awl">', '<h1><a name="lups">', 'report campaign units');
assert_contains($campaign, "<br /><h2><a name=\"$lo\" href=\"#$lo\">$lo</a></h2>\n", 'report campaign unit heading');
assert_contains($campaign, '<h3><a name="Piratenpartei ' . escaped('oo') . '">Piratenpartei ' . escaped('oo') . "</a></h3>\n", 'report campaign suborganization heading');
assert_contains($campaign, '<tr><td>' . escaped('type-wk') . '</td><td>', 'report campaign type');
assert_contains($report, '<tr><td><b>' . escaped('ptdescription') . '</b></td>', 'report Anlage A');

$wk = with_expected_diagnostics('KF-WK-UNDEFINED-YEAR', array(array('wk.php', 'Undefined variable')), function () {
    return page('/index.php?action=wk&print_empty=1');
});
foreach (array('type-in', 'type-out', 'type-wk', 'type11', 'lo', 'oo') as $field) {
    assert_escaped($wk, $field, 'wk');
}
assert_contains($wk, '<a href="index.php?action=kdonations&type=11&from=2012-09-28&to=2013-01-29&unit=6">' . escaped('type11') . '</a>', 'wk in-kind type');
assert_contains($wk, '<tr><td>' . escaped('type-in') . '</td><td>', 'wk income type');
assert_contains($wk, '<a href="index.php?action=spendings&type=91&from=2012-09-28&to=2013-01-29&unit=6">' . escaped('type-out') . '</a>', 'wk expense type');
assert_contains($wk, '<a href="index.php?action=spendings&type=92&from=2012-09-28&to=2013-01-29&unit=6">' . escaped('type-wk') . '</a>', 'wk campaign type');
assert_contains($wk, "<br /><h2><a name=\"nrw{$lo}2013\" href=\"#$lo\">$lo</a></h2>\n", 'wk unit heading');
assert_contains($wk, '<h3><a name="Piratenpartei ' . escaped('oo') . '">Piratenpartei ' . escaped('oo') . "</a></h3>\n", 'wk suborganization heading');

$transactions = page('/index.php?action=transactions');
assert_contains($transactions, "<td>2024-07-01</td>\n<td>" . escaped('type-in') . "</td>\n<td>$lo</td>\n<td>" . escaped('account') . "</td>\n<td>12.34€</td>\n", 'transactions row');
$transactionsCsv = page('/index.php?action=transactions&format=csv');
assert_contains($transactionsCsv, "\n2024-07-01\t" . payload('type-in') . "\tPiratenpartei " . payload('lo') . "\t" . payload('account') . "\t12,34€\t\n", 'transactions CSV row');

$spendings = page('/index.php?action=spendings');
assert_contains($spendings, "<td>9002</td>\n<td>2024-07-02</td>\n<td>" . escaped('type-out') . "</td>\n<td>$lo</td>\n<td>-2.00€</td>\n<td>" . escaped('spendname') . "</td>\n", 'spendings row');
assert_contains($spendings, "<td>-3.00€</td>\n<td>Jürgen Müller-Größ</td>\n", 'spendings row with umlauts');
$spendingsCsv = page('/index.php?action=spendings&format=csv');
assert_contains($spendingsCsv, "\n9002\t2024-07-02\t" . payload('type-out') . "\tPiratenpartei " . payload('lo') . "\t-2,00€\t" . payload('spendname') . "\t\n", 'spendings CSV row');
assert_contains($spendingsCsv, "\n9004\t2024-07-04\t" . payload('type-out') . "\tPiratenpartei " . payload('lo') . "\t-4,00€\t'+cmd|x\t\n", 'spendings CSV formula');

$vreport = with_expected_diagnostics('KF-REPORT-UNDEFINED-UNIT', array(array('vreport.php', 'Undefined variable')), function () {
    return page('/index.php?action=vreport&konto=TEST');
});
assert_contains($vreport, '<li><a href="vreport?konto=' . escaped('vaccount') . '">Konto \'' . escaped('vaccount') . '\'</a></li>', 'vreport account');
assert_contains($vreport, "<li><a href=\"vreport?konto=TEST-ÄÖÜ\">Konto 'TEST-ÄÖÜ'</a></li>", 'vreport account with umlauts');
foreach (array('vaccount', 'type-in', 'type-out') as $field) {
    assert_escaped($vreport, $field, 'vreport');
}
$tinyreport = with_expected_diagnostics('KF-REPORT-UNDEFINED-UNIT', array(array('tinyreport.php', 'Undefined variable')), function () use ($admin) {
    return page('/index.php?action=tinyreport&konto=TEST', $admin);
});
assert_contains($tinyreport, '<li><a href="tinyreport?konto=' . escaped('account') . '">Konto \'' . escaped('account') . '\'</a></li>', 'tinyreport account');
foreach (array('account', 'type-in', 'type-out') as $field) {
    assert_escaped($tinyreport, $field, 'tinyreport');
}

// Membership fees (mb.php): organization names, and member names from the
// membership system ("O'Brien Synthetisch", member 9033).
$feeColumns = 'id, voucher_id, type, orga, member, member_id, amount, deleted, date, ack1, ack2';
insert_voucher($database, $feeColumns, array(9005, 9005, 1, 90, 'true', 9004, 500, 'false', date('Y-m-d'), 'reviewer-a', 'reviewer-b'));
// An open loan (type 13) shows its name.
insert_voucher($database, 'id, voucher_id, type, orga, member, name, amount, deleted, date', array(9007, 9007, 13, 90, 'false', payload('loan'), 700, 'false', '2024-07-05'));
$mb = page('/index.php?action=mb', $admin);
assert_contains($mb, '<tr><td>' . escaped('lo2') . '</td><td>', 'mb organization of members');
assert_contains($mb, "<tr><td>$lo</td><td>5.00 €</td></tr>", 'mb organization of accounts');
$inconsistent = body_part($mb, '<h2>Inkonsistenzen bei Zahlungsstatus</h2>', '<h2>Mitglieder mit konsistentem Zahlungsstatus</h2>', 'mb inconsistent');
assert_contains($inconsistent, "<td>O&#039;Brien Synthetisch</td>", 'mb inconsistent member');
assert_not_contains($mb, "O'Brien", 'mb');
insert_voucher($database, $feeColumns, array(9006, 9006, 1, 90, 'true', 9033, 2500, 'false', date('Y-m-d'), 'reviewer-a', 'reviewer-b'));
$mb = page('/index.php?action=mb', $admin);
$consistent = body_part($mb, '<h2>Mitglieder mit konsistentem Zahlungsstatus</h2>', '<h2>Offene Darlehen</h2>', 'mb consistent');
assert_contains($consistent, "<td>O&#039;Brien Synthetisch</td>", 'mb consistent member');
assert_not_contains($mb, "O'Brien", 'mb');
assert_contains($mb, '<td>Anna Synthetisch-Müller</td>', 'mb member with umlauts');
assert_contains($mb, '<a href="index.php?action=edit&id=9007">9007</a></td>' . "\n  <td>7</td>\n  <td>" . escaped('loan') . '</td></tr>', 'mb open loan');
assert_escaped($mb, 'loan', 'mb');

// Bank CSV import (import.php): file names, MIME types and the report of an
// invalid line. Each imported line raises the known KF-IMPORT-REGEX-G warning.
function upload($path, $cookies, $field, $name, $type, $content, $form = array())
{
    $response = http_request($path, 'POST', $form, $cookies, null, array($field => array('name' => $name, 'type' => $type, 'content' => $content)));
    assert_status($response, 200, "upload to $path");
    assert_no_fatal_error($response['body'], "upload to $path");
    return $response['body'];
}
$fileName = '<i id=fn>&ä.csv';
$fileNameEscaped = '&lt;i id=fn&gt;&amp;ä.csv';
$handkassa = "Datum;Betrag;Konto;Text;Kommentar;Fremdkonto;Zweckwidmung;Kostenrückerstattung;Name;Mitgliedsnummer;Lo;Adresse;PLZ;Stadt\r\n"
    . "Montag;5,00;Handkassa;<s>csvline</s>&ä;;;Nein;Nein;Synthetic Importer;0;1;;;\r\n";
$import = with_known_failure('KF-IMPORT-REGEX-G', function () use ($admin, $fileName, $handkassa) {
    return upload('/index.php?action=import', $admin, 'uploadcsv[]', $fileName, 'text/csv', $handkassa, array('MAX_FILE_SIZE' => '1000000'));
});
assert_contains($import, "<h2>Importiere $fileNameEscaped</h2>", 'import heading');
assert_contains($import, "Ungültige Buchungszeile in Datei 1 ($fileNameEscaped), Zeile 2 <br />", 'import invalid line');
assert_contains($import, "<pre>Montag;5,00;Handkassa;&lt;s&gt;csvline&lt;/s&gt;&amp;ä;;;Nein;Nein;Synthetic Importer;0;1;;;</pre>", 'import raw line');
assert_contains($import, "<pre>Montag;5,00;Handkassa;&lt;s&gt;csvline&lt;/s&gt;&amp;ä;;;Nein;Nein;SyntheticImporter;0;1;;;</pre>", 'import line');
assert_contains($import, "Datei 1 ($fileNameEscaped): 1 Buchungszeilen übersprungen (Zeilen 2)<br />", 'import skipped');
assert_contains($import, "Datei 1 ($fileNameEscaped) wurde erfolgreich importiert<br />", 'import done');
assert_not_contains($import, '<i id=fn>', 'import');
assert_not_contains($import, '<s>csvline</s>', 'import');
$wrongType = upload('/index.php?action=import', $admin, 'uploadcsv[]', $fileName, 'text/x-<i id=ty>', $handkassa, array('MAX_FILE_SIZE' => '1000000'));
assert_contains($wrongType, "Datei 1 ($fileNameEscaped) hat ein ungültiges Dateiformat (text/x-&lt;i id=ty&gt;) und konnte daher nicht importiert werden.", 'import MIME type');
assert_not_contains($wrongType, '<i id=', 'import MIME type');
$tooSmall = upload('/index.php?action=import', $admin, 'uploadcsv[]', $fileName, 'text/csv', 'x;', array('MAX_FILE_SIZE' => '1000000'));
assert_contains($tooSmall, "Datei 1 ($fileNameEscaped) ist zu klein", 'import small file');
assert_not_contains($tooSmall, '<i id=fn>', 'import small file');

// Receipt import (import2.php): file name and MIME type.
$receipt = upload('/index.php?action=import2', $admin, 'upload[]', '<i id=pdf>&ä.pdf', 'text/x-<i id=ty>', '%PDF-1.4 synthetic');
assert_contains($receipt, 'Datei 1 (&lt;i id=pdf&gt;&amp;ä.pdf):<br />Hat ein ungültiges Dateiformat (text/x-&lt;i id=ty&gt;) und konnte daher nicht importiert werden.<br />', 'receipt import');
assert_not_contains($receipt, '<i id=', 'receipt import');

// Remove the rows again and restore the changed names.
pg_free_result(escaping_query($database, 'DELETE FROM vouchers WHERE id BETWEEN 9001 AND 9007'));
pg_free_result(escaping_query($database, "DELETE FROM vouchers WHERE comment LIKE '%csvline%'"));
pg_free_result(escaping_query($database, 'DELETE FROM vouchers WHERE name = $1', array($entered)));
pg_free_result(escaping_query($database, 'DELETE FROM users WHERE id = 90'));
pg_free_result(escaping_query($database, 'DELETE FROM type WHERE id IN (90, 91, 92)'));
pg_free_result(escaping_query($database, 'DELETE FROM oo WHERE id = 90'));
pg_free_result(escaping_query($database, 'DELETE FROM lo WHERE id = 90'));
pg_free_result(escaping_query($database, 'UPDATE type SET name = $1 WHERE id = 11', array($saved['type11'])));
pg_free_result(escaping_query($database, 'UPDATE lo SET name = $1 WHERE id = 2', array($saved['lo2'])));
pg_free_result(escaping_query($database, 'UPDATE person_type SET name = $1, description = $2 WHERE id = 1', array($saved['ptname'], $saved['ptdescription'])));
pg_close($database);

assert_no_unexpected_diagnostics();
echo "Output escaping HTTP tests passed\n";
