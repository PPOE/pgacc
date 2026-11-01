<?php
/*
 * Generates tests/fixtures/mcrypt/vectors.json with the real mcrypt extension,
 * the reference for the mcrypt replacement in functions.php. Synthetic keys and
 * data only. Not part of any test run: the test images have no mcrypt.
 *
 * vectors.json was generated in a throwaway image of the PHP 5.6 lane's base
 * image with the mcrypt extension (libmcrypt 2.5.8-3.3 from Debian stretch):
 *
 *   FROM php:5.6-cli@sha256:6ce95208609dc66df163ab936c970b3b34cd901b85c747102c5999f08ade9143
 *   (apt sources and keyring as in tests/php56/runtime.Dockerfile)
 *   RUN apt-get update && apt-get install --yes --no-install-recommends libmcrypt-dev \
 *       && docker-php-ext-install mcrypt
 *
 *   docker run --rm --network none -v "$PWD/tests/fixtures/mcrypt:/v:ro" <image> \
 *     php -d error_reporting=-1 /v/generate-vectors.php > tests/fixtures/mcrypt/vectors.json
 *
 * Regenerated the same way on 2026-10-23 to add the "test-constants key"
 * vectors; all earlier entries came out byte-identical.
 *
 * PHP 7.1.33 with its bundled mcrypt extension (php:7.1-cli, libmcrypt from
 * Debian buster) produced identical vectors apart from the "php" field.
 *
 * Must stay compatible with PHP 5.6.
 */
if (!function_exists('mcrypt_encrypt')) {
    fwrite(STDERR, "The mcrypt extension is required\n");
    exit(1);
}

function receipt_vector($name, $id, $key, $plain)
{
    // The receipt call sites: new.php, import2.php (encrypt), file.php (decrypt).
    $cipher = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($id . $key . $id), $plain, MCRYPT_MODE_CBC, md5($key . $id));
    $decrypted = mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($id . $key . $id), $cipher, MCRYPT_MODE_CBC, md5($key . $id));
    return array('name' => $name, 'id' => $id, 'key' => $key, 'plain' => bin2hex($plain),
        'cipher' => bin2hex($cipher), 'decrypted' => bin2hex($decrypted));
}

function receipt_decrypt_vector($name, $id, $key, $cipher)
{
    $decrypted = mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($id . $key . $id), $cipher, MCRYPT_MODE_CBC, md5($key . $id));
    return array('name' => $name, 'id' => $id, 'key' => $key, 'cipher' => bin2hex($cipher),
        'decrypted' => bin2hex($decrypted));
}

function getusers_vector($name, $datakey, $plain)
{
    // The membership system's export as getusers.php reads it: base64 ciphertext.
    $sData = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($datakey), $plain, MCRYPT_MODE_CBC, md5(md5($datakey))));
    $decrypted = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($datakey), base64_decode($sData), MCRYPT_MODE_CBC, md5(md5($datakey))), "\0");
    return array('name' => $name, 'datakey' => $datakey, 'plain' => bin2hex($plain), 'sData' => $sData,
        'decrypted' => bin2hex($decrypted));
}

$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
    . "\xFF\xDB\x00\x43\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\x09\x09\x08\x0A\x0C\x14\x0D\x0C\x0B\x0B\x0C"
    . "synthetic\xFF\xD9";
$allBytes = '';
for ($i = 0; $i < 256; $i++) {
    $allBytes .= chr($i);
}
$allBytes .= "%PDF\0";

$receipts = array(
    // Legacy receipts (files/<n>.aes) were written and are read with an empty
    // key part (see the receipt key item in tests/COMPATIBILITY_PLAN.md); the
    // non-empty keys pin the derivation order. The "test-constants key"
    // vectors (the synthetic key of tests/runtime/test-constants.php) proved
    // the ignored key (KF-RECEIPT-KEY-IGNORED); new receipts use another
    // format (files/<n>.v2.aes, tests/fixtures/receipt-v2/).
    receipt_vector('empty', '1', '', ''),
    receipt_vector('one byte', '7', '', '%'),
    receipt_vector('pdf 31 bytes', '4711', '', substr($pdf, 0, 31)),
    receipt_vector('pdf 32 bytes', '4711', '', substr($pdf, 0, 32)),
    receipt_vector('pdf 33 bytes', '4711', '', substr($pdf, 0, 33)),
    receipt_vector('pdf document', '4711', '', $pdf),
    receipt_vector('jpeg image', '815', '', $jpeg),
    receipt_vector('jpeg with trailing NUL bytes', '815', '', $jpeg . "\0\0"),
    receipt_vector('all byte values', '123456', '', $allBytes),
    receipt_vector('pdf with a non-empty key', '4711', 'synthetic-receipt-key', $pdf),
    receipt_vector('pdf document, test-constants key', '4711', 'synthetic-test-only-encryption-key', $pdf),
    receipt_vector('jpeg with trailing NUL bytes, test-constants key', '815', 'synthetic-test-only-encryption-key', $jpeg . "\0\0"),
);
$receiptDecrypts = array(
    receipt_decrypt_vector('empty ciphertext', '1', '', ''),
    receipt_decrypt_vector('truncated ciphertext (50 bytes)', '4711', '',
        substr(hex2bin($receipts[5]['cipher']), 0, 50)),
);

$memberExport = "x\t1001\tSynthetic Member \xE4\xF6\xFC\t38\t1\n"
    . "x\t1002\tO\\'Synthetic\t44\t0\n"
    . "x\t1003\tThird Synthetic\t99\t1\n";
// Long enough for getUsers(), which rejects responses under 1000 characters:
// every organization code it maps, an unknown one, a short line it skips, an
// escaped quote and an ISO-8859-15 euro sign.
$getUsersExport = "x\n";
$organizations = array(37, 38, 39, 40, 41, 42, 43, 44, 45, 99);
for ($i = 0; $i < 30; $i++) {
    $getUsersExport .= sprintf("x\t%d\tSynthetic Member %02d\t%d\t%d\n", 2001 + $i, $i, $organizations[$i % 10], $i % 2);
}
$getUsersExport .= "x\t2031\tO\\'Synthetic \xA4uro\t38\t1\n";
// Not block-aligned: the zero padding (5 bytes) must be stripped before parsing.
$getUsersExport .= "x\t2032\tLast Synthetic\t40\t0\n";

$getusers = array(
    getusers_vector('getUsers() export', 'synthetic-membership-key', $getUsersExport),
    getusers_vector('member export', 'synthetic-membership-key', $memberExport),
    getusers_vector('block-aligned export', 'synthetic-membership-key', str_repeat("x\t1004\tAligned\t40\t1\n", 4) . "abcdefghijkl\n"),
    getusers_vector('export ending in NUL bytes', 'synthetic-membership-key', $memberExport . "\0\0"),
);

$vectors = array(
    'generator' => 'tests/fixtures/mcrypt/generate-vectors.php',
    'php' => PHP_VERSION,
    'receipts' => $receipts,
    'receipt_decrypts' => $receiptDecrypts,
    'getusers' => $getusers,
);
echo json_encode($vectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
