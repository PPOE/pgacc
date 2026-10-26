<?php

function download_file($rights)
{
    global $dbconn;
    $file = 0;
    $rightssql = rights2orgasql($rights);
    $bid = intval($_GET['id'] ?? 0);
    $query = "SELECT file,voucher_id FROM vouchers WHERE NOT deleted AND id = $bid $rightssql ORDER BY id ASC;";
    $result = db_query($dbconn, $query);
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        $file = $line['file'];
        $vid = $line['voucher_id'];
    }
    pg_free_result($result);

    if ($file == 0) {
        return false;
    }

    $directory = getcwd() . '/files';
    $path = receipt_v2_path($directory, $file);
    $receipt = false;
    $problem = null;
    if (file_exists($path)) {
        // <n>.v2.aes: keyed on the file number and the configured key; checked
        // and sent in chunks (receipt_v2_open()/receipt_v2_send()).
        [$receipt, $problem] = receipt_v2_open($path, $file);
    }
    $v2 = $receipt !== false;
    if ($v2) {
        $type = receipt_content_type($receipt['head']);
        $length = $receipt['length'];
    } else {
        // Legacy <n>.aes, unchanged: keyed on the voucher ID with an empty key part
        // (see tests/COMPATIBILITY_PLAN.md); anything but a PDF is sent as JPEG.
        // Also sent while <n>.aes is kept and <n>.v2.aes does not decrypt (e.g. a
        // key mismatch before the migration deletes the legacy files).
        // A stored <n>.aes is at least one 32-byte block: mcrypt and
        // receipt_encrypt() pad even an empty receipt to one block. A directory,
        // a file deleted after the check (e.g. by migrate-receipt-key.php
        // --delete-legacy) or a read error give false (receipt_read_file()).
        $ciphertext = receipt_read_file(receipt_legacy_path($directory, $file));
        if (strlen((string) $ciphertext) < 32) {
            // No usable <n>.v2.aes and no readable <n>.aes (also neither file at all).
            error_log($problem === null ? "pgacc: receipt $file: no readable stored file"
              : "pgacc: receipt $file.v2.aes $problem; no readable $file.aes");
            header('Content-Type: text/plain; charset=UTF-8', true, 500);
            echo 'FEHLER: Der Beleg konnte nicht entschlüsselt werden.';
            return true;
        }
        if ($problem !== null) {
            error_log("pgacc: receipt $file.v2.aes $problem; sent the legacy $file.aes");
        }
        $data = receipt_decrypt($vid, '', $ciphertext);
        $type = str_starts_with($data, '%PDF') ? 'application/pdf' : 'image/jpeg';
        // The stored size: the ciphertext is whole blocks, decrypted to as many bytes.
        $length = strlen($data);
    }
    if ($v2) {
        header('X-Content-Type-Options: nosniff');
    }
    if ($type == 'application/pdf') {
        header("Content-Type: application/pdf");
        header("Content-Disposition: inline; filename=\"$file.pdf\"");
        header('Content-Transfer-Encoding: binary');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        $v2 ? receipt_v2_send($receipt) : print($data);
    } else {
        header("Content-Type: $type");
        if ($type == 'application/octet-stream') {
            header("Content-Disposition: attachment; filename=\"$file.bin\"");
        }
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        $v2 ? receipt_v2_send($receipt) : print($data);
    }

    return true;
}
