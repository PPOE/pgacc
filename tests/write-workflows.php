<?php
require __DIR__ . '/http-test-helpers.php';

function database_connection()
{
    $connectionString = sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s',
        getenv('PGHOST'),
        getenv('PGPORT'),
        getenv('PGDATABASE'),
        getenv('PGUSER'),
        getenv('PGPASSWORD')
    );
    $database = @pg_connect($connectionString);
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

$finalization = http_request(
    '/index.php?action=edit&id=' . rawurlencode($createdVoucherId),
    'POST',
    array('id' => $createdVoucherId, 'parts' => '1', 'beleg' => 'Beleg erhalten'),
    $adminCookies
);
assert_redirect($finalization, edit_location($createdVoucherId, 2), 'voucher finalization');
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

// A user restricted to another organization gets the same redirect, but no
// row moves.
$unauthorizedMerge = http_request($mergeUrl, 'GET', array(), $unitOneCookies);
assert_redirect($unauthorizedMerge, edit_location($mergeTargetId, 6), 'cross-organization merge');
assert_database_count($database, $voucherRowsQuery, array($mergeTargetId), 1, 'merge target after cross-organization merge');
assert_database_count($database, $voucherRowsQuery, array($mergeSourceId), 1, 'merge source after cross-organization merge');

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
    'finalization' => array(
        '/index.php?action=edit&id=' . rawurlencode($unbufferedVoucherId),
        array('id' => $unbufferedVoucherId, 'parts' => '1', 'beleg' => 'Beleg erhalten'),
        $adminCookies,
        edit_location($unbufferedVoucherId, 2),
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
    'voucher after unbuffered approvals and finalization'
);
assert_database_field($unbuffered, 'ack1', 'synthetic-admin', 'voucher after unbuffered approvals');
assert_database_field($unbuffered, 'ack2', 'synthetic-reviewer', 'voucher after unbuffered approvals');
assert_database_field($unbuffered, 'receipt_received', 't', 'voucher after unbuffered finalization');
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

pg_close($database);
assert_no_unexpected_diagnostics();
echo "Voucher write-workflow characterization tests passed\n";
