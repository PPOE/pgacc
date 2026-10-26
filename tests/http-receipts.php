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
// read (the application service's log is not shared with the test).
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
        $png, 'application/octet-stream', 'v2 receipt of another type'],
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
    // A damaged <n>.aes that is not whole blocks (no stored file is) decrypts
    // zero-padded; Content-Length is what is sent, not the file size.
    [9125, 4711, 990019, ['aes' => substr(hex2bin($receiptVectors['pdf document']['cipher']), 0, 95)],
        receipt_decrypt('4711', '', substr(hex2bin($receiptVectors['pdf document']['cipher']), 0, 95)), 'application/pdf',
        'legacy file that is not whole blocks'],
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
foreach ([9120 => 990014, 9121 => 990015, 9122 => 990016, 9123 => 990017] as $id => $fileNumber) {
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
    $unreadable = ["$filesDirectory/990001.aes", "$filesDirectory/990012.v2.aes"];
    $restore = function () use ($unreadable) {
        foreach ($unreadable as $name) {
            @chmod($name, 0644);
        }
    };
    register_shutdown_function($restore);
    try {
        chmod($unreadable[0], 0);
        $response = assert_receipt_log(
            $adminCookies,
            9104,
            ['pgacc: receipt 990001: no readable stored file'],
            [],
            'log of an unreadable legacy file',
        );
        assert_status($response, 500, 'unreadable legacy file');
        chmod($unreadable[1], 0);
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

// The receipt key migration is a command-line script; the web server must
// not run it.
$migration = http_request('/migrate-receipt-key.php');
assert_status($migration, 404, 'migration script over HTTP');
if ($migration['body'] !== '') {
    fail_test('migration script over HTTP answered ' . var_export($migration['body'], true));
}

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
if (membership_decrypt('synthetic-reference-membership-key', $ciphertext) !== str_pad($text, 64, "\0")) {
    fail_test('membership export payload does not decrypt to the expected members');
}

pg_close($database);
assert_no_unexpected_diagnostics();
echo "Receipt and membership export tests passed\n";
