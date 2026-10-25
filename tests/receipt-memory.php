<?php

/*
 * Stores and sends a synthetic 20 MB receipt with the receipt v2 helpers
 * (receipt_store(), receipt_v2_open(), receipt_v2_send()) and checks that
 * the bytes sent are the bytes stored. Run with a low memory_limit by
 * tests/unit/ReceiptStorageTest.php: chunked processing must fit, whole-file
 * processing would not.
 *
 *   php -d memory_limit=40M tests/receipt-memory.php <empty directory>
 *
 * Runs on PHP 5.3 and newer.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/functions.php';

$directory = $argv[1];
mkdir($directory . '/files');
$source = $directory . '/upload.tmp';
$size = 20 * 1048576;
$out = fopen($source, 'wb');
$unit = '';
for ($i = 0; $i < 4096; $i++) {
    $unit .= chr($i % 251) . chr(($i * 7) % 256);
}
fwrite($out, '%PDF-1.4');
for ($written = 8; $written < $size - 7; $written += strlen($chunk)) {
    $chunk = substr($unit, 0, min(strlen($unit), $size - 7 - $written));
    fwrite($out, $chunk);
}
fwrite($out, "\n%%EOF\n");
fclose($out);

$GLOBALS['key'] = 'synthetic-test-only-encryption-key';
if (!receipt_store($directory . '/files', 77, $source)) {
    fwrite(STDERR, "receipt_store() failed\n");
    exit(1);
}
$receipt = receipt_v2_open($directory . '/files/77.v2.aes', 77);
if ($receipt === false || $receipt['length'] !== $size || receipt_content_type($receipt['head']) !== 'application/pdf') {
    fwrite(STDERR, "receipt_v2_open() failed\n");
    exit(1);
}
// Hash what is sent instead of keeping it.
$GLOBALS['sent'] = hash_init('sha256');
$GLOBALS['sentLength'] = 0;
function receipt_memory_sink($buffer)
{
    hash_update($GLOBALS['sent'], $buffer);
    $GLOBALS['sentLength'] += strlen($buffer);
    return '';
}
ob_start('receipt_memory_sink', 65536);
receipt_v2_send($receipt);
ob_end_flush();
if ($GLOBALS['sentLength'] !== $size || hash_final($GLOBALS['sent']) !== hash_file('sha256', $source)) {
    fwrite(STDERR, "the receipt sent differs from the upload\n");
    exit(1);
}
unlink($source);
unlink($directory . '/files/77.v2.aes');
rmdir($directory . '/files');
echo "$size bytes stored and sent; peak memory ", round(memory_get_peak_usage(true) / 1048576, 1), " MB\n";
