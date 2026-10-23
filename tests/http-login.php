<?php
/*
 * Login/logout response regressions: status, Location, authentication cookies
 * (values and attributes), the database login state, and the login form.
 * Redirects are answered before the page is rendered, so they have an empty
 * body. The redirects are repeated against a second server started with
 * output_buffering=0: headers must not depend on output buffering.
 *
 * Must stay compatible with PHP 5.6.
 */
require __DIR__ . '/http-test-helpers.php';

$problems = array();

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

// Login and logout redirects are sent before acc_header() renders anything.
function redirect_body_problems($response)
{
    if ($response['body'] !== '') {
        return array('redirect body was not empty: ' . substr($response['body'], 0, 200));
    }
    return array();
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

// Set-Cookie attributes after the value, keyed by lowercase name; e.g.
// "expires=Sat, 24-Oct-2026 10:00:00 GMT; Max-Age=86400" becomes
// array('expires' => 'Sat, 24-Oct-2026 10:00:00 GMT', 'max-age' => '86400').
function response_cookie_attributes($response, $name)
{
    foreach ($response['headers'] as $header) {
        if (stripos($header, 'Set-Cookie:') !== 0) {
            continue;
        }
        $parts = explode(';', trim(substr($header, strlen('Set-Cookie:'))));
        $pair = explode('=', array_shift($parts), 2);
        if ($pair[0] !== $name) {
            continue;
        }
        $attributes = array();
        foreach ($parts as $part) {
            $attribute = explode('=', trim($part), 2);
            $attributes[strtolower($attribute[0])] = isset($attribute[1]) ? $attribute[1] : true;
        }
        return $attributes;
    }
    return null;
}

// Both login cookies last 24 hours and have no path, domain, secure, or
// httponly attribute (setcookie() with only name, value, and expiry).
function login_cookie_attribute_problems($response)
{
    $problems = array();
    foreach (array('pp_pgacc_login', 'pp_pgacc_id') as $cookie) {
        $attributes = response_cookie_attributes($response, $cookie);
        if ($attributes === null) {
            $problems[] = "no $cookie cookie";
            continue;
        }
        $names = array_keys($attributes);
        sort($names);
        if ($names !== array('expires', 'max-age')) {
            $problems[] = "$cookie attributes were " . implode(',', $names) . ', expected expires,max-age';
            continue;
        }
        if ($attributes['max-age'] !== '86400') {
            $problems[] = "$cookie Max-Age was {$attributes['max-age']}, expected 86400";
        }
        $expires = strtotime($attributes['expires']);
        if ($expires === false || abs($expires - (time() + 86400)) > 120) {
            $problems[] = "$cookie expires was {$attributes['expires']}, expected about 24 hours from now";
        }
    }
    return $problems;
}

// PHP clears a cookie set to "" with a fixed past expiry, whatever expiry
// was passed: 1970-01-01 00:00:01 GMT ("Thu, 01-Jan-1970 ..." before PHP 8.2,
// "Thu, 01 Jan 1970 ..." since).
function logout_cookie_attribute_problems($response)
{
    $problems = array();
    foreach (array('pp_pgacc_login', 'pp_pgacc_id') as $cookie) {
        $attributes = response_cookie_attributes($response, $cookie);
        $names = is_array($attributes) ? array_keys($attributes) : array();
        sort($names);
        if ($names !== array('expires', 'max-age') || $attributes['max-age'] !== '0'
            || strtotime($attributes['expires']) !== 1) {
            $problems[] = "$cookie attributes were " . var_export($attributes, true);
        }
    }
    return $problems;
}

function database_connection()
{
    static $database = null;
    if ($database !== null) {
        return $database;
    }
    $database = @pg_connect(pgacc_test_connection_string());
    if ($database === false) {
        fail_test('could not connect to the fixture database');
    }
    return $database;
}

// users.cookie and users.login as seen by the database.
function login_state($userId)
{
    $result = @pg_query_params(
        database_connection(),
        "SELECT cookie::text AS cookie, login > now() - '1 minute'::interval AS recent FROM users WHERE id = $1",
        array($userId)
    );
    if ($result === false || pg_num_rows($result) !== 1) {
        fail_test("could not read the login state of user $userId");
    }
    return pg_fetch_assoc($result);
}

function login_state_problems($response, $userId)
{
    $cookies = response_cookies($response);
    $state = login_state($userId);
    $problems = array();
    if (!isset($cookies['pp_pgacc_login']) || $state['cookie'] !== $cookies['pp_pgacc_login']) {
        $problems[] = "users.cookie was {$state['cookie']}, expected the pp_pgacc_login cookie";
    }
    if ($state['recent'] !== 't') {
        $problems[] = 'users.login was not updated';
    }
    return $problems;
}

function rejected_login_problems($response)
{
    $problems = array();
    if ($response['status'] !== 200) {
        $problems[] = "returned HTTP {$response['status']}, expected 200";
    }
    if (response_header($response, 'Location') !== null) {
        $problems[] = 'sent a Location header';
    }
    if (count(response_cookies($response)) !== 0) {
        $problems[] = 'issued cookies';
    }
    if (strpos($response['body'], 'Login erforderlich') === false) {
        $problems[] = 'did not render the login form';
    }
    return $problems;
}

// A logged-in user posting to the login page is redirected to the posted
// target without a new login (no cookies, database state unchanged).
function logged_in_target_problems($cookies, $target, $baseUrl = null)
{
    $before = login_state($cookies['pp_pgacc_id']);
    $response = http_request(
        '/index.php?action=login',
        'POST',
        array(
            'submit' => 'Anmeldung',
            'name' => 'synthetic-nobody',
            'pass' => 'wrong-password',
            'target' => strtoupper(bin2hex($target)),
        ),
        $cookies,
        $baseUrl
    );
    $problems = array();
    if ($response['status'] !== 302) {
        $problems[] = "returned HTTP {$response['status']}, expected 302";
    }
    $location = response_header($response, 'Location');
    if ($location !== $target) {
        $problems[] = 'Location was ' . var_export($location, true) . ', expected ' . var_export($target, true);
    }
    if (count(response_cookies($response)) !== 0) {
        $problems[] = 'issued cookies';
    }
    if (login_state($cookies['pp_pgacc_id']) !== $before) {
        $problems[] = 'changed the database login state';
    }
    return array_merge($problems, redirect_body_problems($response));
}

// Valid login without target redirects to index.php with both cookies.
$login = login_request('synthetic-admin', 'correct-password');
check('valid login', array_merge(login_response_problems($login, 'index.php', 1), redirect_body_problems($login)));
check('valid login cookie attributes', login_cookie_attribute_problems($login));
check('valid login database state', login_state_problems($login, 1));
$cookies = response_cookies($login);
if (isset($cookies['pp_pgacc_login']) && isset($cookies['pp_pgacc_id'])) {
    check('valid login cookies authenticate', authenticated_as_problems($cookies));
}

// Valid login with a hex-encoded target redirects to that target.
$target = 'index.php?action=all&filter_date=2024';
$targetLogin = login_request('synthetic-reviewer', 'reviewer-password', $target);
check('valid login with target', array_merge(
    login_response_problems($targetLogin, $target, 2),
    redirect_body_problems($targetLogin)
));
check('valid login with target database state', login_state_problems($targetLogin, 2));

// hex2ascii() ignores spaces and decodes lowercase hex as well.
$spacedLogin = http_request('/index.php?action=login', 'POST', array(
    'submit' => 'Anmeldung',
    'name' => 'synthetic-unit-one',
    'pass' => 'unit-password',
    'target' => '69 6e 64 65 78 2E 70 68 70 3F 61 3D 31',
));
check('valid login with spaced lowercase hex target', login_response_problems($spacedLogin, 'index.php?a=1', 3));

// The login form always posts a target field, empty unless the login page was
// opened with ?target=. An empty target is used as is: "Location:" is empty.
$emptyTargetLogin = http_request('/index.php?action=login', 'POST', array(
    'submit' => 'Anmeldung',
    'name' => 'synthetic-admin',
    'pass' => 'correct-password',
    'target' => '',
));
check('valid login with empty target', login_response_problems($emptyTargetLogin, '', 1));

// Already logged in: the login page only reports success, posted credentials
// are not used, and a posted target redirects.
$adminCookies = response_cookies($emptyTargetLogin);
$loggedIn = http_request('/index.php?action=login', 'POST', array(
    'submit' => 'Anmeldung',
    'name' => 'synthetic-reviewer',
    'pass' => 'reviewer-password',
), $adminCookies);
$loggedInProblems = array();
if ($loggedIn['status'] !== 200 || response_header($loggedIn, 'Location') !== null
    || count(response_cookies($loggedIn)) !== 0
    || strpos($loggedIn['body'], 'Login erfolgreich!') === false) {
    $loggedInProblems[] = "expected 200 with 'Login erfolgreich!', no Location and no cookies";
}
check('login page when already logged in', $loggedInProblems);
if (isset($adminCookies['pp_pgacc_login']) && isset($adminCookies['pp_pgacc_id'])) {
    check('login page when already logged in, with target', logged_in_target_problems($adminCookies, $target));
} else {
    check('login page when already logged in, with target', array('no login cookies to test with'));
}

// Rejected logins render the form: no redirect and no cookies. Quotes in the
// user name or password are escaped, so they are rejected like any other
// wrong credentials (no SQL error).
foreach (array(
    'wrong password' => array('synthetic-admin', 'wrong-password'),
    'unknown user' => array('synthetic-nobody', 'correct-password'),
    'quote in user name' => array("synthetic-admin' --", 'correct-password'),
    'quote in password' => array('synthetic-admin', "x' OR 'a'='a"),
) as $label => $credentials) {
    $rejected = login_request($credentials[0], $credentials[1]);
    check("rejected login ($label)", rejected_login_problems($rejected));
}
$stateBefore = login_state(1);
$rejectedWithTarget = http_request('/index.php?action=login&target=ABC', 'POST', array(
    'submit' => 'Anmeldung',
    'name' => 'synthetic-admin',
    'pass' => 'wrong-password',
    'target' => strtoupper(bin2hex($target)),
));
$rejectedWithTargetProblems = rejected_login_problems($rejectedWithTarget);
// The form keeps the target from the URL, not the posted one.
if (strpos($rejectedWithTarget['body'], '<input name="target" type="hidden" value="ABC" />') === false) {
    $rejectedWithTargetProblems[] = 'form did not carry the URL target';
}
if (login_state(1) !== $stateBefore) {
    $rejectedWithTargetProblems[] = 'changed the database login state';
}
check('rejected login with target', $rejectedWithTargetProblems);

// The login page without a submission renders the empty form.
$form = http_request('/index.php?action=login');
$formProblems = rejected_login_problems($form);
if (strpos($form['body'], '<input name="target" type="hidden" value="" />') === false) {
    $formProblems[] = 'form target was not empty';
}
check('login form', $formProblems);

// Logout redirects to index.php and clears both cookies.
$logout = http_request('/index.php?action=logout', 'GET', array(), $cookies);
check('logout', array_merge(logout_response_problems($logout), redirect_body_problems($logout)));
check('logout cookie attributes', logout_cookie_attribute_problems($logout));

// Same login and logout against a server without output buffering.
$unbufferedUrl = start_unbuffered_server(8081);

$unbufferedLogin = login_request('synthetic-admin', 'correct-password', null, $unbufferedUrl);
check('valid login with output_buffering=0', array_merge(
    login_response_problems($unbufferedLogin, 'index.php', 1),
    login_cookie_attribute_problems($unbufferedLogin),
    redirect_body_problems($unbufferedLogin)
));
$unbufferedTargetLogin = login_request('synthetic-reviewer', 'reviewer-password', $target, $unbufferedUrl);
check('valid login with target with output_buffering=0', array_merge(
    login_response_problems($unbufferedTargetLogin, $target, 2),
    redirect_body_problems($unbufferedTargetLogin)
));
$unbufferedCookies = response_cookies($unbufferedTargetLogin);
if (isset($unbufferedCookies['pp_pgacc_login']) && isset($unbufferedCookies['pp_pgacc_id'])) {
    check(
        'login page when already logged in, with target, with output_buffering=0',
        logged_in_target_problems($unbufferedCookies, $target, $unbufferedUrl)
    );
} else {
    check('login page when already logged in, with target, with output_buffering=0', array('no login cookies to test with'));
}
$unbufferedLogout = http_request('/index.php?action=logout', 'GET', array(), array(), $unbufferedUrl);
check('logout with output_buffering=0', array_merge(
    logout_response_problems($unbufferedLogout),
    logout_cookie_attribute_problems($unbufferedLogout),
    redirect_body_problems($unbufferedLogout)
));

if (count($problems) > 0) {
    print_diagnostics_summary();
    fail_test(count($problems) . " login/logout check(s) failed:\n  " . implode("\n  ", $problems));
}
assert_no_unexpected_diagnostics();
echo "Login/logout response tests passed\n";
