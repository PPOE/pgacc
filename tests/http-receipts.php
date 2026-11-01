<?php

/*
 * Encrypted receipts and the membership export over HTTP: receipt upload
 * (new.php), PDF import (import2.php), receipt download (file.php) and
 * mbapi.php. The byte-exact formats are pinned by tests/unit/CryptoTest.php
 * (legacy, mcrypt) and tests/unit/ReceiptStorageTest.php (v2); this checks the
 * call sites and the stored files: new receipts are files/<n>.v2.aes (keyed on
 * the file number and the configured key), legacy files/<n>.aes (voucher ID,
 * empty key part) stay readable as before, and <n>.v2.aes wins.
 * The test reads and writes the server's files/ directory: a volume shared
 * with the server in compose.test.yaml, the copy's files/ in the mutation
 * runner (PGACC_TEST_APP_ROOT).
 */
require __DIR__ . '/http-test-helpers.php';
// The membership export and receipt files are encrypted and decrypted with
// the application's helpers (pinned by the vectors).
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/functions.php';
require_once dirname(__DIR__) . '/src/db.php';

function receipts_database()
{
    $database = @pg_connect(pgacc_test_connection_string());
    if ($database === false) {
        fail_test('could not connect to the fixture database');
    }
    return $database;
}

function receipts_query($database, $query, $parameters, $label)
{
    $result = @pg_query_params($database, $query, $parameters);
    if ($result === false) {
        fail_test("database query failed for $label: " . pg_last_error($database));
    }
    $rows = pg_fetch_all($result);
    pg_free_result($result);
    return $rows === false ? [] : $rows;
}

function insert_receipt_voucher($database, $id, $voucherId, $comment)
{
    receipts_query(
        $database,
        "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, committed,
                               date, person_type, file, save_user, vaccount)
         VALUES ($1, $2, 20, 2, false, -1000, 'TEST-ACCOUNT-2', $3, true,
                 timestamp '2024-09-15 00:00:00', 1, 0, 1, 'TEST-VIRTUAL-2')",
        [$id, $voucherId, $comment],
        "voucher $id",
    );
}

function receipt_file_number($database, $id)
{
    $rows = receipts_query($database, 'SELECT file FROM vouchers WHERE id = $1', [$id], "file of voucher $id");
    if (count($rows) !== 1 || intval($rows[0]['file']) <= 0) {
        fail_test("voucher $id has no receipt file after the upload");
    }
    return $rows[0]['file'];
}

// $body is the expected response body: the plain text of a <n>.v2.aes
// ($v2), or the zero-padded plain text of a legacy <n>.aes. Content-Length is
// its size (for a legacy file the size of the stored ciphertext, which mcrypt
// also padded to whole 32-byte blocks). v2 responses forbid content sniffing
// and send other types as an attachment; legacy responses are unchanged.
function assert_receipt_download($cookies, $id, $body, $contentType, $fileNumber, $label, $v2)
{
    $response = http_request('/index.php?action=file&id=' . $id, 'GET', [], $cookies);
    assert_status($response, 200, $label);
    if ($response['body'] !== $body) {
        fail_test("$label returned " . strlen($response['body']) . ' bytes that differ from the receipt');
    }
    $headers = [
        'Content-Type' => $contentType,
        'Content-Length' => strval(strlen($body)),
        'Content-Transfer-Encoding' => 'binary',
        'Accept-Ranges' => 'bytes',
        'Content-Disposition' => $contentType === 'application/pdf' ? "inline; filename=\"$fileNumber.pdf\""
            : ($contentType === 'application/octet-stream' ? "attachment; filename=\"$fileNumber.bin\"" : null),
        'X-Content-Type-Options' => $v2 ? 'nosniff' : null,
    ];
    foreach ($headers as $name => $expected) {
        $actual = response_header($response, $name);
        if ($actual !== $expected) {
            fail_test("$label header $name was " . var_export($actual, true) . ', expected ' . var_export($expected, true));
        }
    }
}

// The refusal: a 500 text response without any of the download headers.
function assert_undecryptable_download($cookies, $id, $label, $baseUrl = null)
{
    $response = http_request('/index.php?action=file&id=' . $id, 'GET', [], $cookies, $baseUrl);
    assert_status($response, 500, $label);
    if (response_header($response, 'Content-Type') !== 'text/plain; charset=UTF-8'
        || $response['body'] !== 'FEHLER: Der Beleg konnte nicht entschlüsselt werden.') {
        fail_test("$label: " . var_export(response_header($response, 'Content-Type'), true) . ' '
            . var_export($response['body'], true));
    }
    foreach (['X-Content-Type-Options', 'Content-Length', 'Content-Disposition', 'Content-Transfer-Encoding',
        'Accept-Ranges'] as $name) {
        if (response_header($response, $name) !== null) {
            fail_test("$label sent the header $name: " . var_export(response_header($response, $name), true));
        }
    }
}

// What the server logs (error_log()) for one download: the request goes to a
// second server started by this script, whose standard error the test can
// read (the application service's log is not shared with the test). Its fixed
// port and log file cannot collide with another run: every run
// (PGACC_TEST_ID) has its own test container, with its own network namespace
// and /tmp, and runs its HTTP test files one after another; the other files
// use ports 8081 to 8083 and 8085 to 8088.
function receipt_download_log($cookies, $id)
{
    static $baseUrl = null;
    if ($baseUrl === null) {
        $baseUrl = start_unbuffered_server(8084);
    }
    $log = unbuffered_server_log(8084);
    clearstatcache();
    $offset = is_file($log) ? filesize($log) : 0;
    $response = http_request('/index.php?action=file&id=' . $id, 'GET', [], $cookies, $baseUrl);
    clearstatcache();
    return [$response, (string) file_get_contents($log, false, null, $offset)];
}

// $expected lines must be in the log of one download, $unexpected must not;
// neither the key nor receipt contents are logged.
function assert_receipt_log($cookies, $id, $expected, $unexpected, $label)
{
    list($response, $log) = receipt_download_log($cookies, $id);
    foreach ($expected as $line) {
        if (strpos($log, $line) === false) {
            fail_test("$label: the log lacks " . var_export($line, true) . ': ' . var_export($log, true));
        }
    }
    foreach (array_merge($unexpected, [$GLOBALS['testKey'], '%PDF']) as $text) {
        if (strpos($log, $text) !== false) {
            fail_test("$label: the log contains " . var_export($text, true) . ': ' . var_export($log, true));
        }
    }
    return $response;
}

// The server's files/ directory and its receipt files of one file number.
function receipt_files_directory()
{
    $root = getenv('PGACC_TEST_APP_ROOT') ? rtrim(getenv('PGACC_TEST_APP_ROOT'), '/') : '/app';
    if (!is_dir("$root/files") || !is_writable("$root/files")) {
        fail_test("the server's receipt directory $root/files is not shared with the test");
    }
    return "$root/files";
}

function stored_receipt_files($fileNumber)
{
    $names = [];
    foreach (scandir(receipt_files_directory()) as $name) {
        if (strpos($name, "$fileNumber.") === 0) {
            $names[] = $name;
        }
    }
    sort($names);
    return $names;
}

// An upload stores <n>.v2.aes only, keyed on the file number and the key.
function assert_stored_receipt($fileNumber, $key, $plain, $label)
{
    $names = stored_receipt_files($fileNumber);
    if ($names !== ["$fileNumber.v2.aes"]) {
        fail_test("$label stored " . var_export($names, true) . ", expected $fileNumber.v2.aes only");
    }
    $stored = file_get_contents(receipt_files_directory() . "/$fileNumber.v2.aes");
    if (receipt_v2_decrypt($key, $fileNumber, $stored) !== $plain) {
        fail_test("$label: $fileNumber.v2.aes does not decrypt to the receipt with the configured key");
    }
}

function merge_vouchers($cookies, $target, $source)
{
    // The merge form of the edit page posts bid1=<this voucher>, bid2=<other>.
    $response = http_request('/index.php?action=merge', 'POST', ['bid1' => $target, 'bid2' => $source], $cookies);
    assert_no_problems(redirect_problems($response, "index.php?action=edit&id=$target&hint=6"), "merge of $source into $target");
}

$vectors = json_decode(file_get_contents(__DIR__ . '/fixtures/mcrypt/vectors.json'), true);
$receiptVectors = [];
foreach ($vectors['receipts'] as $vector) {
    $receiptVectors[$vector['name']] = $vector;
}
$pdf = hex2bin($receiptVectors['pdf document']['plain']);
$jpeg = hex2bin($receiptVectors['jpeg with trailing NUL bytes']['plain']);
$png = "\x89PNG\r\n\x1A\nsynthetic";
// tests/runtime/test-constants.php sets this synthetic $key.
$testKey = 'synthetic-test-only-encryption-key';
$otherKey = 'another-synthetic-receipt-key';

$database = receipts_database();
$adminCookies = login_user('synthetic-admin', 'correct-password');

// The user administration shows root the fingerprint of the configured key
// (for the migration's --key-fingerprint), never the key; others see neither.
$fingerprintLine = '<p>Fingerabdruck des Beleg-Schlüssels (für migrate-receipt-key.php --key-fingerprint): '
    . receipt_key_fingerprint($testKey) . '</p>';
$accounts = http_request('/index.php?action=accounts', 'GET', [], $adminCookies);
assert_status($accounts, 200, 'user administration as root');
assert_contains($accounts['body'], $fingerprintLine, 'key fingerprint for root');
assert_not_contains($accounts['body'], $testKey, 'key on the user administration page');
$accounts = http_request('/index.php?action=accounts', 'GET', [], login_user('synthetic-reviewer', 'reviewer-password'));
assert_status($accounts, 200, 'user administration as another user');
assert_not_contains($accounts['body'], 'Fingerabdruck', 'key fingerprint for another user');
insert_receipt_voucher($database, 9101, 4711, 'Synthetic PDF receipt upload');
insert_receipt_voucher($database, 9102, 815, 'Synthetic JPEG receipt upload');
insert_receipt_voucher($database, 9103, 4712, 'Synthetic bank import AB/123456789');
insert_receipt_voucher($database, 9109, 4740, 'Synthetic receipt upload before a merge');
insert_receipt_voucher($database, 9110, 4741, 'Synthetic merge target');

function upload_receipt($database, $cookies, $id, $voucherId, $name, $type, $content)
{
    $response = http_request(
        '/index.php?action=edit&id=' . $voucherId,
        'POST',
        ['fileupload' => 'PDF Hochladen (OHNE SPEICHERN)', 'parts' => '1', 'bid0' => strval($id)],
        $cookies,
        null,
        ['file0' => ['name' => $name, 'type' => $type, 'content' => $content]],
    );
    // relocate() discards the confirmation page and redirects to the voucher.
    assert_no_problems(
        redirect_problems($response, "index.php?action=edit&id=$voucherId&bid=$id&hint=1"),
        "$name upload",
    );
    return receipt_file_number($database, $id);
}

// Receipt upload on the voucher edit page (new.php page_new_file()).
$uploads = [
    [9101, 4711, 'receipt.pdf', 'application/pdf', $pdf],
    [9102, 815, 'receipt.jpg', 'image/jpeg', $jpeg],
];
foreach ($uploads as $upload) {
    list($id, $voucherId, $name, $type, $content) = $upload;
    $fileNumber = upload_receipt($database, $adminCookies, $id, $voucherId, $name, $type, $content);
    assert_stored_receipt($fileNumber, $testKey, $content, "$name upload");
    assert_receipt_download($adminCookies, $id, $content, $type, $fileNumber, "$name download", true);
}

// A save takes no receipt number from the request: a new voucher starts
// without a receipt; a revised row takes the receipt of the current row it
// revises (a current row of the voucher within the user's rights), each one
// at most once. A posted row that is not such a row (an old revision, a row
// of another voucher or organization) refuses the save. A user of
// organization 1 posts the receipt number of the organization 2 receipt of
// 9101 and cannot download it.
function receipt_voucher_form($comment, $bids, $file)
{
    $form = ['speichern' => 'Speichern', 'parts' => strval(count((array) $bids))];
    foreach (array_values((array) $bids) as $part => $bid) {
        $form += [
            "bid$part" => strval($bid), "dir$part" => 'out', "in_type$part" => '8', "out_type$part" => '20',
            "wk_type$part" => '20', "date$part" => '2024-09-15', "person_type$part" => '1', "lo$part" => '1',
            "amount$part" => '-1.00', "gegenkonto$part" => 'SYNTHETIC001', "konto$part" => 'TESTACCOUNT1',
            "vkonto$part" => 'TESTVIRTUAL1', "comment$part" => $comment, "commentgf$part" => '',
            "mitgliedsnummer$part" => '0', "name$part" => 'Synthetic Supplier', "street$part" => 'Test Street 1',
            "plz$part" => '0000', "city$part" => 'Test City', "file$part" => strval($file),
        ];
    }
    return $form;
}

function assert_receipt_number($expected, $actual, $label)
{
    if ($actual !== $expected) {
        fail_test("$label stored receipt number " . var_export($actual, true) . ", expected $expected");
    }
}

// The current rows of the vouchers with this comment, ordered by ID.
function current_receipt_rows($database, $comment)
{
    return receipts_query(
        $database,
        'SELECT id, voucher_id, file FROM vouchers WHERE comment = $1 AND NOT deleted ORDER BY id',
        [$comment],
        "current rows of $comment",
    );
}

function current_receipt_row($database, $comment)
{
    $rows = current_receipt_rows($database, $comment);
    if (count($rows) !== 1) {
        fail_test("$comment has " . count($rows) . ' current rows, expected 1');
    }
    return $rows[0];
}

function assert_receipt_refused($cookies, $id, $content, $label)
{
    $response = http_request('/index.php?action=file&id=' . $id, 'GET', [], $cookies);
    if ($response['body'] === $content || response_header($response, 'Content-Disposition') !== null) {
        fail_test("$label sent a receipt");
    }
}

function revision_request($cookies, $voucherId, $form)
{
    $form += ['id' => strval($voucherId), 'changed' => '1', 'ack1' => '', 'ack2' => '', 'ack1_old' => '', 'ack2_old' => ''];
    return http_request('/index.php?action=edit&id=' . $voucherId, 'POST', $form, $cookies);
}

function revise_receipt_voucher($database, $cookies, $voucherId, $form, $label)
{
    $response = revision_request($cookies, $voucherId, $form);
    assert_no_problems(redirect_problems($response, "index.php?action=edit&id=$voucherId&hint=5"), $label);
}

// A refused revision writes nothing (the voucher keeps its rows) and shows
// the message and the form again with the posted values.
function revision_refused_message($message)
{
    return '<div class="slot_error" id="slot_error">FEHLER: ' . $message . '</div>';
}

function voucher_state($database, $voucherId, $label)
{
    return receipts_query($database, 'SELECT * FROM vouchers WHERE voucher_id = $1 ORDER BY id', [$voucherId], $label);
}

// A stale form shows the voucher's current form (its current row IDs) and
// the posted parts read-only; other refusals show the posted form again,
// rights refusals no form.
function assert_refused_response($database, $response, $voucherId, $before, $form, $message, $label)
{
    assert_status($response, 200, $label);
    assert_contains($response['body'], revision_refused_message($message), $label);
    assert_not_contains($response['body'], 'Buchung aktualisiert', $label);
    if ($message === 'Die Buchung wurde inzwischen geändert. Bitte neu laden.') {
        assert_contains($response['body'], '<h2>Nicht gespeicherte Eingaben</h2>', $label);
        assert_contains($response['body'], '<td>' . htmlspecialchars($form['comment0'], ENT_QUOTES, 'ISO-8859-1') . '</td>', $label);
        assert_not_contains($response['body'], 'value="' . $form['comment0'] . '"', $label);
        $current = receipts_query($database, 'SELECT id FROM vouchers WHERE voucher_id = $1 AND NOT deleted AND orga = 1 ORDER BY id LIMIT 1', [$voucherId], $label);
        assert_contains($response['body'], '<input type="hidden" name="bid0" value="' . $current[0]['id'] . '" />', $label);
    } elseif (strpos($message, 'Keine') === 0) {
        assert_not_contains($response['body'], 'name="bid0"', $label);
    } elseif (isset($form['comment0'])) {
        assert_contains($response['body'], 'value="' . $form['comment0'] . '"', $label);
    }
    if (voucher_state($database, $voucherId, $label) !== $before) {
        fail_test("$label changed the voucher");
    }
}

function assert_revision_refused($database, $cookies, $voucherId, $form, $label, $message = 'Die Buchung wurde inzwischen geändert. Bitte neu laden.')
{
    $before = voucher_state($database, $voucherId, $label);
    $response = revision_request($cookies, $voucherId, $form);
    assert_refused_response($database, $response, $voucherId, $before, $form, $message, $label);
}

$unitOneCookies = login_user('synthetic-unit-one', 'unit-password');
$foreignFile = receipt_file_number($database, 9101);
$response = http_request('/index.php?action=new', 'POST', receipt_voucher_form('Synthetic voucher with a posted receipt number', 0, $foreignFile), $unitOneCookies);
assert_contains($response['body'], 'Buchung erfasst', 'new voucher with a posted receipt number');
$posted = current_receipt_row($database, 'Synthetic voucher with a posted receipt number');
assert_receipt_number('0', $posted['file'], 'new voucher with a posted receipt number');
assert_receipt_refused($unitOneCookies, $posted['id'], $pdf, 'new voucher with a posted receipt number');

receipts_query(
    $database,
    "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, committed,
                           date, person_type, file, save_user, vaccount)
     VALUES (9180, 4780, 20, 1, false, -100, 'TESTACCOUNT1', 'Synthetic revision without a receipt', false,
             timestamp '2024-09-15 00:00:00', 1, 0, 3, 'TESTVIRTUAL1'),
            (9181, 4781, 20, 1, false, -100, 'TESTACCOUNT1', 'Synthetic revision with a receipt', false,
             timestamp '2024-09-15 00:00:00', 1, 99001, 3, 'TESTVIRTUAL1'),
            (9182, 4782, 20, 1, false, -100, 'TESTACCOUNT1', 'Synthetic revision of two organizations', false,
             timestamp '2024-09-15 00:00:00', 1, 0, 3, 'TESTVIRTUAL1'),
            (9183, 4782, 20, 2, false, -100, 'TESTACCOUNT2', 'Synthetic row of organization 2', false,
             timestamp '2024-09-15 00:00:00', 1, $1, 1, 'TESTVIRTUAL2'),
            (9184, 4783, 20, 1, false, -100, 'TESTACCOUNT1', 'Synthetic revision with two receipts', false,
             timestamp '2024-09-15 00:00:00', 1, 99003, 3, 'TESTVIRTUAL1'),
            (9185, 4783, 20, 1, false, -100, 'TESTACCOUNT1', 'Synthetic revision with two receipts', false,
             timestamp '2024-09-15 00:00:00', 1, 99004, 3, 'TESTVIRTUAL1')",
    [$foreignFile],
    'vouchers of organization 1',
);

// The current row: a posted receipt number is ignored.
$row = current_receipt_row($database, 'Synthetic revision without a receipt');
revise_receipt_voucher($database, $unitOneCookies, 4780, receipt_voucher_form('Synthetic revision without a receipt', $row['id'], $foreignFile), 'revision without a receipt');
assert_receipt_number('0', current_receipt_row($database, 'Synthetic revision without a receipt')['file'], 'revision without a receipt');
$row = current_receipt_row($database, 'Synthetic revision with a receipt');
$staleRow = $row['id'];
revise_receipt_voucher($database, $unitOneCookies, 4781, receipt_voucher_form('Synthetic revision with a receipt', $row['id'], $foreignFile), 'revision with a receipt');
$row = current_receipt_row($database, 'Synthetic revision with a receipt');
assert_receipt_number('99001', $row['file'], 'revision with a receipt');
assert_receipt_refused($unitOneCookies, $row['id'], $pdf, 'revision with a receipt');
// No posted receipt number: the receipt is kept too.
$form = receipt_voucher_form('Synthetic revision with a receipt', $row['id'], 0);
unset($form['file0']);
revise_receipt_voucher($database, $unitOneCookies, 4781, $form, 'revision without a posted receipt number');
assert_receipt_number('99001', current_receipt_row($database, 'Synthetic revision with a receipt')['file'], 'revision without a posted receipt number');

// Rows that are no current row of the voucher within the user's rights:
// refused, the receipts stay where they are.
// A current row of another organization of the voucher is refused for the
// rights, the others as a stale form.
$stale = 'Die Buchung wurde inzwischen geändert. Bitte neu laden.';
$refused = [
    'old revision (a form opened before another save)' => [4781, 'Synthetic revision with a receipt', $staleRow, $stale],
    'row of organization 2 of another voucher' => [4780, 'Synthetic revision without a receipt', 9101, $stale],
    'row of organization 2 of the voucher' => [4782, 'Synthetic revision of two organizations', 9183, 'Keine Berechtigung für diese Buchungszeile.'],
    'row of another voucher of organization 1' => [4780, 'Synthetic revision without a receipt', current_receipt_row($database, 'Synthetic revision with a receipt')['id'], $stale],
];
foreach ($refused as $label => $case) {
    list($voucherId, $comment, $bid, $message) = $case;
    $form = receipt_voucher_form($comment, $bid, $foreignFile);
    $form['comment0'] = "Synthetic refused revision <$label>";
    assert_revision_refused($database, $unitOneCookies, $voucherId, $form, "revision posting the $label", $message);
}
// A row with a receipt that is not posted again (a removed part) is not
// dropped: refused. No part at all is refused too.
$form = receipt_voucher_form('Synthetic revision dropping a receipt', 0, 0);
assert_revision_refused($database, $unitOneCookies, 4781, $form, 'revision without the row with a receipt', 'Eine Buchungszeile mit Beleg kann nicht entfernt werden.');
// A row ID that is no number (also one with a trailing newline) is a new part.
$form = receipt_voucher_form('Synthetic revision with an invalid row', current_receipt_row($database, 'Synthetic revision with a receipt')['id'] . "\n", 0);
assert_revision_refused($database, $unitOneCookies, 4781, $form, 'revision with an invalid row ID', 'Eine Buchungszeile mit Beleg kann nicht entfernt werden.');
$form = receipt_voucher_form('Synthetic revision without parts', 0, 0);
$form['parts'] = '0';
unset($form['comment0']);
assert_revision_refused($database, $unitOneCookies, 4780, $form, 'revision without parts', 'Keine Buchungszeile angegeben.');
assert_receipt_number('99001', current_receipt_row($database, 'Synthetic revision with a receipt')['file'], 'receipt after the refused revisions');
assert_receipt_number($foreignFile, receipts_query($database, 'SELECT file FROM vouchers WHERE id = 9183', [], 'row of organization 2')[0]['file'], 'receipt of organization 2 after the refused revisions');

// Each stored receipt goes to one revised row at most: a row posted twice
// keeps its receipt once, swapped rows keep their own receipts.
list($first, $second) = current_receipt_rows($database, 'Synthetic revision with two receipts');
revise_receipt_voucher($database, $unitOneCookies, 4783, receipt_voucher_form('Synthetic revision with two receipts', [$first['id'], $first['id'], $second['id']], 0), 'revision posting a row twice');
$rows = current_receipt_rows($database, 'Synthetic revision with two receipts');
if (array_column($rows, 'file') !== ['99003', '0', '99004']) {
    fail_test('revision posting a row twice stored receipts ' . var_export(array_column($rows, 'file'), true));
}
// The row without a receipt can be removed.
revise_receipt_voucher($database, $unitOneCookies, 4783, receipt_voucher_form('Synthetic revision with two receipts', [$rows[0]['id'], $rows[2]['id']], 0), 'revision removing a row without a receipt');
list($first, $second) = current_receipt_rows($database, 'Synthetic revision with two receipts');
revise_receipt_voucher($database, $unitOneCookies, 4783, receipt_voucher_form('Synthetic revision with two receipts', [$second['id'], $first['id']], 0), 'revision with swapped rows');
$files = array_column(current_receipt_rows($database, 'Synthetic revision with two receipts'), 'file');
if ($files !== ['99004', '99003']) {
    fail_test('revision with swapped rows stored receipts ' . var_export($files, true));
}
assert_receipt_download($adminCookies, 9101, $pdf, 'application/pdf', $foreignFile, 'receipt after the posted receipt numbers', true);

// A revision whose row lock PostgreSQL aborts for a deadlock is rolled back
// and shown again with a message to save again. The test holds the lock of
// the second row and, once the save waits for it while holding the first
// one, requests the first row: the save (waiting first) is the deadlock's
// victim. The request is sent without waiting for its answer (the test
// server answers one request at a time).
function http_request_start($path, $form, $cookies, $files = [])
{
    $form['csrf_token'] = session_csrf_token($cookies);
    $url = parse_url(getenv('PGACC_TEST_BASE_URL'));
    $socket = @stream_socket_client('tcp://' . $url['host'] . ':' . ($url['port'] ?? 80), $errno, $error, 10);
    if ($socket === false) {
        fail_test("could not connect to the application: $error");
    }
    $cookieParts = [];
    foreach ($cookies as $name => $value) {
        $cookieParts[] = "$name=$value";
    }
    $content = http_build_query($form, '', '&');
    $type = 'application/x-www-form-urlencoded';
    if (count($files) > 0) {
        $boundary = 'pgacc-test-' . md5(uniqid('', true));
        $content = '';
        foreach ($form as $name => $value) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        foreach ($files as $name => $file) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"{$file['name']}\"\r\n"
                . "Content-Type: {$file['type']}\r\n\r\n{$file['content']}\r\n";
        }
        $content .= "--$boundary--\r\n";
        $type = "multipart/form-data; boundary=$boundary";
    }
    fwrite($socket, "POST $path HTTP/1.0\r\nHost: pgacc.test\r\nX-Forwarded-Proto: https\r\n"
        . 'X-Pgacc-Test-Request: ' . next_diagnostics_request_id() . "\r\n"
        . 'Cookie: ' . implode('; ', $cookieParts) . "\r\n"
        . "Content-Type: $type\r\nContent-Length: " . strlen($content) . "\r\n\r\n$content");
    return $socket;
}

function http_request_finish($socket)
{
    stream_set_timeout($socket, 20);
    $raw = stream_get_contents($socket);
    fclose($socket);
    $parts = explode("\r\n\r\n", $raw, 2);
    if (count($parts) !== 2 || preg_match('#^HTTP/\S+ (\d+)#', $parts[0], $matches) !== 1) {
        fail_test('the application sent no HTTP response');
    }
    return ['status' => intval($matches[1]), 'headers' => explode("\r\n", $parts[0]), 'body' => $parts[1]];
}

receipts_query(
    $database,
    "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, committed,
                           date, person_type, file, save_user, vaccount)
     VALUES (9186, 4784, 20, 1, false, -100, 'TESTACCOUNT1', 'Synthetic revision in a deadlock', false,
             timestamp '2024-09-15 00:00:00', 1, 0, 3, 'TESTVIRTUAL1'),
            (9187, 4784, 20, 1, false, -100, 'TESTACCOUNT1', 'Synthetic revision in a deadlock', false,
             timestamp '2024-09-15 00:00:00', 1, 99005, 3, 'TESTVIRTUAL1')",
    [],
    'voucher of the deadlock',
);
$locker = pg_connect(pgacc_test_connection_string(), PGSQL_CONNECT_FORCE_NEW);
receipts_query($locker, 'BEGIN', [], 'lock of the second row');
receipts_query($locker, 'SELECT id FROM vouchers WHERE id = 9187 FOR UPDATE', [], 'lock of the second row');
$before = voucher_state($database, 4784, 'voucher of the deadlock');
$form = receipt_voucher_form('Synthetic revision in a deadlock', [9186, 9187], 0);
$form['comment0'] = 'Synthetic revised in a deadlock';
$form += ['id' => '4784', 'changed' => '1', 'ack1' => '', 'ack2' => '', 'ack1_old' => '', 'ack2_old' => ''];
$socket = http_request_start('/index.php?action=edit&id=4784', $form, $unitOneCookies);
// The save waits for the second row (holding the first one).
$waiting = false;
for ($try = 0; $try < 100 && !$waiting; $try++) {
    usleep(50000);
    $waiting = receipts_query($database, "SELECT count(*) AS n FROM pg_locks WHERE NOT granted AND locktype = 'transactionid'", [], 'waiting save')[0]['n'] !== '0';
}
if (!$waiting) {
    fail_test('the revision did not wait for the locked row');
}
@pg_query($locker, 'SELECT id FROM vouchers WHERE id = 9186 FOR UPDATE');
receipts_query($locker, 'ROLLBACK', [], 'end of the lock');
pg_close($locker);
$response = http_request_finish($socket);
assert_refused_response($database, $response, 4784, $before, $form, 'Die Buchung wird gerade gleichzeitig geändert. Bitte erneut speichern.', 'revision in a deadlock');
// Saved again, it is stored.
revise_receipt_voucher($database, $unitOneCookies, 4784, receipt_voucher_form('Synthetic revision after a deadlock', [9186, 9187], 0), 'revision after a deadlock');
if (array_column(current_receipt_rows($database, 'Synthetic revision after a deadlock'), 'file') !== ['0', '99005']) {
    fail_test('revision after a deadlock lost a receipt');
}
// The member list of a member's part is fetched before the transaction,
// after the checks without the lock: a revision refused by them does not
// contact the membership system (the stub counts the list requests). With
// the membership system unreachable or answering with invalid data, a save
// is refused with the form shown again and writes nothing. With the list
// available, a member's revision is saved.
function receipts_membership_mode($mode)
{
    $answer = @file_get_contents('http://mitglieder.piratenpartei.at/__stub/mode?set=' . $mode);
    if ($answer !== "mode $mode\n") {
        fail_test("membership stub mode $mode: " . var_export($answer, true));
    }
}

$memberRows = array_column(current_receipt_rows($database, 'Synthetic revision after a deadlock'), 'id');
$memberForm = receipt_voucher_form('Synthetic member revision', $memberRows, 0);
$memberForm['member0'] = '1';
$membersUnavailable = 'Die Mitgliederverwaltung ist nicht erreichbar. Bitte später erneut speichern.';
// The stub's mode outlives this script: reset it even when a check fails.
register_shutdown_function(function () {
    @file_get_contents('http://mitglieder.piratenpartei.at/__stub/mode?set=ok');
});
$memberCases = [
    'unreachable member list' => ['error', 4784, [], $membersUnavailable, true],
    'invalid member list' => ['malformed', 4784, [], $membersUnavailable, true],
    'invalid amount' => ['error', 4784, ['amount0' => 'abc'], 'Ungültiger Betrag', true],
    'stale row' => ['error', 4784, ['bid0' => '9186'], 'Die Buchung wurde inzwischen geändert. Bitte neu laden.', false],
    'no parts' => ['error', 4784, ['parts' => '0'], 'Keine Buchungszeile angegeben.', false],
    'organization of another user' => ['error', 4784, ['lo0' => '2'], 'FEHLER: Keine Zugriffsrechte.', false],
    'voucher of another organization' => ['error', 4711, [], 'FEHLER: Buchung nicht gefunden.', false],
    'finalized voucher' => ['error', 4784, [], 'bereits finalisiert', false],
];
foreach ($memberCases as $label => $case) {
    list($mode, $voucherId, $changes, $expected, $formShown) = $case;
    receipts_membership_mode($mode);
    if ($label === 'finalized voucher') {
        receipts_query($database, 'UPDATE vouchers SET receipt_received = true WHERE id = $1', [$memberRows[1]], 'finalized row');
    }
    $form = array_merge($memberForm, $changes);
    $before = voucher_state($database, $voucherId, "member revision: $label");
    $response = revision_request($unitOneCookies, $voucherId, $form);
    assert_contains($response['body'], $expected, "member revision: $label");
    if ($expected !== $membersUnavailable) {
        assert_not_contains($response['body'], $membersUnavailable, "member revision: $label");
    }
    if ($formShown) {
        assert_contains($response['body'], 'value="Synthetic member revision"', "member revision: $label");
    }
    $fetched = @file_get_contents('http://mitglieder.piratenpartei.at/__stub/members-requests');
    if ($fetched !== ($expected === $membersUnavailable ? "1\n" : "0\n")) {
        fail_test("member revision: $label fetched the member list " . var_export($fetched, true) . ' times');
    }
    if (voucher_state($database, $voucherId, "member revision: $label") !== $before) {
        fail_test("member revision: $label changed the voucher");
    }
}
receipts_query($database, 'UPDATE vouchers SET receipt_received = false WHERE id = $1', [$memberRows[1]], 'finalized row');
// A new member's voucher is refused the same way: the form again, no voucher.
$newMemberForm = receipt_voucher_form('Synthetic new member voucher', 0, 0);
$newMemberForm['member0'] = '1';
$response = http_request('/index.php?action=new', 'POST', $newMemberForm, $unitOneCookies);
assert_contains($response['body'], revision_refused_message($membersUnavailable), 'new member voucher');
assert_contains($response['body'], 'value="Synthetic new member voucher"', 'new member voucher');
assert_not_contains($response['body'], 'Buchung erfasst', 'new member voucher');
if (current_receipt_rows($database, 'Synthetic new member voucher') !== []) {
    fail_test('new member voucher was saved without the member list');
}
// With the member list (also while another connection holds a shared
// ppmembers table locked, as an earlier release's refresh did) the member's
// revision is saved.
receipts_membership_mode('ok');
$other = pg_connect(pgacc_test_connection_string(), PGSQL_CONNECT_FORCE_NEW);
foreach (['CREATE TABLE IF NOT EXISTS public.ppmembers (id integer, name text, lo integer, paid integer)', 'BEGIN',
    'LOCK TABLE public.ppmembers IN ACCESS EXCLUSIVE MODE'] as $query) {
    receipts_query($other, $query, [], 'shared member table');
}
revise_receipt_voucher($database, $unitOneCookies, 4784, $memberForm, 'member revision with the member list');
receipts_query($other, 'ROLLBACK', [], 'shared member table');
receipts_query($other, 'DROP TABLE public.ppmembers', [], 'shared member table');
pg_close($other);
$memberSaved = receipts_query($database, 'SELECT member FROM vouchers WHERE comment = $1 AND NOT deleted ORDER BY id', ['Synthetic member revision'], 'member revision');
if (array_column($memberSaved, 'member') !== ['t', 'f']) {
    fail_test('member revision with the member list: ' . var_export($memberSaved, true));
}
$memberRows = array_column(current_receipt_rows($database, 'Synthetic member revision'), 'id');

// Two concurrent saves: while the first one (here the test's connection,
// with the voucher's lock like every writer) holds the voucher, the second
// waits; the first replaces the rows and commits. The second then sees the
// new rows, finds the posted ones replaced and refuses with the current form,
// instead of "Buchung nicht gefunden" or a save over the first.
$memberRows = concurrent_replacement(
    $database,
    4784,
    receipt_voucher_form('Synthetic second concurrent save', $memberRows, 0),
    $unitOneCookies,
    'Synthetic first concurrent save',
    'second concurrent save',
);
if (count($memberRows) !== 2) {
    fail_test('the first concurrent save lost rows');
}
// The first save: the test's connection takes the voucher's lock, the
// second save (HTTP) waits, then the first replaces the rows ($comment) and
// commits. Returns the new rows.
function concurrent_replacement($database, $voucherId, $form, $cookies, $comment, $label)
{
    $first = pg_connect(pgacc_test_connection_string(), PGSQL_CONNECT_FORCE_NEW);
    receipts_query($first, 'BEGIN', [], $label);
    receipts_query($first, 'SELECT pg_advisory_xact_lock(1, $1)', [$voucherId], $label);
    $form += ['id' => strval($voucherId), 'changed' => '1', 'ack1' => '', 'ack2' => '', 'ack1_old' => '', 'ack2_old' => ''];
    $socket = http_request_start('/index.php?action=edit&id=' . $voucherId, $form, $cookies);
    $waiting = false;
    for ($try = 0; $try < 100 && !$waiting; $try++) {
        usleep(50000);
        $waiting = receipts_query($database, "SELECT count(*) AS n FROM pg_locks WHERE NOT granted AND locktype = 'advisory'", [], $label)[0]['n'] !== '0';
    }
    receipts_query($first, "INSERT INTO vouchers (voucher_id, type, orga, member, amount, account, comment, committed, date, person_type, file, save_user, vaccount)
        SELECT voucher_id, type, orga, member, amount, account, $2, committed, date, person_type, file, save_user, vaccount
          FROM vouchers WHERE voucher_id = $1 AND NOT deleted ORDER BY id", [$voucherId, $comment], $label);
    receipts_query($first, 'UPDATE vouchers SET deleted = true WHERE voucher_id = $1 AND NOT deleted AND comment <> $2', [$voucherId, $comment], $label);
    receipts_query($first, 'COMMIT', [], $label);
    pg_close($first);
    if (!$waiting) {
        fail_test("$label did not wait for the concurrent save");
    }
    $after = voucher_state($database, $voucherId, $label);
    assert_refused_response($database, http_request_finish($socket), $voucherId, $after, $form, 'Die Buchung wurde inzwischen geändert. Bitte neu laden.', $label);
    return array_column(current_receipt_rows($database, $comment), 'id');
}
// The same with a member's part and the membership system unreachable: the
// stale form is refused as such (the refusal that does not need the list).
receipts_membership_mode('error');
$form = receipt_voucher_form('Synthetic member save during a concurrent save', $memberRows, 0);
$form['member0'] = '1';
$memberRows = concurrent_replacement($database, 4784, $form, $unitOneCookies, 'Synthetic concurrent save with members', 'member save during a concurrent save');
receipts_membership_mode('ok');
// Three saves of one opened form, one after the other: the first is saved,
// the second and third are refused as stale and change nothing.
$form = receipt_voucher_form('Synthetic first sequential save', $memberRows, 0);
revise_receipt_voucher($database, $unitOneCookies, 4784, $form, 'first sequential save');
foreach (['second', 'third'] as $label) {
    $form = receipt_voucher_form("Synthetic $label sequential save", $memberRows, 0);
    assert_revision_refused($database, $unitOneCookies, 4784, $form, "$label sequential save");
}
$memberRows = array_column(current_receipt_rows($database, 'Synthetic first sequential save'), 'id');

// The other writers of a voucher's rows take its lock too: a receipt upload
// and an approval wait while another connection holds it.
function request_waiting_for_voucher_lock($database, $voucherId, $path, $form, $cookies, $files, $label)
{
    $holder = pg_connect(pgacc_test_connection_string(), PGSQL_CONNECT_FORCE_NEW);
    receipts_query($holder, 'BEGIN', [], $label);
    receipts_query($holder, 'SELECT pg_advisory_xact_lock(1, $1)', [$voucherId], $label);
    $socket = http_request_start($path, $form, $cookies, $files);
    $waiting = false;
    for ($try = 0; $try < 100 && !$waiting; $try++) {
        usleep(50000);
        $waiting = receipts_query($database, "SELECT count(*) AS n FROM pg_locks WHERE NOT granted AND locktype = 'advisory'", [], $label)[0]['n'] !== '0';
    }
    receipts_query($holder, 'COMMIT', [], $label);
    pg_close($holder);
    $response = http_request_finish($socket);
    if (!$waiting) {
        fail_test("$label did not wait for the voucher's lock");
    }
    return $response;
}
$response = request_waiting_for_voucher_lock(
    $database,
    4784,
    '/index.php?action=edit&id=4784',
    ['fileupload' => 'PDF Hochladen (OHNE SPEICHERN)', 'parts' => '1', 'bid0' => $memberRows[0], 'id' => '4784'],
    $unitOneCookies,
    ['file0' => ['name' => 'locked.pdf', 'type' => 'application/pdf', 'content' => $pdf]],
    'upload during a lock',
);
assert_no_problems(redirect_problems($response, "index.php?action=edit&id=4784&bid={$memberRows[0]}&hint=1"), 'upload during a lock');
$response = request_waiting_for_voucher_lock(
    $database,
    4784,
    '/index.php?action=edit&id=4784',
    ['id' => '4784', 'parts' => '1', 'ack' => 'Bestätigen'],
    $unitOneCookies,
    [],
    'approval during a lock',
);
assert_no_problems(redirect_problems($response, 'index.php?action=edit&id=4784&hint=4'), 'approval during a lock');

// Any other failure of the row lock ends the request with the pages'
// message, as every failed statement does, and writes nothing (here: a
// column renamed for the test).
$before = voucher_state($database, 4784, 'voucher before a failed lock');
receipts_query($database, 'ALTER TABLE vouchers RENAME COLUMN receipt_received TO receipt_received_renamed', [], 'renamed column');
$response = revision_request($unitOneCookies, 4784, receipt_voucher_form('Synthetic revision with a failed lock', $memberRows, 0));
receipts_query($database, 'ALTER TABLE vouchers RENAME COLUMN receipt_received_renamed TO receipt_received', [], 'restored column');
assert_contains($response['body'], 'Abfrage fehlgeschlagen: ERROR:  column "receipt_received" does not exist', 'revision with a failed lock');
assert_not_contains($response['body'], 'Buchung aktualisiert', 'revision with a failed lock');
if (voucher_state($database, 4784, 'voucher after a failed lock') !== $before) {
    fail_test('revision with a failed lock changed the voucher');
}

// A merge changes the voucher ID of the merged rows. A <n>.v2.aes is keyed on
// the file number, so its receipt still downloads afterwards.
$fileNumber = upload_receipt($database, $adminCookies, 9109, 4740, 'merged.pdf', 'application/pdf', $pdf);
merge_vouchers($adminCookies, 4741, 4740);
assert_receipt_download($adminCookies, 9109, $pdf, 'application/pdf', $fileNumber, 'v2 receipt after a merge', true);

// Stored receipts with synthetic file numbers above the sequence.
$filesDirectory = receipt_files_directory();
$storedCases = [
    // The legacy format alone: unchanged, zero-padded body.
    [9104, 4711, 990001, ['aes' => hex2bin($receiptVectors['pdf document']['cipher'])],
        rijndael256_zero_pad($pdf), 'application/pdf', 'legacy receipt download'],
    [9111, 815, 990006, ['aes' => hex2bin($receiptVectors['jpeg image']['cipher'])],
        rijndael256_zero_pad(hex2bin($receiptVectors['jpeg image']['plain'])), 'image/jpeg', 'legacy JPEG download'],
    // Both formats: <n>.v2.aes wins; Content-Length is its plain text size.
    [9105, 4711, 990002, ['aes' => hex2bin($receiptVectors['pdf 33 bytes']['cipher']),
        'v2.aes' => receipt_v2_encrypt($testKey, 990002, $pdf)], $pdf, 'application/pdf', 'download with both formats'],
    [9106, 815, 990003, ['aes' => hex2bin($receiptVectors['jpeg image']['cipher']),
        'v2.aes' => receipt_v2_encrypt($testKey, 990003, $jpeg)], $jpeg, 'image/jpeg', 'JPEG download with both formats'],
    // Another type is sent as a download, not refused.
    [9107, 4711, 990004, ['v2.aes' => receipt_v2_encrypt($testKey, 990004, $png)],
        $png, 'image/png', 'v2 PNG receipt'],
    [9129, 4711, 990023, ['v2.aes' => receipt_v2_encrypt($testKey, 990023, "II*\0synthetic")],
        "II*\0synthetic", 'application/octet-stream', 'v2 receipt of another type'],
    // A PDF after whitespace is sent as a PDF from <n>.v2.aes; the legacy
    // download sends it as JPEG, as before. Text such as an HTML page is
    // only offered as a download, never rendered.
    [9135, 4711, 990024, ['v2.aes' => receipt_v2_encrypt($testKey, 990024, "\n" . $pdf)],
        "\n" . $pdf, 'application/pdf', 'v2 PDF after a newline'],
    [9136, 4711, 990025, ['aes' => receipt_encrypt('4711', '', "\n" . $pdf)],
        rijndael256_zero_pad("\n" . $pdf), 'image/jpeg', 'legacy PDF after a newline'],
    [9137, 4711, 990026, ['v2.aes' => receipt_v2_encrypt($testKey, 990026, "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>\n")],
        "<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>\n", 'application/octet-stream', 'v2 HTML receipt'],
    // A <n>.v2.aes that fails its MAC while <n>.aes still exists (before
    // --delete-legacy, e.g. with another key): the legacy file is sent, as
    // before, and logged.
    [9117, 4711, 990012, ['aes' => hex2bin($receiptVectors['pdf document']['cipher']),
        'v2.aes' => receipt_v2_encrypt($otherKey, 990012, $pdf)], rijndael256_zero_pad($pdf), 'application/pdf',
        'legacy receipt after a v2 key mismatch', 'legacy'],
    [9118, 815, 990013, ['aes' => hex2bin($receiptVectors['jpeg image']['cipher']),
        'v2.aes' => 'not a v2 file'], rijndael256_zero_pad(hex2bin($receiptVectors['jpeg image']['plain'])), 'image/jpeg',
        'legacy JPEG after an unreadable v2 file', 'legacy'],
    // Errors without a legacy file: another key, a file copied from another
    // number, a legacy file under the new name (no fallback), a modified file.
    [9108, 4711, 990005, ['v2.aes' => receipt_v2_encrypt($otherKey, 990005, $pdf)],
        null, null, 'v2 receipt with another key'],
    [9112, 4711, 990007, ['v2.aes' => receipt_v2_encrypt($testKey, 990008, $pdf)],
        null, null, 'v2 receipt of another file number'],
    [9113, 4711, 990009, ['v2.aes' => hex2bin($receiptVectors['pdf document']['cipher'])],
        null, null, 'legacy ciphertext under the new name'],
    [9114, 4711, 990010, ['v2.aes' => substr_replace(receipt_v2_encrypt($testKey, 990010, $pdf), 'X', 40, 1)],
        null, null, 'modified v2 receipt'],
    // No stored file at all: refused like an undecryptable receipt, without a
    // warning or an empty JPEG.
    [9120, 4711, 990014, [], null, null, 'receipt without a stored file'],
    // A <n>.aes that cannot be read or is shorter than one 32-byte block (a
    // stored legacy receipt never is): refused, not sent as a short JPEG.
    [9121, 4711, 990015, ['aes' => null], null, null, 'directory in place of the legacy file'],
    [9122, 4711, 990016, ['aes' => ''], null, null, 'zero-byte legacy file'],
    [9123, 4711, 990017, ['aes' => substr(hex2bin($receiptVectors['pdf 33 bytes']['cipher']), 0, 31)],
        null, null, 'legacy file shorter than one block'],
    // One block, the shortest stored legacy receipt: sent.
    [9124, 4711, 990018, ['aes' => hex2bin($receiptVectors['pdf 32 bytes']['cipher'])],
        hex2bin($receiptVectors['pdf 32 bytes']['plain']), 'application/pdf', 'one-block legacy receipt'],
    // A damaged <n>.aes that is not whole 32-byte blocks (no stored file is):
    // refused, not sent with a zero-padded last block.
    [9125, 4711, 990019, ['aes' => substr(hex2bin($receiptVectors['pdf document']['cipher']), 0, 95)],
        null, null, 'legacy file that is not whole blocks'],
    [9128, 4711, 990022, ['aes' => substr(hex2bin($receiptVectors['pdf document']['cipher']), 0, 48)],
        null, null, 'legacy file of one and a half blocks'],
    // A directory at <n>.v2.aes cannot be opened (no notice, not a wrong
    // key): the legacy file is sent if there is one, otherwise refused.
    [9126, 4711, 990020, ['v2.aes' => null, 'aes' => hex2bin($receiptVectors['pdf document']['cipher'])],
        rijndael256_zero_pad($pdf), 'application/pdf', 'legacy receipt beside a directory at the v2 name', 'legacy'],
    [9127, 4711, 990021, ['v2.aes' => null], null, null, 'directory in place of the v2 file'],
];
foreach ($storedCases as $case) {
    list($id, $voucherId, $fileNumber, $files, $body, $type, $label) = $case;
    $legacyServed = isset($case[7]);
    insert_receipt_voucher($database, $id, $voucherId, "Synthetic stored receipt $fileNumber");
    receipts_query($database, 'UPDATE vouchers SET file = $1 WHERE id = $2', [$fileNumber, $id], "file of voucher $id");
    foreach ($files as $suffix => $bytes) {
        $bytes === null ? mkdir("$filesDirectory/$fileNumber.$suffix") : file_put_contents("$filesDirectory/$fileNumber.$suffix", $bytes);
    }
    if ($body === null) {
        assert_undecryptable_download($adminCookies, $id, $label);
    } else {
        assert_receipt_download($adminCookies, $id, $body, $type, $fileNumber, $label, isset($files['v2.aes']) && !$legacyServed);
    }
}

// The log names the file number of a receipt without a readable stored file,
// or why <n>.v2.aes was not used (a verification failure is not logged as a
// missing file).
foreach ([9120 => 990014, 9121 => 990015, 9122 => 990016, 9123 => 990017, 9125 => 990019, 9128 => 990022] as $id => $fileNumber) {
    $response = assert_receipt_log(
        $adminCookies,
        $id,
        ["pgacc: receipt $fileNumber: no readable stored file"],
        [],
        "log of voucher $id",
    );
    assert_status($response, 500, "voucher $id on the second server");
}
$wrongKey = 'could not be decrypted (wrong key or modified file)';
$logCases = [
    9117 => [["pgacc: receipt 990012.v2.aes $wrongKey; sent the legacy 990012.aes"], ['no readable', 'opened'],
        'log of a v2 key mismatch'],
    9108 => [["pgacc: receipt 990005.v2.aes $wrongKey; no readable 990005.aes"], ['no readable stored file', 'sent the legacy'],
        'log of a v2 receipt with another key'],
    9126 => [['pgacc: receipt 990020.v2.aes could not be opened; sent the legacy 990020.aes'], ['wrong key'],
        'log of a directory at the v2 name beside a legacy file'],
    9127 => [['pgacc: receipt 990021.v2.aes could not be opened; no readable 990021.aes'], ['wrong key'],
        'log of a directory at the v2 name'],
    9124 => [[], ['pgacc: receipt'], 'log of a one-block legacy receipt'],
    9101 => [[], ['pgacc: receipt'], 'log of a v2 download'],
];
foreach ($logCases as $id => $logCase) {
    assert_receipt_log($adminCookies, $id, $logCase[0], $logCase[1], $logCase[2]);
}

// Files the server cannot open: only on the second server, which runs as this
// script's user, and only without root rights (which ignore the mode). An
// unreadable <n>.aes is refused; an unreadable <n>.v2.aes is logged as such,
// not as a wrong key, and the readable <n>.aes is sent. The modes are restored
// even if an assertion fails.
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    $modes = [];
    foreach (["$filesDirectory/990001.aes", "$filesDirectory/990012.v2.aes"] as $name) {
        $modes[$name] = fileperms($name) & 07777;
    }
    $restore = function () use ($modes) {
        foreach ($modes as $name => $mode) {
            @chmod($name, $mode);
        }
    };
    register_shutdown_function($restore);
    try {
        chmod("$filesDirectory/990001.aes", 0);
        $response = assert_receipt_log(
            $adminCookies,
            9104,
            ['pgacc: receipt 990001: no readable stored file'],
            [],
            'log of an unreadable legacy file',
        );
        assert_status($response, 500, 'unreadable legacy file');
        chmod("$filesDirectory/990012.v2.aes", 0);
        $response = assert_receipt_log(
            $adminCookies,
            9117,
            ['pgacc: receipt 990012.v2.aes could not be opened; sent the legacy 990012.aes'],
            ['wrong key'],
            'log of an unreadable v2 file',
        );
        assert_status($response, 200, 'unreadable v2 file beside a legacy file');
    } finally {
        $restore();
    }
}

// UPGRADING.md and the runbook in tests/COMPATIBILITY_PLAN.md tell operators
// to grep the log for downloads that fell back to <n>.aes before
// --delete-legacy: both give the same pattern, and it finds every fallback
// line above (each reason) and no other receipt line.
$patterns = [];
foreach (['UPGRADING.md', 'tests/COMPATIBILITY_PLAN.md'] as $document) {
    $text = preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__) . '/' . $document));
    if (preg_match_all("/`grep '(pgacc: receipt [^']*)'/", $text, $found) !== 1) {
        fail_test("$document should give exactly one grep pattern for the receipt fallback log line");
    }
    $patterns[$document] = $found[1][0];
}
if (count(array_unique($patterns)) !== 1) {
    fail_test('the documented grep patterns differ: ' . var_export($patterns, true));
}
$pattern = '/' . str_replace('/', '\/', $patterns['UPGRADING.md']) . '/';
$fallbackLines = [
    "pgacc: receipt 990012.v2.aes $wrongKey; sent the legacy 990012.aes",
    'pgacc: receipt 990020.v2.aes could not be opened; sent the legacy 990020.aes',
];
// The reason without a configured key, as receipt_v2_open() gives it.
$GLOBALS['key'] = 'CHANGE-ME';
list(, $noKey) = receipt_v2_open("$filesDirectory/990012.v2.aes", '990012');
unset($GLOBALS['key']);
$fallbackLines[] = "pgacc: receipt 990012.v2.aes $noKey; sent the legacy 990012.aes";
foreach ($fallbackLines as $line) {
    if (preg_match($pattern, $line) !== 1) {
        fail_test("the documented grep pattern $pattern does not find " . var_export($line, true));
    }
}
foreach (['pgacc: receipt 990005.v2.aes ' . $wrongKey . '; no readable 990005.aes',
    'pgacc: receipt 990014: no readable stored file'] as $line) {
    if (preg_match($pattern, $line) === 1) {
        fail_test("the documented grep pattern $pattern also finds " . var_export($line, true));
    }
}
// The fallback lines checked above are the ones the server writes.
list(, $log) = receipt_download_log($adminCookies, 9126);
if (strpos($log, $fallbackLines[1]) === false || preg_match($pattern, $log) !== 1) {
    fail_test('the fallback log line changed: ' . var_export($log, true));
}

// Known bug KB-RECEIPT-LEGACY-MERGE (tests/COMPATIBILITY_PLAN.md), not
// changed: a legacy <n>.aes is keyed on the voucher ID, so after a merge the
// download decrypts it with the new ID and sends unreadable bytes.
insert_receipt_voucher($database, 9115, 4742, 'Synthetic legacy receipt before a merge');
insert_receipt_voucher($database, 9116, 4743, 'Synthetic legacy merge target');
receipts_query($database, 'UPDATE vouchers SET file = 990011 WHERE id = 9115', [], 'file of voucher 9115');
file_put_contents("$filesDirectory/990011.aes", receipt_encrypt('4742', '', $pdf));
assert_receipt_download($adminCookies, 9115, rijndael256_zero_pad($pdf), 'application/pdf', 990011, 'legacy receipt before a merge', false);
merge_vouchers($adminCookies, 4743, 4742);
$merged = http_request('/index.php?action=file&id=9115', 'GET', [], $adminCookies);
assert_status($merged, 200, 'legacy receipt after a merge');
if ($merged['body'] !== receipt_decrypt('4743', '', receipt_encrypt('4742', '', $pdf))
    || response_header($merged, 'Content-Type') !== 'image/jpeg') {
    fail_test('KB-RECEIPT-LEGACY-MERGE changed: the legacy receipt after a merge now downloads as '
        . var_export(response_header($merged, 'Content-Type'), true) . '; update tests/COMPATIBILITY_PLAN.md');
}

// Bank-statement PDF import (import2.php): matched by reference and date.
// A one-page PDF statement with the content stream $stream.
function pdf_statement($stream)
{
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $statement = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($statement);
        $statement .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($statement);
    $statement .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $statement .= sprintf("%010d 00000 n \n", $offset);
    }
    return $statement . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

$statement = pdf_statement('BT /F1 12 Tf 72 712 Td (Buchung AB/123456789 vom 15.09.2024) Tj ET');

$import = http_request(
    '/index.php?action=import2',
    'POST',
    [],
    $adminCookies,
    null,
    ['upload[]' => ['name' => 'statement.pdf', 'type' => 'application/pdf', 'content' => $statement]],
);
assert_status($import, 200, 'PDF import');
assert_contains($import['body'], 'Eine ID (AB/123456789) gefunden.', 'PDF import');
assert_contains($import['body'], 'Ein Datum (2024-09-15) gefunden.', 'PDF import');
assert_contains($import['body'], 'done: <a href="index.php?action=edit&id=4712&bid=9103">4712</a>', 'PDF import');
$fileNumber = receipt_file_number($database, 9103);
assert_stored_receipt($fileNumber, $testKey, $statement, 'PDF import');
assert_receipt_download($adminCookies, 9103, $statement, 'application/pdf', $fileNumber, 'imported PDF download', true);

// Several files in one import: pdfparser 2.x writes a tab (0.9 a space) for
// a wide gap within a line, which must not hide the cover-page text or split
// an ID; a file that is no readable PDF is reported and the next one is
// still imported.
insert_receipt_voucher($database, 9119, 4744, 'Synthetic bank import CD/987654321');
$cover = pdf_statement('BT /F1 12 Tf 72 712 Td (Neuer Kontostand) Tj 300 0 Td (zu Ihren Gunsten) Tj ET');
$split = pdf_statement('BT /F1 12 Tf 72 712 Td (Buchung CD/) Tj 300 0 Td (987654321 vom 15.09.2024) Tj ET');
$import = http_request(
    '/index.php?action=import2',
    'POST',
    [],
    $adminCookies,
    null,
    [
        'upload[0]' => ['name' => 'cover.pdf', 'type' => 'application/pdf', 'content' => $cover],
        'upload[1]' => ['name' => 'corrupt.pdf', 'type' => 'application/pdf', 'content' => "%PDF-1.4\nno objects, no xref\n"],
        'upload[2]' => ['name' => 'split.pdf', 'type' => 'application/pdf', 'content' => $split],
    ],
);
assert_status($import, 200, 'PDF import of several files');
assert_contains($import['body'], 'Datei 1 (cover.pdf):<br />Ist ein Übersichtsdeckblatt.<br />Keine Jahreszahl gefunden.<br />'
    . 'Datei 2 (corrupt.pdf):<br />Konnte nicht als PDF gelesen werden und wurde daher nicht importiert.<br />'
    . 'Datei 3 (split.pdf):<br />Eine ID (CD/987654321) gefunden.<br />Ein Datum (2024-09-15) gefunden.<br />', 'PDF import of several files');
assert_contains($import['body'], 'done: <a href="index.php?action=edit&id=4744&bid=9119">4744</a>', 'PDF import of several files');
$fileNumber = receipt_file_number($database, 9119);
assert_stored_receipt($fileNumber, $testKey, $split, 'PDF import of several files');

// Files that are not imported: an empty file field, an upload error (here
// the posted MAX_FILE_SIZE), a file below 10 bytes, a statement without an
// ID, without a date or with more than two dates. Each one is reported and
// the next file is still checked.
function import2_request($cookies, $files, $form = [])
{
    $response = http_request('/index.php?action=import2', 'POST', $form, $cookies, null, $files);
    assert_status($response, 200, 'PDF import');
    return $response;
}

function import2_text_pdf($lines)
{
    $stream = 'BT /F1 12 Tf 72 712 Td';
    foreach ($lines as $index => $line) {
        $stream .= ($index === 0 ? '' : ' 0 -14 Td') . ' (' . $line . ') Tj';
    }
    return pdf_statement($stream . ' ET');
}

$rejected = import2_request($adminCookies, [
    'upload[0]' => ['name' => '', 'type' => 'application/pdf', 'content' => ''],
    'upload[1]' => ['name' => 'tiny.pdf', 'type' => 'application/pdf', 'content' => '%PDF-1'],
    'upload[2]' => ['name' => 'noid.pdf', 'type' => 'application/pdf', 'content' => import2_text_pdf(['Synthetic Bank Information', 'Keine Buchung'])],
    'upload[3]' => ['name' => 'nodate.pdf', 'type' => 'application/pdf', 'content' => import2_text_pdf(['Buchung GH/123456789', 'ohne Datum'])],
    'upload[4]' => ['name' => 'dates.pdf', 'type' => 'application/pdf',
        'content' => import2_text_pdf(['Beleg GH/123456789', 'von 01.01.2024', 'bis 02.01.2024', 'am 03.01.2024'])],
]);
assert_contains($rejected['body'], 'Datei 1 ():<br />Datei 2 (tiny.pdf):<br />Iist zu klein und konnte daher nicht importiert werden.<br />'
    . 'Datei 3 (noid.pdf):<br />Keine IDs gefunden.<br />'
    . 'Datei 4 (nodate.pdf):<br />Eine ID (GH/123456789) gefunden.<br />Kein Datum gefunden.<br />'
    . 'Datei 5 (dates.pdf):<br />Eine ID (GH/123456789) gefunden.<br />Zuviele Daten gefunden gefunden.<br />', 'PDF import of rejected files');
$tooLarge = import2_request(
    $adminCookies,
    ['upload[]' => ['name' => 'large.pdf', 'type' => 'application/pdf', 'content' => $statement]],
    ['MAX_FILE_SIZE' => '20'],
);
// PHP reports an upload error with an empty type, refused as a file format
// (the upload error message itself: tests/unit/ReceiptImportTest.php).
assert_contains($tooLarge['body'], 'Datei 1 (large.pdf):<br />Hat ein ungültiges Dateiformat () und konnte daher nicht importiert werden.<br />', 'PDF import over MAX_FILE_SIZE');

// A matching voucher is required exactly once, without a receipt.
insert_receipt_voucher($database, 9130, 4760, 'Synthetic bank import twice JK/111111111');
insert_receipt_voucher($database, 9131, 4761, 'Synthetic bank import twice JK/111111111 again');
insert_receipt_voucher($database, 9132, 4762, 'Synthetic bank import with a receipt JK/222222222');
receipts_query($database, 'UPDATE vouchers SET file = 77 WHERE id = 9132', [], 'receipt of voucher 9132');
$matches = import2_request($adminCookies, [
    'upload[0]' => ['name' => 'twice.pdf', 'type' => 'application/pdf', 'content' => import2_text_pdf(['Buchung JK/111111111 vom 15.09.2024'])],
    'upload[1]' => ['name' => 'attached.pdf', 'type' => 'application/pdf', 'content' => import2_text_pdf(['Buchung JK/222222222 vom 15.09.2024'])],
    'upload[2]' => ['name' => 'unknown.pdf', 'type' => 'application/pdf', 'content' => import2_text_pdf(['Buchung JK/333333333 vom 15.09.2024'])],
]);
assert_contains($matches['body'], 'Datei 1 (twice.pdf):<br />Eine ID (JK/111111111) gefunden.<br />Ein Datum (2024-09-15) gefunden.<br />Zuviele Buchungen gefunden.<br />'
    . 'Datei 2 (attached.pdf):<br />Eine ID (JK/222222222) gefunden.<br />Ein Datum (2024-09-15) gefunden.<br />Has file attached.<br />'
    . 'Datei 3 (unknown.pdf):<br />Eine ID (JK/333333333) gefunden.<br />Ein Datum (2024-09-15) gefunden.<br />Keine Buchung gefunden.<br />', 'PDF import matches');
$unchanged = receipts_query($database, 'SELECT id, file FROM vouchers WHERE id IN (9130, 9131, 9132) ORDER BY id', [], 'vouchers after the PDF import matches');
if ($unchanged !== [['id' => '9130', 'file' => '0'], ['id' => '9131', 'file' => '0'], ['id' => '9132', 'file' => '77']]) {
    fail_test('PDF import matches changed receipts: ' . var_export($unchanged, true));
}

// Several IDs and statement cover pages are matched by date and ID (interest,
// "ZinsenHABEN") and by date and IBAN (account fees, "Entgelt für
// Kontoführung" or "... Buchungsposten und Belege"). A file with several IDs
// and no year, a cover page without IBAN, and one without such lines are
// reported.
receipts_query(
    $database,
    "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, committed,
                           date, person_type, file, save_user, vaccount)
     VALUES (9133, 4763, 28, 2, false, -50, 'AT606000050110117300', 'Zinsen LM/444444444', true,
             timestamp '2024-03-31 00:00:00', 1, 0, 1, ''),
            (9134, 4764, 28, 2, false, -700, 'AT606000050110117300', 'Entgelt für Kontoführung', true,
             timestamp '2024-06-30 00:00:00', 1, 0, 1, '')",
    [],
    'cover page vouchers',
);
$covers = import2_request($adminCookies, [
    'upload[0]' => ['name' => 'ids.pdf', 'type' => 'application/pdf', 'content' => import2_text_pdf(['Buchungen NP/555555555 und NP/666666666'])],
    'upload[1]' => ['name' => 'ids-year.pdf', 'type' => 'application/pdf',
        'content' => import2_text_pdf(['Buchungen NP/555555555 und NP/666666666', 'Auszug vom 15.09.2024'])],
    'upload[2]' => ['name' => 'cover-empty.pdf', 'type' => 'application/pdf', 'content' => import2_text_pdf([
        'Kontoauszug vom 31.03.2024', 'IBAN AT606000050110117300', 'Neuer Kontostand zu Ihren Gunsten 1.000,00'])],
    'upload[3]' => ['name' => 'cover.pdf', 'type' => 'application/pdf', 'content' => import2_text_pdf([
        'Kontoauszug vom 31.03.2024', 'IBAN AT606000050110117300', '31.03 Zinsen HABEN LM/444444444',
        '30.06 Entgeltfuer Kontofuehrung', 'Neuer Kontostand zu Ihren Gunsten 1.000,00'])],
]);
assert_contains($covers['body'], 'Datei 1 (ids.pdf):<br />Mehrere IDs gefunden.<br />Keine Jahreszahl gefunden.<br />'
    . 'Datei 2 (ids-year.pdf):<br />Mehrere IDs gefunden.<br />Kein IBAN gefunden.<br />'
    . 'Datei 3 (cover-empty.pdf):<br />Ist ein Übersichtsdeckblatt.<br />Keine IDs and Daten gefunden für Spezialfälle.<br />Keine IDs and Daten gefunden für Spezialfälle.<br />'
    . 'Datei 4 (cover.pdf):<br />Ist ein Übersichtsdeckblatt.<br />'
    . 'done: <a href="index.php?action=edit&id=4763&bid=9133">4763</a><br />'
    . 'done: <a href="index.php?action=edit&id=4764&bid=9134">4764</a><br />Keine Buchung gefunden.<br />', 'PDF import of cover pages');
assert_stored_receipt(receipt_file_number($database, 9133), $testKey, import2_text_pdf([
    'Kontoauszug vom 31.03.2024', 'IBAN AT606000050110117300', '31.03 Zinsen HABEN LM/444444444',
    '30.06 Entgeltfuer Kontofuehrung', 'Neuer Kontostand zu Ihren Gunsten 1.000,00']), 'PDF import of a cover page');

// The receipt key migration is a command-line script outside the document
// root; a web server serving the release root must not run it either.
$migration = http_request('/migrate-receipt-key.php', 'GET', [], [], start_release_root_server(8086));
assert_status($migration, 404, 'migration script over HTTP');
if ($migration['body'] !== '') {
    fail_test('migration script over HTTP answered ' . var_export($migration['body'], true));
}

// Genuine legacy files of the original code (tests/fixtures/legacy-php53),
// stored under new file numbers: the legacy download sends what the original
// download sent; after the migration (in a separate directory, the new
// <n>.v2.aes then copied next to the legacy file) the same bytes, PDF and
// JPEG as before, PNG as image/png and an empty file as a download.
$fixture = dirname(__DIR__) . '/tests/fixtures/legacy-php53';
$originalDownloads = [];
foreach (file("$fixture/downloads.txt", FILE_IGNORE_NEW_LINES) as $line) {
    if ($line[0] !== '#') {
        [$name, $type, , $sha256] = explode(' ', $line);
        $originalDownloads[$name] = [$type, $sha256];
    }
}
// Fixture file => [voucher ID now, type after the migration].
$fixtureCases = [1 => [701, 'application/pdf'], 3 => [703, 'image/jpeg'], 7 => [707, 'application/octet-stream'],
    9 => [709, 'image/png'], 10 => [710, 'image/jpeg'], 11 => [799, 'application/pdf']];
$migrationFiles = sys_get_temp_dir() . '/pgacc-http-migration-' . getmypid();
mkdir($migrationFiles);
foreach ($fixtureCases as $case => [$voucherId, $type]) {
    $id = 9600 + $case;
    $fileNumber = 990100 + $case;
    insert_receipt_voucher($database, $id, $voucherId, "Synthetic legacy fixture $case");
    receipts_query($database, 'UPDATE vouchers SET file = $1 WHERE id = $2', [$fileNumber, $id], "fixture $case");
    copy("$fixture/files/$case.aes", receipt_files_directory() . "/$fileNumber.aes");
    copy("$fixture/files/$case.aes", "$migrationFiles/$fileNumber.aes");
    $response = http_request("/index.php?action=file&id=$id", 'GET', [], $adminCookies);
    assert_status($response, 200, "legacy fixture $case");
    if ([response_header($response, 'Content-Type'), hash('sha256', $response['body'])] !== $originalDownloads[$case]) {
        fail_test("legacy fixture $case does not download as the original code sent it");
    }
}
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/migrate-receipt-key.php') . ' --include-unusual --search-voucher-ids'
    . ' --key-fingerprint=' . receipt_key_fingerprint($testKey) . ' --files=' . escapeshellarg($migrationFiles)
    . ' --constants=' . escapeshellarg(__DIR__ . '/runtime/test-constants.php')
    . ' --database=' . escapeshellarg(pgacc_test_connection_string()) . ' 2>&1', $migrationOutput, $migrationStatus);
if ($migrationStatus !== 0) {
    fail_test("fixture migration exited with $migrationStatus: " . implode("\n", $migrationOutput));
}
foreach ($fixtureCases as $case => [$voucherId, $type]) {
    $fileNumber = 990100 + $case;
    copy("$migrationFiles/$fileNumber.v2.aes", receipt_files_directory() . "/$fileNumber.v2.aes");
    $original = $originalDownloads[$case === 11 ? '11-before-merge' : $case];
    $response = http_request('/index.php?action=file&id=' . (9600 + $case), 'GET', [], $adminCookies);
    assert_status($response, 200, "migrated fixture $case");
    if (hash('sha256', $response['body']) !== $original[1] || response_header($response, 'Content-Type') !== $type
        || response_header($response, 'X-Content-Type-Options') !== 'nosniff') {
        fail_test("migrated fixture $case does not download as expected");
    }
}
array_map('unlink', array_merge(glob("$migrationFiles/*"), ["$migrationFiles/.migrate-receipt-key.lock"]));
rmdir($migrationFiles);

// Membership export (mbapi.php): members whose acknowledged membership fees
// since October of the previous year reach 20.00, as "<SHA-1>\n<base64>".
$year = date('Y');
$fees = [
    [990001, 2500, "$year-01-02", 'reviewer-a', false],
    [990002, 1000, "$year-01-02", 'reviewer-a', false],
    [990002, 1000, ($year - 1) . '-10-01', 'reviewer-a', false],
    [990003, 1999, "$year-01-02", 'reviewer-a', false],
    [990004, 5000, ($year - 1) . '-09-30', 'reviewer-a', false],
    [990005, 5000, "$year-01-02", null, false],
    [990006, 5000, "$year-01-02", 'reviewer-a', true],
];
foreach ($fees as $index => $fee) {
    receipts_query(
        $database,
        "INSERT INTO vouchers (id, voucher_id, type, orga, member, member_id, amount, comment, date, ack1, deleted)
         VALUES ($1, $2, 1, 1, true, $3, $4, 'Synthetic membership fee', $5, $6, $7)",
        [9200 + $index, 9200 + $index, $fee[0], $fee[1], $fee[2], $fee[3], $fee[4] ? 't' : 'f'],
        "membership fee $index",
    );
}
$export = http_request('/mbapi.php');
assert_status($export, 200, 'membership export');
$text = "990001\t2500\t$year-12-31\n990002\t2000\t$year-12-31\n";
$lines = explode("\n", $export['body']);
if (count($lines) !== 2 || $lines[0] !== sha1($text)) {
    fail_test('membership export framing: ' . var_export($export['body'], true));
}
$ciphertext = base64_decode($lines[1], true);
if ($ciphertext === false || strlen($ciphertext) !== 64) {
    fail_test('membership export payload is not 64 bytes of base64: ' . var_export($lines[1], true));
}
// The synthetic $datakey of tests/runtime/test-constants.php.
[$cipherKey, $iv] = membership_cipher_params('synthetic-reference-membership-key');
if (rijndael256_decrypt($cipherKey, $ciphertext, $iv) !== str_pad($text, 64, "\0")) {
    fail_test('membership export payload does not decrypt to the expected members');
}

pg_close($database);
assert_no_unexpected_diagnostics();
echo "Receipt and membership export tests passed\n";
