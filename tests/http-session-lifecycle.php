<?php

/*
 * Focused session lifecycle regressions. Run directly against a seeded lane:
 * php tests/http-session-lifecycle.php [all|logout|password|length]
 *
 * The password cases used to live at the end of write-workflows.php. Keep
 * them independent so old-code red checks do not run unrelated voucher writes.
 */
require __DIR__ . '/http-test-helpers.php';
require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/src/db.php';
require __DIR__ . '/runtime/test-constants.php';

function lifecycle_row($database, $userId)
{
    $result = pg_query_params($database, 'SELECT cookie, login, rights FROM users WHERE id = $1', [$userId]);
    if ($result === false || pg_num_rows($result) !== 1) {
        fail_test('could not read synthetic session user ' . $userId);
    }
    return pg_fetch_assoc($result);
}

function lifecycle_hash($database, $userId)
{
    $result = pg_query_params($database, 'SELECT hash FROM users WHERE id = $1', [$userId]);
    return $result === false || pg_num_rows($result) !== 1 ? null : pg_fetch_result($result, 0, 0);
}

// Synthetic users get an MD5-crypt hash like accounts created before bcrypt;
// logging in with it checks that old hashes stay valid.
function lifecycle_user($database, $id, $name, $password, $rights)
{
    $result = pg_query_params(
        $database,
        "INSERT INTO users (id, name, hash, rights, hide) VALUES ($1, $2, crypt($3, gen_salt('md5')), $4, '')",
        [$id, $name, $password, $rights],
    );
    if ($result === false) {
        fail_test('could not create synthetic session user ' . $id);
    }
    return login_user($name, $password);
}

function lifecycle_accepted($cookies)
{
    $response = http_request('/index.php?action=login', 'GET', [], $cookies);
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
    if (lifecycle_accepted(['pp_pgacc_id' => strval($id),
        'pp_pgacc_login' => login_session_token($id, '', $row['login'])])) {
        fail_test($label . ': a token for the cleared nonce authenticates');
    }
}

$scenario = isset($argv[1]) ? $argv[1] : 'all';
if (!in_array($scenario, ['all', 'logout', 'password', 'length'], true)) {
    fail_test('expected all, logout, password or length scenario');
}
$database = pg_connect(pgacc_test_connection_string());
if ($database === false) {
    fail_test('could not connect to the synthetic session database');
}

if ($scenario === 'all' || $scenario === 'logout') {
    $firstCookies = lifecycle_user($database, 58, 'synthetic-session-logout', 'logout-password', '2');
    $firstToken = session_csrf_token($firstCookies);
    // A new login replaces the form token: forms opened before it are refused.
    $cookies = login_user('synthetic-session-logout', 'logout-password');
    $token = session_csrf_token($cookies);
    if ($firstToken === null || $token === null || $firstToken === $token) {
        fail_test('a new login did not replace the form token');
    }
    $staleLogout = http_request('/index.php?action=logout', 'POST', ['csrf_token' => $firstToken], $cookies);
    assert_status($staleLogout, 200, 'logout with the form token of the previous login');
    assert_contains($staleLogout['body'], 'Ungültiges Formular-Token', 'logout with the form token of the previous login');
    if (!lifecycle_accepted($cookies)) {
        fail_test('logout with the form token of the previous login ended the session');
    }
    $logout = http_request('/index.php?action=logout', 'POST', [], $cookies);
    assert_status($logout, 302, 'session logout');
    lifecycle_ended($database, 58, $cookies, 'logout');
    // The form token died with the session.
    $afterLogout = http_request('/index.php?action=logout', 'POST', ['csrf_token' => $token], $cookies);
    assert_contains($afterLogout['body'], 'Ungültiges Formular-Token', 'form token after logout');
    echo "Session logout regression passed\n";
}

if ($scenario === 'all' || $scenario === 'password') {
    $adminCookies = login_user('synthetic-admin', 'correct-password');
    $ownCookies = lifecycle_user($database, 55, 'synthetic-own-password', 'own-password', '2');
    $otherCookies = lifecycle_user($database, 56, 'synthetic-other-password', 'other-password', '2');
    $rightsCookies = lifecycle_user($database, 57, 'synthetic-rights-only', 'rights-only-password', '2');

    $ownChange = http_request(
        '/index.php?action=accounts',
        'POST',
        ['submit' => 'Benutzer 55 aktualisieren', 'password55' => 'own-password-changed'],
        $ownCookies,
    );
    assert_status($ownChange, 200, 'own password change');
    assert_contains($ownChange['body'], 'synthetic-own-password', 'own account remains listed after password change');
    lifecycle_ended($database, 55, $ownCookies, 'own password change');
    if (lifecycle_row($database, 55)['rights'] !== '2') {
        fail_test('own password change changed the rights');
    }
    // Checked before the next login, which would also upgrade an MD5 hash.
    if (strpos((string) lifecycle_hash($database, 55), '$2a$10$') !== 0) {
        fail_test('own changed password is not stored as bcrypt');
    }
    login_user('synthetic-own-password', 'own-password-changed');

    $otherChange = http_request(
        '/index.php?action=accounts',
        'POST',
        ['submit' => 'Benutzer 56 aktualisieren', 'password56' => 'other-password-changed', 'rights56' => '2'],
        $adminCookies,
    );
    assert_status($otherChange, 200, 'password change by root');
    lifecycle_ended($database, 56, $otherCookies, 'password change by root');
    if (strpos((string) lifecycle_hash($database, 56), '$2a$10$') !== 0) {
        fail_test('password changed by root is not stored as bcrypt');
    }
    login_user('synthetic-other-password', 'other-password-changed');
    if (!lifecycle_accepted($adminCookies)) {
        fail_test('changing another password ended the root session');
    }

    $beforeRightsChange = lifecycle_row($database, 57);
    $rightsChange = http_request(
        '/index.php?action=accounts',
        'POST',
        ['submit' => 'Benutzer 57 aktualisieren', 'password57' => '******', 'rights57' => '2,1'],
        $adminCookies,
    );
    assert_status($rightsChange, 200, 'rights-only change');
    $afterRightsChange = lifecycle_row($database, 57);
    if ($afterRightsChange['rights'] !== '2,1' || $afterRightsChange['cookie'] !== $beforeRightsChange['cookie']
        || !lifecycle_accepted($rightsCookies)) {
        fail_test('rights-only change failed or ended the existing session');
    }
    echo "Password session regressions passed\n";
}

if ($scenario === 'all' || $scenario === 'length') {
    $adminCookies = login_user('synthetic-admin', 'correct-password');
    $shortCookies = lifecycle_user($database, 54, 'synthetic-short-old', 'short', '2');
    // The login replaced the MD5-crypt hash with bcrypt.
    if (strpos((string) lifecycle_hash($database, 54), '$2a$10$') !== 0) {
        fail_test('login did not upgrade the MD5-crypt hash to bcrypt');
    }
    $before = lifecycle_hash($database, 54);
    // 11 characters (22 bytes in UTF-8) are too short, empty too.
    $refused = [
        'own short password' => [['submit' => 'Benutzer 54 aktualisieren', 'password54' => str_repeat('ä', 11)], $shortCookies],
        'own empty password' => [['submit' => 'Benutzer 54 aktualisieren', 'password54' => ''], $shortCookies],
        'own missing password' => [['submit' => 'Benutzer 54 aktualisieren'], $shortCookies],
        'own array password' => [['submit' => 'Benutzer 54 aktualisieren', 'password54' => ['long-enough-password']], $shortCookies],
        'root short password' => [['submit' => 'Benutzer 54 aktualisieren', 'password54' => 'elevenchars', 'rights54' => '2'], $adminCookies],
        'root short new user' => [['submit' => 'Benutzer -1 erstellen', 'name-1' => 'synthetic-short-new',
            'password-1' => 'elevenchars', 'rights-1' => '2'], $adminCookies],
    ];
    foreach ($refused as $label => [$post, $cookies]) {
        $response = http_request('/index.php?action=accounts', 'POST', $post, $cookies);
        assert_status($response, 200, $label);
        assert_contains($response['body'], 'FEHLER: Das Passwort muss mindestens 12 Zeichen lang sein.', $label);
        // The form is shown again, with the hint.
        assert_contains($response['body'], 'value="Benutzer 54 aktualisieren"', $label);
        assert_contains($response['body'], '<b>Passwort</b> (mindestens 12 Zeichen)', $label);
    }
    $created = pg_query_params($database, 'SELECT 1 FROM users WHERE name = $1', ['synthetic-short-new']);
    if (lifecycle_hash($database, 54) !== $before || $created === false || pg_num_rows($created) !== 0) {
        fail_test('a refused short password was saved');
    }
    if (!lifecycle_accepted($shortCookies)) {
        fail_test('a refused short password ended the session');
    }
    // The old short password still logs in; 12 characters (24 bytes) are enough.
    $shortCookies = login_user('synthetic-short-old', 'short');
    $before = lifecycle_hash($database, 54);
    $accepted = http_request(
        '/index.php?action=accounts',
        'POST',
        ['submit' => 'Benutzer 54 aktualisieren', 'password54' => str_repeat('ä', 12)],
        $shortCookies,
    );
    assert_status($accepted, 200, 'own 12-character password');
    $after = lifecycle_hash($database, 54);
    if ($after === $before || strpos((string) $after, '$2a$10$') !== 0) {
        fail_test('12-character password was not stored as bcrypt');
    }
    login_user('synthetic-short-old', str_repeat('ä', 12));
    // A new user gets a bcrypt hash.
    $create = http_request('/index.php?action=accounts', 'POST', ['submit' => 'Benutzer -1 erstellen',
        'name-1' => 'synthetic-long-new', 'password-1' => 'long-new-password', 'rights-1' => '2'], $adminCookies);
    assert_status($create, 200, 'new user with a 12-character password');
    $created = pg_query_params($database, 'SELECT hash FROM users WHERE name = $1', ['synthetic-long-new']);
    if ($created === false || pg_num_rows($created) !== 1 || strpos((string) pg_fetch_result($created, 0, 0), '$2a$10$') !== 0) {
        fail_test('new user is not stored with a bcrypt hash');
    }
    echo "Password length regressions passed\n";
}

pg_query($database, "DELETE FROM users WHERE id IN (54, 55, 56, 57, 58) OR name IN ('synthetic-short-new', 'synthetic-long-new')");
pg_close($database);
assert_no_unexpected_diagnostics();
echo "Session lifecycle tests passed ($scenario)\n";
