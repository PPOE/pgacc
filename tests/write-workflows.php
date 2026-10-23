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
assert_status($firstApproval, 302, 'first approval');
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
assert_status($duplicateApproval, 302, 'duplicate approval');
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
assert_status($secondApproval, 302, 'second approval');
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
assert_status($finalization, 302, 'voucher finalization');
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
assert_status($revision, 302, 'voucher revision');

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

pg_close($database);
assert_no_unexpected_diagnostics();
echo "Voucher write-workflow characterization tests passed\n";
