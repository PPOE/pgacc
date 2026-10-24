<?php
/*
 * Regenerates adm_names.b64, the synthetic member list served by the
 * membership stub (server.php) in place of the membership system's
 * adm_api/adm_names.php. It is encrypted exactly the way getusers.php
 * decrypts it: base64 of Rijndael-256/CBC (mcrypt), key md5($datakey), IV
 * md5(md5($datakey)), zero padding, names in ISO-8859-15. $datakey is the
 * synthetic key from tests/reference/runtime/reference-constants.php.
 *
 * Needs mcrypt, i.e. the PHP 5.6 reference image:
 *   docker run --rm -v "$PWD/tests/reference/membership:/out" \
 *     pgacc-test-reference-php56<suffix> php /app/tests/reference/membership/generate.php /out
 * The output is deterministic (fixed IV), so regenerating it without changing
 * adm_names.tsv or the key reproduces the committed file byte for byte.
 */
require '/app/constants.php';
$out = isset($argv[1]) ? rtrim($argv[1], '/') : __DIR__;
$plain = '';
foreach (file(__DIR__ . '/adm_names.tsv', FILE_IGNORE_NEW_LINES) as $line) {
    $fields = explode("\t", $line);
    $fields[2] = iconv('UTF-8', 'ISO-8859-15', $fields[2]);
    $plain .= implode("\t", $fields) . "\n";
}
$encrypted = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($datakey), $plain, MCRYPT_MODE_CBC, md5(md5($datakey))));
$check = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($datakey), base64_decode($encrypted), MCRYPT_MODE_CBC, md5(md5($datakey))), "\0");
if ($check !== $plain || strlen($encrypted) < 1000) {
    fwrite(STDERR, "round trip failed or payload shorter than getusers() accepts\n");
    exit(1);
}
file_put_contents($out . '/adm_names.b64', $encrypted);
echo 'wrote ' . strlen($encrypted) . " bytes\n";
