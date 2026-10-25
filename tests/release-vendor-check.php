<?php
/*
 * Release check of the locked runtime dependencies on PHP 8.5, run by
 * scripts/release.sh on the built vendor/ (mounted at /app/vendor of a plain
 * php:8.5-cli container). Proves that the autoloader loads without
 * diagnostics, that phpseclib (the mcrypt replacement in functions.php)
 * reproduces the mcrypt contract vectors through the application's helpers,
 * and that smalot/pdfparser (import2.php) extracts the text of a synthetic
 * PDF statement.
 */

error_reporting(-1);
ini_set('display_errors', 'stderr');
set_error_handler('pgacc_vendor_check_error');

function pgacc_vendor_check_error($type, $message, $file, $line)
{
    fwrite(STDERR, "vendor check: PHP diagnostic $type: $message in $file:$line\n");
    exit(1);
}

function pgacc_vendor_check_fail($message)
{
    fwrite(STDERR, "vendor check failed: $message\n");
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$installed = json_decode(file_get_contents($root . '/vendor/composer/installed.json'), true);
$packages = isset($installed['packages']) ? $installed['packages'] : $installed;
foreach ($packages as $package) {
    echo 'vendor: ' . $package['name'] . ' ' . $package['version'] . "\n";
}

require __DIR__ . '/functions-bootstrap.php';

$vectors = json_decode(file_get_contents(__DIR__ . '/fixtures/mcrypt/vectors.json'), true);
$checked = 0;
foreach ($vectors['receipts'] as $vector) {
    if (bin2hex(receipt_encrypt($vector['id'], $vector['key'], hex2bin($vector['plain']))) !== $vector['cipher']
        || bin2hex(receipt_decrypt($vector['id'], $vector['key'], hex2bin($vector['cipher']))) !== $vector['decrypted']) {
        pgacc_vendor_check_fail("receipt vector '{$vector['name']}' differs");
    }
    $checked++;
}
foreach ($vectors['receipt_decrypts'] as $vector) {
    if (bin2hex(receipt_decrypt($vector['id'], $vector['key'], hex2bin($vector['cipher']))) !== $vector['decrypted']) {
        pgacc_vendor_check_fail("receipt decrypt vector '{$vector['name']}' differs");
    }
    $checked++;
}
foreach ($vectors['mbapi'] as $vector) {
    $text = hex2bin($vector['text']);
    $params = membership_cipher_params($vector['datakey']);
    $output = sha1($text) . "\n"
        . base64_encode(rijndael256_cbc($params[0], $params[1])->encrypt(rijndael256_zero_pad($text)));
    if ($output !== $vector['output']) {
        pgacc_vendor_check_fail("mbapi vector '{$vector['name']}' differs");
    }
    $checked++;
}
foreach ($vectors['getusers'] as $vector) {
    $params = membership_cipher_params($vector['datakey']);
    $plain = rtrim(rijndael256_cbc($params[0], $params[1])->decrypt(
        rijndael256_zero_pad(base64_decode($vector['sData']))), "\0");
    if (bin2hex($plain) !== $vector['decrypted']) {
        pgacc_vendor_check_fail("getusers vector '{$vector['name']}' differs");
    }
    $checked++;
}
echo "vendor: phpseclib: $checked mcrypt contract vectors match\n";

// The calls of import2.php on a one-page statement with one text line.
$stream = 'BT /F1 12 Tf 72 712 Td (Buchung AB/123456789 vom 15.09.2024) Tj ET';
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
$parser = new \Smalot\PdfParser\Parser();
$text = $parser->parseContent($statement)->getText();
if (trim($text) !== 'Buchung AB/123456789 vom 15.09.2024') {
    pgacc_vendor_check_fail('pdfparser text differs: ' . var_export($text, true));
}
echo "vendor: pdfparser: synthetic statement text matches\n";
echo 'vendor check passed on PHP ' . PHP_VERSION . "\n";
