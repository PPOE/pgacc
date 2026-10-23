<?php
/*
 * Encrypted receipts and the membership export over HTTP: receipt upload
 * (new.php), PDF import (import2.php), receipt download (file.php) and
 * mbapi.php. The byte-exact mcrypt compatibility of the encryption itself is
 * pinned by tests/unit/CryptoTest.php; this checks the call sites.
 *
 * Must stay compatible with PHP 5.6.
 */
require __DIR__ . '/http-test-helpers.php';
// The membership export is decrypted with the application's helper.
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/functions.php';

function receipts_database()
{
    $database = @pg_connect(sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s',
        getenv('PGHOST'),
        getenv('PGPORT'),
        getenv('PGDATABASE'),
        getenv('PGUSER'),
        getenv('PGPASSWORD')
    ));
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
    $padded = str_pad($plain, intval(ceil(strlen($plain) / 32)) * 32, "\0");
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

$vectors = json_decode(file_get_contents(__DIR__ . '/fixtures/mcrypt/vectors.json'), true);
$receiptVectors = array();
foreach ($vectors['receipts'] as $vector) {
    $receiptVectors[$vector['name']] = $vector;
}
$pdf = hex2bin($receiptVectors['pdf document']['plain']);
$jpeg = hex2bin($receiptVectors['jpeg with trailing NUL bytes']['plain']);

$database = receipts_database();
$adminCookies = login_user('synthetic-admin', 'correct-password');
insert_receipt_voucher($database, 9101, 4711, 'Synthetic PDF receipt upload');
insert_receipt_voucher($database, 9102, 815, 'Synthetic JPEG receipt upload');
insert_receipt_voucher($database, 9103, 4712, 'Synthetic bank import AB/123456789');

// Receipt upload on the voucher edit page (new.php page_new_file()).
$uploads = array(
    array(9101, 4711, 'receipt.pdf', 'application/pdf', $pdf),
    array(9102, 815, 'receipt.jpg', 'image/jpeg', $jpeg),
);
foreach ($uploads as $upload) {
    list($id, $voucherId, $name, $type, $content) = $upload;
    $response = http_request(
        '/index.php?action=edit&id=' . $voucherId,
        'POST',
        array('fileupload' => 'PDF Hochladen (OHNE SPEICHERN)', 'parts' => '1', 'bid0' => strval($id)),
        $adminCookies,
        null,
        array('file0' => array('name' => $name, 'type' => $type, 'content' => $content))
    );
    assert_contains($response['body'], "Upload erfolgreich - Buchung Nr. $voucherId - Buchungszeile Nr. $id", "$name upload");
    assert_no_fatal_error($response['body'], "$name upload");
    $fileNumber = receipt_file_number($database, $id);
    assert_receipt_download($adminCookies, $id, $content, $type, $fileNumber, "$name download");
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
    assert_receipt_download($adminCookies, 9103, $statement, 'application/pdf', $fileNumber, 'imported PDF download');
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
// tests/runtime/test-constants.php sets an empty $datakey.
if (membership_decrypt('', $ciphertext) !== str_pad($text, 64, "\0")) {
    fail_test('membership export payload does not decrypt to the expected members');
}

pg_close($database);
assert_no_unexpected_diagnostics();
echo "Receipt and membership export tests passed\n";
