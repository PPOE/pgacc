<?php

/*
 * Form tokens (CSRF). Every POST request except the login form and the public
 * captcha forms must carry the form token of its session (csrf_field(),
 * checked in index.php before anything is written). For every write action a
 * missing token, a wrong token and the token of another user's session show
 * the token error and change nothing in the database; the valid token works.
 * Merge, recovery, the column preferences and logout no longer write on GET.
 *
 * Runs last in a lane: it creates its own synthetic vouchers (ids 93xx) and
 * removes them again. Test client: PHP 5.6; application: PHP 5.3 and newer.
 */
require __DIR__ . '/http-test-helpers.php';

define('CSRF_ERROR', 'FEHLER: Ungültiges Formular-Token. Bitte die Seite neu laden.');

function csrf_query($database, $query, $parameters, $label)
{
    $result = @pg_query_params($database, $query, $parameters);
    if ($result === false) {
        fail_test("$label: " . pg_last_error($database));
    }
    $rows = array();
    while (($row = pg_fetch_assoc($result)) !== false) {
        $rows[] = $row;
    }
    pg_free_result($result);
    return $rows;
}

// Everything a write action can change: the written tables and the sequences
// (a refused receipt upload or voucher creation must not even take a number).
function csrf_state($database)
{
    $rows = csrf_query($database, "SELECT
        md5(array_to_string(ARRAY(SELECT v::text FROM vouchers v ORDER BY 1), '|')) AS vouchers,
        md5(array_to_string(ARRAY(SELECT u::text FROM users u ORDER BY 1), '|')) AS users,
        md5(array_to_string(ARRAY(SELECT i::text FROM import i ORDER BY 1), '|')) AS import,
        (SELECT last_value FROM voucher_number) AS voucher_number,
        (SELECT last_value FROM file_number) AS file_number", array(), 'database state');
    return $rows[0];
}

function csrf_voucher($database, $id, $voucherId, $comment, $deleted = false, $ack1 = null, $ack2 = null)
{
    csrf_query(
        $database,
        "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, committed,
                               date, person_type, file, save_user, vaccount, deleted, ack1, ack2)
         VALUES ($1, $2, 20, 2, false, -1000, 'TEST-ACCOUNT-2', $3, true,
                 timestamp '2024-09-15 00:00:00', 1, 0, 1, 'TEST-VIRTUAL-2', $4, $5, $6)",
        array($id, $voucherId, $comment, $deleted ? 't' : 'f', $ack1, $ack2),
        "voucher $id"
    );
}

function csrf_field_value($database, $query, $parameters, $label)
{
    $rows = csrf_query($database, $query, $parameters, $label);
    if (count($rows) !== 1) {
        fail_test("$label returned " . count($rows) . ' rows');
    }
    return current($rows[0]);
}

function csrf_voucher_form($comment)
{
    return array(
        'speichern' => 'Speichern', 'parts' => '1', 'bid0' => '0', 'dir0' => 'out', 'in_type0' => '8',
        'out_type0' => '20', 'wk_type0' => '20', 'date0' => '2024-09-15', 'person_type0' => '1', 'lo0' => '2',
        'amount0' => '-1.00', 'gegenkonto0' => 'SYNTHETIC001', 'konto0' => 'TESTACCOUNT2',
        'vkonto0' => 'TESTVIRTUAL2', 'comment0' => $comment, 'commentgf0' => 'Synthetic CSRF test',
        'mitgliedsnummer0' => '0', 'name0' => 'Synthetic CSRF Supplier', 'street0' => 'Test Street 1',
        'plz0' => '0000', 'city0' => 'Test City', 'file0' => '0',
    );
}

$database = pg_connect(pgacc_test_connection_string());
if ($database === false) {
    fail_test('could not connect to the synthetic database');
}
$admin = login_user('synthetic-admin', 'correct-password');
$reviewer = login_user('synthetic-reviewer', 'reviewer-password');
$adminToken = session_csrf_token($admin);
$reviewerToken = session_csrf_token($reviewer);
foreach (array('admin' => $adminToken, 'reviewer' => $reviewerToken) as $user => $token) {
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail_test("the pages of the $user session show no form token (64 hex digits)");
    }
}
if ($adminToken === $reviewerToken || $adminToken === $admin['pp_pgacc_login']) {
    fail_test('the form token is shared between sessions or equals the login cookie');
}
if (session_csrf_token(array()) !== null) {
    fail_test('a visitor without a session sees a form token');
}

csrf_voucher($database, 9301, 4901, 'Synthetic CSRF edit target');
csrf_voucher($database, 9302, 4902, 'Synthetic CSRF approved voucher', false, 'synthetic-admin', 'synthetic-reviewer');
csrf_voucher($database, 9303, 4903, 'Synthetic CSRF merge target');
csrf_voucher($database, 9304, 4904, 'Synthetic CSRF merge source');
csrf_voucher($database, 9305, 4905, 'Synthetic CSRF deleted revision', true);

$pdf = "%PDF-1.4\nsynthetic CSRF receipt\n%%EOF\n";
$csv = "Synthetic;CSRF;import\n2024-09-15;1,00;line\n";
$revision = array_merge(csrf_voucher_form('Synthetic CSRF revision'), array(
    'id' => '4901', 'changed' => '1', 'bid0' => '9301', 'ack1' => '', 'ack2' => '', 'ack1_old' => '', 'ack2_old' => '',
));
// label => path, form, files
$writes = array(
    'voucher creation' => array('/index.php?action=new', csrf_voucher_form('Synthetic CSRF voucher'), array()),
    'revision' => array('/index.php?action=edit&id=4901', $revision, array()),
    'approval' => array('/index.php?action=edit&id=4901', array('id' => '4901', 'parts' => '1', 'ack' => 'Bestätigen'), array()),
    'approval reset' => array('/index.php?action=edit&id=4902', array('id' => '4902', 'parts' => '1', 'belegfehler' => 'Fehler'), array()),
    'receipt upload' => array('/index.php?action=edit&id=4901',
        array('fileupload' => 'PDF Hochladen (OHNE SPEICHERN)', 'parts' => '1', 'bid0' => '9301', 'id' => '4901'),
        array('file0' => array('name' => 'csrf.pdf', 'type' => 'application/pdf', 'content' => $pdf))),
    'merge' => array('/index.php?action=merge', array('bid1' => '4903', 'bid2' => '4904'), array()),
    'recovery' => array('/index.php?action=recover&id=4905&bid=9305', array('doit' => 'Ja, ich weiß was ich tue!'), array()),
    'account creation' => array('/index.php?action=accounts', array('submit' => 'Benutzer -1 erstellen',
        'name-1' => 'synthetic-csrf-user', 'password-1' => 'csrf-password', 'rights-1' => '2'), array()),
    'password change' => array('/index.php?action=accounts', array('submit' => 'Benutzer 2 aktualisieren',
        'password2' => 'csrf-changed-password', 'rights2' => 'bgf'), array()),
    'bank import' => array('/index.php?action=import', array('MAX_FILE_SIZE' => '1000000'),
        array('uploadcsv[]' => array('name' => 'csrf.csv', 'type' => 'text/csv', 'content' => $csv))),
    'statement import' => array('/index.php?action=import2', array('MAX_FILE_SIZE' => '10000000'),
        array('upload[]' => array('name' => 'csrf.pdf', 'type' => 'application/pdf', 'content' => $pdf))),
    'column preference' => array('/index.php?action=all&filter_date=2024', array('hide' => '3'), array()),
    'logout' => array('/index.php?action=logout', array(), array()),
);
$badTokens = array('missing' => null, 'wrong' => str_repeat('0', 64), "another user's" => $reviewerToken);

$problems = array();
foreach ($writes as $label => $write) {
    foreach ($badTokens as $kind => $token) {
        $before = csrf_state($database);
        $response = http_request($write[0], 'POST', array_merge($write[1], array('csrf_token' => $token)), $admin, null, $write[2]);
        $after = csrf_state($database);
        if ($response['status'] !== 200) {
            $problems[] = "$label with the $kind token: HTTP {$response['status']}, expected 200";
        }
        if (strpos($response['body'], CSRF_ERROR) === false) {
            $problems[] = "$label with the $kind token: no token error";
        }
        if (response_header($response, 'Set-Cookie') !== null) {
            $problems[] = "$label with the $kind token: sent a cookie";
        }
        if ($after !== $before) {
            $problems[] = "$label with the $kind token changed the database: " . implode(', ', array_keys(array_diff_assoc($after, $before)));
        }
    }
}
// A token sent in the query string does not count either.
$before = csrf_state($database);
$response = http_request('/index.php?action=merge&csrf_token=' . $adminToken, 'POST', array('bid1' => '4903', 'bid2' => '4904', 'csrf_token' => null), $admin);
if (strpos($response['body'], CSRF_ERROR) === false || csrf_state($database) !== $before) {
    $problems[] = 'merge with the token in the query string was not refused';
}
// The login form and the public captcha forms need no token.
$response = http_request('/index.php?action=donations&year=2024', 'POST', array('captcha_input' => '1', 'captcha' => '0'));
if (strpos($response['body'], CSRF_ERROR) !== false) {
    $problems[] = 'the donations captcha asked for a form token';
}

// GET no longer writes: merge, recovery and column preferences show their
// page (or a message), logout keeps the session.
$gets = array(
    'merge' => '/index.php?action=merge&bid1=4903&bid2=4904',
    'recovery' => '/index.php?action=recover&id=4905&bid=9305&doit=1',
    'column preference' => '/index.php?action=all&filter_date=2024&hide=3',
    'logout' => '/index.php?action=logout',
);
foreach ($gets as $label => $path) {
    $before = csrf_state($database);
    $response = http_request($path, 'GET', array(), $admin);
    if ($response['status'] !== 200 || response_header($response, 'Set-Cookie') !== null) {
        $problems[] = "GET $label: HTTP {$response['status']} or a cookie, expected the page";
    }
    if (csrf_state($database) !== $before) {
        $problems[] = "GET $label changed the database";
    }
}
$getRecovery = http_request($gets['recovery'], 'GET', array(), $admin);
if (strpos($getRecovery['body'], 'Ja, ich weiß was ich tue!') === false || strpos($getRecovery['body'], 'wiederhergestellt!') !== false) {
    $problems[] = 'GET recovery with doit did not show the confirmation form';
}
$getMerge = http_request($gets['merge'], 'GET', array(), $admin);
if (strpos($getMerge['body'], 'FEHLER: Buchungen nur über die Schaltfläche') === false) {
    $problems[] = 'GET merge did not explain that merging needs the button';
}

// The forms that write carry the form token of the session.
$field = '<input type="hidden" name="csrf_token" value="' . $adminToken . '" />';
$forms = array(
    'voucher form' => array('/index.php?action=edit&id=4901', 'action="index.php?action=edit" method="post">' . "\r\n" . $field),
    'merge form' => array('/index.php?action=edit&id=4901', '<form id="mergeform" action="index.php?action=merge" method="post" style="display: none">'
        . $field . '<input type="hidden" name="bid1" value="4901" />'),
    'recovery form' => array('/index.php?action=recover&id=4905&bid=9305', '<form action="index.php?action=recover&amp;id=4905&amp;bid=9305" method="post">'
        . "\n" . $field . "\n" . '<input type="submit" name="doit"'),
    'user list' => array('/index.php?action=accounts', '<form action="index.php?action=accounts" method="POST">' . $field . '<table>'),
    'Logout button' => array('/index.php?action=impressum', '<form class="inline" action="index.php?action=logout" method="post">' . $field
        . '<button class="link" type="submit">Logout</button></form>'),
);
foreach ($forms as $label => $form) {
    $page = http_request($form[0], 'GET', array(), $admin);
    if (strpos($page['body'], $form[1]) === false) {
        $problems[] = "the $label does not carry the form token of the session";
    }
}
// Only root gets the merge form (and its token).
$reviewerPage = http_request('/index.php?action=edit&id=4901', 'GET', array(), $reviewer);
if (strpos($reviewerPage['body'], 'Synthetic CSRF edit target') === false || strpos($reviewerPage['body'], 'id="mergeform"') !== false) {
    $problems[] = 'the voucher page of a bgf user without root has a merge form';
}
// Column preferences are stored only where the token is checked: not on
// the pages without a token (the public captcha forms).
$before = csrf_state($database);
http_request('/index.php?action=donations&year=2024', 'POST', array('hide' => '3', 'csrf_token' => null), $admin);
if (csrf_state($database) !== $before) {
    $problems[] = 'a column preference posted to the donations page without a token was stored';
}

// The valid token works.
$response = http_request($writes['approval'][0], 'POST', $writes['approval'][1], $admin);
if (csrf_field_value($database, 'SELECT ack1 FROM vouchers WHERE id = 9301', array(), 'approval') !== 'synthetic-admin') {
    $problems[] = 'approval with the valid token did not approve';
}
http_request($writes['approval reset'][0], 'POST', $writes['approval reset'][1], $admin);
if (csrf_field_value($database, 'SELECT ack1_old FROM vouchers WHERE id = 9302', array(), 'approval reset') !== 'synthetic-admin') {
    $problems[] = 'approval reset with the valid token did not reset';
}
$response = http_request($writes['merge'][0], 'POST', $writes['merge'][1], $admin);
assert_no_problems(redirect_problems($response, 'index.php?action=edit&id=4903&hint=6'), 'merge with the valid token');
if (csrf_field_value($database, 'SELECT COUNT(*) FROM vouchers WHERE voucher_id = 4903', array(), 'merge') !== '2') {
    $problems[] = 'merge with the valid token moved no row';
}
// A merge that moves no row (the second voucher is gone now) says so.
$response = http_request($writes['merge'][0], 'POST', $writes['merge'][1], $admin);
if ($response['status'] !== 200 || strpos($response['body'], 'FEHLER: Buchungen nicht gefunden.') === false) {
    $problems[] = 'a merge that moved no row did not report "Buchungen nicht gefunden"';
}
$response = http_request($writes['recovery'][0], 'POST', $writes['recovery'][1], $admin);
if (csrf_field_value($database, 'SELECT deleted FROM vouchers WHERE id = 9305', array(), 'recovery') !== 'f'
    || strpos($response['body'], 'wiederhergestellt!') === false) {
    $problems[] = 'recovery with the valid token did not recover';
}
http_request($writes['account creation'][0], 'POST', $writes['account creation'][1], $admin);
if (csrf_field_value($database, "SELECT COUNT(*) FROM users WHERE name = 'synthetic-csrf-user'", array(), 'account') !== '1') {
    $problems[] = 'account creation with the valid token created no account';
}
// The fixtures do not advance the users id sequence: remove the new account
// before it shares an id with a fixture user.
csrf_query($database, "DELETE FROM users WHERE name = 'synthetic-csrf-user'", array(), 'new account');
http_request($writes['column preference'][0], 'POST', $writes['column preference'][1], $admin);
if (csrf_field_value($database, "SELECT hide FROM users WHERE name = 'synthetic-admin'", array(), 'column preference') !== '3') {
    $problems[] = 'the column preference with the valid token was not stored';
}
http_request($writes['column preference'][0], 'POST', array('hide' => '-3'), $admin);

// A new login replaces the token: forms of the old session stop working.
$again = login_user('synthetic-admin', 'correct-password');
$againToken = session_csrf_token($again);
if ($againToken === null || $againToken === $adminToken) {
    $problems[] = 'a new login kept the form token';
}
$before = csrf_state($database);
$response = http_request($writes['merge'][0], 'POST', array('bid1' => '4901', 'bid2' => '4902', 'csrf_token' => $adminToken), $again);
if (strpos($response['body'], CSRF_ERROR) === false || csrf_state($database) !== $before) {
    $problems[] = 'the token of the previous login still works';
}
$response = http_request($writes['logout'][0], 'POST', array(), $again);
assert_no_problems(logout_response_problems($response), 'logout with the valid token');
if (csrf_field_value($database, "SELECT cookie FROM users WHERE name = 'synthetic-admin'", array(), 'logout') !== null) {
    $problems[] = 'logout with the valid token kept users.cookie';
}

csrf_query($database, 'DELETE FROM vouchers WHERE id BETWEEN 9301 AND 9310', array(), 'cleanup');
csrf_query($database, "DELETE FROM users WHERE name = 'synthetic-csrf-user'", array(), 'cleanup');
pg_close($database);
if (count($problems) > 0) {
    fail_test(count($problems) . " CSRF check(s) failed:\n  " . implode("\n  ", $problems));
}
assert_no_unexpected_diagnostics();
echo "CSRF tests passed\n";
