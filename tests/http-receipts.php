<?php
/*
 * Encrypted receipts and the membership export over HTTP: receipt upload
 * (new.php), PDF import (import2.php), receipt download (file.php) and
 * mbapi.php. The byte-exact mcrypt compatibility of the encryption itself is
 * pinned by tests/unit/CryptoTest.php; this checks the call sites and the
 * stored files: new receipts are files/<n>.v2.aes with the configured key,
 * legacy files/<n>.aes (empty key part) stay readable, and <n>.v2.aes wins.
 * The test reads and writes the server's files/ directory: a volume shared
 * with the server in compose.test.yaml, the copy's files/ in the mutation
 * runner (PGACC_TEST_APP_ROOT).
 *
 * Must stay compatible with PHP 5.6.
 */
require __DIR__ . '/http-test-helpers.php';
// The membership export is decrypted, and downloads padded, with the
// application's helpers (pinned by the mcrypt vectors).
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/functions.php';

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
    return $rows === false ? array() : $rows;
}

function insert_receipt_voucher($database, $id, $voucherId, $comment)
{
    receipts_query(
        $database,
        "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, committed,
                               date, person_type, file, save_user, vaccount)
         VALUES ($1, $2, 20, 2, false, -1000, 'TEST-ACCOUNT-2', $3, true,
                 timestamp '2024-09-15 00:00:00', 1, 0, 1, 'TEST-VIRTUAL-2')",
        array($id, $voucherId, $comment),
        "voucher $id"
    );
}

function receipt_file_number($database, $id)
{
    $rows = receipts_query($database, 'SELECT file FROM vouchers WHERE id = $1', array($id), "file of voucher $id");
    if (count($rows) !== 1 || intval($rows[0]['file']) <= 0) {
        fail_test("voucher $id has no receipt file after the upload");
    }
    return $rows[0]['file'];
}

// The body is the zero-padded plain text; Content-Length is the size of the
// stored ciphertext, which mcrypt also padded to whole 32-byte blocks.
function assert_receipt_download($cookies, $id, $plain, $contentType, $fileNumber, $label)
{
    $response = http_request('/index.php?action=file&id=' . $id, 'GET', array(), $cookies);
    assert_status($response, 200, $label);
    $padded = rijndael256_zero_pad($plain);
    if ($response['body'] !== $padded) {
        fail_test("$label returned " . strlen($response['body']) . ' bytes that differ from the uploaded receipt');
    }
    $headers = array(
        'Content-Type' => $contentType,
        'Content-Length' => strval(strlen($padded)),
        'Content-Transfer-Encoding' => 'binary',
        'Accept-Ranges' => 'bytes',
        'Content-Disposition' => $contentType === 'application/pdf' ? "inline; filename=\"$fileNumber.pdf\"" : null,
    );
    foreach ($headers as $name => $expected) {
        $actual = response_header($response, $name);
        if ($actual !== $expected) {
            fail_test("$label header $name was " . var_export($actual, true) . ', expected ' . var_export($expected, true));
        }
    }
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
    $names = array();
    foreach (scandir(receipt_files_directory()) as $name) {
        if (strpos($name, "$fileNumber.") === 0) {
            $names[] = $name;
        }
    }
    sort($names);
    return $names;
}

function assert_stored_receipt($fileNumber, $cipherHex, $label)
{
    $names = stored_receipt_files($fileNumber);
    if ($names !== array("$fileNumber.v2.aes")) {
        fail_test("$label stored " . var_export($names, true) . ", expected $fileNumber.v2.aes only");
    }
    $stored = bin2hex(file_get_contents(receipt_files_directory() . "/$fileNumber.v2.aes"));
    if ($stored !== $cipherHex) {
        fail_test("$label: $fileNumber.v2.aes is not the configured-key ciphertext");
    }
}

$vectors = json_decode(file_get_contents(__DIR__ . '/fixtures/mcrypt/vectors.json'), true);
$receiptVectors = array();
foreach ($vectors['receipts'] as $vector) {
    $receiptVectors[$vector['name']] = $vector;
}
$pdf = hex2bin($receiptVectors['pdf document']['plain']);
$jpeg = hex2bin($receiptVectors['jpeg with trailing NUL bytes']['plain']);
// tests/runtime/test-constants.php sets this synthetic $key.
$testKey = 'synthetic-test-only-encryption-key';
$pdfV2 = $receiptVectors['pdf document, test-constants key'];
$jpegV2 = $receiptVectors['jpeg with trailing NUL bytes, test-constants key'];
if ($pdfV2['key'] !== $testKey || $jpegV2['key'] !== $testKey
    || hex2bin($pdfV2['plain']) !== $pdf || hex2bin($jpegV2['plain']) !== $jpeg) {
    fail_test('the test-constants key vectors do not match the receipts uploaded here');
}

$database = receipts_database();
$adminCookies = login_user('synthetic-admin', 'correct-password');
insert_receipt_voucher($database, 9101, 4711, 'Synthetic PDF receipt upload');
insert_receipt_voucher($database, 9102, 815, 'Synthetic JPEG receipt upload');
insert_receipt_voucher($database, 9103, 4712, 'Synthetic bank import AB/123456789');

// Receipt upload on the voucher edit page (new.php page_new_file()).
$uploads = array(
    array(9101, 4711, 'receipt.pdf', 'application/pdf', $pdf, $pdfV2['cipher']),
    array(9102, 815, 'receipt.jpg', 'image/jpeg', $jpeg, $jpegV2['cipher']),
);
foreach ($uploads as $upload) {
    list($id, $voucherId, $name, $type, $content, $cipherHex) = $upload;
    $response = http_request(
        '/index.php?action=edit&id=' . $voucherId,
        'POST',
        array('fileupload' => 'PDF Hochladen (OHNE SPEICHERN)', 'parts' => '1', 'bid0' => strval($id)),
        $adminCookies,
        null,
        array('file0' => array('name' => $name, 'type' => $type, 'content' => $content))
    );
    // relocate() discards the confirmation page and redirects to the voucher.
    assert_no_problems(
        redirect_problems($response, "index.php?action=edit&id=$voucherId&bid=$id&hint=1"),
        "$name upload"
    );
    $fileNumber = receipt_file_number($database, $id);
    assert_stored_receipt($fileNumber, $cipherHex, "$name upload");
    assert_receipt_download($adminCookies, $id, $content, $type, $fileNumber, "$name download");
}

// Stored receipts with synthetic file numbers above the sequence: the legacy
// format alone, both formats (the configured-key file wins, and
// Content-Length is its size), a legacy-scheme file under the new name (read
// with the logged fallback), and a file that decrypts to neither.
$storedCases = array(
    array(9104, 4711, 990001, array('aes' => $receiptVectors['pdf document']['cipher']),
        $pdf, 'application/pdf', 'legacy receipt download'),
    array(9105, 4711, 990002, array('aes' => $receiptVectors['pdf 33 bytes']['cipher'], 'v2.aes' => $pdfV2['cipher']),
        $pdf, 'application/pdf', 'download with both formats'),
    array(9106, 815, 990003, array('aes' => $receiptVectors['jpeg image']['cipher'], 'v2.aes' => $jpegV2['cipher']),
        $jpeg, 'image/jpeg', 'JPEG download with both formats'),
    array(9107, 4711, 990004, array('v2.aes' => $receiptVectors['pdf document']['cipher']),
        $pdf, 'application/pdf', 'legacy-scheme receipt under the new name'),
    array(9108, 4711, 990005, array('v2.aes' => str_repeat('00', 64)),
        null, null, 'undecryptable receipt'),
);
$filesDirectory = receipt_files_directory();
foreach ($storedCases as $case) {
    list($id, $voucherId, $fileNumber, $files, $content, $type, $label) = $case;
    insert_receipt_voucher($database, $id, $voucherId, "Synthetic stored receipt $fileNumber");
    receipts_query($database, 'UPDATE vouchers SET file = $1 WHERE id = $2', array($fileNumber, $id), "file of voucher $id");
    foreach ($files as $suffix => $bytes) {
        file_put_contents("$filesDirectory/$fileNumber.$suffix", hex2bin($bytes));
    }
    if ($content !== null) {
        assert_receipt_download($adminCookies, $id, $content, $type, $fileNumber, $label);
        continue;
    }
    $response = http_request('/index.php?action=file&id=' . $id, 'GET', array(), $adminCookies);
    assert_status($response, 500, $label);
    if (response_header($response, 'Content-Type') !== 'text/plain; charset=UTF-8'
        || $response['body'] !== 'FEHLER: Der Beleg konnte nicht entschlüsselt werden.') {
        fail_test("$label: " . var_export(response_header($response, 'Content-Type'), true) . ' '
            . var_export($response['body'], true));
    }
}

// Bank-statement PDF import (import2.php): matched by reference and date.
// The locked smalot/pdfparser v0.9.23 declares a class named Object, which is
// a fatal error from PHP 7.2 on, so the import only works on PHP 5.6 for now
// (separate dependency issue; see tests/COMPATIBILITY_PLAN.md).
if (PHP_VERSION_ID < 70200) {
    $stream = "BT /F1 12 Tf 72 712 Td (Buchung AB/123456789 vom 15.09.2024) Tj ET";
    $objects = array(
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    );
    $statement = "%PDF-1.4\n";
    $offsets = array();
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($statement);
        $statement .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($statement);
    $statement .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $statement .= sprintf("%010d 00000 n \n", $offset);
    }
    $statement .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

    $import = http_request(
        '/index.php?action=import2',
        'POST',
        array(),
        $adminCookies,
        null,
        array('upload[]' => array('name' => 'statement.pdf', 'type' => 'application/pdf', 'content' => $statement))
    );
    assert_status($import, 200, 'PDF import');
    assert_contains($import['body'], 'Eine ID (AB/123456789) gefunden.', 'PDF import');
    assert_contains($import['body'], 'Ein Datum (2024-09-15) gefunden.', 'PDF import');
    assert_contains($import['body'], 'done: <a href="index.php?action=edit&id=4712&bid=9103">4712</a>', 'PDF import');
    $fileNumber = receipt_file_number($database, 9103);
    assert_stored_receipt($fileNumber, bin2hex(receipt_encrypt('4712', $testKey, $statement)), 'PDF import');
    assert_receipt_download($adminCookies, 9103, $statement, 'application/pdf', $fileNumber, 'imported PDF download');
}

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
$fees = array(
    array(990001, 2500, "$year-01-02", 'reviewer-a', false),
    array(990002, 1000, "$year-01-02", 'reviewer-a', false),
    array(990002, 1000, ($year - 1) . '-10-01', 'reviewer-a', false),
    array(990003, 1999, "$year-01-02", 'reviewer-a', false),
    array(990004, 5000, ($year - 1) . '-09-30', 'reviewer-a', false),
    array(990005, 5000, "$year-01-02", null, false),
    array(990006, 5000, "$year-01-02", 'reviewer-a', true),
);
foreach ($fees as $index => $fee) {
    receipts_query(
        $database,
        "INSERT INTO vouchers (id, voucher_id, type, orga, member, member_id, amount, comment, date, ack1, deleted)
         VALUES ($1, $2, 1, 1, true, $3, $4, 'Synthetic membership fee', $5, $6, $7)",
        array(9200 + $index, 9200 + $index, $fee[0], $fee[1], $fee[2], $fee[3], $fee[4] ? 't' : 'f'),
        "membership fee $index"
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
