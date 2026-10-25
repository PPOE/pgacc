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
if (preg_match('/if \(in_array\(\$_GET\["action"\],array\(([^)]*)\)\)\)/', $index, $match) !== 1
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

// Known failures (tests/COMPATIBILITY_PLAN.md, owner decisions): page_transfer()
// is defined nowhere, and page_closed() selects three columns too few.
function page_view_known($path, $cookies, $label)
{
    $known = array(
        '/index.php?action=transfer' => array('KF-TRANSFER-UNDEFINED-FUNCTION', 'index.php', 'page_transfer()'),
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

assert_no_problems($page_view_problems, 'plain page views:');
assert_no_unexpected_diagnostics();
echo "PHP/PostgreSQL plain page view tests passed\n";
