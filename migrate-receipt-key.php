<?php
/*
 * Receipt key migration (command line only): re-encrypts legacy receipt files
 * (files/<n>.aes, encrypted with an empty key part) with the configured $key
 * of constants.php as files/<n>.v2.aes. See "Receipt key migration" in
 * tests/COMPATIBILITY_PLAN.md before running it.
 *
 *   php migrate-receipt-key.php [--dry-run] [--limit=N] [--keep-legacy]
 *       [--files=DIR] [--constants=FILE] [--database=CONNINFO]
 *
 * For each <n>.aes without <n>.v2.aes: decrypt it with the legacy scheme and
 * the voucher ID of a row that references file n; require a PDF or JPEG;
 * encrypt it with the configured key; write <n>.v2.aes atomically; read it
 * back and compare; only then delete <n>.aes (kept with --keep-legacy). Where
 * both files exist (an interrupted run, or --keep-legacy), <n>.v2.aes is
 * compared with <n>.aes before <n>.aes is deleted. Files that are not a PDF or
 * JPEG are skipped and listed. Safe to run again; run one instance at a time,
 * as the user the web server runs as.
 *
 *   --dry-run      report only; writes and deletes nothing
 *   --limit=N      migrate (or finish) at most N files, then stop
 *   --keep-legacy  keep <n>.aes after a verified migration
 *   --files        receipt directory (default: files/ next to this script)
 *   --constants    configuration (default: constants.php next to this script)
 *   --database     PostgreSQL connection string (default: dbname=accounting,
 *                  as the application)
 *
 * Exit status: 0 done (skipped files are listed), 1 a file failed, 2 not run
 * (usage, no key configured, no database). The key is never printed.
 *
 * Runs on PHP 5.3 and newer.
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.0 404 Not Found');
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
// PHPUnit and Infection have already loaded functions.php (or a copy of it).
if (!function_exists('receipt_encrypt')) require_once __DIR__ . '/functions.php';

function receipt_migration_usage()
{
    return "Usage: php migrate-receipt-key.php [--dry-run] [--limit=N] [--keep-legacy]\n"
        . "       [--files=DIR] [--constants=FILE] [--database=CONNINFO]\n";
}

// Options from the arguments (without the script name), or false.
function receipt_migration_options($arguments)
{
    $options = array(
        'dry-run' => false,
        'keep-legacy' => false,
        'limit' => 0,
        'files' => __DIR__ . '/files',
        'constants' => __DIR__ . '/constants.php',
        'database' => 'dbname=accounting',
    );
    foreach ($arguments as $argument) {
        if ($argument === '--dry-run' || $argument === '--keep-legacy') {
            $options[substr($argument, 2)] = true;
        } elseif (preg_match('/^--limit=([1-9][0-9]*)$/', $argument, $match)) {
            $options['limit'] = intval($match[1]);
        } elseif (preg_match('/^--(files|constants|database)=(.+)$/', $argument, $match)) {
            $options[$match[1]] = $match[2];
        } else {
            return false;
        }
    }
    return $options;
}

// The $key of a constants.php, like receipt_configured_key(); '' if none.
function receipt_migration_key($constants)
{
    if (!is_file($constants)) {
        return '';
    }
    include $constants;
    return isset($key) && is_string($key) ? $key : '';
}

// File numbers of the legacy receipt files, in ascending order.
function receipt_migration_numbers($directory)
{
    $numbers = array();
    foreach (scandir($directory) as $name) {
        if (preg_match('/^([0-9]+)\.aes$/', $name, $match) && is_file("$directory/$name")) {
            $numbers[] = $match[1];
        }
    }
    sort($numbers, SORT_NUMERIC);
    return $numbers;
}

/*
 * The voucher ID and padded plain text of a legacy receipt: the first voucher
 * that references the file and decrypts it to a PDF or JPEG. Null if none
 * does, false if the query failed.
 */
function receipt_migration_source($connection, $number, $legacy)
{
    $result = @pg_query_params($connection,
        'SELECT DISTINCT voucher_id FROM vouchers WHERE file = $1 ORDER BY voucher_id', array($number));
    if ($result === false) {
        return false;
    }
    $ids = pg_fetch_all_columns($result, 0);
    pg_free_result($result);
    foreach ($ids as $id) {
        $plain = receipt_decrypt($id, '', $legacy);
        if (receipt_has_known_type($plain)) {
            return array($id, $plain);
        }
    }
    return null;
}

// Migrates one file: array(status, message); status is a summary counter.
function receipt_migration_file($connection, $options, $key, $number)
{
    $legacyPath = receipt_legacy_path($options['files'], $number);
    $v2Path = receipt_v2_path($options['files'], $number);
    $source = receipt_migration_source($connection, $number, (string) @file_get_contents($legacyPath));
    if ($source === false) {
        return array('failed', 'database query failed: ' . pg_last_error($connection));
    }
    if ($source === null) {
        return array('skipped', 'not migrated: no voucher with this file decrypts it to a PDF or JPEG');
    }
    list($id, $plain) = $source;
    $keep = $options['keep-legacy'];
    $dryRun = $options['dry-run'];

    if (file_exists($v2Path)) {
        if (receipt_decrypt($id, $key, (string) @file_get_contents($v2Path)) !== $plain) {
            return array('failed', "$number.v2.aes exists but does not match $number.aes; both left as they are");
        }
        if ($keep) {
            return array('verified', "already migrated (voucher $id); $number.aes kept");
        }
        if ($dryRun) {
            return array('completed', "already migrated (voucher $id); would delete $number.aes");
        }
        // One statement: deleting cannot be made to fail in the tests (root).
        return @unlink($legacyPath) ? array('completed', "already migrated (voucher $id); deleted $number.aes") : array('failed', "already migrated (voucher $id), but $number.aes could not be deleted");
    }

    if ($dryRun) {
        return array('migrated', "would migrate (voucher $id)");
    }
    if (!receipt_write_file($v2Path, receipt_encrypt($id, $key, $plain))
        || receipt_decrypt($id, $key, (string) @file_get_contents($v2Path)) !== $plain) {
        @unlink($v2Path);
        return array('failed', "$number.v2.aes could not be written or verified; $number.aes left as it is");
    }
    if ($keep) {
        return array('migrated', "migrated (voucher $id); $number.aes kept");
    }
    return @unlink($legacyPath) ? array('migrated', "migrated (voucher $id); deleted $number.aes") : array('failed', "migrated (voucher $id), but $number.aes could not be deleted");
}

// Migrates the directory; returns the exit status.
function receipt_migration_run($connection, $options, $key, $out)
{
    $counts = array('migrated' => 0, 'completed' => 0, 'verified' => 0, 'skipped' => 0, 'failed' => 0);
    $changed = 0;
    foreach (receipt_migration_numbers($options['files']) as $number) {
        if ($options['limit'] > 0 && $changed >= $options['limit']) {
            fwrite($out, "Stopped after --limit={$options['limit']}; run again for the remaining files.\n");
            break;
        }
        list($status, $message) = receipt_migration_file($connection, $options, $key, $number);
        $counts[$status]++;
        if ($status === 'migrated' || $status === 'completed') {
            $changed++;
        }
        fwrite($out, "$number.aes: $message\n");
    }
    fwrite($out, ($options['dry-run'] ? 'Dry run, nothing written: ' : '')
        . "{$counts['migrated']} migrated, {$counts['completed']} completed (legacy file deleted), "
        . "{$counts['verified']} verified (legacy file kept), {$counts['skipped']} skipped, "
        . "{$counts['failed']} failed\n");
    return $counts['failed'] > 0 ? 1 : 0;
}

function receipt_migration_main($arguments, $out, $err)
{
    $options = receipt_migration_options($arguments);
    if ($options === false) {
        fwrite($err, receipt_migration_usage());
        return 2;
    }
    $key = receipt_migration_key($options['constants']);
    if ($key === '') {
        fwrite($err, "No receipt key: \$key is not set in {$options['constants']}. Nothing was changed.\n");
        return 2;
    }
    if (!is_dir($options['files'])) {
        fwrite($err, "No receipt directory {$options['files']}. Nothing was changed.\n");
        return 2;
    }
    // Not closed: pg_connect() may return a link the caller still uses.
    $connection = @pg_connect($options['database']);
    if ($connection === false) {
        fwrite($err, "Cannot connect to the database. Nothing was changed.\n");
        return 2;
    }
    return receipt_migration_run($connection, $options, $key, $out);
}

if (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) exit(receipt_migration_main(array_slice($_SERVER['argv'], 1), STDOUT, STDERR));
