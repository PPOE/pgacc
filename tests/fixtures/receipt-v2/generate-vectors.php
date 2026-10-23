<?php
/*
 * Generates tests/fixtures/receipt-v2/vectors.json, the reference for the
 * files/<n>.v2.aes format (receipt_v2_encrypt() in functions.php), with the
 * openssl extension instead of phpseclib, so the vectors do not come from
 * the code they check. Synthetic keys and data only; fixed IVs (the
 * application uses a random IV per file). Not part of any test run:
 *
 *   docker run --rm --network none -v "$PWD/tests/fixtures:/f:ro" \
 *     php:7.4.33-cli-bullseye php /f/receipt-v2/generate-vectors.php \
 *     > tests/fixtures/receipt-v2/vectors.json
 *
 * Format: "PGR2" . IV (16 bytes) . AES-256-CBC(PKCS#7) ciphertext . HMAC-SHA256
 * over all of that; encryption key HMAC-SHA256(key, "pgacc-receipt-v2-enc|<n>"),
 * MAC key HMAC-SHA256(key, "pgacc-receipt-v2-mac|<n>").
 *
 * Must stay compatible with PHP 5.6.
 */
function v2_vector($name, $key, $number, $iv, $plain)
{
    $encryptionKey = hash_hmac('sha256', 'pgacc-receipt-v2-enc|' . $number, $key, true);
    $macKey = hash_hmac('sha256', 'pgacc-receipt-v2-mac|' . $number, $key, true);
    $body = 'PGR2' . $iv . openssl_encrypt($plain, 'aes-256-cbc', $encryptionKey, OPENSSL_RAW_DATA, $iv);
    return array('name' => $name, 'key' => $key, 'number' => $number, 'iv' => bin2hex($iv),
        'plain' => bin2hex($plain), 'file' => bin2hex($body . hash_hmac('sha256', $body, $macKey, true)));
}

$key = 'synthetic-test-only-encryption-key';
$otherKey = 'another-synthetic-receipt-key';
$iv = '';
for ($i = 0; $i < 16; $i++) {
    $iv .= chr($i);
}
$otherIv = str_repeat("\xA5", 16);
// The same synthetic receipts as tests/fixtures/mcrypt/generate-vectors.php.
$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
    . "\xFF\xDB\x00\x43\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\x09\x09\x08\x0A\x0C\x14\x0D\x0C\x0B\x0B\x0C"
    . "synthetic\xFF\xD9";

$vectors = array(
    v2_vector('empty', $key, '42', $iv, ''),
    v2_vector('15 bytes', $key, '42', $iv, str_repeat('%', 15)),
    v2_vector('16 bytes', $key, '42', $iv, str_repeat('%', 16)),
    v2_vector('pdf document', $key, '42', $iv, $pdf),
    v2_vector('pdf document, file 43', $key, '43', $iv, $pdf),
    v2_vector('pdf document, other IV', $key, '42', $otherIv, $pdf),
    v2_vector('pdf document, other key', $otherKey, '42', $iv, $pdf),
    v2_vector('jpeg with trailing NUL bytes', $key, '815', $iv, $jpeg . "\0\0"),
    v2_vector('png (other type)', $key, '42', $iv, "\x89PNG\r\n\x1A\nsynthetic"),
);
echo json_encode(array(
    'generator' => 'tests/fixtures/receipt-v2/generate-vectors.php',
    'php' => PHP_VERSION,
    'fingerprints' => array(
        $key => substr(hash_hmac('sha256', 'pgacc-key-fingerprint', $key), 0, 8),
        $otherKey => substr(hash_hmac('sha256', 'pgacc-key-fingerprint', $otherKey), 0, 8),
    ),
    'vectors' => $vectors,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
