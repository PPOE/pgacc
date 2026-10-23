<?php
/*
 * Build-time check of the locked Composer dependencies on PHP 5.3 (run by
 * tests/php53/runtime.Dockerfile). Composer itself cannot install on the
 * squeeze image (no TLS 1.2), so vendor/ comes from a separate build stage;
 * this proves that the copied code loads and works on this runtime.
 *
 * Round trip: TCPDF (pdfparser's locked dependency) writes a synthetic PDF,
 * smalot/pdfparser (used by import2.php) parses it back.
 *
 * Must stay compatible with PHP 5.3.
 */

error_reporting(-1);
ini_set('display_errors', 'stderr');
set_error_handler('pgacc_vendor_check_error');

function pgacc_vendor_check_error($type, $message, $file, $line)
{
    if ((error_reporting() & $type) === 0) {
        return false;
    }
    fwrite(STDERR, "vendor check: PHP diagnostic $type: $message in $file:$line\n");
    exit(1);
}

function pgacc_vendor_check_fail($message)
{
    fwrite(STDERR, "vendor check failed: $message\n");
    exit(1);
}

require dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php';

$installed = json_decode(file_get_contents(dirname(dirname(dirname(__FILE__))) . '/vendor/composer/installed.json'), true);
$packages = isset($installed['packages']) ? $installed['packages'] : $installed;
foreach ($packages as $package) {
    echo 'vendor: ' . $package['name'] . ' ' . $package['version'] . "\n";
}

$pdf = new TCPDF();
$pdf->SetCreator('pgacc test');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$pdf->Write(0, 'Synthetic pgacc PHP 5.3 check');
$document = $pdf->Output('check.pdf', 'S');
if (strpos($document, '%PDF-') !== 0) {
    pgacc_vendor_check_fail('TCPDF did not produce a PDF');
}

$parser = new \Smalot\PdfParser\Parser();
$text = $parser->parseContent($document)->getText();
if (strpos(str_replace(' ', '', $text), 'SyntheticpgaccPHP5.3check') === false) {
    pgacc_vendor_check_fail('pdfparser did not read the text back: ' . var_export($text, true));
}

// phpseclib is not locked today; check it too once composer.lock adds it.
if (class_exists('phpseclib\Crypt\AES')) {
    $aes = new \phpseclib\Crypt\AES();
    $aes->setKey(str_repeat('k', 16));
    if ($aes->decrypt($aes->encrypt('synthetic')) !== 'synthetic') {
        pgacc_vendor_check_fail('phpseclib AES round trip failed');
    }
    echo "vendor: phpseclib AES round trip ok\n";
}

echo 'vendor check passed on PHP ' . PHP_VERSION . "\n";
