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
    if (file_exists($path)) {
        // <n>.v2.aes: keyed on the file number and the configured key; checked
        // and sent in chunks (receipt_v2_open()/receipt_v2_send()).
        $receipt = receipt_v2_open($path, $file);
        if ($receipt === false && !file_exists(receipt_legacy_path($directory, $file))) {
            header('Content-Type: text/plain; charset=UTF-8', true, 500);
            echo 'FEHLER: Der Beleg konnte nicht entschlüsselt werden.';
            return true;
        }
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
        if (file_exists($path)) {
            error_log("pgacc: receipt $file.v2.aes could not be decrypted; sent the legacy $file.aes");
        }
        $path = receipt_legacy_path($directory, $file);
        $data = receipt_decrypt($vid, '', file_get_contents($path));
        $type = str_starts_with($data, '%PDF') ? 'application/pdf' : 'image/jpeg';
        $length = filesize($path);
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
