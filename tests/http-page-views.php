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
    if ($href === '/' || strpos($href, '/?') === 0) {
        return '/index.php' . substr($href, 1);
    }
    if (strpos($href, 'index.php') === 0) {
        return '/' . $href;
    }
    if (preg_match('/^([a-z0-9]+)(?:\?(.*))?$/D', $href, $parts) === 1 && in_array($parts[1], $actions, true)) {
        return '/index.php?action=' . $parts[1] . (isset($parts[2]) ? '&' . $parts[2] : '');
    }
    return null;
}

// The release version (PGACC_VERSION in impressum.php).
if (preg_match("/^const PGACC_VERSION = '([^']*)';$/m", file_get_contents(__DIR__ . '/../impressum.php'), $releaseVersion) !== 1) {
    fail_test('impressum.php: PGACC_VERSION was not found');
}

function page_view($path, $cookies, $label)
{
    $response = http_request($path, 'GET', [], $cookies);
    // Collected, so that one run reports every broken page.
    if (!in_array($response['status'], [200, 302, 303], true)) {
        $GLOBALS['page_view_problems'][] = "$label GET $path: HTTP {$response['status']}";
    } elseif (strpos($response['body'], 'Fatal error') !== false) {
        $GLOBALS['page_view_problems'][] = "$label GET $path: Fatal error";
    }
    // Every stylesheet and script of the application carries the release
    // version, so that browsers load it again after an upgrade.
    preg_match_all('/<(?:link\b[^>]*\bhref|script\b[^>]*\bsrc)="([^"]*)"/i', $response['body'], $assets);
    foreach ($assets[1] as $asset) {
        $GLOBALS['page_view_assets'][$asset] = true;
        if (substr($asset, -strlen('?v=' . $GLOBALS['releaseVersion'][1])) !== '?v=' . $GLOBALS['releaseVersion'][1]) {
            $GLOBALS['page_view_problems'][] = "$label GET $path: $asset without ?v={$GLOBALS['releaseVersion'][1]}";
        }
    }
    return $response;
}

$page_view_problems = [];
$page_view_assets = [];
$users = [
    'root,bgf' => login_user('synthetic-admin', 'correct-password'),
    'bgf' => login_user('synthetic-reviewer', 'reviewer-password'),
    'suborganization' => login_user('synthetic-unit-one', 'unit-password'),
    'anonymous' => [],
];
$year = date('Y');
foreach ($users as $label => $cookies) {
    $paths = ['/index.php' => true];
    foreach ($actions as $action) {
        $paths['/index.php?action=' . $action] = true;
    }
    foreach (['open', 'closed', 'all', 'deleted'] as $action) {
        $paths["/index.php?action=$action&filter_date=$year"] = true;
    }
    // Follow the links without further parameters once: the menu links, the
    // year links (report, donations) and the account links (tinyreport,
    // vreport).
    $seen = [];
    while (count($paths) > 0) {
        $path = key($paths);
        unset($paths[$path]);
        $seen[$path] = true;
        $response = page_view($path, $cookies, $label);
        preg_match_all('/<a href="([^"]*)"/', $response['body'], $links);
        foreach ($links[1] as $href) {
            $next = page_view_path($href, $actions);
            if ($next === null || isset($seen[$next])) {
                continue;
            }
            parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
            if (count(array_diff(array_keys($query), ['action', 'filter_date', 'year', 'konto'])) > 0) {
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
foreach (['vreport' => 'TEST-VIRTUAL', 'tinyreport' => 'TEST-ACCOUNT'] as $action => $konto) {
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
$chart = page_view('/index.php?action=vreport&konto=TEST-VIRTUAL-1', [], 'anonymous')['body'];
preg_match_all('/<script\b([^>]*)>/i', $chart, $scripts);
foreach ($scripts[1] as $attributes) {
    if (strpos($attributes, ' src="') === false && $attributes !== ' type="application/json" id="graph-data"') {
        fail_test("vreport chart: inline script <script$attributes>");
    }
}
assert_contains($chart, "<script src=\"js/chart.umd.min.js?v={$releaseVersion[1]}\"></script>\n<script src=\"js/vreport-chart.js?v={$releaseVersion[1]}\"></script>\n", 'vreport chart scripts');
assert_contains($chart, '<div id="graph" style="position: relative; width: 100%; height: 400px;"><canvas id="graph-canvas"></canvas></div>', 'vreport chart canvas');
assert_not_contains($chart, 'jquery', 'vreport chart without jQuery');
if (preg_match('#<script type="application/json" id="graph-data">(.*?)</script>#s', $chart, $graphData) !== 1) {
    fail_test('vreport chart: no graph-data element');
}
$points = [
    ['1325289600000.000000', '50.0000000000000000'],  // 2011-12-31
    ['1706745600000.000000', '170.0000000000000000'], // 2024-02-01
    ['1709251200000.000000', '200.0000000000000000'], // 2024-03-01
    ['1719792000000.000000', '200.0000000000000000'], // 2024-07-01
    ['1722470400000.000000', '210.0000000000000000'], // 2024-08-01
    ['1735689600000.000000', '235.0000000000000000'], // 2025-01-01
];
if (json_decode($graphData[1], true) !== $points) {
    fail_test("vreport chart: unexpected points {$graphData[1]}");
}
// The web server serves both files as they are (static files bypass the
// diagnostics recorder, so they are read here instead of requested).
foreach (['js/chart.umd.min.js' => 'Chart.js v4.6.1', 'js/vreport-chart.js' => "'graph-data'"] as $script => $marker) {
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
$spendings = page_view('/index.php?action=spendings&type=20&unit=2&from=2024-01-01&to=2025-01-01', [], 'anonymous')['body'];
assert_contains($spendings, "<td>1003</td>\n<td>2024-04-01</td>\n", 'filtered spendings');
assert_contains($spendings, "<td>-50.00€</td>\n<td>Synthetic Supplier</td>\n</tr>", 'filtered spendings');
assert_not_contains($spendings, '-15.00€', 'filtered spendings (type 21)');
$laterSpendings = page_view('/index.php?action=spendings&type=20&unit=2&from=2024-05-01&to=2025-01-01', [], 'anonymous')['body'];
assert_not_contains($laterSpendings, '<td>1003</td>', 'spendings from May 2024');
$kdonations = page_view('/index.php?action=kdonations&type=11&unit=1&from=2024-01-01&to=2025-01-01', $admin, 'root,bgf')['body'];
assert_contains($kdonations, 'Aus rechtlichen Gründen werden Spenden nur mit Zustimmung Veröffentlicht.</table>', 'filtered in-kind donations');
foreach (['tinyreport' => 'TEST-ACCOUNT-1,TEST-ACCOUNT-2', 'vreport' => 'TEST-VIRTUAL-1,TEST-VIRTUAL-2'] as $action => $konto) {
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
$numeric = page_view('/index.php?action=vreport', [], 'anonymous')['body'];
assert_contains($numeric, "<li><a href=\"vreport?konto=890\">Konto '890'</a></li>", 'vreport numeric account for visitors');
$numeric = page_view('/index.php?action=vreport', $admin, 'root,bgf')['body'];
assert_contains($numeric, "<li><a href=\"vreport?konto=AT12%203456%207890\">Konto 'AT12 3456 7890'</a></li>", 'vreport numeric account');
page_views_query($database, 'DELETE FROM vouchers WHERE id = 9150');

// A type or organization id without a row in its lookup table (the schema has
// no foreign keys): the listing row still has one cell per column, the type
// or organization cell is empty. Type 1 (membership fee) exists, like in the
// production data; 77 is an unknown type and organization.
page_views_query($database, "INSERT INTO type (id, name, used, income, realtype) VALUES (1, 'Synthetic membership fee', 1, 1, 1)");
page_views_query($database, "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, date, person_type, file, vaccount, ack1, ack2)
    VALUES (9151, 4781, 1, 1, false, 4781, 'TEST-ACCOUNT-1', 'Synthetic known lookups', timestamp '2024-09-16 00:00:00', 2, 0, 'TEST-VIRTUAL-1', 'reviewer-a', 'reviewer-b'),
    (9152, 4782, 77, 1, false, 4782, 'TEST-ACCOUNT-1', 'Synthetic unknown type', timestamp '2024-09-16 00:00:00', 2, 0, 'TEST-VIRTUAL-1', 'reviewer-a', 'reviewer-b'),
    (9153, 4783, 8, 77, false, 4783, 'TEST-ACCOUNT-1', 'Synthetic unknown organization', timestamp '2024-09-16 00:00:00', 2, 0, 'TEST-VIRTUAL-1', 'reviewer-a', 'reviewer-b'),
    (9154, 4784, NULL, NULL, false, 4784, 'TEST-ACCOUNT-1', 'Synthetic no type and organization', timestamp '2024-09-16 00:00:00', 2, 0, 'TEST-VIRTUAL-1', 'reviewer-a', 'reviewer-b')");
// Cells of the listing row (HTML) or line (CSV) that contains $marker.
function lookup_row_cells($body, $marker, $csv, $label)
{
    $rows = $csv ? explode("\n", $body) : preg_split('/<tr[\s>]/', $body);
    foreach ($rows as $row) {
        if (strpos($row, $marker) !== false) {
            if ($csv) {
                return explode("\t", rtrim($row, "\t"));
            }
            preg_match_all('#<td[^>]*?(?:/>|>(.*?)</td>)#s', $row, $cells);
            return $cells[1];
        }
    }
    fail_test("$label: no row with $marker");
}
$listings = [
    'transactions' => ['/index.php?action=transactions', [], 'anonymous'],
    'transactions csv' => ['/index.php?action=transactions&format=csv', [], 'anonymous'],
    'all' => ['/index.php?action=all&filter_date=2024', $users['root,bgf'], 'root,bgf'],
    'all csv' => ['/index.php?action=all&format=csv&filter_date=2024', $users['root,bgf'], 'root,bgf'],
];
foreach ($listings as $label => $listing) {
    $csv = strpos($listing[0], 'format=csv') !== false;
    $body = page_view($listing[0], $listing[1], $listing[2])['body'];
    $known = lookup_row_cells($body, $csv ? '47,81' : '47.81', $csv, $label);
    if (!in_array('Synthetic membership fee', $known, true) || !in_array('Synthetic National Unit', $known, true)) {
        fail_test("$label: known type/organization row " . var_export($known, true));
    }
    foreach (['47,82' => ['Synthetic National Unit'], '47,83' => ['Synthetic donation'], '47,84' => []] as $amount => $names) {
        $cells = lookup_row_cells($body, $csv ? $amount : strtr($amount, ',', '.'), $csv, $label);
        $typeIndex = array_search('Synthetic membership fee', $known, true);
        $loIndex = array_search('Synthetic National Unit', $known, true);
        $wanted = $known;
        $wanted[$typeIndex] = in_array('Synthetic donation', $names, true) ? 'Synthetic donation' : '';
        $wanted[$loIndex] = in_array('Synthetic National Unit', $names, true) ? 'Synthetic National Unit' : '';
        if (count($cells) !== count($known) || $cells[$typeIndex] !== $wanted[$typeIndex] || $cells[$loIndex] !== $wanted[$loIndex]) {
            fail_test("$label: row $amount " . var_export($cells, true) . ', expected the cells of ' . var_export($known, true) . ' with type/organization ' . var_export([$wanted[$typeIndex], $wanted[$loIndex]], true));
        }
    }
}
// Edit form of a line with an unknown type: direction "Einnahme", the type
// selected as unknown, without diagnostics.
$edit = page_view('/index.php?action=edit&id=4782', $users['root,bgf'], 'root,bgf')['body'];
assert_contains($edit, '<option value="in" selected>Einnahme</option>', 'unknown type: direction');
assert_contains($edit, "<option value=\"77\" selected=\"selected\">Unbekannt (77)</option>", 'unknown type: type');
// Also when the form is shown again (here: a booking line added).
$again = http_request('/index.php?action=edit&id=4782', 'POST', [
    'changed' => '1', 'parts' => '1', 'bid0' => '9152', 'dir0' => 'in', 'in_type0' => '77', 'out_type0' => '20',
    'wk_type0' => '21', 'date0' => '16.09.2024', 'person_type0' => '2', 'lo0' => '1', 'amount0' => '47,82',
    'id' => '4782', 'add' => 'Neue Buchungszeile',
], $users['root,bgf']);
assert_status($again, 200, 'unknown type: form shown again');
assert_contains($again['body'], '<option value="77" selected="selected">Unbekannt (77)</option>', 'unknown type: form shown again');
// A known type is not marked.
$edit = page_view('/index.php?action=edit&id=4781', $users['root,bgf'], 'root,bgf')['body'];
assert_contains($edit, '<option value="1" selected="selected">Synthetic membership fee</option>', 'known type 1');
assert_not_contains($edit, 'Unbekannt (', 'known type 1');
page_views_query($database, 'DELETE FROM vouchers WHERE id IN (9151, 9152, 9153, 9154)');
page_views_query($database, 'DELETE FROM type WHERE id = 1');

// The report lists the balance of a "Piratenpartei" organization on the
// last day of the year.
page_views_query($database, "INSERT INTO lo (id, name) VALUES (93, 'Piratenpartei Synthetic Balance Unit')");
page_views_query($database, "INSERT INTO balance (id, value, orga, date) VALUES (93, 4321, 93, timestamp '2024-12-31 00:00:00')");
$report = page_view('/index.php?year=2024', [], 'anonymous')['body'];
assert_contains($report, "<tr><td><a href=\"#Piratenpartei Synthetic Balance Unit\">Piratenpartei Synthetic Balance Unit</a></td>\n"
    . "<td>0.00&nbsp;€</td><td>0.00&nbsp;€</td>\n<td>0.00&nbsp;€</td>\n<td>0.00&nbsp;€</td>\n<td>43.21&nbsp;€</td></tr>\n", 'report organization balance');
page_views_query($database, 'DELETE FROM balance WHERE id = 93');
page_views_query($database, 'DELETE FROM lo WHERE id = 93');
pg_close($database);

// An unknown action shows the report.
$unknown = page_view('/index.php?action=unknown', [], 'anonymous')['body'];
assert_contains($unknown, '<h1>Rechenschaftsbericht der Piratenpartei Österreichs ' . $year . '</h1>', 'unknown action');

// The removed transfer page (transfer.php, deleted in 2014) is an unknown
// action like any other: the report, for visitors and logged-in users.
foreach (['anonymous', 'root,bgf'] as $label) {
    $transfer = page_view('/index.php?action=transfer', $users[$label], $label)['body'];
    assert_contains($transfer, '<h1>Rechenschaftsbericht der Piratenpartei Österreichs ' . $year . '</h1>', "transfer action ($label)");
}

// A year that is not four digits (here with a suffix, "2024-1") is invalid
// input: the report of the current year, like a missing year.
$suffixed = page_view('/index.php?year=2024-1', [], 'anonymous')['body'];
assert_contains($suffixed, '<h1>Rechenschaftsbericht der Piratenpartei Österreichs ' . $year . '</h1>', 'report of 2024-1');
assert_not_contains($suffixed, 'Abfrage fehlgeschlagen', 'report of 2024-1');

// Array filter values ("filter_id[]") are ignored by getfilter() like other
// invalid filter values: the unfiltered listing (CSV; the HTML listing
// reflects the filters in its links, an open follow-up in the plan).
$arrayFilters = '';
foreach (['filter_id', 'filter_bid', 'filter_date', 'filter_type', 'filter_lo', 'filter_member_id', 'filter_gk', 'filter_amount',
    'filter_k', 'filter_vk', 'filter_text', 'filter_comment', 'filter_ack', 'filter_ack_old', 'filter_name'] as $filter) {
    $arrayFilters .= '&' . $filter . '%5B%5D=1';
}
$arrayListing = page_view('/index.php?action=all&format=csv' . $arrayFilters, $admin, 'root,bgf')['body'];
assert_contains($arrayListing, "\tClosed donation\t", 'listing with array filter values');

// The Impressum names the provider and links to the party's legal notice. Its
// software table lists pgacc (exact release version), PHP and PostgreSQL (major
// versions only, no distribution suffix) and the third-party software without
// versions: every runtime package of composer.lock, Chart.js (js/), Tailwind CSS
// (style.css) and the Silk icons (icons/, credit required by CC BY 2.5).
$impressum = page_view('/index.php?action=impressum', [], 'anonymous')['body'];
assert_contains($impressum, '<span style="font-weight: bold;">Diensteanbieter:</span></div><br />' . "\n"
    . 'Piratenpartei Österreichs<br />' . "\n"
    . 'Impressum: <a href="https://piratenpartei.at/rechtliches/impressum/">piratenpartei.at/rechtliches/impressum</a><br />', 'Impressum');
assert_not_contains($impressum, 'Schadinagasse', 'Impressum');
assert_not_contains($impressum, 'findet sich unter', 'Impressum');
// The version format of the release tooling (scripts/release.sh,
// .github/workflows/release.yml), prereleases included.
$releaseVersionPattern = '/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$/D';
foreach (['2.0.0' => 1, '2.1.0-rc.1' => 1, '2.0' => 0, '2.0.0 (Debian)' => 0] as $sample => $matches) {
    if (preg_match($releaseVersionPattern, $sample) !== $matches) {
        fail_test("release version pattern: wrong result for $sample");
    }
}
// Expected rows: name => [link, version pattern, exact version, license, license link].
$expectedRows = [
    'pgacc' => ['https://github.com/PPOE/pgacc', $releaseVersionPattern, $releaseVersion[1], 'LICENSE', 'https://github.com/PPOE/pgacc/blob/master/LICENSE'],
    'PHP' => ['http://www.php.net', '/^\d+$/D', (string) PHP_MAJOR_VERSION, 'PHP License', 'http://www.php.net/license/3_01.txt'],
    'PostgreSQL' => ['http://www.postgresql.org/', '/^\d+$/D', '18', 'PostgreSQL License', 'http://www.postgresql.org/about/licence'],
];
$lock = json_decode(file_get_contents(__DIR__ . '/../composer.lock'), true);
if (!is_array($lock) || count($lock['packages']) < 1) {
    fail_test('composer.lock: no runtime packages');
}
foreach ($lock['packages'] as $package) {
    $licenses = $package['license'];
    $expectedRows[$package['name']] = [
        isset($package['homepage']) ? $package['homepage'] : preg_replace('/\.git$/', '', $package['source']['url']),
        null, '–', implode(', ', $licenses), count($licenses) === 1 ? 'https://spdx.org/licenses/' . $licenses[0] . '.html' : null,
    ];
}
// The bundled software exists, so its rows are not stale.
foreach (['js/chart.umd.min.js', 'icons/accept.png'] as $bundled) {
    if (!is_file(__DIR__ . '/../' . $bundled)) {
        fail_test("Impressum: $bundled no longer exists; update the software table");
    }
}
if (strncmp((string) file_get_contents(__DIR__ . '/../style.css'), '/*! tailwindcss v', 17) !== 0) {
    fail_test('Impressum: style.css is no longer built with Tailwind CSS; update the software table');
}
$expectedRows['Chart.js'] = ['https://www.chartjs.org', null, '–', 'MIT', 'https://spdx.org/licenses/MIT.html'];
$expectedRows['Tailwind CSS'] = ['https://tailwindcss.com', null, '–', 'MIT', 'https://spdx.org/licenses/MIT.html'];
$expectedRows['Silk Icons von Mark James'] = ['http://www.famfamfam.com/lab/icons/silk/', null, '–', 'CC BY 2.5', 'https://creativecommons.org/licenses/by/2.5/'];

if (preg_match('#<table class="ui_list impressum_list">.*?<tbody class="ui_list_body">(.*?)</tbody></table>#s', $impressum, $softwareTable) !== 1) {
    fail_test('Impressum: no software table');
}
preg_match_all('#<tr class="ui_list_row ui_list_odd"><td class="">(?:<a href="([^"]*)">)?([^<]*)(?:</a>)?</td><td class=""><div>([^<]*)</div></td><td class="">(?:<a href="([^"]*)">)?([^<]*)(?:</a>)?</td></tr>#', $softwareTable[1], $rows, PREG_SET_ORDER);
$actualNames = array_map(function ($row) {
    return html_entity_decode($row[2], ENT_QUOTES, 'UTF-8');
}, $rows);
if ($actualNames !== array_keys($expectedRows)) {
    fail_test('Impressum: software rows ' . var_export($actualNames, true) . ', expected ' . var_export(array_keys($expectedRows), true));
}
foreach ($rows as $index => $row) {
    $expected = $expectedRows[$actualNames[$index]];
    $actual = [html_entity_decode($row[1], ENT_QUOTES, 'UTF-8'), $row[3], html_entity_decode($row[5], ENT_QUOTES, 'UTF-8'), html_entity_decode($row[4], ENT_QUOTES, 'UTF-8')];
    $wanted = [$expected[0], $expected[2], $expected[3], $expected[4] === null ? '' : $expected[4]];
    if ($actual !== $wanted || ($expected[1] !== null && preg_match($expected[1], $row[3]) !== 1)) {
        fail_test("Impressum: row {$actualNames[$index]} " . var_export($actual, true) . ', expected ' . var_export($wanted, true));
    }
}

// Requests without X-Forwarded-Proto: https are redirected to https.
$plain = @file_get_contents(rtrim(getenv('PGACC_TEST_BASE_URL'), '/') . '/index.php?action=impressum', false, stream_context_create([
    'http' => ['header' => "Host: pgacc.test\r\nConnection: close", 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10],
]));
$plainHeaders = http_get_last_response_headers();
$plainResponse = ['status' => 0, 'headers' => is_array($plainHeaders) ? $plainHeaders : [], 'body' => $plain];
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

// The asset check above saw the stylesheet and both scripts.
$v = '?v=' . $releaseVersion[1];
foreach (["style.css$v", "js/chart.umd.min.js$v", "js/vreport-chart.js$v"] as $asset) {
    if (!isset($page_view_assets[$asset])) {
        $page_view_problems[] = "no page references $asset";
    }
}
assert_no_problems($page_view_problems, 'plain page views:');
assert_no_unexpected_diagnostics();
echo "PHP/PostgreSQL plain page view tests passed\n";
