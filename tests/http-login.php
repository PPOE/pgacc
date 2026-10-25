<?php

/*
 * Login/logout response regressions: status, Location, authentication cookies
 * (values and attributes), the database login state, and the login form.
 * Redirects are answered before the page is rendered, so they have an empty
 * body. The redirects are repeated against a second server started with
 * output_buffering=0: headers must not depend on output buffering.
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

// Login and logout redirects are sent before acc_header() renders anything.
function redirect_body_problems($response)
{
    if ($response['body'] !== '') {
        return ['redirect body was not empty: ' . substr($response['body'], 0, 200)];
    }
    return [];
}

function authenticated_as_problems($cookies, $baseUrl = null)
{
    // page_login() reports success when checklogin() accepts the cookies.
    $response = http_request('/index.php?action=login', 'GET', [], $cookies, $baseUrl);
    if (strpos($response['body'], 'Login erfolgreich!') === false) {
        return ['cookies were not accepted as an authenticated session'];
    }
    return [];
}

function not_authenticated_problems($cookies)
{
    $response = http_request('/index.php?action=login', 'GET', [], $cookies);
    if (strpos($response['body'], 'Login erforderlich') === false
        || strpos($response['body'], 'Login erfolgreich!') !== false) {
        return ['cookies were accepted as an authenticated session'];
    }
    return [];
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
        $attributes = [];
        foreach ($parts as $part) {
            $attribute = explode('=', trim($part), 2);
            $attributes[strtolower($attribute[0])] = isset($attribute[1]) ? $attribute[1] : true;
        }
        return $attributes;
    }
    return null;
}

// The login cookies are secure, HttpOnly and SameSite=Lax, without path or
// domain (index.php sends the Set-Cookie headers itself). Each cookie is sent
// exactly once. $maxAge 0: the cookie is deleted (value "deleted", expiry
// 1970-01-01 00:00:01 GMT, as setcookie() sends it).
function login_cookie_header_problems($response, $maxAge)
{
    $problems = [];
    $setCookies = 0;
    foreach ($response['headers'] as $header) {
        if (stripos($header, 'Set-Cookie:') === 0) {
            $setCookies++;
        }
    }
    if ($setCookies !== 2) {
        $problems[] = "sent $setCookies Set-Cookie headers, expected 2";
    }
    $expected = ['expires', 'httponly', 'max-age', 'samesite', 'secure'];
    $cookies = response_cookies($response);
    foreach (['pp_pgacc_login', 'pp_pgacc_id'] as $cookie) {
        $attributes = response_cookie_attributes($response, $cookie);
        if ($attributes === null) {
            $problems[] = "no $cookie cookie";
            continue;
        }
        $names = array_keys($attributes);
        sort($names);
        if ($names !== $expected) {
            $problems[] = "$cookie attributes were " . implode(',', $names) . ', expected ' . implode(',', $expected);
            continue;
        }
        if ($attributes['secure'] !== true || $attributes['httponly'] !== true || $attributes['samesite'] !== 'Lax') {
            $problems[] = "$cookie flags were " . var_export($attributes, true);
        }
        if ($attributes['max-age'] !== strval($maxAge)) {
            $problems[] = "$cookie Max-Age was {$attributes['max-age']}, expected $maxAge";
        }
        $expires = strtotime($attributes['expires']);
        if ($maxAge === 0 && ($expires !== 1 || $cookies[$cookie] !== 'deleted')) {
            $problems[] = "$cookie was not deleted: " . var_export($attributes, true);
        }
        if ($maxAge > 0 && ($expires === false || abs($expires - (time() + $maxAge)) > 120)) {
            $problems[] = "$cookie expires was {$attributes['expires']}, expected about $maxAge seconds from now";
        }
    }
    return $problems;
}

// Both login cookies last 24 hours.
function login_cookie_attribute_problems($response)
{
    return login_cookie_header_problems($response, 86400);
}

function logout_cookie_attribute_problems($response)
{
    return login_cookie_header_problems($response, 0);
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
        "SELECT cookie::text AS cookie, login::text AS login, login > now() - '1 minute'::interval AS recent"
        . ' FROM users WHERE id = $1',
        [$userId],
    );
    if ($result === false || pg_num_rows($result) !== 1) {
        fail_test("could not read the login state of user $userId");
    }
    return pg_fetch_assoc($result);
}

// The pp_pgacc_login cookie of a session: an HMAC of the user id, the
// users.cookie nonce and users.login under a key derived from $key
// (functions.php, login_session_token()).
function expected_login_token($userId, $nonce, $login)
{
    include __DIR__ . '/runtime/test-constants.php';
    $sessionKey = hash_hmac('sha256', 'pgacc-session-key', $key, true);
    return hash_hmac('sha256', 'pgacc-login|' . $userId . '|' . $nonce . '|' . $login, $sessionKey);
}

function login_state_problems($response, $userId)
{
    $cookies = response_cookies($response);
    $state = login_state($userId);
    $problems = [];
    // users.cookie (an integer column) holds a random 31-bit nonce, never 0.
    if (preg_match('/^[1-9]\d*$/', (string) $state['cookie']) !== 1 || intval($state['cookie']) > 2147483647) {
        $problems[] = 'users.cookie was ' . var_export($state['cookie'], true) . ', expected a positive 31-bit nonce';
    } elseif (!isset($cookies['pp_pgacc_login'])
        || $cookies['pp_pgacc_login'] !== expected_login_token($userId, $state['cookie'], $state['login'])) {
        $problems[] = "the pp_pgacc_login cookie was not the session token of users.cookie {$state['cookie']}";
    }
    if ($state['recent'] !== 't') {
        $problems[] = 'users.login was not updated';
    }
    return $problems;
}

function rejected_login_problems($response)
{
    $problems = [];
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
function logged_in_target_problems($cookies, $target, $baseUrl = null, $expectedLocation = null)
{
    if ($expectedLocation === null) {
        $expectedLocation = $target;
    }
    $before = login_state($cookies['pp_pgacc_id']);
    $response = http_request(
        '/index.php?action=login',
        'POST',
        [
            'submit' => 'Anmeldung',
            'name' => 'synthetic-nobody',
            'pass' => 'wrong-password',
            'target' => strtoupper(bin2hex($target)),
        ],
        $cookies,
        $baseUrl,
    );
    $problems = [];
    if ($response['status'] !== 302) {
        $problems[] = "returned HTTP {$response['status']}, expected 302";
    }
    $location = response_header($response, 'Location');
    if ($location !== $expectedLocation) {
        $problems[] = 'Location was ' . var_export($location, true) . ', expected ' . var_export($expectedLocation, true);
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

// Every login replaces the token: the cookies of the previous login of the
// same user no longer authenticate. Tokens are random, not derived from the
// user or the time alone.
$relogin = login_request('synthetic-admin', 'correct-password');
check('second login', array_merge(login_response_problems($relogin, 'index.php', 1), login_state_problems($relogin, 1)));
$reloginCookies = response_cookies($relogin);
if (isset($cookies['pp_pgacc_login']) && isset($reloginCookies['pp_pgacc_login'])) {
    $rotationProblems = $cookies['pp_pgacc_login'] === $reloginCookies['pp_pgacc_login']
        ? ['the second login reused the token'] : [];
    check('second login replaces the token', $rotationProblems);
    check('previous login cookies are rejected', not_authenticated_problems($cookies));
    check('second login cookies authenticate', authenticated_as_problems($reloginCookies));
    $cookies = $reloginCookies;
}

// Wrong tokens: one changed hex digit, upper case, the users.cookie nonce
// itself (the token format before the fix), another user's token, empty, too
// long, and an array.
if (isset($cookies['pp_pgacc_login']) && isset($cookies['pp_pgacc_id'])) {
    $token = $cookies['pp_pgacc_login'];
    $state = login_state(1);
    $otherLogin = response_cookies(login_request('synthetic-read-only-two', 'read-only-two-password'));
    $lastDigit = substr($token, -1) === '0' ? '1' : '0';
    foreach ([
        'changed digit' => substr($token, 0, 63) . $lastDigit,
        'upper case' => strtoupper($token) === $token ? 'x' : strtoupper($token),
        'users.cookie nonce' => $state['cookie'],
        "another user's token" => isset($otherLogin['pp_pgacc_login']) ? $otherLogin['pp_pgacc_login'] : 'none',
        'empty' => '',
        'too long' => $token . '0',
        'array' => null,
    ] as $label => $wrongToken) {
        $wrongCookies = $wrongToken === null
            ? ['pp_pgacc_login[]' => $token, 'pp_pgacc_id' => '1']
            : ['pp_pgacc_login' => $wrongToken, 'pp_pgacc_id' => '1'];
        check("wrong token rejected ($label)", not_authenticated_problems($wrongCookies));
    }
    check('right token with another user id rejected', not_authenticated_problems(
        ['pp_pgacc_login' => $token, 'pp_pgacc_id' => isset($otherLogin['pp_pgacc_id']) ? $otherLogin['pp_pgacc_id'] : '0'],
    ));
    check('valid login cookies still authenticate', authenticated_as_problems($cookies));
}

// Even the exact dummy token must not turn a missing database row into a
// session. The schema does not guarantee unique user IDs, so keep looking
// when the first row's token does not match (the name column is unique).
check('missing user rejects the dummy token', not_authenticated_problems([
    'pp_pgacc_id' => '999999', 'pp_pgacc_login' => expected_login_token(0, 0, ''),
]));
$duplicateSessions = pg_query(
    database_connection(),
    "INSERT INTO users (id, name, login, cookie, rights, hide) VALUES
     (59, 'synthetic-session-decoy', now(), 101, '2', ''),
     (59, 'synthetic-session-match', now(), 102, '2', '') RETURNING cookie, login",
);
if ($duplicateSessions === false || pg_num_rows($duplicateSessions) !== 2) {
    fail_test('could not create the synthetic duplicate-id sessions');
}
// Check both tokens: no assumption about which row PostgreSQL returns first.
while ($candidateSession = pg_fetch_assoc($duplicateSessions)) {
    check('session lookup accepts matching row ' . $candidateSession['cookie'], authenticated_as_problems([
        'pp_pgacc_id' => '59',
        'pp_pgacc_login' => expected_login_token(59, $candidateSession['cookie'], $candidateSession['login']),
    ]));
}
pg_free_result($duplicateSessions);
pg_query(database_connection(), "DELETE FROM users WHERE id = 59 AND name IN ('synthetic-session-decoy', 'synthetic-session-match')");

// Valid login with a hex-encoded target redirects to that target.
$target = 'index.php?action=all&filter_date=2024';
$targetLogin = login_request('synthetic-reviewer', 'reviewer-password', $target);
check('valid login with target', array_merge(
    login_response_problems($targetLogin, $target, 2),
    redirect_body_problems($targetLogin),
));
check('valid login with target database state', login_state_problems($targetLogin, 2));

// hex2ascii() ignores spaces and decodes lowercase hex as well.
$spacedLogin = http_request('/index.php?action=login', 'POST', [
    'submit' => 'Anmeldung',
    'name' => 'synthetic-unit-one',
    'pass' => 'unit-password',
    'target' => '69 6e 64 65 78 2E 70 68 70 3F 61 3D 31',
]);
check('valid login with spaced lowercase hex target', login_response_problems($spacedLogin, 'index.php?a=1', 3));

// A login older than 24 hours no longer authenticates. The token binds
// users.login, so the test stores an older login and computes its token.
function aged_session_cookies($userId, $age)
{
    if (@pg_query_params(
        database_connection(),
        'UPDATE users SET login = now() - $1::interval WHERE id = $2',
        [$age, $userId],
    ) === false) {
        fail_test("could not age the login of user $userId");
    }
    $state = login_state($userId);
    return [
        'pp_pgacc_login' => expected_login_token($userId, $state['cookie'], $state['login']),
        'pp_pgacc_id' => strval($userId),
    ];
}
check('login of 23 hours ago authenticates', authenticated_as_problems(aged_session_cookies(3, '23 hours')));
check('login older than 24 hours rejected', not_authenticated_problems(aged_session_cookies(3, '25 hours')));

// The login form always posts a target field, empty unless the login page was
// opened with ?target=. An empty target redirects to index.php (not an empty
// "Location:", which browsers show as a blank page).
$emptyTargetLogin = http_request('/index.php?action=login', 'POST', [
    'submit' => 'Anmeldung',
    'name' => 'synthetic-admin',
    'pass' => 'correct-password',
    'target' => '',
]);
check('valid login with empty target', login_response_problems($emptyTargetLogin, 'index.php', 1));

// Already logged in: the login page only reports success, posted credentials
// are not used, and a posted target redirects.
$adminCookies = response_cookies($emptyTargetLogin);
$loggedIn = http_request('/index.php?action=login', 'POST', [
    'submit' => 'Anmeldung',
    'name' => 'synthetic-reviewer',
    'pass' => 'reviewer-password',
], $adminCookies);
$loggedInProblems = [];
if ($loggedIn['status'] !== 200 || response_header($loggedIn, 'Location') !== null
    || count(response_cookies($loggedIn)) !== 0
    || strpos($loggedIn['body'], 'Login erfolgreich!') === false) {
    $loggedInProblems[] = "expected 200 with 'Login erfolgreich!', no Location and no cookies";
}
check('login page when already logged in', $loggedInProblems);
if (isset($adminCookies['pp_pgacc_login']) && isset($adminCookies['pp_pgacc_id'])) {
    check('login page when already logged in, with target', logged_in_target_problems($adminCookies, $target));
    check('login page when already logged in, with empty target', logged_in_target_problems($adminCookies, '', null, 'index.php'));
} else {
    check('login page when already logged in, with target', ['no login cookies to test with']);
}

// Login targets must be pages of this application: index.php with an
// optional query, or an absolute path (the targets of checklogin()).
// Anything else (an empty target, other hosts, schemes, "//",
// backslashes, control characters, line breaks that would add headers,
// spaces) redirects to index.php instead, after a login and when already
// logged in.
foreach (['index.php', '/index.php?action=all&filter_date=2024', '/', 'index.php#top'] as $allowedTarget) {
    check("valid login with target " . var_export($allowedTarget, true), login_response_problems(
        login_request('synthetic-unit-two', 'unit-two-password', $allowedTarget),
        $allowedTarget,
        6,
    ));
}
foreach ([
    'absolute URL' => 'https://evil.example/index.php',
    'plain HTTP URL' => 'http://evil.example/',
    'scheme-relative URL' => '//evil.example/',
    'backslash host' => '/\\evil.example/',
    'backslashes' => '\\\\evil.example',
    'javascript URL' => 'javascript:alert(1)',
    'index.php as scheme' => 'index.php://evil.example/',
    'index.php prefix' => 'index.phpevil',
    'CRLF header injection' => "index.php\r\nSet-Cookie: injected=1",
    'LF header injection' => "/index.php\nX-Injected: 1",
    'final newline' => "index.php\n",
    'leading space' => ' //evil.example/',
    'space in query' => 'index.php?a=1 b',
    'tab' => "/\tevil.example",
    'relative path' => 'evil.php',
    'NUL byte' => "index.php\0",
] as $label => $badTarget) {
    $badLogin = login_request('synthetic-unit-two', 'unit-two-password', $badTarget);
    check("login with target ($label) redirects to index.php", array_merge(
        login_response_problems($badLogin, 'index.php', 6),
        redirect_body_problems($badLogin),
    ));
    $badLoginCookies = response_cookies($badLogin);
    if (isset($badLoginCookies['pp_pgacc_login']) && isset($badLoginCookies['pp_pgacc_id'])) {
        check(
            "logged-in login page with target ($label) redirects to index.php",
            logged_in_target_problems($badLoginCookies, $badTarget, null, 'index.php'),
        );
    }
}
$arrayTarget = http_request('/index.php?action=login', 'POST', [
    'submit' => 'Anmeldung',
    'name' => 'synthetic-unit-two',
    'pass' => 'unit-two-password',
    'target' => ['41'],
]);
check('login with an array target redirects to index.php', login_response_problems($arrayTarget, 'index.php', 6));

// Rejected logins render the form: no redirect and no cookies. Quotes in the
// user name or password are escaped, so they are rejected like any other
// wrong credentials (no SQL error).
foreach ([
    'wrong password' => ['synthetic-admin', 'wrong-password'],
    'unknown user' => ['synthetic-nobody', 'correct-password'],
    'quote in user name' => ["synthetic-admin' --", 'correct-password'],
    'quote in password' => ['synthetic-admin', "x' OR 'a'='a"],
] as $label => $credentials) {
    $rejected = login_request($credentials[0], $credentials[1]);
    check("rejected login ($label)", rejected_login_problems($rejected));
}
$stateBefore = login_state(1);
$formTarget = strtoupper(bin2hex('/index.php?action=all'));
$rejectedWithTarget = http_request('/index.php?action=login&target=' . $formTarget, 'POST', [
    'submit' => 'Anmeldung',
    'name' => 'synthetic-admin',
    'pass' => 'wrong-password',
    'target' => strtoupper(bin2hex($target)),
]);
$rejectedWithTargetProblems = rejected_login_problems($rejectedWithTarget);
// The form keeps the target from the URL, not the posted one.
if (strpos($rejectedWithTarget['body'], '<input name="target" type="hidden" value="' . $formTarget . '" />') === false) {
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

// The form keeps only a target that is hex for a page of this application
// (see above); anything else, also markup, becomes an empty target.
foreach ([
    'lowercase spaced hex' => ['2f 69 6e', '2f 69 6e'],
    'odd hex' => ['ABC', ''],
    'hex of another host' => [strtoupper(bin2hex('https://evil.example/')), ''],
    'markup' => ['"><script>alert(1)</script>', ''],
    'quote' => ["2F' onfocus='alert(1)", ''],
] as $label => $case) {
    $targetForm = http_request('/index.php?action=login&target=' . rawurlencode($case[0]));
    $targetFormProblems = rejected_login_problems($targetForm);
    if (strpos($targetForm['body'], '<input name="target" type="hidden" value="' . $case[1] . '" />') === false) {
        $targetFormProblems[] = 'form target was not ' . var_export($case[1], true);
    }
    if (strpos($targetForm['body'], '<script>alert') !== false || strpos($targetForm['body'], 'onfocus') !== false) {
        $targetFormProblems[] = 'form contained the target markup';
    }
    check("login form with target ($label)", $targetFormProblems);
}
$arrayTargetForm = http_request('/index.php?action=login&target[]=41');
check('login form with an array target', strpos($arrayTargetForm['body'], '<input name="target" type="hidden" value="" />') === false
    ? ['form target was not empty'] : []);

// Logout (the POST form with the form token) redirects to index.php and
// clears both cookies. It also ends the session on the server (users.cookie
// is cleared), so the cookies of that session no longer authenticate, even
// if a copy was kept. A GET request (old link or bookmark) ends nothing.
$reviewerCookies = response_cookies(login_request('synthetic-reviewer', 'reviewer-password'));
check('admin session before logout', authenticated_as_problems($adminCookies));
$adminState = login_state(1);
$getLogout = http_request('/index.php?action=logout', 'GET', [], $adminCookies);
check('logout by GET keeps the session', array_merge(
    $getLogout['status'] === 200 ? [] : ["returned HTTP {$getLogout['status']}, expected 200"],
    response_header($getLogout, 'Set-Cookie') === null ? [] : ['sent a cookie'],
    strpos($getLogout['body'], 'Abmelden mit der Schaltfläche') !== false ? [] : ['no hint to the Logout button'],
    login_state(1) === $adminState ? [] : ['changed the database login state'],
    authenticated_as_problems($adminCookies),
));
$logout = http_request('/index.php?action=logout', 'POST', [], $adminCookies);
check('logout', array_merge(logout_response_problems($logout), redirect_body_problems($logout)));
check('logout cookie attributes', logout_cookie_attribute_problems($logout));
$loggedOut = login_state(1);
check('logout clears users.cookie', $loggedOut['cookie'] === null
    ? [] : ['users.cookie was ' . var_export($loggedOut['cookie'], true) . ', expected NULL']);
check('cookies of a logged-out session are rejected', not_authenticated_problems($adminCookies));
// A logged-out session has no token: not even one computed for the cleared
// (empty) users.cookie.
check('token for the cleared users.cookie rejected', not_authenticated_problems([
    'pp_pgacc_login' => expected_login_token(1, '', $loggedOut['login']),
    'pp_pgacc_id' => '1',
]));
check('logout keeps the sessions of other users', authenticated_as_problems($reviewerCookies));

// A logout with a wrong session token for a user id ends nothing: without a
// session there is no form token, and the token error clears no cookie.
$reviewerState = login_state(2);
$forgedLogout = http_request('/index.php?action=logout', 'POST', [], [
    'pp_pgacc_login' => str_repeat('0', 64),
    'pp_pgacc_id' => '2',
]);
check('logout with a wrong token', array_merge(
    $forgedLogout['status'] === 200 ? [] : ["returned HTTP {$forgedLogout['status']}, expected 200"],
    response_header($forgedLogout, 'Set-Cookie') === null ? [] : ['sent a cookie'],
    strpos($forgedLogout['body'], 'Ungültiges Formular-Token') !== false ? [] : ['no token error'],
));
check('logout with a wrong token keeps the session', array_merge(
    login_state(2) === $reviewerState ? [] : ['changed the database login state'],
    authenticated_as_problems($reviewerCookies),
));

// Same login and logout against a server without output buffering.
$unbufferedUrl = start_unbuffered_server(8081);

$unbufferedLogin = login_request('synthetic-admin', 'correct-password', null, $unbufferedUrl);
check('valid login with output_buffering=0', array_merge(
    login_response_problems($unbufferedLogin, 'index.php', 1),
    login_cookie_attribute_problems($unbufferedLogin),
    redirect_body_problems($unbufferedLogin),
));
$unbufferedTargetLogin = login_request('synthetic-reviewer', 'reviewer-password', $target, $unbufferedUrl);
check('valid login with target with output_buffering=0', array_merge(
    login_response_problems($unbufferedTargetLogin, $target, 2),
    redirect_body_problems($unbufferedTargetLogin),
));
$unbufferedCookies = response_cookies($unbufferedTargetLogin);
if (isset($unbufferedCookies['pp_pgacc_login']) && isset($unbufferedCookies['pp_pgacc_id'])) {
    check(
        'login page when already logged in, with target, with output_buffering=0',
        logged_in_target_problems($unbufferedCookies, $target, $unbufferedUrl),
    );
} else {
    check('login page when already logged in, with target, with output_buffering=0', ['no login cookies to test with']);
}
// Logout with and without the cookies of a session: the session check and
// the end of the session run before the headers.
$unbufferedLogout = http_request('/index.php?action=logout', 'POST', [], $unbufferedCookies, $unbufferedUrl);
$unbufferedLoggedOut = login_state(2);
check('logout with output_buffering=0', array_merge(
    logout_response_problems($unbufferedLogout),
    logout_cookie_attribute_problems($unbufferedLogout),
    redirect_body_problems($unbufferedLogout),
    $unbufferedLoggedOut['cookie'] === null ? [] : ['users.cookie was not cleared'],
    not_authenticated_problems($unbufferedCookies),
));
$unbufferedAnonymousLogout = http_request('/index.php?action=logout', 'POST', [], [], $unbufferedUrl);
check('logout without cookies with output_buffering=0', array_merge(
    $unbufferedAnonymousLogout['status'] === 200 ? [] : ["returned HTTP {$unbufferedAnonymousLogout['status']}, expected 200"],
    response_header($unbufferedAnonymousLogout, 'Set-Cookie') === null ? [] : ['sent a cookie'],
    strpos($unbufferedAnonymousLogout['body'], 'Ungültiges Formular-Token') !== false ? [] : ['no token error'],
));

if (count($problems) > 0) {
    print_diagnostics_summary();
    fail_test(count($problems) . " login/logout check(s) failed:\n  " . implode("\n  ", $problems));
}
assert_no_unexpected_diagnostics();
echo "Login/logout response tests passed\n";
