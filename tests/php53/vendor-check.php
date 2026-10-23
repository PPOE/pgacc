<?php
/*
 * Build-time check of the locked Composer dependencies on PHP 5.3 (run by
 * tests/php53/runtime.Dockerfile). Composer itself cannot install on the
 * squeeze image (no TLS 1.2), so vendor/ comes from a separate build stage;
 * this proves that the copied code loads and works on this runtime.
 *
 * Round trip: TCPDF (pdfparser's locked dependency) writes a synthetic PDF,
 * smalot/pdfparser (used by import2.php) parses it back. phpseclib: the
 * mcrypt contract vectors through functions.php, with both engines.
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

// phpseclib 2 (the mcrypt replacement in functions.php): the mcrypt contract
// vectors (tests/fixtures/mcrypt/vectors.json, produced by the real mcrypt
// extension) through the application's helpers, on 32-bit PHP 5.3. With
// php5-mcrypt loaded (as on production) phpseclib picks the mcrypt engine;
// its pure-PHP engine is checked too, which is what runs without mcrypt.
require dirname(dirname(__FILE__)) . '/functions-bootstrap.php';

function pgacc_vendor_check_unhex($hex)
{
    return pack('H*', $hex); // hex2bin() is PHP 5.4+
}

$engines = array(
    'default' => null,
    'internal' => \phpseclib\Crypt\Base::ENGINE_INTERNAL,
);
$vectors = json_decode(file_get_contents(dirname(dirname(__FILE__)) . '/fixtures/mcrypt/vectors.json'), true);
foreach ($engines as $engineName => $engine) {
    $GLOBALS['pgacc_vendor_check_engine'] = $engine;
    $probe = rijndael256_cbc(str_repeat('k', 32), str_repeat('i', 32));
    if ($engine !== null) {
        $probe->setPreferredEngine($engine);
    }
    $engineNames = array(1 => 'internal', 2 => 'mcrypt', 3 => 'openssl');
    $used = $engineNames[$probe->getEngine()];
    $checked = 0;
    foreach ($vectors['receipts'] as $vector) {
        $cipher = pgacc_vendor_check_crypt('encrypt', receipt_cipher_params($vector['id'], $vector['key']),
            pgacc_vendor_check_unhex($vector['plain']));
        $plain = pgacc_vendor_check_crypt('decrypt', receipt_cipher_params($vector['id'], $vector['key']),
            pgacc_vendor_check_unhex($vector['cipher']));
        if (bin2hex($cipher) !== $vector['cipher'] || bin2hex($plain) !== $vector['decrypted']) {
            pgacc_vendor_check_fail("receipt vector '{$vector['name']}' differs ($used engine)");
        }
        $checked++;
    }
    foreach ($vectors['receipt_decrypts'] as $vector) {
        $plain = pgacc_vendor_check_crypt('decrypt', receipt_cipher_params($vector['id'], $vector['key']),
            pgacc_vendor_check_unhex($vector['cipher']));
        if (bin2hex($plain) !== $vector['decrypted']) {
            pgacc_vendor_check_fail("receipt decrypt vector '{$vector['name']}' differs ($used engine)");
        }
        $checked++;
    }
    foreach ($vectors['mbapi'] as $vector) {
        $text = pgacc_vendor_check_unhex($vector['text']);
        $output = sha1($text) . "\n" . base64_encode(
            pgacc_vendor_check_crypt('encrypt', membership_cipher_params($vector['datakey']), $text));
        if ($output !== $vector['output']) {
            pgacc_vendor_check_fail("mbapi vector '{$vector['name']}' differs ($used engine)");
        }
        $checked++;
    }
    foreach ($vectors['getusers'] as $vector) {
        $plain = rtrim(pgacc_vendor_check_crypt('decrypt', membership_cipher_params($vector['datakey']),
            base64_decode($vector['sData'])), "\0");
        if (bin2hex($plain) !== $vector['decrypted']) {
            pgacc_vendor_check_fail("getusers vector '{$vector['name']}' differs ($used engine)");
        }
        $checked++;
    }
    // The application's own entry points (default engine).
    if ($engine === null) {
        $vector = $vectors['receipts'][0];
        if (bin2hex(receipt_encrypt($vector['id'], $vector['key'], pgacc_vendor_check_unhex($vector['plain']))) !== $vector['cipher']
            || bin2hex(receipt_decrypt($vector['id'], $vector['key'], pgacc_vendor_check_unhex($vector['cipher']))) !== $vector['decrypted']) {
            pgacc_vendor_check_fail('receipt_encrypt()/receipt_decrypt() differ from the vectors');
        }
    }
    echo "vendor: phpseclib $engineName engine ($used): $checked mcrypt contract vectors match\n";
}

// rijndael256_encrypt()/rijndael256_decrypt() with the selected engine.
function pgacc_vendor_check_crypt($direction, $params, $data)
{
    $cipher = rijndael256_cbc($params[0], $params[1]);
    if ($GLOBALS['pgacc_vendor_check_engine'] !== null) {
        $cipher->setPreferredEngine($GLOBALS['pgacc_vendor_check_engine']);
    }
    return $cipher->$direction(rijndael256_zero_pad($data));
}

echo 'vendor check passed on PHP ' . PHP_VERSION . "\n";
