<?php
/*
 * Login/logout response regressions: status, Location, and authentication
 * cookies. Also characterizes the same responses on a second server started
 * with output_buffering=0, where they currently fail because index.php starts
 * rendering (acc_header) before login()/logout send headers. Those checks are
 * known failures: when the application is fixed they will pass, and this
 * script then fails until they are moved to the regular checks.
 *
 * Must stay compatible with PHP 5.6.
 */
require __DIR__ . '/http-test-helpers.php';

$problems = array();
$knownFailures = array();

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

function check_known_failure($id, $label, $callback)
{
    global $problems, $knownFailures;
    $checkProblems = with_known_failure($id, $callback);
    if (count($checkProblems) > 0) {
        $knownFailures[] = $id;
        echo "known failure $id: $label " . implode('; ', $checkProblems) . "\n";
        return;
    }
    $problems[] = "$label unexpectedly passed; known failure $id is fixed: move it to the "
        . 'regular checks and remove it from the known-failure list';
    fwrite(STDERR, "UNEXPECTED PASS: $id $label\n");
}

function authenticated_as_problems($cookies, $baseUrl = null)
{
    // page_login() reports success when checklogin() accepts the cookies.
    $response = http_request('/index.php?action=login', 'GET', array(), $cookies, $baseUrl);
    if (strpos($response['body'], 'Login erfolgreich!') === false) {
        return array('cookies were not accepted as an authenticated session');
    }
    return array();
}

// Valid login without target redirects to index.php with both cookies.
$login = login_request('synthetic-admin', 'correct-password');
check('valid login', login_response_problems($login, 'index.php', 1));
$cookies = response_cookies($login);
if (isset($cookies['pp_pgacc_login']) && isset($cookies['pp_pgacc_id'])) {
    check('valid login cookies authenticate', authenticated_as_problems($cookies));
}

// Valid login with a hex-encoded target redirects to that target.
$target = 'index.php?action=all&filter_date=2024';
$targetLogin = login_request('synthetic-reviewer', 'reviewer-password', $target);
check('valid login with target', login_response_problems($targetLogin, $target, 2));

// Rejected logins render the form: no redirect and no cookies.
foreach (array(
    'wrong password' => array('synthetic-admin', 'wrong-password'),
    'unknown user' => array('synthetic-nobody', 'correct-password'),
) as $label => $credentials) {
    $rejected = login_request($credentials[0], $credentials[1]);
    $rejectedProblems = array();
    if ($rejected['status'] !== 200) {
        $rejectedProblems[] = "returned HTTP {$rejected['status']}, expected 200";
    }
    if (response_header($rejected, 'Location') !== null) {
        $rejectedProblems[] = 'sent a Location header';
    }
    if (count(response_cookies($rejected)) !== 0) {
        $rejectedProblems[] = 'issued cookies';
    }
    if (strpos($rejected['body'], 'Login erforderlich') === false) {
        $rejectedProblems[] = 'did not render the login form';
    }
    check("rejected login ($label)", $rejectedProblems);
}

// Logout redirects to index.php and clears both cookies.
$logout = http_request('/index.php?action=logout', 'GET', array(), $cookies);
check('logout', logout_response_problems($logout));

// Same login and logout against a server without output buffering.
$port = 8081;
$command = 'exec php -d output_buffering=0'
    . ' -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/runtime/diagnostics-prepend.php')
    . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(dirname(__DIR__));
$server = proc_open(
    $command,
    array(
        0 => array('file', '/dev/null', 'r'),
        1 => array('file', '/tmp/unbuffered-server.log', 'a'),
        2 => array('file', '/tmp/unbuffered-server.log', 'a'),
    ),
    $pipes
);
if (!is_resource($server)) {
    fail_test('could not start the output_buffering=0 server');
}
$ready = false;
for ($attempt = 0; $attempt < 50 && !$ready; $attempt++) {
    $socket = @fsockopen('127.0.0.1', $port);
    if ($socket !== false) {
        fclose($socket);
        $ready = true;
    } else {
        usleep(100000);
    }
}
if (!$ready) {
    proc_terminate($server);
    fail_test('output_buffering=0 server did not start: ' . @file_get_contents('/tmp/unbuffered-server.log'));
}
$unbufferedUrl = 'http://127.0.0.1:' . $port;

check_known_failure(
    'KF-LOGIN-UNBUFFERED',
    'valid login with output_buffering=0',
    function () use ($unbufferedUrl) {
        $response = login_request('synthetic-admin', 'correct-password', null, $unbufferedUrl);
        return login_response_problems($response, 'index.php', 1);
    }
);
check_known_failure(
    'KF-LOGOUT-UNBUFFERED',
    'logout with output_buffering=0',
    function () use ($unbufferedUrl) {
        $response = http_request('/index.php?action=logout', 'GET', array(), array(), $unbufferedUrl);
        return logout_response_problems($response);
    }
);

proc_terminate($server);
proc_close($server);

if (count($problems) > 0) {
    print_diagnostics_summary();
    fail_test(count($problems) . " login/logout check(s) failed:\n  " . implode("\n  ", $problems));
}
assert_no_unexpected_diagnostics();
echo 'Login/logout response tests passed; known failures: '
    . (count($knownFailures) > 0 ? implode(', ', $knownFailures) : 'none') . "\n";
