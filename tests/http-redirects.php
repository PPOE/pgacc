<?php

/*
 * Redirects that must be answered before any output: the login redirect of
 * every protected page for visitors who are not logged in (CSV listings and
 * the column-hiding parameter included), and the member search. Each response
 * must be a 302 with exactly the expected Location and an empty body, also
 * against a second server started with output_buffering=0. The voucher write
 * redirects (relocate() in edit.php) are in tests/write-workflows.php.
 */
require __DIR__ . '/http-test-helpers.php';

$problems = [];

function check($label, $checkProblems)
{
    global $problems;
    if (count($checkProblems) === 0) {
        echo "ok: $label\n";
        return;
    }
    $problems[] = "$label " . implode('; ', $checkProblems);
    fwrite(STDERR, "FAIL: $label " . implode('; ', $checkProblems) . "\n");
}

// Protected pages answer visitors who are not logged in with the login
// redirect only. Merge and recovery use voucher IDs that do not exist, so a
// page that ran anyway could not change the fixtures.
$protectedUris = [
    '/index.php?action=all&filter_date=2024',
    '/index.php?action=mb',
    '/index.php?action=open',
    '/index.php?action=merge&bid1=999998&bid2=999999',
    '/index.php?action=edit&id=1004',
    '/index.php?action=new',
    '/index.php?action=closed',
    '/index.php?action=deleted',
    '/index.php?action=recover&id=999998&bid=999999&doit=1',
    '/index.php?action=accounts',
    '/index.php?action=import2',
    // CSV listings: no byte order mark before the redirect.
    '/index.php?action=all&format=csv',
    '/index.php?action=open&format=csv',
    '/index.php?action=closed&format=csv',
    '/index.php?action=deleted&format=csv',
    // Hiding a column needs a logged-in user as well.
    '/index.php?action=all&hide=3',
];

function protected_page_problems($uris, $baseUrl)
{
    $problems = [];
    foreach ($uris as $uri) {
        $response = http_request($uri, 'GET', [], [], $baseUrl);
        foreach (redirect_problems($response, login_redirect_location($uri)) as $problem) {
            $problems[] = "$uri: $problem";
        }
    }
    return $problems;
}

// page_import() first fetches the member list from the external membership
// system. It is only requested once every other protected page has passed,
// so a missing login check never makes the test reach that code.
function import_page_problems($baseUrl, $earlierProblems)
{
    $uri = '/index.php?action=import';
    if (count($earlierProblems) > 0) {
        return ["$uri: not requested, because other protected pages are not redirected first"];
    }
    $response = http_request($uri, 'GET', [], [], $baseUrl);
    return redirect_problems($response, login_redirect_location($uri));
}

// The member search redirects to the ledger filtered by member, on the
// production host. Visitors who are not logged in get the same redirect:
// page_search()'s Location replaces the login redirect of checklogin().
$searchLocation = 'https://finanzen.piratenpartei.at/index.php?action=all&filter_member_id=5';
$adminCookies = login_user('synthetic-admin', 'correct-password');

function search_problems($cookies, $searchLocation, $baseUrl)
{
    $problems = [];
    foreach ([
        'logged in' => ['/index.php?action=search&member=5', $cookies],
        'logged in, CSV' => ['/index.php?action=search&member=5&format=csv', $cookies],
        'not logged in' => ['/index.php?action=search&member=5', []],
    ] as $label => $request) {
        $response = http_request($request[0], 'GET', [], $request[1], $baseUrl);
        foreach (redirect_problems($response, $searchLocation) as $problem) {
            $problems[] = "$label: $problem";
        }
    }
    return $problems;
}

$unbufferedUrl = start_unbuffered_server(8082);
foreach (['' => null, ' with output_buffering=0' => $unbufferedUrl] as $suffix => $baseUrl) {
    $pageProblems = protected_page_problems($protectedUris, $baseUrl);
    check("protected pages when not logged in$suffix", $pageProblems);
    check("import page when not logged in$suffix", import_page_problems($baseUrl, $pageProblems));
    check("member search$suffix", search_problems($adminCookies, $searchLocation, $baseUrl));
}

if (count($problems) > 0) {
    print_diagnostics_summary();
    fail_test(count($problems) . " redirect check(s) failed:\n  " . implode("\n  ", $problems));
}
assert_no_unexpected_diagnostics();
echo "Redirect response tests passed\n";
