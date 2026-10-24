<?php
if (PHP_SAPI !== 'cli') {
    // A web request gets a 404; php-cgi run from a shell gets a message.
    isset($_SERVER['REQUEST_METHOD']) ? header('HTTP/1.0 404 Not Found') : fwrite(fopen('php://stderr', 'w'), "migrate-receipt-key.php must be run with the php command-line binary.\n");
    exit(1);
}
/*
 * Receipt key migration (command line only; the statement above stops any
 * other server API, with a 404 for web requests, before anything else runs). Re-encrypts legacy receipt
 * files (files/<n>.aes: keyed on the voucher ID, empty key part) as
 * files/<n>.v2.aes (keyed on the file number and the configured $key, see
 * receipt_v2_encrypt() in functions.php). Runbook: "Receipt key" in the
 * deploy checklist of tests/COMPATIBILITY_PLAN.md.
 *
 *   php migrate-receipt-key.php [--dry-run] [--delete-legacy] [--limit=N]
 *       [--max-size=BYTES] [--files=DIR] [--constants=FILE] [--database=CONNINFO]
 *
 * Two phases, because PHP 5.3 cannot fsync: a normal run writes and verifies
 * <n>.v2.aes for each <n>.aes and keeps <n>.aes. After `sync`, a run with
 * --delete-legacy decrypts both files again and deletes <n>.aes only where
 * <n>.v2.aes holds the same plain text. A legacy file is decrypted with the
 * voucher ID of a row that references it (the first 32-byte block is probed
 * per candidate) and must be a complete PDF or JPEG; other files, files above
 * --max-size, files whose voucher ID changed (a merge) and names that are not
 * file numbers are skipped and listed, and keep working as legacy files.
 *
 *   --dry-run        report only; writes, deletes and locks nothing
 *   --delete-legacy  the second phase
 *   --limit=N        migrate (or delete) at most N files, then stop
 *   --max-size=B     skip files above B bytes (default: memory_limit / 8,
 *                    no limit if memory_limit is -1); raise memory_limit with
 *                    php -d memory_limit=1G for large receipts
 *   --files          receipt directory (default: files/ next to this script)
 *   --constants      configuration; by default constants.php is included from
 *                    this directory exactly as index.php does
 *                    (relative --files and --constants are relative to the
 *                    working directory)
 *   --database       PostgreSQL connection string (default: dbname=accounting,
 *                    as the application)
 *   --sync           command that flushes written files to disk before
 *                    --delete-legacy deletes (default: sync)
 *
 * One run at a time: a run holds an exclusive lock on
 * files/.migrate-receipt-key.lock. Safe to run again. An existing <n>.v2.aes
 * is checked like a download (MAC, then chunked decryption); an invalid one
 * is re-created from <n>.aes, or reported. The first phase never deletes
 * anything. Before --delete-legacy deletes anything it runs --sync (default:
 * sync), because PHP 5.3 cannot fsync the new files. The summary shows a key
 * fingerprint to compare runs (only here; the web application never logs
 * it); the key itself is never printed. Exit status: 0 done (skipped files
 * are listed), 1 a file failed, 2 not run.
 *
 * Runs on PHP 5.3 and newer.
 */

require_once __DIR__ . '/vendor/autoload.php';
// PHPUnit and Infection have already loaded functions.php (or a copy of it).
if (!function_exists('receipt_encrypt')) require_once __DIR__ . '/functions.php';

function receipt_migration_usage()
{
    return "Usage: php migrate-receipt-key.php [--dry-run] [--delete-legacy] [--limit=N]\n"
        . "       [--max-size=BYTES] [--files=DIR] [--constants=FILE] [--database=CONNINFO] [--sync=COMMAND]\n";
}

// A php.ini size ("128M", "1G", "512K", "1048576") in bytes; -1 is unlimited.
function receipt_migration_bytes($value)
{
    $value = trim($value);
    $units = array('k' => 1024, 'm' => 1048576, 'g' => 1073741824);
    $unit = strtolower(substr($value, -1));
    if (isset($units[$unit])) {
        return intval(substr($value, 0, -1)) * $units[$unit];
    }
    return intval($value);
}

// Default --max-size: a migration holds a file about five times in memory.
function receipt_migration_default_max_size()
{
    $limit = receipt_migration_bytes(ini_get('memory_limit'));
    return $limit > 0 ? intval($limit / 8) : 0;
}

// Options from the arguments (without the script name), or false.
function receipt_migration_options($arguments)
{
    $options = array(
        'dry-run' => false,
        'delete-legacy' => false,
        'limit' => 0,
        'max-size' => receipt_migration_default_max_size(),
        'files' => __DIR__ . '/files',
        'constants' => 'constants.php',
        'database' => 'dbname=accounting',
        'sync' => 'sync',
    );
    foreach ($arguments as $argument) {
        if ($argument === '--dry-run' || $argument === '--delete-legacy') {
            $options[substr($argument, 2)] = true;
        } elseif (preg_match('/^--(limit|max-size)=([1-9][0-9]*)$/', $argument, $match)) {
            $options[$match[1]] = intval($match[2]);
        } elseif (preg_match('/^--(files|constants)=(.+)$/', $argument, $match)) {
            $options[$match[1]] = substr($match[2], 0, 1) === '/' ? $match[2] : getcwd() . '/' . $match[2];
        } elseif (preg_match('/^--(database|sync)=(.+)$/', $argument, $match)) {
            $options[$match[1]] = $match[2];
        } else {
            return false;
        }
    }
    return $options;
}

// The $key of the configuration, read like index.php: constants.php is
// included from the application directory (include_path); null if unset.
function receipt_migration_key($constants)
{
    $workingDirectory = getcwd();
    chdir(__DIR__);
    @include $constants;
    chdir($workingDirectory);
    return isset($key) ? $key : null;
}

// File numbers of the legacy receipt files, ascending, and the other *.aes
// names (not <n>.v2.aes): array(numbers, names). A file number is canonical
// and fits PostgreSQL's integer (and a 32-bit PHP integer).
function receipt_migration_numbers($directory, $names)
{
    $numbers = array();
    $others = array();
    foreach ($names as $name) {
        if (!is_file("$directory/$name") || substr($name, -4) !== '.aes' || substr($name, -7) === '.v2.aes') {
            continue;
        }
        $number = substr($name, 0, -4);
        if (preg_match('/^[1-9][0-9]{0,9}$/', $number) && (strlen($number) < 10 || strcmp($number, '2147483647') <= 0)) {
            $numbers[] = $number;
        } else {
            $others[] = $name;
        }
    }
    sort($numbers, SORT_NUMERIC);
    sort($others, SORT_STRING);
    return array($numbers, $others);
}

// A decrypted legacy receipt (whole 32-byte blocks, less than one block of
// zero padding) that ends like a PDF ("%%EOF" in its last 1024 bytes) or a
// JPEG (FF D9).
function receipt_migration_is_complete($plain)
{
    $data = rtrim($plain, "\0");
    if (strlen($plain) % 32 !== 0 || strlen($plain) - strlen($data) >= 32) {
        return false;
    }
    if (substr($data, 0, 4) === '%PDF') {
        return strpos(substr($data, -1024), '%%EOF') !== false;
    }
    return substr($data, 0, 3) === "\xFF\xD8\xFF" && substr($data, -2) === "\xFF\xD9";
}

/*
 * Decrypts a legacy receipt: array('ok', voucher ID, padded plain text) with
 * the first voucher that references the file, decrypts its first block to a
 * PDF or JPEG and the whole file to a complete one; otherwise array(status,
 * message).
 */
function receipt_migration_legacy($connection, $options, $number)
{
    $path = receipt_legacy_path($options['files'], $number);
    $size = filesize($path);
    if ($options['max-size'] > 0 && $size > $options['max-size']) {
        return array('skipped', "not migrated: $size bytes, above --max-size={$options['max-size']}");
    }
    $legacy = (string) @file_get_contents($path);
    $result = @pg_query_params($connection,
        'SELECT DISTINCT voucher_id FROM vouchers WHERE file = $1 ORDER BY voucher_id', array($number));
    if ($result === false) {
        return array('failed', 'database query failed: ' . pg_last_error($connection));
    }
    $ids = pg_fetch_all_columns($result, 0);
    pg_free_result($result);
    foreach ($ids as $id) {
        // CBC: the first block decrypts on its own, without the whole file.
        if (!receipt_has_known_type(receipt_decrypt($id, '', substr($legacy, 0, 32)))) {
            continue;
        }
        $plain = receipt_decrypt($id, '', $legacy);
        if (receipt_migration_is_complete($plain)) {
            return array('ok', $id, $plain);
        }
    }
    return array('skipped', 'not migrated: no voucher with this file decrypts it to a complete PDF or JPEG');
}

// One legacy file: array(status, message); status is a summary counter.
function receipt_migration_file($connection, $options, $key, $number)
{
    $v2Path = receipt_v2_path($options['files'], $number);
    $hasV2 = is_file($v2Path);
    if ($options['delete-legacy'] && !$hasV2) {
        return array('pending', "not migrated yet; $number.aes kept");
    }
    // An existing <n>.v2.aes is checked the way a download reads it.
    $invalidV2 = !$options['delete-legacy'] && $hasV2 && receipt_v2_read_with($key, $number, $v2Path) === false;
    if (!$options['delete-legacy'] && $hasV2 && !$invalidV2) {
        return array('present', "already migrated; $number.aes kept until --delete-legacy");
    }
    $legacy = receipt_migration_legacy($connection, $options, $number);
    if ($legacy[0] !== 'ok' && $invalidV2) {
        return array('failed', "$number.v2.aes does not verify, and $number.aes cannot replace it ("
            . preg_replace('/^not migrated: /', '', $legacy[1]) . '); both kept');
    }
    if ($legacy[0] !== 'ok') {
        return $legacy;
    }
    $id = $legacy[1];
    $plain = $legacy[2];
    $recreate = $invalidV2 ? "$number.v2.aes does not verify; " : '';

    if ($options['delete-legacy']) {
        if (receipt_v2_read_with($key, $number, $v2Path) !== $plain) {
            return array('failed', "$number.v2.aes does not decrypt to the content of $number.aes with this key; both kept");
        }
        if ($options['dry-run']) {
            return array('deleted', "verified; would delete $number.aes");
        }
        // One statement: deleting cannot be made to fail in the tests (root).
        return @unlink(receipt_legacy_path($options['files'], $number)) ? array('deleted', "verified; deleted $number.aes") : array('failed', "verified, but $number.aes could not be deleted");
    }

    if ($options['dry-run']) {
        return array('migrated', $recreate === '' ? "would migrate (voucher $id)" : $recreate . "would re-create it (voucher $id)");
    }
    if (!receipt_write_file($v2Path, receipt_v2_encrypt($key, $number, $plain))) {
        return array('failed', "$number.v2.aes could not be written; $number.aes kept");
    }
    // Read back. One statement: a written file cannot be made to differ in the
    // tests. The file is this run's own, so it may be removed.
    if (receipt_v2_read_with($key, $number, $v2Path) !== $plain) return @unlink($v2Path) ? array('failed', "$number.v2.aes did not verify and was removed; $number.aes kept") : array('failed', "$number.v2.aes did not verify and could not be removed; $number.aes kept");
    return array('migrated', ($recreate === '' ? 'migrated' : $recreate . 're-created it') . " (voucher $id); $number.aes kept until --delete-legacy");
}

// Migrates the listed files; returns the exit status.
function receipt_migration_run($connection, $options, $key, $names, $out)
{
    $counts = array('migrated' => 0, 'present' => 0, 'deleted' => 0, 'pending' => 0, 'skipped' => 0, 'failed' => 0);
    $changed = 0;
    if ($options['delete-legacy']) {
        fwrite($out, "*** --delete-legacy: only after `sync` and after checking several migrated receipts in the browser. "
            . "Deleted legacy files can only be restored from the backup. ***\n");
    }
    list($numbers, $others) = receipt_migration_numbers($options['files'], $names);
    foreach ($others as $name) {
        $counts['skipped']++;
        fwrite($out, "$name: not migrated: not a file number\n");
    }
    foreach ($numbers as $number) {
        if ($options['limit'] > 0 && $changed >= $options['limit']) {
            fwrite($out, "Stopped after --limit={$options['limit']}; run again for the remaining files.\n");
            break;
        }
        list($status, $message) = receipt_migration_file($connection, $options, $key, $number);
        $counts[$status]++;
        if ($status === 'migrated' || $status === 'deleted') {
            $changed++;
        }
        fwrite($out, "$number.aes: $message\n");
    }
    fwrite($out, ($options['dry-run'] ? 'Dry run, nothing written: ' : '')
        . "{$counts['migrated']} migrated, {$counts['present']} already migrated, {$counts['deleted']} legacy deleted, "
        . "{$counts['pending']} not yet migrated, {$counts['skipped']} skipped, {$counts['failed']} failed; "
        . 'key fingerprint ' . receipt_key_fingerprint($key) . "\n");
    return $counts['failed'] > 0 ? 1 : 0;
}

// Runs the flush command; true if it succeeded.
function receipt_migration_sync($command)
{
    exec($command . ' 2>&1', $output, $status);
    return $status === 0;
}

function receipt_migration_main($arguments, $out, $err)
{
    $options = receipt_migration_options($arguments);
    if ($options === false) {
        fwrite($err, receipt_migration_usage());
        return 2;
    }
    $key = receipt_migration_key($options['constants']);
    if (!receipt_key_is_valid($key)) {
        fwrite($err, "No valid receipt key: \$key in {$options['constants']} must have at least 16 characters "
            . "and not be the CHANGE-ME placeholder. Nothing was changed.\n");
        return 2;
    }
    $names = @scandir($options['files']);
    if ($names === false) {
        fwrite($err, "Cannot read the receipt directory {$options['files']}. Nothing was changed.\n");
        return 2;
    }
    $lock = null;
    if (!$options['dry-run']) {
        $lockPath = $options['files'] . '/.migrate-receipt-key.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            fwrite($err, "Another migration is running, or $lockPath cannot be opened. Nothing was changed.\n");
            if ($lock !== false) {
                fclose($lock);
            }
            return 2;
        }
    }
    // Not closed: pg_connect() may return a link the caller still uses.
    $connection = @pg_connect($options['database']);
    if ($connection === false) {
        fwrite($err, "Cannot connect to the database. Nothing was changed.\n");
    }
    // PHP 5.3 has no fsync(): flush everything written so far (the new
    // <n>.v2.aes of earlier runs) before the first legacy file is deleted.
    $flushed = $connection === false || !$options['delete-legacy'] || $options['dry-run'] || receipt_migration_sync($options['sync']);
    if (!$flushed) {
        fwrite($err, "Could not flush the receipt files to disk with `{$options['sync']}`; nothing was deleted.\n");
    }
    $status = $connection === false || !$flushed ? 2 : receipt_migration_run($connection, $options, $key, $names, $out);
    if ($lock !== null) {
        fclose($lock);
    }
    return $status;
}

if (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) exit(receipt_migration_main(array_slice($_SERVER['argv'], 1), STDOUT, STDERR));
