<?php
/*
 * Release check of the locked runtime dependencies on PHP 8.5, run by
 * scripts/release.sh on the built vendor/ (mounted at /app/vendor of a plain
 * php:8.5-cli container). Proves that the autoloader loads without
 * diagnostics and that phpseclib (the mcrypt replacement in functions.php)
 * reproduces the mcrypt contract vectors through the application's helpers.
 *
 * smalot/pdfparser (import2.php) and its dependency TCPDF are not loaded: the
 * locked pdfparser v0.9.23 declares a class named Object (a fatal error since
 * PHP 7.2) and TCPDF 6.2.6 does not parse on PHP 8 (curly-brace string
 * offsets). They are replaced in a later step (tests/COMPATIBILITY_PLAN.md,
 * "PHP 8.5-only migration", step b), which adds a PDF round trip here.
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
echo 'vendor check passed on PHP ' . PHP_VERSION . "\n";
