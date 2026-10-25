<?php

/*
 * Plain page views: every page of the index.php dispatch and every link of the
 * public and the logged-in menu, plus the year and account links those pages
 * offer, requested with GET and without further parameters as a root,bgf
 * user, a bgf user, a suborganization user and anonymously. Any PHP
 * diagnostic fails the run (tests/http-diagnostics.php), as does an HTTP
 * status other than 200 or a redirect.
 */
require __DIR__ . '/http-test-helpers.php';

// The pages index.php dispatches on ?action= (its in_array() list).
$index = file_get_contents(__DIR__ . '/../index.php');
if (preg_match('/if \(in_array\(\$_GET\["action"\],\s*\[([^\]]*)\]\)\)/', $index, $match) !== 1
    || preg_match_all('/"([a-z0-9]+)"/', $match[1], $actions) < 20) {
    fail_test('index.php: the ?action= dispatch list was not found');
}
$actions = $actions[1];

// Turns a link of a page into a request path; the pretty URLs ("donations",
// "vreport?konto=...") are rewritten to index.php?action= by the web server.
function page_view_path($href, $actions)
{
    $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
    if ($href === '/') {
        return '/index.php';
    }
    if (strpos($href, 'index.php') === 0) {
        return '/' . $href;
    }
    if (preg_match('/^([a-z0-9]+)(?:\?(.*))?$/D', $href, $parts) === 1 && in_array($parts[1], $actions, true)) {
        return '/index.php?action=' . $parts[1] . (isset($parts[2]) ? '&' . $parts[2] : '');
    }
    return null;
}

function page_view($path, $cookies, $label)
{
    $response = http_request($path, 'GET', array(), $cookies);
    // Collected, so that one run reports every broken page.
    if (!in_array($response['status'], array(200, 302, 303), true)) {
        $GLOBALS['page_view_problems'][] = "$label GET $path: HTTP {$response['status']}";
    } elseif (strpos($response['body'], 'Fatal error') !== false) {
        $GLOBALS['page_view_problems'][] = "$label GET $path: Fatal error";
    }
    return $response;
}

// Known failure (tests/COMPATIBILITY_PLAN.md): page_closed() selects three
// columns too few.
function page_view_known($path, $cookies, $label)
{
    $known = array(
        '/index.php?action=closed' => array('KF-CLOSED-MISSING-COLUMNS', 'closed.php', 'Undefined array key'),
    );
    if (!isset($known[$path])) {
        return page_view($path, $cookies, $label);
    }
    list($id, $file, $message) = $known[$path];
    if ($id === 'KF-CLOSED-MISSING-COLUMNS' && $cookies === array()) {
        return page_view($path, $cookies, $label); // login redirect
    }
    return with_expected_diagnostics($id, array(array($file, $message)), function () use ($path, $cookies) {
        return http_request($path, 'GET', array(), $cookies);
    });
}

$page_view_problems = array();
$users = array(
    'root,bgf' => login_user('synthetic-admin', 'correct-password'),
    'bgf' => login_user('synthetic-reviewer', 'reviewer-password'),
    'suborganization' => login_user('synthetic-unit-one', 'unit-password'),
    'anonymous' => array(),
);
$year = date('Y');
foreach ($users as $label => $cookies) {
    $paths = array('/index.php' => true);
    foreach ($actions as $action) {
        $paths['/index.php?action=' . $action] = true;
    }
    foreach (array('open', 'closed', 'all', 'deleted') as $action) {
        $paths["/index.php?action=$action&filter_date=$year"] = true;
    }
    // Follow the links without further parameters once: the menu links, the
    // year links (report, donations) and the account links (tinyreport,
    // vreport).
    $seen = array();
    while (count($paths) > 0) {
        $path = key($paths);
        unset($paths[$path]);
        $seen[$path] = true;
        $response = page_view_known($path, $cookies, $label);
        preg_match_all('/<a href="([^"]*)"/', $response['body'], $links);
        foreach ($links[1] as $href) {
            $next = page_view_path($href, $actions);
            if ($next === null || isset($seen[$next])) {
                continue;
            }
            parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
            if (count(array_diff(array_keys($query), array('action', 'filter_date', 'year', 'konto'))) > 0) {
                continue;
            }
            $paths[$next] = true;
        }
    }
    if (!isset($seen['/index.php?action=vreport&konto=TEST-VIRTUAL-2'])
        || !isset($seen['/index.php?action=donations&year=' . $year])) {
        fail_test("$label: the vreport account and the donations year links were not followed");
    }
    if ($label !== 'anonymous' && !isset($seen['/index.php?action=tinyreport&konto=TEST-ACCOUNT-2'])) {
        fail_test("$label: the tinyreport account links were not followed");
    }
}

// The account reports render both tables, income and expenses, of an account
// with both.
foreach (array('vreport' => 'TEST-VIRTUAL', 'tinyreport' => 'TEST-ACCOUNT') as $action => $konto) {
    $body = page_view("/index.php?action=$action&konto=$konto", $users['root,bgf'], 'root,bgf')['body'];
    assert_contains($body, "<h1>Bericht Konto $konto</h1>", "$action $konto");
    assert_contains($body, "<h3>Einnahmen</h3>", "$action $konto income");
    assert_contains($body, "<tr><td><b>Summe</b></td><td>235.00&nbsp;€</td></tr>", "$action $konto income sum");
    assert_contains($body, "<h3>Ausgaben</h3>", "$action $konto expenses");
    assert_contains($body, "<tr><td><b>Summe</b></td><td>-90.00&nbsp;€</td></tr>", "$action $konto expense sum");
}

// The virtual-account chart (js/vreport-chart.js, Chart.js): no inline
// script, only the two script files and the points (timestamp in ms, running
// total) as a JSON data element. TEST-VIRTUAL-1% also matches TEST-VIRTUAL-10.
$chart = page_view('/index.php?action=vreport&konto=TEST-VIRTUAL-1', array(), 'anonymous')['body'];
preg_match_all('/<script\b([^>]*)>/i', $chart, $scripts);
foreach ($scripts[1] as $attributes) {
    if (strpos($attributes, ' src="') === false && $attributes !== ' type="application/json" id="graph-data"') {
        fail_test("vreport chart: inline script <script$attributes>");
    }
}
assert_contains($chart, "<script src=\"js/chart.umd.min.js\"></script>\n<script src=\"js/vreport-chart.js\"></script>\n", 'vreport chart scripts');
assert_contains($chart, '<div id="graph" style="position: relative; width: 100%; height: 400px;"><canvas id="graph-canvas"></canvas></div>', 'vreport chart canvas');
assert_not_contains($chart, 'jquery', 'vreport chart without jQuery');
if (preg_match('#<script type="application/json" id="graph-data">(.*?)</script>#s', $chart, $graphData) !== 1) {
    fail_test('vreport chart: no graph-data element');
}
$points = array(
    array('1325289600000.000000', '50.0000000000000000'),  // 2011-12-31
    array('1706745600000.000000', '170.0000000000000000'), // 2024-02-01
    array('1709251200000.000000', '200.0000000000000000'), // 2024-03-01
    array('1719792000000.000000', '200.0000000000000000'), // 2024-07-01
    array('1722470400000.000000', '210.0000000000000000'), // 2024-08-01
    array('1735689600000.000000', '235.0000000000000000'), // 2025-01-01
);
if (json_decode($graphData[1], true) !== $points) {
    fail_test("vreport chart: unexpected points {$graphData[1]}");
}
// The web server serves both files as they are (static files bypass the
// diagnostics recorder, so they are read here instead of requested).
foreach (array('js/chart.umd.min.js' => 'Chart.js v4.6.1', 'js/vreport-chart.js' => "'graph-data'") as $script => $marker) {
    $content = @file_get_contents(__DIR__ . '/../' . $script);
    if ($content === false) {
        fail_test("$script is missing");
    }
    assert_contains($content, $marker, $script);
}

// The listing and report filters of the links of the campaign report
// (kdonations: type, unit, from, to) and of the account reports (several
// comma-separated accounts, date_begin, date_end).
$admin = $users['root,bgf'];
$spendings = page_view('/index.php?action=spendings&type=20&unit=2&from=2024-01-01&to=2025-01-01', array(), 'anonymous')['body'];
assert_contains($spendings, "<td>1003</td>\n<td>2024-04-01</td>\n", 'filtered spendings');
assert_contains($spendings, "<td>-50.00€</td>\n<td>Synthetic Supplier</td>\n</tr>", 'filtered spendings');
assert_not_contains($spendings, '-15.00€', 'filtered spendings (type 21)');
$laterSpendings = page_view('/index.php?action=spendings&type=20&unit=2&from=2024-05-01&to=2025-01-01', array(), 'anonymous')['body'];
assert_not_contains($laterSpendings, '<td>1003</td>', 'spendings from May 2024');
$kdonations = page_view('/index.php?action=kdonations&type=11&unit=1&from=2024-01-01&to=2025-01-01', $admin, 'root,bgf')['body'];
assert_contains($kdonations, 'Aus rechtlichen Gründen werden Spenden nur mit Zustimmung Veröffentlicht.</table>', 'filtered in-kind donations');
foreach (array('tinyreport' => 'TEST-ACCOUNT-1,TEST-ACCOUNT-2', 'vreport' => 'TEST-VIRTUAL-1,TEST-VIRTUAL-2') as $action => $konto) {
    $body = page_view("/index.php?action=$action&konto=$konto&date_begin=2024-01-01&date_end=2024-12-31", $admin, 'root,bgf')['body'];
    assert_contains($body, "<h1>Bericht Konto $konto</h1>", "$action $konto 2024");
    assert_contains($body, "<tr><td><b>Summe</b></td><td>160.00&nbsp;€</td></tr>", "$action $konto 2024 income sum");
    assert_contains($body, "<tr><td><b>Summe</b></td><td>-90.00&nbsp;€</td></tr>", "$action $konto 2024 expense sum");
}

$database = @pg_connect(pgacc_test_connection_string());
if ($database === false) {
    fail_test('could not connect to the fixture database');
}
function page_views_query($database, $query)
{
    if (@pg_query($database, $query) === false) {
        fail_test("query failed: $query: " . pg_last_error($database));
    }
}

// Visitors see a numeric virtual account (bank account number, "AT"
// and spaces ignored) by its last three characters only.
page_views_query($database, "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, date, person_type, file, vaccount)
    VALUES (9150, 4780, 8, 1, false, 100, 'TEST-ACCOUNT-1', 'Synthetic numeric virtual account', timestamp '2024-09-15 00:00:00', 2, 0, 'AT12 3456 7890')");
$numeric = page_view('/index.php?action=vreport', array(), 'anonymous')['body'];
assert_contains($numeric, "<li><a href=\"vreport?konto=890\">Konto '890'</a></li>", 'vreport numeric account for visitors');
$numeric = page_view('/index.php?action=vreport', $admin, 'root,bgf')['body'];
assert_contains($numeric, "<li><a href=\"vreport?konto=AT12%203456%207890\">Konto 'AT12 3456 7890'</a></li>", 'vreport numeric account');
page_views_query($database, 'DELETE FROM vouchers WHERE id = 9150');

// The report lists the balance of a "Piratenpartei" organization on the
// last day of the year.
page_views_query($database, "INSERT INTO lo (id, name) VALUES (93, 'Piratenpartei Synthetic Balance Unit')");
page_views_query($database, "INSERT INTO balance (id, value, orga, date) VALUES (93, 4321, 93, timestamp '2024-12-31 00:00:00')");
$report = page_view('/index.php?year=2024', array(), 'anonymous')['body'];
assert_contains($report, "<tr><td><a href=\"#Piratenpartei Synthetic Balance Unit\">Piratenpartei Synthetic Balance Unit</a></td>\n"
    . "<td>0.00&nbsp;€</td><td>0.00&nbsp;€</td>\n<td>0.00&nbsp;€</td>\n<td>0.00&nbsp;€</td>\n<td>43.21&nbsp;€</td></tr>\n", 'report organization balance');
page_views_query($database, 'DELETE FROM balance WHERE id = 93');
page_views_query($database, 'DELETE FROM lo WHERE id = 93');
pg_close($database);

// An unknown action shows the report.
$unknown = page_view('/index.php?action=unknown', array(), 'anonymous')['body'];
assert_contains($unknown, '<h1>Rechenschaftsbericht der Piratenpartei Österreichs ' . $year . '</h1>', 'unknown action');

// The removed transfer page (transfer.php, deleted in 2014) is an unknown
// action like any other: the report, for visitors and logged-in users.
foreach (array('anonymous', 'root,bgf') as $label) {
    $transfer = page_view('/index.php?action=transfer', $users[$label], $label)['body'];
    assert_contains($transfer, '<h1>Rechenschaftsbericht der Piratenpartei Österreichs ' . $year . '</h1>', "transfer action ($label)");
}

// A year that is not four digits (here with a suffix, "2024-1") is invalid
// input: the report of the current year, like a missing year.
$suffixed = page_view('/index.php?year=2024-1', array(), 'anonymous')['body'];
assert_contains($suffixed, '<h1>Rechenschaftsbericht der Piratenpartei Österreichs ' . $year . '</h1>', 'report of 2024-1');
assert_not_contains($suffixed, 'Abfrage fehlgeschlagen', 'report of 2024-1');

// Requests without X-Forwarded-Proto: https are redirected to https.
$plain = @file_get_contents(rtrim(getenv('PGACC_TEST_BASE_URL'), '/') . '/index.php?action=impressum', false, stream_context_create(array(
    'http' => array('header' => "Host: pgacc.test\r\nConnection: close", 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10),
)));
$plainHeaders = http_get_last_response_headers();
$plainResponse = array('status' => 0, 'headers' => is_array($plainHeaders) ? $plainHeaders : array(), 'body' => $plain);
if (is_array($plainHeaders) && preg_match('/^HTTP\/\S+ (\d{3})/', $plainHeaders[0], $status) === 1) {
    $plainResponse['status'] = intval($status[1]);
}
assert_status($plainResponse, 302, 'request without https');
// The host is the server's SERVER_NAME (here the address it listens on).
if (preg_match('#^https://[^/]+/index\.php\?action=impressum$#D', (string) response_header($plainResponse, 'Location')) !== 1) {
    fail_test('request without https sent Location ' . var_export(response_header($plainResponse, 'Location'), true));
}
if ($plain !== '') {
    fail_test('request without https answered ' . var_export($plain, true));
}

assert_no_problems($page_view_problems, 'plain page views:');
assert_no_unexpected_diagnostics();
echo "PHP/PostgreSQL plain page view tests passed\n";
