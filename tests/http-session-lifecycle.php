<?php
/*
 * Focused session lifecycle regressions. Run directly against a seeded lane:
 * php tests/http-session-lifecycle.php [all|logout|password]
 *
 * The password cases used to live at the end of write-workflows.php. Keep
 * them independent so old-code red checks do not run unrelated voucher writes.
 * Test client: PHP 5.6; application: PHP 5.3 and newer.
 */
require __DIR__ . '/http-test-helpers.php';
require_once dirname(__DIR__) . '/functions.php';
require __DIR__ . '/runtime/test-constants.php';

function lifecycle_row($database, $userId)
{
    $result = pg_query_params($database, 'SELECT cookie, login, rights FROM users WHERE id = $1', array($userId));
    if ($result === false || pg_num_rows($result) !== 1) {
        fail_test('could not read synthetic session user ' . $userId);
    }
    return pg_fetch_assoc($result);
}

function lifecycle_user($database, $id, $name, $password, $rights)
{
    $result = pg_query_params($database,
        "INSERT INTO users (id, name, hash, rights, hide) VALUES ($1, $2, crypt($3, gen_salt('md5')), $4, '')",
        array($id, $name, $password, $rights));
    if ($result === false) {
        fail_test('could not create synthetic session user ' . $id);
    }
    return login_user($name, $password);
}

function lifecycle_accepted($cookies)
{
    $response = http_request('/index.php?action=login', 'GET', array(), $cookies);
    assert_status($response, 200, 'session authentication check');
    return strpos($response['body'], 'Login erfolgreich!') !== false;
}

function lifecycle_ended($database, $id, $cookies, $label)
{
    $row = lifecycle_row($database, $id);
    if ($row['cookie'] !== null) {
        fail_test($label . ': users.cookie was not cleared');
    }
    if (lifecycle_accepted($cookies)) {
        fail_test($label . ': old cookies still authenticate');
    }
    // Clearing the nonce must not create another usable token.
    if (lifecycle_accepted(array('pp_pgacc_id' => strval($id),
        'pp_pgacc_login' => login_session_token($id, '', $row['login'])))) {
        fail_test($label . ': a token for the cleared nonce authenticates');
    }
}

$scenario = isset($argv[1]) ? $argv[1] : 'all';
if (!in_array($scenario, array('all', 'logout', 'password'), true)) {
    fail_test('expected all, logout or password scenario');
}
$database = pg_connect(pgacc_test_connection_string());
if ($database === false) {
    fail_test('could not connect to the synthetic session database');
}

if ($scenario === 'all' || $scenario === 'logout') {
    $cookies = lifecycle_user($database, 58, 'synthetic-session-logout', 'logout-password', '2');
    $logout = http_request('/index.php?action=logout', 'GET', array(), $cookies);
    assert_status($logout, 302, 'session logout');
    lifecycle_ended($database, 58, $cookies, 'logout');
    echo "Session logout regression passed\n";
}

if ($scenario === 'all' || $scenario === 'password') {
    $adminCookies = login_user('synthetic-admin', 'correct-password');
    $ownCookies = lifecycle_user($database, 55, 'synthetic-own-password', 'own-password', '2');
    $otherCookies = lifecycle_user($database, 56, 'synthetic-other-password', 'other-password', '2');
    $rightsCookies = lifecycle_user($database, 57, 'synthetic-rights-only', 'rights-only-password', '2');

    $ownChange = http_request('/index.php?action=accounts', 'POST',
        array('submit' => 'Benutzer 55 aktualisieren', 'password55' => 'own-password-changed'), $ownCookies);
    assert_status($ownChange, 200, 'own password change');
    assert_contains($ownChange['body'], 'synthetic-own-password', 'own account remains listed after password change');
    lifecycle_ended($database, 55, $ownCookies, 'own password change');
    if (lifecycle_row($database, 55)['rights'] !== '2') {
        fail_test('own password change changed the rights');
    }
    login_user('synthetic-own-password', 'own-password-changed');

    $otherChange = http_request('/index.php?action=accounts', 'POST',
        array('submit' => 'Benutzer 56 aktualisieren', 'password56' => 'other-password-changed', 'rights56' => '2'), $adminCookies);
    assert_status($otherChange, 200, 'password change by root');
    lifecycle_ended($database, 56, $otherCookies, 'password change by root');
    login_user('synthetic-other-password', 'other-password-changed');
    if (!lifecycle_accepted($adminCookies)) {
        fail_test('changing another password ended the root session');
    }

    $beforeRightsChange = lifecycle_row($database, 57);
    $rightsChange = http_request('/index.php?action=accounts', 'POST',
        array('submit' => 'Benutzer 57 aktualisieren', 'password57' => '******', 'rights57' => '2,1'), $adminCookies);
    assert_status($rightsChange, 200, 'rights-only change');
    $afterRightsChange = lifecycle_row($database, 57);
    if ($afterRightsChange['rights'] !== '2,1' || $afterRightsChange['cookie'] !== $beforeRightsChange['cookie']
        || !lifecycle_accepted($rightsCookies)) {
        fail_test('rights-only change failed or ended the existing session');
    }
    echo "Password session regressions passed\n";
}

pg_query($database, 'DELETE FROM users WHERE id IN (55, 56, 57, 58)');
pg_close($database);
assert_no_unexpected_diagnostics();
echo "Session lifecycle tests passed ($scenario)\n";
