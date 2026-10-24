<?php
require __DIR__ . '/http-test-helpers.php';

function database_connection()
{
    $database = @pg_connect(pgacc_test_connection_string());
    if ($database === false) {
        fail_test('could not connect to the fixture database');
    }
    return $database;
}

function database_row($database, $query, $parameters, $label)
{
    $result = @pg_query_params($database, $query, $parameters);
    if ($result === false) {
        fail_test("database query failed for $label: " . pg_last_error($database));
    }
    if (pg_num_rows($result) !== 1) {
        fail_test("database query for $label returned " . pg_num_rows($result) . ' rows');
    }
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    return $row;
}

function assert_database_field($row, $field, $expected, $label)
{
    if (!array_key_exists($field, $row) || $row[$field] !== $expected) {
        $actual = array_key_exists($field, $row) ? var_export($row[$field], true) : 'missing';
        $wanted = var_export($expected, true);
        fail_test("$label field $field was $actual, expected $wanted");
    }
}

function assert_database_count($database, $query, $parameters, $expected, $label)
{
    $row = database_row($database, $query, $parameters, $label);
    assert_database_field($row, 'count', strval($expected), $label);
}

function voucher_form($comment, $amount, $file)
{
    return array(
        'speichern' => 'Speichern',
        'parts' => '1',
        'bid0' => '0',
        'dir0' => 'wk',
        'in_type0' => '8',
        'out_type0' => '20',
        'wk_type0' => '20',
        'date0' => '2024-09-15',
        'person_type0' => '1',
        'lo0' => '2',
        'amount0' => $amount,
        'gegenkonto0' => 'SYNTHETIC001',
        'konto0' => 'TESTACCOUNT2',
        'vkonto0' => 'TESTVIRTUAL2',
        'comment0' => $comment,
        'commentgf0' => 'Synthetic write workflow',
        'mitgliedsnummer0' => '0',
        'name0' => 'Synthetic Workflow Supplier',
        'street0' => 'Test Street 1',
        'plz0' => '0000',
        'city0' => 'Test City',
        'file0' => strval($file),
    );
}

function create_voucher($database, $comment, $amount, $file, $cookies)
{
    $response = http_request('/index.php?action=new', 'POST', voucher_form($comment, $amount, $file), $cookies);
    assert_status($response, 200, "creation of $comment");
    assert_contains($response['body'], 'Buchung erfasst', "creation of $comment");
    $row = database_row(
        $database,
        'SELECT voucher_id FROM vouchers WHERE comment = $1 AND NOT deleted',
        array($comment),
        "created $comment"
    );
    return $row['voucher_id'];
}

// Voucher write redirects (relocate() in edit.php): 302 to the voucher's
// edit page with a hint for the message, answered before any output.
function assert_redirect($response, $location, $label)
{
    assert_no_problems(redirect_problems($response, $location), $label);
}

function edit_location($voucherId, $hint)
{
    return "index.php?action=edit&id=$voucherId&hint=$hint";
}

// The form of a revision ("Speichern" on the edit page) of a voucher that has
// no approvals.
function revision_form($voucherId, $comment, $amount)
{
    $form = voucher_form($comment, $amount, 0);
    $form['id'] = $voucherId;
    $form['changed'] = '1';
    $form['ack1'] = '';
    $form['ack2'] = '';
    $form['ack1_old'] = '';
    $form['ack2_old'] = '';
    return $form;
}

$database = database_connection();
$adminCookies = login_user('synthetic-admin', 'correct-password');
$reviewerCookies = login_user('synthetic-reviewer', 'reviewer-password');
$unitOneCookies = login_user('synthetic-unit-one', 'unit-password');

$createdComment = 'Synthetic HTTP-created expense';
$creation = http_request(
    '/index.php?action=new',
    'POST',
    voucher_form($createdComment, '-12.34', 9001),
    $adminCookies
);
assert_status($creation, 200, 'voucher creation');
assert_contains($creation['body'], 'Buchung erfasst', 'voucher creation');
assert_no_fatal_error($creation['body'], 'voucher creation');

$created = database_row(
    $database,
    'SELECT id, voucher_id, type, orga, amount, file, deleted, receipt_received, ack1, ack2
       FROM vouchers
      WHERE comment = $1 AND NOT deleted',
    array($createdComment),
    'created voucher'
);
assert_database_field($created, 'type', '20', 'created voucher');
assert_database_field($created, 'orga', '2', 'created voucher');
assert_database_field($created, 'amount', '-1234', 'created voucher');
assert_database_field($created, 'file', '9001', 'created voucher');
assert_database_field($created, 'deleted', 'f', 'created voucher');
assert_database_field($created, 'receipt_received', 'f', 'created voucher');
assert_database_field($created, 'ack1', null, 'created voucher');
assert_database_field($created, 'ack2', null, 'created voucher');
$createdVoucherId = $created['voucher_id'];

$unauthorizedApproval = http_request(
    '/index.php?action=edit&id=' . rawurlencode($createdVoucherId),
    'POST',
    array('id' => $createdVoucherId, 'parts' => '1', 'ack' => 'Bestätigen'),
    $unitOneCookies
);
assert_status($unauthorizedApproval, 200, 'cross-organization approval');
assert_contains($unauthorizedApproval['body'], 'Hier fehlt noch der Beleg', 'cross-organization approval');
$created = database_row(
    $database,
    'SELECT ack1, ack2 FROM vouchers WHERE id = $1',
    array($created['id']),
    'voucher after cross-organization approval'
);
assert_database_field($created, 'ack1', null, 'voucher after cross-organization approval');
assert_database_field($created, 'ack2', null, 'voucher after cross-organization approval');

$firstApproval = http_request(
    '/index.php?action=edit&id=' . rawurlencode($createdVoucherId),
    'POST',
    array('id' => $createdVoucherId, 'parts' => '1', 'ack' => 'Bestätigen'),
    $adminCookies
);
assert_redirect($firstApproval, edit_location($createdVoucherId, 4), 'first approval');
$created = database_row(
    $database,
    'SELECT id, ack1, ack2 FROM vouchers WHERE voucher_id = $1 AND NOT deleted',
    array($createdVoucherId),
    'voucher after first approval'
);
assert_database_field($created, 'ack1', 'synthetic-admin', 'voucher after first approval');
assert_database_field($created, 'ack2', null, 'voucher after first approval');

$duplicateApproval = http_request(
    '/index.php?action=edit&id=' . rawurlencode($createdVoucherId),
    'POST',
    array('id' => $createdVoucherId, 'parts' => '1', 'ack' => 'Bestätigen'),
    $adminCookies
);
assert_redirect($duplicateApproval, edit_location($createdVoucherId, 4), 'duplicate approval');
$created = database_row(
    $database,
    'SELECT id, ack1, ack2 FROM vouchers WHERE voucher_id = $1 AND NOT deleted',
    array($createdVoucherId),
    'voucher after duplicate approval'
);
assert_database_field($created, 'ack1', 'synthetic-admin', 'voucher after duplicate approval');
assert_database_field($created, 'ack2', null, 'voucher after duplicate approval');

$secondApproval = http_request(
    '/index.php?action=edit&id=' . rawurlencode($createdVoucherId),
    'POST',
    array('id' => $createdVoucherId, 'parts' => '1', 'ack' => 'Bestätigen'),
    $reviewerCookies
);
assert_redirect($secondApproval, edit_location($createdVoucherId, 4), 'second approval');
$created = database_row(
    $database,
    'SELECT id, ack1, ack2, receipt_received FROM vouchers
      WHERE voucher_id = $1 AND NOT deleted',
    array($createdVoucherId),
    'voucher after second approval'
);
assert_database_field($created, 'ack1', 'synthetic-admin', 'voucher after second approval');
assert_database_field($created, 'ack2', 'synthetic-reviewer', 'voucher after second approval');
assert_database_field($created, 'receipt_received', 'f', 'voucher after second approval');

// Finalization ("beleg") is removed: the form has had no button for it since
// 68cda09 (2015), and production has a single finalized row. A POST with
// "beleg" shows the voucher page and changes nothing, for every user (users
// who see no row of the voucher are left out: the voucher page then raises
// the open edit.php $vouchers finding).
$finalizeForm = array('beleg' => 'Beleg erhalten');
$finalizationUsers = array(
    'admin' => $adminCookies,
    'bgf' => $reviewerCookies,
    'organization two' => login_user('synthetic-unit-two', 'unit-two-password'),
    'read-only bgf' => login_user('synthetic-read-only', 'read-only-password'),
);
foreach ($finalizationUsers as $userLabel => $cookies) {
    $finalization = http_request(
        '/index.php?action=edit&id=' . rawurlencode($createdVoucherId),
        'POST',
        array('id' => $createdVoucherId, 'parts' => '1', 'beleg' => 'Beleg erhalten'),
        $cookies
    );
    assert_status($finalization, 200, "finalization request by $userLabel");
    assert_not_contains($finalization['body'], 'finalisiert', "finalization request by $userLabel");
    assert_database_count(
        $database,
        'SELECT COUNT(*) AS count FROM vouchers WHERE receipt_received AND voucher_id = $1',
        array($createdVoucherId),
        0,
        "voucher after a finalization request by $userLabel"
    );
}
assert_contains($finalization['body'], 'value="' . $createdComment . '"', 'finalization request (voucher page)');

// The existing finalized rows stay locked: set up in the database.
function finalize_in_database($database, $voucherId, $orga = null)
{
    $result = @pg_query_params(
        $database,
        'UPDATE vouchers SET receipt_received = true WHERE voucher_id = $1 AND ($2::integer IS NULL OR orga = $2)',
        array($voucherId, $orga)
    );
    if ($result === false) {
        fail_test('could not finalize voucher ' . $voucherId . ': ' . pg_last_error($database));
    }
}
finalize_in_database($database, $createdVoucherId);
$created = database_row(
    $database,
    'SELECT id, receipt_received, deleted FROM vouchers
      WHERE voucher_id = $1 AND NOT deleted',
    array($createdVoucherId),
    'finalized voucher'
);
assert_database_field($created, 'receipt_received', 't', 'finalized voucher');
assert_database_field($created, 'deleted', 'f', 'finalized voucher');

$blockedEditForm = voucher_form('Should not replace finalized voucher', '-99.99', 9001);
$blockedEditForm['id'] = $createdVoucherId;
$blockedEditForm['changed'] = '1';
$blockedEditForm['ack1'] = 'synthetic-admin';
$blockedEditForm['ack2'] = 'synthetic-reviewer';
$blockedEditForm['ack1_old'] = '';
$blockedEditForm['ack2_old'] = '';
$blockedEdit = http_request(
    '/index.php?action=edit&id=' . rawurlencode($createdVoucherId),
    'POST',
    $blockedEditForm,
    $adminCookies
);
assert_status($blockedEdit, 200, 'finalized voucher edit');
assert_contains($blockedEdit['body'], 'bereits finalisiert', 'finalized voucher edit');
assert_database_count(
    $database,
    'SELECT COUNT(*) AS count FROM vouchers WHERE comment = $1',
    array('Should not replace finalized voucher'),
    0,
    'blocked finalized revision'
);

$revisionComment = 'Synthetic revised draft';
$revisionForm = voucher_form($revisionComment, '-30.00', 0);
$revisionForm['id'] = '1004';
$revisionForm['changed'] = '1';
$revisionForm['ack1'] = '';
$revisionForm['ack2'] = '';
$revisionForm['ack1_old'] = '';
$revisionForm['ack2_old'] = '';
$revision = http_request(
    '/index.php?action=edit&id=1004',
    'POST',
    $revisionForm,
    $adminCookies
);
assert_redirect($revision, edit_location('1004', 5), 'voucher revision');

$oldRevision = database_row(
    $database,
    'SELECT id, deleted FROM vouchers WHERE id = 105',
    array(),
    'original revision'
);
assert_database_field($oldRevision, 'deleted', 't', 'original revision');
$newRevision = database_row(
    $database,
    'SELECT id, voucher_id, amount, deleted FROM vouchers WHERE comment = $1',
    array($revisionComment),
    'replacement revision'
);
assert_database_field($newRevision, 'voucher_id', '1004', 'replacement revision');
assert_database_field($newRevision, 'amount', '-3000', 'replacement revision');
assert_database_field($newRevision, 'deleted', 'f', 'replacement revision');

// Merging moves every row of the second voucher into the first one. The edit
// page links it as index.php?action=merge&bid1=<this voucher>&bid2=<other>.
$mergeTargetId = create_voucher($database, 'Synthetic merge target', '-1.00', 0, $adminCookies);
$mergeSourceId = create_voucher($database, 'Synthetic merge source', '-2.00', 0, $adminCookies);
$mergeUrl = '/index.php?action=merge&bid1=' . rawurlencode($mergeTargetId) . '&bid2=' . rawurlencode($mergeSourceId);
$voucherRowsQuery = 'SELECT COUNT(*) AS count FROM vouchers WHERE voucher_id = $1';

// Merging needs the exact root right (the edit page offers it only to root):
// other users get the warning of the recovery page, and no row moves.
$unauthorizedMerge = http_request($mergeUrl, 'GET', array(), $unitOneCookies);
assert_status($unauthorizedMerge, 200, 'cross-organization merge');
assert_contains($unauthorizedMerge['body'], 'nur von Admins', 'cross-organization merge');
assert_database_count($database, $voucherRowsQuery, array($mergeTargetId), 1, 'merge target after cross-organization merge');
assert_database_count($database, $voucherRowsQuery, array($mergeSourceId), 1, 'merge source after cross-organization merge');
$bgfMerge = http_request($mergeUrl, 'GET', array(), $reviewerCookies);
assert_status($bgfMerge, 200, 'merge by a bgf user without root');
assert_contains($bgfMerge['body'], 'nur von Admins', 'merge by a bgf user without root');
assert_database_count($database, $voucherRowsQuery, array($mergeTargetId), 1, 'merge target after a merge by a bgf user');
assert_database_count($database, $voucherRowsQuery, array($mergeSourceId), 1, 'merge source after a merge by a bgf user');

$merge = http_request($mergeUrl, 'GET', array(), $adminCookies);
assert_redirect($merge, edit_location($mergeTargetId, 6), 'voucher merge');
assert_database_count($database, $voucherRowsQuery, array($mergeTargetId), 2, 'merge target');
assert_database_count($database, $voucherRowsQuery, array($mergeSourceId), 0, 'merge source');

$unauthorizedRecovery = http_request(
    '/index.php?action=recover&id=1004&bid=105&doit=1',
    'GET',
    array(),
    $unitOneCookies
);
assert_status($unauthorizedRecovery, 200, 'unauthorized recovery');
assert_contains($unauthorizedRecovery['body'], 'nur von Admins', 'unauthorized recovery');
$oldRevision = database_row(
    $database,
    'SELECT deleted FROM vouchers WHERE id = 105',
    array(),
    'revision after unauthorized recovery'
);
assert_database_field($oldRevision, 'deleted', 't', 'revision after unauthorized recovery');

$authorizedRecovery = http_request(
    '/index.php?action=recover&id=1004&bid=105&doit=1',
    'GET',
    array(),
    $adminCookies
);
assert_status($authorizedRecovery, 200, 'authorized recovery');
assert_contains($authorizedRecovery['body'], 'wiederhergestellt', 'authorized recovery');
$oldRevision = database_row(
    $database,
    'SELECT deleted FROM vouchers WHERE id = 105',
    array(),
    'revision after authorized recovery'
);
assert_database_field($oldRevision, 'deleted', 'f', 'revision after authorized recovery');

// The same write redirects against a server without output buffering.
$unbufferedUrl = start_unbuffered_server(8083);
$unbufferedVoucherId = create_voucher($database, 'Synthetic unbuffered expense', '-3.21', 9002, $adminCookies);
$unbufferedRevisionId = create_voucher($database, 'Synthetic unbuffered draft', '-4.00', 0, $adminCookies);
$unbufferedMergeTargetId = create_voucher($database, 'Synthetic unbuffered merge target', '-1.50', 0, $adminCookies);
$unbufferedMergeSourceId = create_voucher($database, 'Synthetic unbuffered merge source', '-2.50', 0, $adminCookies);
$unbufferedSteps = array(
    'first approval' => array(
        '/index.php?action=edit&id=' . rawurlencode($unbufferedVoucherId),
        array('id' => $unbufferedVoucherId, 'parts' => '1', 'ack' => 'Bestätigen'),
        $adminCookies,
        edit_location($unbufferedVoucherId, 4),
    ),
    'second approval' => array(
        '/index.php?action=edit&id=' . rawurlencode($unbufferedVoucherId),
        array('id' => $unbufferedVoucherId, 'parts' => '1', 'ack' => 'Bestätigen'),
        $reviewerCookies,
        edit_location($unbufferedVoucherId, 4),
    ),
    'revision' => array(
        '/index.php?action=edit&id=' . rawurlencode($unbufferedRevisionId),
        revision_form($unbufferedRevisionId, 'Synthetic unbuffered revision', '-4.50'),
        $adminCookies,
        edit_location($unbufferedRevisionId, 5),
    ),
    'merge' => array(
        '/index.php?action=merge&bid1=' . rawurlencode($unbufferedMergeTargetId)
            . '&bid2=' . rawurlencode($unbufferedMergeSourceId),
        null,
        $adminCookies,
        edit_location($unbufferedMergeTargetId, 6),
    ),
);
foreach ($unbufferedSteps as $label => $step) {
    $method = $step[1] === null ? 'GET' : 'POST';
    $form = $step[1] === null ? array() : $step[1];
    $response = http_request($step[0], $method, $form, $step[2], $unbufferedUrl);
    assert_redirect($response, $step[3], "$label with output_buffering=0");
}
$unbuffered = database_row(
    $database,
    'SELECT ack1, ack2, receipt_received FROM vouchers WHERE voucher_id = $1 AND NOT deleted',
    array($unbufferedVoucherId),
    'voucher after unbuffered approvals'
);
assert_database_field($unbuffered, 'ack1', 'synthetic-admin', 'voucher after unbuffered approvals');
assert_database_field($unbuffered, 'ack2', 'synthetic-reviewer', 'voucher after unbuffered approvals');
$unbufferedRevision = database_row(
    $database,
    'SELECT voucher_id, amount, deleted FROM vouchers WHERE comment = $1',
    array('Synthetic unbuffered revision'),
    'unbuffered replacement revision'
);
assert_database_field($unbufferedRevision, 'voucher_id', $unbufferedRevisionId, 'unbuffered replacement revision');
assert_database_field($unbufferedRevision, 'amount', '-450', 'unbuffered replacement revision');
assert_database_field($unbufferedRevision, 'deleted', 'f', 'unbuffered replacement revision');
assert_database_count(
    $database,
    'SELECT COUNT(*) AS count FROM vouchers WHERE comment = $1 AND deleted',
    array('Synthetic unbuffered draft'),
    1,
    'unbuffered original revision'
);
assert_database_count($database, $voucherRowsQuery, array($unbufferedMergeTargetId), 2, 'unbuffered merge target');
assert_database_count($database, $voucherRowsQuery, array($unbufferedMergeSourceId), 0, 'unbuffered merge source');

// Visitors who are not logged in get the login redirect for every write
// action, and nothing changes.
$visitorDraftId = create_voucher($database, 'Synthetic draft for visitors', '-6.00', 9003, $adminCookies);
$visitorEditUri = '/index.php?action=edit&id=' . rawurlencode($visitorDraftId);
$visitorMergeUri = '/index.php?action=merge&bid1=' . rawurlencode($visitorDraftId)
    . '&bid2=' . rawurlencode($mergeTargetId);
$visitorRequests = array(
    'voucher creation' => array('/index.php?action=new', voucher_form('Synthetic visitor voucher', '-7.00', 0)),
    'approval' => array($visitorEditUri, array('id' => $visitorDraftId, 'parts' => '1', 'ack' => 'Bestätigen')),
    'finalization' => array($visitorEditUri, array('id' => $visitorDraftId, 'parts' => '1', 'beleg' => 'Beleg erhalten')),
    'revision' => array($visitorEditUri, revision_form($visitorDraftId, 'Synthetic visitor revision', '-8.00')),
    'merge' => array($visitorMergeUri, null),
);
foreach ($visitorRequests as $label => $request) {
    $method = $request[1] === null ? 'GET' : 'POST';
    $form = $request[1] === null ? array() : $request[1];
    $response = http_request($request[0], $method, $form);
    assert_redirect($response, login_redirect_location($request[0]), "$label when not logged in");
}
$commentQuery = 'SELECT COUNT(*) AS count FROM vouchers WHERE comment = $1';
assert_database_count($database, $commentQuery, array('Synthetic visitor voucher'), 0, 'voucher created by a visitor');
assert_database_count($database, $commentQuery, array('Synthetic visitor revision'), 0, 'revision saved by a visitor');
assert_database_count(
    $database,
    'SELECT COUNT(*) AS count FROM vouchers WHERE voucher_id = $1 AND NOT deleted'
        . ' AND ack1 IS NULL AND NOT receipt_received AND comment = $2',
    array($visitorDraftId, 'Synthetic draft for visitors'),
    1,
    'draft after visitor writes'
);
assert_database_count($database, $voucherRowsQuery, array($visitorDraftId), 1, 'merge target after a visitor merge');
assert_database_count($database, $voucherRowsQuery, array($mergeTargetId), 2, 'merge source after a visitor merge');

// Organization scope of voucher writes. Rights: synthetic-unit-one '1',
// synthetic-unit-two '2', synthetic-reviewer 'bgf', synthetic-admin
// 'root,bgf', synthetic-no-rights ''. Organization 10 is the default of
// get_voucher() when the form posts no organization (lo).
$unitTwoCookies = login_user('synthetic-unit-two', 'unit-two-password');
$noRightsCookies = login_user('synthetic-no-rights', 'no-rights-password');

function voucher_form_without_organization($comment, $amount, $file)
{
    $form = voucher_form($comment, $amount, $file);
    unset($form['lo0']);
    return $form;
}

// A voucher with one row in organization 1 and one in organization 2.
function two_organization_form($comment, $file)
{
    $form = voucher_form($comment, '-1.00', $file);
    $form['parts'] = '2';
    $form['lo0'] = '1';
    foreach (voucher_form($comment, '-2.00', $file) as $name => $value) {
        if (substr($name, -1) === '0') {
            $form[substr($name, 0, -1) . '1'] = $value;
        }
    }
    $form['lo1'] = '2';
    return $form;
}

function voucher_rows($database, $voucherId, $label)
{
    $result = @pg_query_params(
        $database,
        'SELECT orga, ack1, ack2, receipt_received FROM vouchers
          WHERE voucher_id = $1 AND NOT deleted ORDER BY orga',
        array($voucherId)
    );
    if ($result === false) {
        fail_test("database query failed for $label: " . pg_last_error($database));
    }
    $rows = pg_fetch_all($result);
    pg_free_result($result);
    return $rows === false ? array() : $rows;
}

function assert_voucher_rows($database, $voucherId, $expected, $label)
{
    $actual = voucher_rows($database, $voucherId, $label);
    if ($actual !== $expected) {
        fail_test("$label rows were " . var_export($actual, true) . ', expected ' . var_export($expected, true));
    }
}

function voucher_row_state($orga, $ack1, $ack2, $receiptReceived)
{
    return array('orga' => $orga, 'ack1' => $ack1, 'ack2' => $ack2, 'receipt_received' => $receiptReceived);
}

function edit_request($voucherId, $form, $cookies)
{
    $form['id'] = $voucherId;
    $form['parts'] = '1';
    return http_request('/index.php?action=edit&id=' . rawurlencode($voucherId), 'POST', $form, $cookies);
}

function create_voucher_from_form($database, $form, $cookies)
{
    $comment = $form['comment0'];
    $response = http_request('/index.php?action=new', 'POST', $form, $cookies);
    assert_status($response, 200, "creation of $comment");
    assert_contains($response['body'], 'Buchung erfasst', "creation of $comment");
    $row = database_row(
        $database,
        'SELECT DISTINCT voucher_id FROM vouchers WHERE comment = $1 AND NOT deleted',
        array($comment),
        "created $comment"
    );
    return $row['voucher_id'];
}

// Creates a voucher from the form and returns its comment.
function create_voucher_comment($database, $form, $cookies)
{
    create_voucher_from_form($database, $form, $cookies);
    return $form['comment0'];
}

function create_test_user($database, $id, $name, $password, $rights)
{
    $result = @pg_query_params(
        $database,
        "INSERT INTO users (id, name, hash, rights, hide) VALUES ($1, $2, crypt($3, gen_salt('md5')), $4, '')",
        array($id, $name, $password, $rights)
    );
    if ($result === false) {
        fail_test("could not create user $name: " . pg_last_error($database));
    }
    return login_user($name, $password);
}

function voucher_by_comment($database, $comment)
{
    return database_row(
        $database,
        'SELECT voucher_id, orga FROM vouchers WHERE comment = $1 AND NOT deleted',
        array($comment),
        $comment
    );
}

// Creation without an organization: the voucher is saved in the user's
// organization (before the fix in organization 10, because page_new_save()
// passed an undefined $rights), and its creator can open it.
$scopedComment = 'Synthetic voucher of organization two';
$scopedCreation = http_request(
    '/index.php?action=new',
    'POST',
    voucher_form_without_organization($scopedComment, '-5.00', 0),
    $unitTwoCookies
);
assert_status($scopedCreation, 200, 'creation by an organization user');
assert_contains($scopedCreation['body'], 'Buchung erfasst', 'creation by an organization user');
$scopedVoucher = voucher_by_comment($database, $scopedComment);
assert_database_field($scopedVoucher, 'orga', '2', 'voucher created by an organization user');
$scopedVoucherPage = http_request(
    '/index.php?action=edit&id=' . rawurlencode($scopedVoucher['voucher_id']),
    'GET',
    array(),
    $unitTwoCookies
);
assert_status($scopedVoucherPage, 200, 'voucher page for its creator');
assert_contains($scopedVoucherPage['body'], 'value="' . $scopedComment . '"', 'voucher page for its creator');

$bgfComment = 'Synthetic voucher of the bgf reviewer';
$bgfCreation = http_request(
    '/index.php?action=new',
    'POST',
    voucher_form_without_organization($bgfComment, '-5.50', 0),
    $reviewerCookies
);
assert_contains($bgfCreation['body'], 'Buchung erfasst', 'creation by a bgf user');
assert_database_field(voucher_by_comment($database, $bgfComment), 'orga', '10', 'voucher created by a bgf user');

// A posted organization must be one of the user's (numeric) organizations,
// unless the user has the bgf right. Otherwise the whole voucher is refused
// before anything is saved.
$explicitComment = 'Synthetic voucher with a posted organization';
$explicitCreation = http_request(
    '/index.php?action=new',
    'POST',
    voucher_form($explicitComment, '-6.50', 0),
    $unitOneCookies
);
assert_status($explicitCreation, 200, 'creation with a foreign posted organization');
assert_contains($explicitCreation['body'], 'FEHLER: Keine Zugriffsrechte.', 'creation with a foreign posted organization');
assert_not_contains($explicitCreation['body'], 'Buchung erfasst', 'creation with a foreign posted organization');
assert_database_count($database, $commentQuery, array($explicitComment), 0, 'voucher created with a foreign posted organization');
$ownForm = voucher_form('Synthetic voucher with its own posted organization', '-6.60', 0);
$ownForm['lo0'] = '1';
assert_database_field(
    voucher_by_comment($database, create_voucher_comment($database, $ownForm, $unitOneCookies)),
    'orga',
    '1',
    'voucher created with its own posted organization'
);
// Writes stay within what the user can read (rights2orgasql()): root is no
// organization right, so a user with only root may neither write nor read
// organization 1, and 'root,1' writes like '1'.
$rootOnlyCookies = create_test_user($database, 52, 'synthetic-root-only', 'root-only-password', 'root');
$rootForm = voucher_form('Synthetic voucher of root in organization one', '-6.62', 0);
$rootForm['lo0'] = '1';
$rootCreation = http_request('/index.php?action=new', 'POST', $rootForm, $rootOnlyCookies);
assert_contains($rootCreation['body'], 'FEHLER: Keine Zugriffsrechte.', 'voucher in organization 1 by root alone');
assert_database_count($database, $commentQuery, array('Synthetic voucher of root in organization one'), 0, 'voucher in organization 1 by root alone');
$rootOneCookies = create_test_user($database, 55, 'synthetic-root-one', 'root-one-password', 'root,1');
$rootOneForm = voucher_form('Synthetic voucher of root,1 in organization two', '-6.61', 0);
$rootOneCreation = http_request('/index.php?action=new', 'POST', $rootOneForm, $rootOneCookies);
assert_contains($rootOneCreation['body'], 'FEHLER: Keine Zugriffsrechte.', 'voucher in organization 2 by root,1');
// Without a posted organization the default one (get_voucher(): the user's
// single organization, otherwise 10) must be allowed too: a user without
// rights or with several organizations who posts none is refused.
$twoUnitsCookies = create_test_user($database, 53, 'synthetic-two-units', 'two-units-password', '1,2');
foreach (array('without rights' => $noRightsCookies, 'of two organizations' => $twoUnitsCookies) as $userLabel => $cookies) {
    $comment = "Synthetic voucher without organization by a user $userLabel";
    $response = http_request('/index.php?action=new', 'POST', voucher_form_without_organization($comment, '-6.63', 0), $cookies);
    assert_contains($response['body'], 'FEHLER: Keine Zugriffsrechte.', "creation without organization by a user $userLabel");
    assert_database_count($database, $commentQuery, array($comment), 0, "voucher without organization by a user $userLabel");
}
assert_database_field(
    voucher_by_comment($database, create_voucher_comment($database, voucher_form('Synthetic voucher of two organizations', '-6.64', 0), $twoUnitsCookies)),
    'orga',
    '2',
    'voucher created with a posted organization by a user of two organizations'
);
$twoUnitsDraftId = create_voucher($database, 'Synthetic draft for two organizations', '-6.65', 0, $adminCookies);
$twoUnitsRevision = edit_request(
    $twoUnitsDraftId,
    array_diff_key(revision_form($twoUnitsDraftId, 'Synthetic revision without organization', '-6.66'), array('lo0' => true)),
    $twoUnitsCookies
);
assert_contains($twoUnitsRevision['body'], 'FEHLER: Keine Zugriffsrechte.', 'revision without organization by a user of two organizations');
assert_database_count($database, $commentQuery, array('Synthetic revision without organization'), 0, 'revision without organization by a user of two organizations');
// Organizations are compared as numbers, as the database compares them
// ('01' is organization 1), and spaces around rights do not count.
$paddedForm = voucher_form('Synthetic voucher with a padded organization', '-6.65', 0);
$paddedForm['lo0'] = '01';
assert_database_field(
    voucher_by_comment($database, create_voucher_comment($database, $paddedForm, $unitOneCookies)),
    'orga',
    '1',
    'voucher with a padded organization'
);
// The default organization uses the trimmed single organization ('2 ' is
// organization 2 on every PHP version).
$trailingCookies = create_test_user($database, 56, 'synthetic-trailing-space', 'trailing-password', '2 ');
assert_database_field(
    voucher_by_comment($database, create_voucher_comment($database, voucher_form_without_organization('Synthetic voucher of trailing-space rights', '-6.69', 0), $trailingCookies)),
    'orga',
    '2',
    'voucher without organization of trailing-space rights'
);
$spacedCookies = create_test_user($database, 54, 'synthetic-spaced-rights', 'spaced-password', '1, bgf');
// (The voucher form of the edit page; the new-voucher page raises the open
// $bgfandack finding.)
// The rights SQL trims the rights too: '1, bgf' reads every organization,
// as it may write to every one.
$optionsDraftId = create_voucher_from_form(
    $database,
    array_merge(voucher_form('Synthetic draft for the organization options', '-6.68', 0), array('lo0' => '2')),
    $adminCookies
);
$spacedForm = http_request('/index.php?action=edit&id=' . $optionsDraftId, 'GET', array(), $spacedCookies);
assert_contains($spacedForm['body'], '>Synthetic National Unit</option>', 'voucher form for spaced rights');
assert_contains($spacedForm['body'], '>Synthetic Regional Unit</option>', 'voucher form for spaced rights');
// The form's other rights checks trim too: '1, bgf' gets the save buttons
// and the row delete link of bgf.
assert_contains($spacedForm['body'], 'name="speichern"', 'voucher form for spaced rights');
assert_contains($spacedForm['body'], 'Diese Buchungszeile löschen', 'voucher form for spaced rights');
$spacedVoucher = voucher_form('Synthetic voucher of spaced rights', '-6.67', 0);
assert_database_field(
    voucher_by_comment($database, create_voucher_comment($database, $spacedVoucher, $spacedCookies)),
    'orga',
    '2',
    'voucher of spaced rights'
);
$adminNewForm = http_request('/index.php?action=edit&id=' . $optionsDraftId, 'GET', array(), $adminCookies);
$unitTwoOptions = http_request('/index.php?action=edit&id=' . $optionsDraftId, 'GET', array(), $twoUnitsCookies);
assert_contains($adminNewForm['body'], '>Synthetic National Unit</option>', 'voucher form for root,bgf');
assert_contains($adminNewForm['body'], '>Synthetic Regional Unit</option>', 'voucher form for root,bgf');
assert_contains($unitTwoOptions['body'], '>Synthetic Regional Unit</option>', 'voucher form for 1,2');
// The second part of a two-part voucher is in organization 2.
$foreignPartForm = two_organization_form('Synthetic voucher with a foreign second part', 0);
$foreignPart = http_request('/index.php?action=new', 'POST', $foreignPartForm, $unitOneCookies);
assert_contains($foreignPart['body'], 'FEHLER: Keine Zugriffsrechte.', 'creation with a foreign second part');
assert_database_count($database, $commentQuery, array('Synthetic voucher with a foreign second part'), 0, 'voucher with a foreign second part');
foreach (array('bgf' => $reviewerCookies, 'root,bgf' => $adminCookies) as $rightsLabel => $cookies) {
    $comment = "Synthetic voucher in organization 1 by $rightsLabel";
    $form = voucher_form($comment, '-6.70', 0);
    $form['lo0'] = '1';
    assert_database_field(
        voucher_by_comment($database, create_voucher_comment($database, $form, $cookies)),
        'orga',
        '1',
        "voucher created in organization 1 by $rightsLabel"
    );
}

// A revision without an organization keeps the user's organization.
$scopedDraftId = create_voucher($database, 'Synthetic draft of organization two', '-7.50', 0, $adminCookies);
// The voucher form (shared by the creation and edit pages) offers the
// organization only for rights with several entries (here 'root,bgf'); a
// user of one organization posts none.
$scopedDraftUri = '/index.php?action=edit&id=' . rawurlencode($scopedDraftId);
$unitTwoForm = http_request($scopedDraftUri, 'GET', array(), $unitTwoCookies);
assert_status($unitTwoForm, 200, 'voucher form of an organization user');
assert_contains($unitTwoForm['body'], 'name="date0"', 'voucher form of an organization user');
assert_not_contains($unitTwoForm['body'], 'name="lo0"', 'voucher form of an organization user');
$adminForm = http_request($scopedDraftUri, 'GET', array(), $adminCookies);
assert_contains($adminForm['body'], 'name="lo0"', 'voucher form of an admin');
$scopedRevisionForm = revision_form($scopedDraftId, 'Synthetic revision of organization two', '-8.50');
unset($scopedRevisionForm['lo0']);
$scopedRevision = edit_request($scopedDraftId, $scopedRevisionForm, $unitTwoCookies);
assert_redirect($scopedRevision, edit_location($scopedDraftId, 5), 'revision by an organization user');
assert_database_field(
    voucher_by_comment($database, 'Synthetic revision of organization two'),
    'orga',
    '2',
    'revision by an organization user'
);
// A revision that posts another organization is refused; the voucher keeps
// its rows.
$foreignRevisionForm = revision_form($scopedDraftId, 'Synthetic revision into organization one', '-8.60');
$foreignRevisionForm['lo0'] = '1';
$foreignRevision = edit_request($scopedDraftId, $foreignRevisionForm, $unitTwoCookies);
assert_status($foreignRevision, 200, 'revision into a foreign organization');
assert_contains($foreignRevision['body'], 'FEHLER: Keine Zugriffsrechte.', 'revision into a foreign organization');
assert_database_count($database, $commentQuery, array('Synthetic revision into organization one'), 0, 'revision into a foreign organization');
assert_database_field(
    voucher_by_comment($database, 'Synthetic revision of organization two'),
    'orga',
    '2',
    'voucher after a revision into a foreign organization'
);

// Approval of a finalized voucher is refused. A user of another
// organization gets the answer for any voucher without visible rows ("Hier
// fehlt noch der Beleg", as for the unfinalized voucher above), so it does
// not tell whether the voucher is finalized.
$approveForm = array('ack' => 'Bestätigen');
$finalizedId = create_voucher($database, 'Synthetic finalized draft', '-9.50', 9004, $adminCookies);
finalize_in_database($database, $finalizedId);
$finalizedApproval = edit_request($finalizedId, $approveForm, $adminCookies);
assert_status($finalizedApproval, 200, 'approval of a finalized voucher');
assert_contains($finalizedApproval['body'], 'bereits finalisiert', 'approval of a finalized voucher');
$crossFinalizedApproval = edit_request($finalizedId, $approveForm, $unitOneCookies);
assert_status($crossFinalizedApproval, 200, 'cross-organization approval of a finalized voucher');
assert_contains(
    $crossFinalizedApproval['body'],
    'FEHLER: Hier fehlt noch der Beleg!',
    'cross-organization approval of a finalized voucher'
);
assert_not_contains($crossFinalizedApproval['body'], 'bereits finalisiert', 'cross-organization approval of a finalized voucher');
assert_voucher_rows(
    $database,
    $finalizedId,
    array(voucher_row_state('2', null, null, 't')),
    'finalized voucher after approvals'
);

// Vouchers with rows in two organizations: approval and finalization change
// only the rows of the user's organization.
$mixedId = create_voucher_from_form($database, two_organization_form('Synthetic mixed voucher', 9005), $adminCookies);
$mixedApproval = edit_request($mixedId, $approveForm, $unitTwoCookies);
assert_redirect($mixedApproval, edit_location($mixedId, 4), 'approval of a mixed voucher');
assert_voucher_rows(
    $database,
    $mixedId,
    array(voucher_row_state('1', null, null, 'f'), voucher_row_state('2', 'synthetic-unit-two', null, 'f')),
    'mixed voucher after approval by organization two'
);
// Organization one's approval is the first one of its row; it does not count
// as the second approval of organization two's row.
$mixedApproval = edit_request($mixedId, $approveForm, $unitOneCookies);
assert_redirect($mixedApproval, edit_location($mixedId, 4), 'approval of a mixed voucher by organization one');
assert_voucher_rows(
    $database,
    $mixedId,
    array(
        voucher_row_state('1', 'synthetic-unit-one', null, 'f'),
        voucher_row_state('2', 'synthetic-unit-two', null, 'f'),
    ),
    'mixed voucher after approval by organization one'
);

$mixedFinalizedId = create_voucher_from_form(
    $database,
    two_organization_form('Synthetic partly finalized voucher', 9006),
    $adminCookies
);
// A partly finalized voucher (organization users could finalize their rows
// before the removal), set up in the database.
finalize_in_database($database, $mixedFinalizedId, 1);
// The finalized row of organization one blocks the approval by organization
// two.
$partlyFinalizedApproval = edit_request($mixedFinalizedId, $approveForm, $unitTwoCookies);
assert_status($partlyFinalizedApproval, 200, 'approval of a partly finalized voucher');
assert_contains($partlyFinalizedApproval['body'], 'bereits finalisiert', 'approval of a partly finalized voucher');
assert_voucher_rows(
    $database,
    $mixedFinalizedId,
    array(voucher_row_state('1', null, null, 't'), voucher_row_state('2', null, null, 'f')),
    'partly finalized voucher after approval by organization two'
);

// The receipt check counts only the user's rows: organization two's row is
// approved once and has no receipt, so its second approval is refused even
// though organization one's row is still open.
$receiptCheckId = create_voucher_from_form($database, two_organization_form('Synthetic voucher without a receipt', 9010), $adminCookies);
pg_query_params(
    $database,
    "UPDATE vouchers SET ack1 = 'synthetic-first-approver', file = 0 WHERE voucher_id = $1 AND orga = 2",
    array($receiptCheckId)
);
$receiptCheckState = array(voucher_row_state('1', null, null, 'f'), voucher_row_state('2', 'synthetic-first-approver', null, 'f'));
$receiptCheck = edit_request($receiptCheckId, $approveForm, $unitTwoCookies);
assert_status($receiptCheck, 200, 'approval of a row without a receipt');
assert_contains($receiptCheck['body'], 'FEHLER: Hier fehlt noch der Beleg!', 'approval of a row without a receipt');
assert_voucher_rows($database, $receiptCheckId, $receiptCheckState, 'voucher after the approval of a row without a receipt');

// A revision needs a current row of the voucher that the user sees; the
// finalized and approved checks then cover the whole voucher, also the rows
// of other organizations.
$missingRevision = edit_request('999999', revision_form('999999', 'Synthetic revision of a missing voucher', '-1.10'), $adminCookies);
assert_status($missingRevision, 200, 'revision of a missing voucher');
assert_contains($missingRevision['body'], 'FEHLER: Buchung nicht gefunden.', 'revision of a missing voucher');
assert_database_count($database, $commentQuery, array('Synthetic revision of a missing voucher'), 0, 'revision of a missing voucher');
$deletedRevisionId = create_voucher($database, 'Synthetic voucher with old revisions only', '-1.20', 0, $adminCookies);
pg_query_params($database, 'UPDATE vouchers SET deleted = true WHERE voucher_id = $1', array($deletedRevisionId));
$deletedRevision = edit_request($deletedRevisionId, revision_form($deletedRevisionId, 'Synthetic revision of old revisions', '-1.30'), $adminCookies);
assert_contains($deletedRevision['body'], 'FEHLER: Buchung nicht gefunden.', 'revision of a voucher with old revisions only');
assert_database_count($database, $commentQuery, array('Synthetic revision of old revisions'), 0, 'revision of a voucher with old revisions only');
$foreignRevisionId = create_voucher_from_form(
    $database,
    array_merge(voucher_form('Synthetic voucher of organization one only', '-1.40', 0), array('lo0' => '1')),
    $adminCookies
);
$foreignRevision = edit_request($foreignRevisionId, revision_form($foreignRevisionId, 'Synthetic rows added by organization two', '-1.50'), $unitTwoCookies);
assert_contains($foreignRevision['body'], 'FEHLER: Buchung nicht gefunden.', 'revision of a voucher of another organization');
assert_database_count($database, $commentQuery, array('Synthetic rows added by organization two'), 0, 'revision of a voucher of another organization');
assert_voucher_rows($database, $foreignRevisionId, array(voucher_row_state('1', null, null, 'f')), 'voucher of another organization after a revision');
// A finalized row of another organization refuses the revision.
$lockedId = create_voucher_from_form($database, two_organization_form('Synthetic mixed voucher finalized in one', 0), $adminCookies);
finalize_in_database($database, $lockedId, 1);
$lockedRevision = edit_request($lockedId, revision_form($lockedId, 'Synthetic revision of a voucher finalized in one', '-1.60'), $unitTwoCookies);
assert_contains($lockedRevision['body'], 'bereits finalisiert', 'revision of a voucher finalized in another organization');
assert_database_count($database, $commentQuery, array('Synthetic revision of a voucher finalized in one'), 0, 'revision of a voucher finalized in another organization');
assert_database_count($database, 'SELECT COUNT(*) AS count FROM vouchers WHERE voucher_id = $1 AND NOT deleted', array($lockedId), 2, 'rows of the voucher finalized in another organization');

// A user may correct their own rows although rows of another
// organization are approved; every change resets the approvals of the whole
// voucher: each current row's ack1/ack2 move to ack1_old/ack2_old.
function voucher_approvals($database, $voucherId, $label)
{
    $result = @pg_query_params(
        $database,
        'SELECT orga, ack1, ack2, ack1_old, ack2_old, comment FROM vouchers WHERE voucher_id = $1 AND NOT deleted ORDER BY orga, id',
        array($voucherId)
    );
    if ($result === false) {
        fail_test("database query failed for $label: " . pg_last_error($database));
    }
    $rows = pg_fetch_all($result);
    pg_free_result($result);
    return $rows === false ? array() : $rows;
}
function approval_row($orga, $ack1, $ack2, $ack1Old, $ack2Old, $comment)
{
    return array('orga' => $orga, 'ack1' => $ack1, 'ack2' => $ack2, 'ack1_old' => $ack1Old, 'ack2_old' => $ack2Old, 'comment' => $comment);
}
function assert_voucher_approvals($database, $voucherId, $expected, $label)
{
    $actual = voucher_approvals($database, $voucherId, $label);
    if ($actual !== $expected) {
        fail_test("$label approvals were " . var_export($actual, true) . ', expected ' . var_export($expected, true));
    }
}
$approvedOneId = create_voucher_from_form($database, two_organization_form('Synthetic mixed voucher approved in one', 0), $adminCookies);
pg_query_params($database, "UPDATE vouchers SET ack1 = 'synthetic-a', ack2 = 'synthetic-b' WHERE voucher_id = $1 AND orga = 1", array($approvedOneId));
$correctionForm = revision_form($approvedOneId, 'Synthetic correction by organization two', '-1.65');
$correctionForm['ack1_old'] = 'synthetic-forged-first';
$correctionForm['ack2_old'] = 'synthetic-forged-second';
$correction = edit_request($approvedOneId, $correctionForm, $unitTwoCookies);
assert_redirect($correction, edit_location($approvedOneId, 5), 'correction of own rows of a voucher approved in another organization');
assert_voucher_approvals($database, $approvedOneId, array(
    approval_row('1', null, null, 'synthetic-a', 'synthetic-b', 'Synthetic mixed voucher approved in one'),
    approval_row('2', null, null, null, null, 'Synthetic correction by organization two'),
), 'voucher approved in another organization after a correction');

// A member voucher takes the member's name from the membership list
// (getusers(), the stub's synthetic members); it is escaped like posted names.
$memberForm = voucher_form('Synthetic voucher of a member with a quote', '-1.70', 0);
$memberForm['member0'] = '1';
$memberForm['mitgliedsnummer0'] = '9033';
$memberVoucher = http_request('/index.php?action=new', 'POST', $memberForm, $adminCookies);
assert_contains($memberVoucher['body'], 'Buchung erfasst', 'member voucher');
assert_not_contains($memberVoucher['body'], 'fehlgeschlagen', 'member voucher');
$memberRow = database_row(
    $database,
    'SELECT name, member_id FROM vouchers WHERE comment = $1',
    array('Synthetic voucher of a member with a quote'),
    'member voucher'
);
assert_database_field($memberRow, 'name', "O'Brien Synthetisch", 'member voucher');
assert_database_field($memberRow, 'member_id', '9033', 'member voucher');

// The voucher page of a voucher without current rows answers "Buchung nicht
// gefunden" for every user, also bgf and Rbgf (before, they got an empty
// form that the save refuses); deleted rows are restored with the recovery
// page.
foreach (array('admin' => $adminCookies, 'bgf' => $reviewerCookies, 'read-only bgf' => login_user('synthetic-read-only', 'read-only-password')) as $userLabel => $cookies) {
    $missingPage = http_request('/index.php?action=edit&id=999999', 'GET', array(), $cookies);
    assert_status($missingPage, 200, "voucher page of a missing voucher for $userLabel");
    assert_contains($missingPage['body'], 'FEHLER: Buchung nicht gefunden.', "voucher page of a missing voucher for $userLabel");
    assert_not_contains($missingPage['body'], 'name="speichern"', "voucher page of a missing voucher for $userLabel");
}

// A voucher whose rows are all deleted (old revisions only) counts as not
// visible: approval is refused and the deleted rows keep their state.
$deletedOnlyId = create_voucher($database, 'Synthetic voucher with deleted rows only', '-10.25', 9013, $adminCookies);
pg_query_params($database, 'UPDATE vouchers SET deleted = true WHERE voucher_id = $1', array($deletedOnlyId));
$deletedOnlyApproval = edit_request($deletedOnlyId, $approveForm, $unitTwoCookies);
assert_status($deletedOnlyApproval, 200, 'approval of a voucher with deleted rows only');
assert_contains($deletedOnlyApproval['body'], 'FEHLER: Hier fehlt noch der Beleg!', 'approval of a voucher with deleted rows only');
assert_database_count(
    $database,
    'SELECT COUNT(*) AS count FROM vouchers WHERE voucher_id = $1 AND ack1 IS NULL AND deleted',
    array($deletedOnlyId),
    1,
    'deleted rows after an approval attempt'
);
foreach (array('bgf' => $reviewerCookies, 'root,bgf' => $adminCookies, 'Rbgf' => login_user('synthetic-read-only', 'read-only-password')) as $userLabel => $cookies) {
    $deletedOnlyPage = http_request('/index.php?action=edit&id=' . $deletedOnlyId, 'GET', array(), $cookies);
    assert_contains($deletedOnlyPage['body'], 'FEHLER: Buchung nicht gefunden.', "voucher page of a voucher with deleted rows only for $userLabel");
}

// Approval ignores old revisions (deleted rows): it does not stamp them or
// clear their old approvals, and they do not count as an open row of the
// receipt check.
$historyId = create_voucher($database, 'Synthetic voucher with history', '-10.75', 0, $adminCookies);
$historyRevision = revision_form($historyId, 'Synthetic revised voucher with history', '-10.80');
$historyRevision['file0'] = '9014';
assert_redirect(edit_request($historyId, $historyRevision, $adminCookies), edit_location($historyId, 5), 'revision before the history check');
pg_query_params($database, "UPDATE vouchers SET ack1_old = 'synthetic-old-first' WHERE voucher_id = $1 AND deleted", array($historyId));
assert_redirect(edit_request($historyId, $approveForm, $adminCookies), edit_location($historyId, 4), 'approval of a voucher with history');
assert_database_count(
    $database,
    "SELECT COUNT(*) AS count FROM vouchers WHERE voucher_id = $1 AND deleted AND ack1 IS NULL AND ack1_old = 'synthetic-old-first'",
    array($historyId),
    1,
    'old revision after the approval'
);
assert_database_count(
    $database,
    "SELECT COUNT(*) AS count FROM vouchers WHERE voucher_id = $1 AND NOT deleted AND ack1 = 'synthetic-admin'",
    array($historyId),
    1,
    'current row after the approval'
);
// The second approval leaves an approved-once old revision alone too.
pg_query_params($database, "UPDATE vouchers SET ack1 = 'synthetic-old-first' WHERE voucher_id = $1 AND deleted", array($historyId));
assert_redirect(edit_request($historyId, $approveForm, $reviewerCookies), edit_location($historyId, 4), 'second approval of a voucher with history');
assert_database_count(
    $database,
    'SELECT COUNT(*) AS count FROM vouchers WHERE voucher_id = $1 AND deleted AND ack2 IS NULL',
    array($historyId),
    1,
    'old revision after the second approval'
);
pg_query_params($database, 'UPDATE vouchers SET ack1 = NULL, ack2 = NULL WHERE voucher_id = $1', array($historyId));
pg_query_params($database, "UPDATE vouchers SET ack1 = 'synthetic-admin' WHERE voucher_id = $1 AND NOT deleted", array($historyId));
// The current row is approved once and has no receipt; the old revision
// (unapproved) must not make the receipt check pass.
pg_query_params($database, 'UPDATE vouchers SET file = 0 WHERE voucher_id = $1 AND NOT deleted', array($historyId));
$historyApproval = edit_request($historyId, $approveForm, $reviewerCookies);
assert_contains($historyApproval['body'], 'FEHLER: Hier fehlt noch der Beleg!', 'second approval of a current row without receipt');
assert_database_count(
    $database,
    'SELECT COUNT(*) AS count FROM vouchers WHERE voucher_id = $1 AND ack2 IS NOT NULL',
    array($historyId),
    0,
    'voucher with history after the refused second approval'
);

// Resetting the approvals ("Bestätigungen zurücksetzen!", offered only to bgf
// users) needs the exact bgf right; other users are refused and nothing
// changes (before, empty rights reset any voucher). For bgf users the reset
// works (before, the rights string itself was appended to the SQL and the
// query failed, the former known failure KF-DROP-ACKS-BGF).
$resetId = create_voucher($database, 'Synthetic approved voucher', '-10.50', 9007, $adminCookies);
assert_redirect(edit_request($resetId, $approveForm, $adminCookies), edit_location($resetId, 4), 'first approval before the reset');
assert_redirect(edit_request($resetId, $approveForm, $reviewerCookies), edit_location($resetId, 4), 'second approval before the reset');
$approvedState = array(voucher_row_state('2', 'synthetic-admin', 'synthetic-reviewer', 'f'));
$resetForm = array('belegfehler' => 'Bestätigungen zurücksetzen!');
foreach (array('without rights' => $noRightsCookies, 'of organization two' => $unitTwoCookies) as $userLabel => $cookies) {
    $reset = edit_request($resetId, $resetForm, $cookies);
    assert_status($reset, 200, "approval reset by a user $userLabel");
    assert_contains($reset['body'], 'FEHLER: Keine Zugriffsrechte.', "approval reset by a user $userLabel");
    assert_voucher_rows($database, $resetId, $approvedState, "voucher after approval reset by a user $userLabel");
}
$bgfReset = edit_request($resetId, $resetForm, $reviewerCookies);
assert_redirect($bgfReset, edit_location($resetId, 3), 'approval reset by a bgf user');
assert_voucher_approvals($database, $resetId, array(
    approval_row('2', null, null, 'synthetic-admin', 'synthetic-reviewer', 'Synthetic approved voucher'),
), 'voucher after approval reset by a bgf user');
// A finalized voucher refuses the reset too, as every other change.
$finalizedResetId = create_voucher($database, 'Synthetic finalized approved voucher', '-10.60', 9015, $adminCookies);
pg_query_params($database, "UPDATE vouchers SET ack1 = 'synthetic-a', ack2 = 'synthetic-b' WHERE voucher_id = $1", array($finalizedResetId));
finalize_in_database($database, $finalizedResetId);
$finalizedReset = edit_request($finalizedResetId, $resetForm, $reviewerCookies);
assert_status($finalizedReset, 200, 'approval reset of a finalized voucher');
assert_contains($finalizedReset['body'], 'bereits finalisiert', 'approval reset of a finalized voucher');
assert_voucher_approvals($database, $finalizedResetId, array(
    approval_row('2', 'synthetic-a', 'synthetic-b', null, null, 'Synthetic finalized approved voucher'),
), 'finalized voucher after an approval reset');

// Voucher IDs of the edit actions (approval, approval reset,
// revision, receipt upload) are used in SQL. Only digits are accepted; any
// other value gets "Buchung nicht gefunden" and changes nothing.
$injectionTargetId = create_voucher($database, 'Synthetic injection target', '-11.00', 9008, $adminCookies);
$injectionOtherId = create_voucher($database, 'Synthetic injection bystander', '-12.00', 9009, $adminCookies);
assert_redirect(edit_request($injectionTargetId, $approveForm, $adminCookies), edit_location($injectionTargetId, 4), 'approval of the injection target');
assert_redirect(edit_request($injectionOtherId, $approveForm, $adminCookies), edit_location($injectionOtherId, 4), 'approval of the injection bystander');
$injectionState = array(voucher_row_state('2', 'synthetic-admin', null, 'f'));
$injectedIds = array(
    'condition' => $injectionTargetId . ' OR voucher_id = ' . $injectionOtherId,
    'quote' => $injectionTargetId . "'",
    'trailing newline' => $injectionTargetId . "\n",
    'sign' => '+' . $injectionTargetId,
);
$injectionActions = array(
    'approval' => array($approveForm, $reviewerCookies),
    'approval reset' => array(array('belegfehler' => 'Bestätigungen zurücksetzen!'), $noRightsCookies),
    'revision' => array(revision_form('', 'Synthetic injected revision', '-13.00'), $adminCookies),
);
foreach ($injectionActions as $action => $request) {
    foreach ($injectedIds as $kind => $injectedId) {
        $label = "$action with an injected voucher ID ($kind)";
        $response = edit_request($injectedId, $request[0], $request[1]);
        assert_status($response, 200, $label);
        assert_contains($response['body'], 'FEHLER: Buchung nicht gefunden.', $label);
        assert_not_contains($response['body'], 'Abfrage fehlgeschlagen', $label);
        assert_voucher_rows($database, $injectionTargetId, $injectionState, "target after $label");
        assert_voucher_rows($database, $injectionOtherId, $injectionState, "bystander after $label");
    }
    $response = http_request('/index.php?action=edit', 'POST', array_merge($request[0], array('id' => array($injectionTargetId))), $request[1]);
    assert_contains($response['body'], 'FEHLER: Buchung nicht gefunden.', "$action with an array as voucher ID");
    $response = http_request('/index.php?action=edit', 'POST', $request[0], $request[1]);
    assert_contains($response['body'], 'FEHLER: Buchung nicht gefunden.', "$action without a voucher ID");
}
assert_database_count($database, $commentQuery, array('Synthetic injected revision'), 0, 'revisions saved with injected voucher IDs');

// The receipt upload checks the voucher ID before it stores the file.
$syntheticReceipt = array('file0' => array('name' => 'synthetic-receipt.pdf', 'type' => 'application/pdf', 'content' => '%PDF-1.4 synthetic'));
foreach ($injectedIds as $kind => $injectedId) {
    $label = "receipt upload with an injected voucher ID ($kind)";
    $response = http_request(
        '/index.php?action=edit',
        'POST',
        array('fileupload' => 'PDF Hochladen (OHNE SPEICHERN)', 'parts' => '1', 'bid0' => '1', 'id' => $injectedId),
        $adminCookies,
        null,
        $syntheticReceipt
    );
    assert_status($response, 200, $label);
    assert_contains($response['body'], 'FEHLER: Buchung nicht gefunden.', $label);
    assert_not_contains($response['body'], 'Abfrage fehlgeschlagen', $label);
}

// The voucher page shows the voucher and row IDs as numbers, as it uses them
// (intval()), and does not echo other request text.
$markup = '<b id="synthetic-markup">x</b>';
foreach (array(1, 2, 3, 4, 5, 6) as $hint) {
    $markupPage = http_request(
        '/index.php?action=edit&id=' . rawurlencode($injectionTargetId . $markup) . '&bid=' . rawurlencode('7' . $markup) . '&hint=' . $hint,
        'GET',
        array(),
        $adminCookies
    );
    assert_status($markupPage, 200, "voucher page with markup in the IDs (hint $hint)");
    assert_not_contains($markupPage['body'], 'synthetic-markup', "voucher page with markup in the IDs (hint $hint)");
    assert_contains($markupPage['body'], 'Buchung Nr. ' . $injectionTargetId, "voucher page with markup in the IDs (hint $hint)");
    assert_contains($markupPage['body'], '<h1>Buchung ' . $injectionTargetId . ' bearbeiten</h1>', "voucher page with markup in the IDs (hint $hint)");
}
assert_contains($markupPage['body'], 'value="Synthetic injection target"', 'voucher page with markup in the IDs');
$markupPost = http_request('/index.php?action=edit&hint=1', 'POST', array('id' => $injectionTargetId . $markup, 'bid' => '7' . $markup), $adminCookies);
assert_not_contains($markupPost['body'], 'synthetic-markup', 'voucher page with markup in the posted IDs');
assert_contains($markupPost['body'], 'Buchung Nr. ' . $injectionTargetId . ' - Buchungszeile Nr. 7<', 'voucher page with markup in the posted IDs');

// A receipt upload to a voucher with a finalized row, also of another
// organization, is refused and changes nothing.
foreach (array('admin' => array(null, $adminCookies, 2), 'organization two' => array(1, $unitTwoCookies, 2)) as $uploadLabel => $case) {
    $uploadId = create_voucher_from_form($database, two_organization_form("Synthetic finalized upload target ($uploadLabel)", 0), $adminCookies);
    finalize_in_database($database, $uploadId, $case[0]);
    $uploadRow = database_row(
        $database,
        'SELECT id FROM vouchers WHERE voucher_id = $1 AND orga = $2 AND NOT deleted',
        array($uploadId, $case[2]),
        "upload row ($uploadLabel)"
    );
    $upload = http_request(
        '/index.php?action=edit',
        'POST',
        array('fileupload' => 'PDF Hochladen (OHNE SPEICHERN)', 'parts' => '1', 'bid0' => $uploadRow['id'], 'id' => $uploadId),
        $case[1],
        null,
        $syntheticReceipt
    );
    assert_status($upload, 200, "receipt upload to a finalized voucher ($uploadLabel)");
    assert_contains($upload['body'], 'bereits finalisiert', "receipt upload to a finalized voucher ($uploadLabel)");
    assert_database_count(
        $database,
        'SELECT COUNT(*) AS count FROM vouchers WHERE voucher_id = $1 AND file != 0',
        array($uploadId),
        0,
        "finalized voucher after a receipt upload ($uploadLabel)"
    );
}

// A user of another organization does not learn that a voucher is
// finalized: the row check comes first ("Buchungszeile nicht gefunden").
$foreignFinalizedId = create_voucher($database, 'Synthetic finalized voucher of organization two', '-20.50', 0, $adminCookies);
finalize_in_database($database, $foreignFinalizedId);
$foreignFinalizedRow = database_row($database, 'SELECT id FROM vouchers WHERE voucher_id = $1 AND NOT deleted', array($foreignFinalizedId), 'foreign finalized row');
$foreignUpload = http_request(
    '/index.php?action=edit',
    'POST',
    array('fileupload' => 'PDF Hochladen (OHNE SPEICHERN)', 'parts' => '1', 'bid0' => $foreignFinalizedRow['id'], 'id' => $foreignFinalizedId),
    $unitOneCookies,
    null,
    $syntheticReceipt
);
assert_contains($foreignUpload['body'], 'FEHLER: Buchungszeile nicht gefunden.', 'receipt upload to a finalized voucher of another organization');
assert_not_contains($foreignUpload['body'], 'finalisiert', 'receipt upload to a finalized voucher of another organization');

// A receipt upload resets the approvals of the whole
// voucher too, also of other organizations' rows, and is allowed for an
// approved voucher.
$uploadResetId = create_voucher_from_form($database, two_organization_form('Synthetic upload voucher approved in one', 0), $adminCookies);
pg_query_params($database, "UPDATE vouchers SET ack1 = 'synthetic-a', ack2 = 'synthetic-b' WHERE voucher_id = $1", array($uploadResetId));
$uploadResetRow = database_row(
    $database,
    'SELECT id FROM vouchers WHERE voucher_id = $1 AND orga = 2 AND NOT deleted',
    array($uploadResetId),
    'upload row of the approved voucher'
);
$uploadReset = http_request(
    '/index.php?action=edit',
    'POST',
    array('fileupload' => 'PDF Hochladen (OHNE SPEICHERN)', 'parts' => '1', 'bid0' => $uploadResetRow['id'], 'id' => $uploadResetId),
    $unitTwoCookies,
    null,
    $syntheticReceipt
);
assert_redirect($uploadReset, 'index.php?action=edit&id=' . $uploadResetId . '&bid=' . $uploadResetRow['id'] . '&hint=1', 'receipt upload to an approved voucher');
assert_voucher_approvals($database, $uploadResetId, array(
    approval_row('1', null, null, 'synthetic-a', 'synthetic-b', 'Synthetic upload voucher approved in one'),
    approval_row('2', null, null, 'synthetic-a', 'synthetic-b', 'Synthetic upload voucher approved in one'),
), 'approved voucher after a receipt upload by organization two');

// A revision keeps the approvals of the replaced rows as old approvals,
// taken from the database (posted old approval names are ignored) and
// escaped (a quote failed the query before).
$quotedRevisionId = create_voucher($database, 'Synthetic draft with a quoted approval', '-14.00', 0, $adminCookies);
pg_query_params($database, 'UPDATE vouchers SET ack1 = $1, ack2 = $2 WHERE voucher_id = $3', array("synthetic-o'reviewer", "synthetic-reviewer'2", $quotedRevisionId));
$quotedRevisionForm = revision_form($quotedRevisionId, 'Synthetic revision with a quoted approval', '-14.50');
$quotedRevisionForm['ack1_old'] = 'synthetic-forged';
$quotedRevisionForm['ack2_old'] = 'synthetic-forged';
$quotedRevisionForm['ack1'] = 'synthetic-forged';
$quotedRevisionForm['ack2'] = 'synthetic-forged';
assert_redirect(edit_request($quotedRevisionId, $quotedRevisionForm, $adminCookies), edit_location($quotedRevisionId, 5), 'revision with quoted old approvals');
$quotedRevision = database_row(
    $database,
    'SELECT ack1_old, ack2_old FROM vouchers WHERE comment = $1 AND NOT deleted',
    array('Synthetic revision with a quoted approval'),
    'revision with quoted old approvals'
);
assert_database_field($quotedRevision, 'ack1_old', "synthetic-o'reviewer", 'revision with quoted old approvals');
assert_database_field($quotedRevision, 'ack2_old', "synthetic-reviewer'2", 'revision with quoted old approvals');

// Dates are accepted only as a whole: D.M.20YY or 20YY-M-D. Other text is
// treated as a missing date ("Kein Datum angegeben", saved as 1800-01-01).
$dateForms = array(
    '15.09.2024' => '2024-09-15 00:00:00',
    '2024-9-5' => '2024-09-05 00:00:00',
    "15.09.2024' x" => '1800-01-01 00:00:00',
    "x' 2024-09-15" => '1800-01-01 00:00:00',
);
$dateIndex = 0;
foreach ($dateForms as $date => $stored) {
    $dateIndex++;
    $dateComment = "Synthetic dated voucher $dateIndex";
    $dateForm = voucher_form($dateComment, '-1.00', 0);
    $dateForm['date0'] = $date;
    $dateCreation = http_request('/index.php?action=new', 'POST', $dateForm, $adminCookies);
    assert_contains($dateCreation['body'], 'Buchung erfasst', "creation with the date " . var_export($date, true));
    assert_not_contains($dateCreation['body'], 'Abfrage fehlgeschlagen', "creation with the date " . var_export($date, true));
    if ($stored === '1800-01-01 00:00:00') {
        assert_contains($dateCreation['body'], 'Kein Datum angegeben', "creation with the date " . var_export($date, true));
    }
    $dated = database_row($database, 'SELECT date FROM vouchers WHERE comment = $1', array($dateComment), $dateComment);
    assert_database_field($dated, 'date', $stored, $dateComment);
}

// The old-approval filter of the listings (filter_ack_old) filters by its own
// value (before the fix it used filter_ack's); values outside its pattern
// are ignored like those of the other filters.
pg_query_params($database, 'UPDATE vouchers SET ack1_old = $1 WHERE comment = $2', array('synthetic-old-approver', 'Synthetic injection target'));
$filterCases = array(
    'filter_ack_old=synthetic-old' => true,
    'filter_ack_old=any' => false,
    'filter_ack=synthetic-old&filter_ack_old=any' => false,
    'filter_ack_old=' . rawurlencode("synthetic-old'") => true,
);
foreach ($filterCases as $query => $listed) {
    $listing = http_request('/index.php?action=all&' . $query, 'GET', array(), $adminCookies);
    assert_status($listing, 200, "listing with $query");
    assert_not_contains($listing['body'], 'Abfrage fehlgeschlagen', "listing with $query");
    if ($listed) {
        assert_contains($listing['body'], 'Synthetic injection target', "listing with $query");
    } else {
        assert_not_contains($listing['body'], 'Synthetic injection target', "listing with $query");
        assert_contains($listing['body'], '</table>', "listing with $query");
    }
}

// A user created by root gets the entered rights (before, the creator's own
// rights were stored); quotes in them are escaped.
pg_query($database, "SELECT setval('users_id_seq', 100)");
$accountForms = array(
    'synthetic-created-unit' => '2',
    'synthetic-created-quoted' => "2,o'clock",
);
foreach ($accountForms as $accountName => $accountRights) {
    $accountCreation = http_request(
        '/index.php?action=accounts',
        'POST',
        array(
            'submit' => 'Benutzer -1 erstellen',
            'name-1' => $accountName,
            'password-1' => 'created-password',
            'rights-1' => $accountRights,
        ),
        $adminCookies
    );
    assert_status($accountCreation, 200, "creation of $accountName");
    assert_contains($accountCreation['body'], '<tr><td>' . $accountName . '</td>', "creation of $accountName");
    $createdAccount = database_row($database, 'SELECT rights FROM users WHERE name = $1', array($accountName), $accountName);
    assert_database_field($createdAccount, 'rights', $accountRights, $accountName);
}
login_user('synthetic-created-unit', 'created-password');

// Read-only bgf ("Rbgf", accounts.php: "Ein vorgestelltes R erzeugt einen
// read only Zugang"): reads like bgf, every write is refused with "Keine
// Zugriffsrechte" (merge and recovery with the root warning) and changes
// nothing. Combined with an organization
// ('Rbgf,2') the user writes like a user of that organization.
$readOnlyCookies = login_user('synthetic-read-only', 'read-only-password');
$readOnlyTwoCookies = login_user('synthetic-read-only-two', 'read-only-two-password');
$readOnlyOneId = create_voucher_from_form(
    $database,
    array_merge(voucher_form('Synthetic read-only check one', '-15.00', 9011), array('lo0' => '1')),
    $adminCookies
);
$readOnlyTwoId = create_voucher($database, 'Synthetic read-only check two', '-16.00', 9012, $adminCookies);
$readOnlyDeletedId = create_voucher($database, 'Synthetic read-only deleted', '-17.00', 0, $adminCookies);
pg_query_params($database, 'UPDATE vouchers SET deleted = true WHERE voucher_id = $1', array($readOnlyDeletedId));

// (The closed listing is left out: it reads columns it does not select, an
// open finding in tests/COMPATIBILITY_PLAN.md.)
$readPages = array(
    'ledger' => array('/index.php?action=all&filter_date=2024', array('Synthetic read-only check one', 'Synthetic read-only check two')),
    'ledger CSV' => array('/index.php?action=all&filter_date=2024&format=csv', array('Synthetic read-only check one', 'Synthetic read-only check two')),
    'open vouchers' => array('/index.php?action=open&filter_date=2024', array('Synthetic read-only check one', 'Synthetic read-only check two')),
    'old revisions' => array('/index.php?action=deleted&filter_date=2024', array('Synthetic read-only deleted')),
    'voucher of organization one' => array('/index.php?action=edit&id=' . $readOnlyOneId, array('value="Synthetic read-only check one"')),
    'voucher of organization two' => array('/index.php?action=edit&id=' . $readOnlyTwoId, array('value="Synthetic read-only check two"')),
);
foreach ($readPages as $pageLabel => $page) {
    $bgfPage = http_request($page[0], 'GET', array(), $reviewerCookies);
    $readOnlyPage = http_request($page[0], 'GET', array(), $readOnlyCookies);
    assert_status($readOnlyPage, 200, "$pageLabel for read-only bgf");
    foreach ($page[1] as $expected) {
        assert_contains($bgfPage['body'], $expected, "$pageLabel for bgf");
        assert_contains($readOnlyPage['body'], $expected, "$pageLabel for read-only bgf");
    }
}
// The voucher form and the menu offer no writes to read-only bgf.
$bgfForm = http_request('/index.php?action=edit&id=' . $readOnlyTwoId, 'GET', array(), $reviewerCookies);
$readOnlyForm = http_request('/index.php?action=edit&id=' . $readOnlyTwoId, 'GET', array(), $readOnlyCookies);
foreach (array('name="speichern"', 'name="ack"', 'name="addB"', 'href="index.php?action=new"', 'href="index.php?action=import"', 'href="index.php?action=import2"') as $control) {
    assert_contains($bgfForm['body'], $control, "voucher page for bgf ($control)");
    assert_not_contains($readOnlyForm['body'], $control, "voucher page for read-only bgf ($control)");
}
foreach (array('href="index.php?action=all', 'href="index.php?action=deleted', 'href="index.php?action=accounts"') as $link) {
    assert_contains($readOnlyForm['body'], $link, "menu for read-only bgf ($link)");
}

$readOnlyStates = array(
    $readOnlyOneId => array(voucher_row_state('1', null, null, 'f')),
    $readOnlyTwoId => array(voucher_row_state('2', null, null, 'f')),
);
function assert_read_only_unchanged($database, $states, $label)
{
    foreach ($states as $voucherId => $state) {
        assert_voucher_rows($database, $voucherId, $state, "voucher $voucherId after $label");
    }
}
$readOnlyUpload = array(
    array('fileupload' => 'PDF Hochladen (OHNE SPEICHERN)', 'parts' => '1', 'bid0' => '1', 'id' => $readOnlyTwoId),
    $syntheticReceipt
);
$readOnlyPassword = database_row($database, 'SELECT hash FROM users WHERE id = 8', array(), 'read-only password');
$writeRequests = array(
    'voucher creation' => array('/index.php?action=new', voucher_form('Synthetic voucher by read-only bgf', '-18.00', 0)),
    'voucher creation without an organization' => array('/index.php?action=new', voucher_form_without_organization('Synthetic voucher by read-only bgf', '-18.00', 0)),
    'revision' => array('/index.php?action=edit&id=' . $readOnlyTwoId, revision_form($readOnlyTwoId, 'Synthetic revision by read-only bgf', '-18.50')),
    'revision without an organization' => array('/index.php?action=edit&id=' . $readOnlyTwoId, array_diff_key(revision_form($readOnlyTwoId, 'Synthetic revision by read-only bgf', '-18.50'), array('lo0' => true))),
    'approval' => array('/index.php?action=edit&id=' . $readOnlyTwoId, array('id' => $readOnlyTwoId, 'parts' => '1', 'ack' => 'Bestätigen')),
    'approval reset' => array('/index.php?action=edit&id=' . $readOnlyTwoId, array('id' => $readOnlyTwoId, 'parts' => '1', 'belegfehler' => 'Bestätigungen zurücksetzen!')),
    'merge' => array('/index.php?action=merge&bid1=' . $readOnlyOneId . '&bid2=' . $readOnlyTwoId, null),
    'receipt upload' => array('/index.php?action=edit', $readOnlyUpload),
    'password change' => array('/index.php?action=accounts', array('submit' => 'Benutzer 8 aktualisieren', 'password8' => 'changed-password')),
    'receipt import' => array('/index.php?action=import2', null),
);
foreach ($writeRequests as $writeLabel => $request) {
    $label = "$writeLabel by read-only bgf";
    if ($request[1] === null) {
        $response = http_request($request[0], 'GET', array(), $readOnlyCookies);
    } elseif ($writeLabel === 'receipt upload') {
        $response = http_request($request[0], 'POST', $request[1][0], $readOnlyCookies, null, $request[1][1]);
    } else {
        $response = http_request($request[0], 'POST', $request[1], $readOnlyCookies);
    }
    assert_status($response, 200, $label);
    assert_contains($response['body'], $writeLabel === 'merge' ? 'nur von Admins' : 'FEHLER: Keine Zugriffsrechte.', $label);
    assert_read_only_unchanged($database, $readOnlyStates, $label);
}
assert_database_count($database, $commentQuery, array('Synthetic voucher by read-only bgf'), 0, 'voucher created by read-only bgf');
assert_database_count($database, $commentQuery, array('Synthetic revision by read-only bgf'), 0, 'revision saved by read-only bgf');
assert_database_field(
    database_row($database, 'SELECT hash FROM users WHERE id = 8', array(), 'read-only password after the change'),
    'hash',
    $readOnlyPassword['hash'],
    'read-only password after the change'
);
// Recovery stays root-only; the user's own column preferences may change.
$readOnlyRecovery = http_request('/index.php?action=recover&id=1004&bid=105&doit=1', 'GET', array(), $readOnlyCookies);
assert_contains($readOnlyRecovery['body'], 'nur von Admins', 'recovery by read-only bgf');
http_request('/index.php?action=all&filter_date=2024&hide=3', 'GET', array(), $readOnlyCookies);
assert_database_field(
    database_row($database, 'SELECT hide FROM users WHERE id = 8', array(), 'read-only column preferences'),
    'hide',
    '3',
    'read-only column preferences'
);

// 'Rbgf,2': reads everything, writes only in organization 2.
$readOnlyTwoApproval = edit_request($readOnlyOneId, $approveForm, $readOnlyTwoCookies);
assert_contains($readOnlyTwoApproval['body'], 'FEHLER: Hier fehlt noch der Beleg!', 'approval of organization one by Rbgf,2');
assert_read_only_unchanged($database, $readOnlyStates, 'approval of organization one by Rbgf,2');
assert_redirect(edit_request($readOnlyTwoId, $approveForm, $readOnlyTwoCookies), edit_location($readOnlyTwoId, 4), 'approval of organization two by Rbgf,2');
assert_voucher_rows($database, $readOnlyTwoId, array(voucher_row_state('2', 'synthetic-read-only-two', null, 'f')), 'voucher approved by Rbgf,2');
$readOnlyTwoMerge = http_request('/index.php?action=merge&bid1=' . $readOnlyTwoId . '&bid2=' . $readOnlyOneId, 'GET', array(), $readOnlyTwoCookies);
assert_contains($readOnlyTwoMerge['body'], 'nur von Admins', 'merge by Rbgf,2');
$foreignByReadOnlyTwo = http_request('/index.php?action=new', 'POST', array_merge(voucher_form('Synthetic voucher of Rbgf,2 in one', '-19.00', 0), array('lo0' => '1')), $readOnlyTwoCookies);
assert_contains($foreignByReadOnlyTwo['body'], 'FEHLER: Keine Zugriffsrechte.', 'creation in organization one by Rbgf,2');
assert_database_field(
    voucher_by_comment($database, create_voucher_comment($database, voucher_form_without_organization('Synthetic voucher of Rbgf,2', '-19.50', 0), $readOnlyTwoCookies)),
    'orga',
    '2',
    'voucher created without an organization by Rbgf,2'
);
// The bank import calls the external membership system (getusers()), which
// tests must never contact: requested last, once everything else passed.
$readOnlyImport = http_request('/index.php?action=import', 'GET', array(), $readOnlyCookies);
assert_status($readOnlyImport, 200, 'bank import by read-only bgf');
assert_contains($readOnlyImport['body'], 'FEHLER: Keine Zugriffsrechte.', 'bank import by read-only bgf');

// Role checks match exact rights: "xroot" and "xbgf" are neither root nor
// bgf (before, strpos() matched any right containing the word).
$substringCookies = create_test_user($database, 51, 'synthetic-role-substrings', 'substring-password', '2,xroot,xbgf');
$adminRights = database_row($database, 'SELECT rights FROM users WHERE id = 1', array(), 'admin rights');
$substringAccounts = http_request(
    '/index.php?action=accounts',
    'POST',
    array('submit' => 'Benutzer 1 aktualisieren', 'password1' => '******', 'rights1' => '2'),
    $substringCookies
);
assert_contains($substringAccounts['body'], 'FEHLER: Keine Zugriffsrechte.', 'rights change of another user by xroot');
assert_database_field(
    database_row($database, 'SELECT rights FROM users WHERE id = 1', array(), 'admin rights after xroot'),
    'rights',
    $adminRights['rights'],
    'admin rights after a change by xroot'
);
$adminHash = database_row($database, 'SELECT hash FROM users WHERE id = 1', array(), 'admin password');
$substringPassword = http_request(
    '/index.php?action=accounts',
    'POST',
    array('submit' => 'Benutzer 1 aktualisieren', 'password1' => 'hijacked-password'),
    $substringCookies
);
assert_contains($substringPassword['body'], 'FEHLER: Keine Zugriffsrechte.', 'password change of another user by xroot');
assert_database_field(
    database_row($database, 'SELECT hash FROM users WHERE id = 1', array(), 'admin password after xroot'),
    'hash',
    $adminHash['hash'],
    'admin password after a change by xroot'
);
$substringOwnRights = http_request(
    '/index.php?action=accounts',
    'POST',
    array('submit' => 'Benutzer 51 aktualisieren', 'password51' => '******', 'rights51' => 'bgf'),
    $substringCookies
);
assert_contains($substringOwnRights['body'], 'FEHLER: Keine Zugriffsrechte.', 'own rights change by xroot');
// Their own account: the password changes, the rights stay.
$substringOwn = http_request(
    '/index.php?action=accounts',
    'POST',
    array('submit' => 'Benutzer 51 aktualisieren', 'password51' => 'changed-substring-password', 'rights51' => 'bgf'),
    $substringCookies
);
assert_status($substringOwn, 200, 'own account change by xroot');
assert_database_field(
    database_row($database, 'SELECT rights FROM users WHERE id = 51', array(), 'xroot rights'),
    'rights',
    '2,xroot,xbgf',
    'rights after an own account change by xroot'
);
$substringCookies = login_user('synthetic-role-substrings', 'changed-substring-password');
$substringUserList = http_request('/index.php?action=accounts', 'GET', array(), $substringCookies);
assert_not_contains($substringUserList['body'], 'synthetic-admin', 'user list for xroot');
assert_not_contains($substringUserList['body'], 'name="rights51"', 'user list for xroot');
assert_not_contains($substringUserList['body'], 'Benutzer -1 erstellen', 'user list for xroot');
assert_contains($substringUserList['body'], '<tr><td>synthetic-role-substrings</td>', 'user list for xroot');
$adminUserList = http_request('/index.php?action=accounts', 'GET', array(), $adminCookies);
foreach (array('synthetic-admin', 'name="rights51"', 'Benutzer -1 erstellen', '<td><b>Rechte</b></td>', 'Ein vorgestelltes R') as $expected) {
    assert_contains($adminUserList['body'], $expected, "user list for root ($expected)");
    assert_not_contains($substringUserList['body'], $expected, "user list for xroot ($expected)");
}
pg_query($database, 'UPDATE vouchers SET deleted = true WHERE id = 105');
$substringRecovery = http_request('/index.php?action=recover&id=1004&bid=105&doit=1', 'GET', array(), $substringCookies);
assert_contains($substringRecovery['body'], 'nur von Admins', 'recovery by xroot');
assert_database_count($database, 'SELECT COUNT(*) AS count FROM vouchers WHERE id = 105 AND deleted', array(), 1, 'row after recovery by xroot');
$substringDraftId = create_voucher($database, 'Synthetic draft for role substrings', '-20.00', 0, $adminCookies);
$substringVoucherPage = http_request('/index.php?action=edit&id=' . $substringDraftId, 'GET', array(), $substringCookies);
assert_contains($substringVoucherPage['body'], 'value="Synthetic draft for role substrings"', 'voucher page for xroot');
assert_not_contains($substringVoucherPage['body'], 'mergelink', 'voucher page for xroot');
$substringMemberFees = http_request('/index.php?action=mb', 'GET', array(), $substringCookies);
assert_status($substringMemberFees, 200, 'member fees for xbgf');
assert_not_contains($substringMemberFees['body'], 'MB Zahlungen nach LO', 'member fees for xbgf');
// (The page itself is not requested for bgf: on the synthetic data it divides
// by zero, an open finding in tests/COMPATIBILITY_PLAN.md.)

pg_close($database);
assert_no_unexpected_diagnostics();
echo "Voucher write-workflow characterization tests passed\n";
