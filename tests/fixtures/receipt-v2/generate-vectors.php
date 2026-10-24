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
 * over all of that; encryption and MAC keys from HKDF-SHA256 (RFC 5869,
 * hash_hkdf(), no salt) of the key with the info "pgacc-receipt-v2|enc|<n>"
 * and "pgacc-receipt-v2|mac|<n>".
 *
 * Needs PHP 7.1.2 (hash_hkdf()).
 */
function v2_vector($name, $key, $number, $iv, $plain)
{
    $encryptionKey = hash_hkdf('sha256', $key, 32, 'pgacc-receipt-v2|enc|' . $number);
    $macKey = hash_hkdf('sha256', $key, 32, 'pgacc-receipt-v2|mac|' . $number);
    $body = 'PGR2' . $iv . openssl_encrypt($plain, 'aes-256-cbc', $encryptionKey, OPENSSL_RAW_DATA, $iv);
    return array('name' => $name, 'key' => $key, 'number' => $number, 'iv' => bin2hex($iv),
        'plain' => bin2hex($plain), 'file' => bin2hex($body . hash_hmac('sha256', $body, $macKey, true)));
}

// A long receipt as prefix . str_repeat(unit, times) . suffix, with only the
// SHA-256 of the file (keeps the fixture small).
function v2_long_vector($name, $key, $number, $iv, $prefix, $unit, $times, $suffix)
{
    $vector = v2_vector($name, $key, $number, $iv, $prefix . str_repeat($unit, $times) . $suffix);
    unset($vector['plain']);
    $vector['plain_parts'] = array(bin2hex($prefix), bin2hex($unit), $times, bin2hex($suffix));
    $vector['file_sha256'] = hash('sha256', hex2bin($vector['file']));
    $vector['file_length'] = strlen($vector['file']) / 2;
    unset($vector['file']);
    return $vector;
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
    // Several 64 KiB chunks plus a partial block (receipt_v2_encrypt_stream()).
    v2_long_vector('three chunks and a bit', $key, '42', $iv, '%PDF', "synthetic\x00\xff", 17000, "\n%%EOF\n"),
    v2_long_vector('exactly two chunks', $key, '42', $iv, '', "\xFF\xD8\xFF", 43690, "\xFF\xD9"),
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
