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
 * receipt_v2_encrypt() in functions.php). Operator steps: "Receipt
 * migration" in UPGRADING.md.
 *
 *   php migrate-receipt-key.php [--dry-run] [--key-fingerprint=HEX]
 *       [--delete-legacy] [--recreate-unverified] [--include-unusual]
 *       [--search-voucher-ids] [--limit=N] [--max-size=BYTES] [--files=DIR]
 *       [--constants=FILE] [--database=CONNINFO] [--sync=COMMAND]
 *
 * Two phases, because PHP 5.3 cannot fsync: a normal run writes and verifies
 * <n>.v2.aes for each <n>.aes and keeps <n>.aes. After `sync`, a run with
 * --delete-legacy decrypts both files again and deletes <n>.aes only where
 * <n>.v2.aes verifies with the key of the run and holds the same plain text.
 * A legacy file is decrypted with the voucher ID of a row that references it
 * (the first 32-byte block is probed per candidate) and must be a complete
 * PDF or JPEG; other files, files above --max-size, files whose voucher ID
 * changed (a merge) and names that are not file numbers are skipped and
 * listed, and keep working as legacy files.
 *
 *   --dry-run        report only; writes, deletes and locks nothing
 *   --key-fingerprint=HEX
 *                    required for every run that writes or deletes: the key
 *                    fingerprint (8 hex digits) that the dry run printed; the
 *                    run stops if the configured key has another one
 *   --delete-legacy  the second phase
 *   --recreate-unverified
 *                    re-create an existing <n>.v2.aes that does not verify
 *                    with the key of the run from <n>.aes (by default it is
 *                    kept and reported as failed)
 *   --include-unusual
 *                    also migrate files that the voucher ID decrypts to
 *                    another recognized type (PNG, GIF, TIFF), to an empty
 *                    file, or to a PDF or JPEG that looks incomplete or has
 *                    data after its end; the download stays byte-identical
 *   --search-voucher-ids
 *                    for a file that no voucher ID of its rows decrypts (a
 *                    merged voucher), try every other voucher ID of the
 *                    vouchers table and the unused ones up to the highest
 *                    voucher number; only a complete PDF or JPEG is accepted
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
 * The --include-unusual and --search-voucher-ids of the migration run must
 * be repeated for --delete-legacy, which decrypts the legacy files the same
 * way. One run at a time: a run holds an exclusive lock on
 * files/.migrate-receipt-key.lock. Safe to run again. Every existing
 * <n>.v2.aes is checked like a download (MAC, then chunked decryption) before
 * anything is written; when two or more, and at least half of them, do not
 * verify, the key is probably wrong and the run stops. The first phase never
 * deletes anything. A new <n>.v2.aes gets the owner, group and mode of its
 * <n>.aes (or is not written). Before --delete-legacy deletes anything it
 * runs --sync (default: sync), because PHP 5.3 cannot fsync the new files.
 * The summary shows the key fingerprint (only here; the web application
 * never logs it); the key itself is never printed. Exit status: 0 done, 1 a
 * file failed, 2 not run (nothing changed), 3 done, but legacy files were
 * skipped (or, in the delete phase, are not migrated yet); the summary lists
 * them.
 *
 * Runs on PHP 5.3 and newer.
 */

require_once __DIR__ . '/vendor/autoload.php';
// PHPUnit and Infection have already loaded functions.php (or a copy of it).
if (!function_exists('receipt_encrypt')) {
    require_once __DIR__ . '/functions.php';
}

function receipt_migration_usage()
{
    return "Usage: php migrate-receipt-key.php [--dry-run] [--key-fingerprint=HEX] [--delete-legacy]\n"
        . "       [--recreate-unverified] [--include-unusual] [--search-voucher-ids] [--limit=N]\n"
        . "       [--max-size=BYTES] [--files=DIR] [--constants=FILE] [--database=CONNINFO] [--sync=COMMAND]\n";
}

// A php.ini size ("128M", "1G", "512K", "1048576") in bytes; -1 is unlimited.
function receipt_migration_bytes($value)
{
    $value = trim($value);
    $units = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];
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
    $options = [
        'dry-run' => false,
        'key-fingerprint' => null,
        'delete-legacy' => false,
        'recreate-unverified' => false,
        'include-unusual' => false,
        'search-voucher-ids' => false,
        'limit' => 0,
        'max-size' => receipt_migration_default_max_size(),
        'files' => __DIR__ . '/files',
        'constants' => 'constants.php',
        'database' => 'dbname=accounting',
        'sync' => 'sync',
    ];
    foreach ($arguments as $argument) {
        if (in_array($argument, ['--dry-run', '--delete-legacy', '--recreate-unverified', '--include-unusual', '--search-voucher-ids'], true)) {
            $options[substr($argument, 2)] = true;
        } elseif (preg_match('/^--key-fingerprint=([0-9a-fA-F]{8})$/', $argument, $match)) {
            $options['key-fingerprint'] = strtolower($match[1]);
        } elseif (preg_match('/^--(limit|max-size)=([1-9][0-9]*)$/', $argument, $match)) {
            $options[$match[1]] = intval($match[2]);
        } elseif (preg_match('/^--(files|constants)=(.+)$/', $argument, $match)) {
            $options[$match[1]] = str_starts_with($match[2], '/') ? $match[2] : getcwd() . '/' . $match[2];
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
    return $key ?? null;
}

// File numbers of the legacy receipt files, ascending, and the other *.aes
// names (not <n>.v2.aes): array(numbers, names). A file number is canonical
// and fits PostgreSQL's integer (and a 32-bit PHP integer).
function receipt_migration_numbers($directory, $names)
{
    $numbers = [];
    $others = [];
    foreach ($names as $name) {
        if (!is_file("$directory/$name") || !str_ends_with($name, '.aes') || str_ends_with($name, '.v2.aes')) {
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
    return [$numbers, $others];
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
    if (str_starts_with($data, '%PDF')) {
        return str_contains(substr($data, -1024), '%%EOF');
    }
    return str_starts_with($data, "\xFF\xD8\xFF") && str_ends_with($data, "\xFF\xD9");
}


/*
 * What --include-unusual accepts, by the first decrypted block ($head) and
 * the size of the legacy file: a description, or null. A wrong voucher ID
 * decrypts to random bytes, which practically never start like one of these.
 */
function receipt_migration_unusual_type($head, $size)
{
    $types = [
        '%PDF' => 'a PDF that looks incomplete or has data after its end',
        "\xFF\xD8\xFF" => 'a JPEG that looks incomplete or has data after its end',
        "\x89PNG\r\n\x1A\n" => 'a PNG image',
        'GIF87a' => 'a GIF image',
        'GIF89a' => 'a GIF image',
        "II*\0" => 'a TIFF image',
        "MM\0*" => 'a TIFF image',
    ];
    foreach ($types as $magic => $type) {
        if (str_starts_with($head, $magic)) {
            return $type;
        }
    }
    // An empty upload was stored as one block of zero padding.
    return $size === 32 && $head === str_repeat("\0", 32) ? 'an empty file' : null;
}

// Voucher IDs for --search-voucher-ids: those of the vouchers table and the
// unused ones up to the highest voucher number (a merge leaves the merged
// voucher's ID unused), ascending; or false if the query failed.
function receipt_migration_search_ids($connection)
{
    $result = @pg_query(
        $connection,
        'SELECT id FROM (SELECT voucher_id AS id FROM vouchers UNION SELECT generate_series(1, GREATEST('
        . '(SELECT max(voucher_id) FROM vouchers), (SELECT last_value FROM voucher_number)))) AS ids '
        . 'WHERE id IS NOT NULL ORDER BY id',
    );
    if ($result === false) {
        return false;
    }
    $ids = pg_fetch_all_columns($result, 0);
    pg_free_result($result);
    return $ids;
}

/*
 * Decrypts a legacy receipt: array('ok', voucher ID, padded plain text,
 * note) with the first voucher that references the file, decrypts its first
 * block to a PDF or JPEG and the whole file to a complete one (or, with
 * --include-unusual, to an unusual type, or with --search-voucher-ids, with
 * another voucher ID to a complete one); otherwise array(status, message).
 */
function receipt_migration_legacy($connection, $options, $number)
{
    $path = receipt_legacy_path($options['files'], $number);
    $size = filesize($path);
    if ($options['max-size'] > 0 && $size > $options['max-size']) {
        return ['skipped', "$size bytes, above --max-size={$options['max-size']}"];
    }
    $legacy = (string) @file_get_contents($path);
    $result = @pg_query_params(
        $connection,
        'SELECT DISTINCT voucher_id FROM vouchers WHERE file = $1 ORDER BY voucher_id',
        [$number],
    );
    if ($result === false) {
        return ['failed', 'database query failed: ' . pg_last_error($connection)];
    }
    $ids = pg_fetch_all_columns($result, 0);
    pg_free_result($result);
    $unusual = null;
    foreach ($ids as $id) {
        // CBC: the first block decrypts on its own, without the whole file.
        $head = receipt_decrypt($id, '', substr($legacy, 0, 32));
        $type = receipt_migration_unusual_type($head, $size);
        if ($type === null) {
            continue;
        }
        $plain = receipt_decrypt($id, '', $legacy);
        if (receipt_has_known_type($head) && receipt_migration_is_complete($plain)) {
            return ['ok', $id, $plain, ''];
        }
        $unusual = $unusual ?? [$id, $plain, $type];
    }
    if ($unusual !== null) {
        return $options['include-unusual'] ? ['ok', $unusual[0], $unusual[1], ", unusual: {$unusual[2]}"]
            : ['skipped', "voucher {$unusual[0]} decrypts it to {$unusual[2]}, not a complete PDF or JPEG (--include-unusual migrates it)"];
    }
    $message = 'no voucher with this file decrypts it to a complete PDF or JPEG';
    if (!$options['search-voucher-ids']) {
        return ['skipped', "$message (--search-voucher-ids tries the other voucher IDs)"];
    }
    $candidates = receipt_migration_search_ids($connection);
    if ($candidates === false) {
        return ['failed', 'database query failed: ' . pg_last_error($connection)];
    }
    $rows = $ids === [] ? 'no voucher has this file' : 'its rows have voucher ' . implode(', ', $ids);
    foreach (array_diff($candidates, $ids) as $id) {
        if (!receipt_has_known_type(receipt_decrypt($id, '', substr($legacy, 0, 32)))) {
            continue;
        }
        $plain = receipt_decrypt($id, '', $legacy);
        if (receipt_migration_is_complete($plain)) {
            return ['ok', $id, $plain, ", found by --search-voucher-ids; $rows"];
        }
    }
    return ['skipped', "$message, and no other voucher ID does"];
}

/*
 * Writes $bytes as $path with the owner, group and mode of the legacy file
 * $legacyPath, through a renamed temporary file: '' on success, otherwise
 * what failed. Only the temporary file is ever removed.
 */
function receipt_migration_write($path, $bytes, $legacyPath)
{
    $temp = receipt_temp_path($path);
    $stat = stat($legacyPath);
    $name = basename($legacyPath);
    $written = @file_put_contents($temp, $bytes) === strlen($bytes);
    $owned = $written && @chmod($temp, $stat['mode'] & 0777) && (fileowner($temp) === $stat['uid'] || @chown($temp, $stat['uid']))
        && (filegroup($temp) === $stat['gid'] || @chgrp($temp, $stat['gid']));
    // One statement: as root, chown() and chgrp() cannot be made to fail in
    // the tests (ReceiptMigrationCommandTest runs that case as another user).
    $problem = !$written ? 'could not be written' : (!$owned ? "could not be given the owner, group and mode of $name (run as the owner of $name, or as root)" : (@rename($temp, $path) ? '' : 'could not be written'));
    if ($problem !== '') {
        @unlink($temp);
    }
    return $problem;
}

/*
 * One legacy file: array(status, message); status is a summary counter.
 * $verified: whether <n>.v2.aes verified with the key of the run, null if
 * there is none.
 */
function receipt_migration_file($connection, $options, $key, $number, $verified)
{
    $v2Path = receipt_v2_path($options['files'], $number);
    if ($verified === false && ($options['delete-legacy'] || !$options['recreate-unverified'])) {
        return ['failed', "$number.v2.aes does not verify with this key; both kept"
            . ($options['delete-legacy'] ? '' : " (check the key; --recreate-unverified re-creates it from $number.aes)")];
    }
    if (!$options['delete-legacy'] && $verified === true) {
        return ['present', "already migrated; $number.aes kept until --delete-legacy"];
    }
    $legacy = receipt_migration_legacy($connection, $options, $number);
    if ($legacy[0] !== 'ok' && $verified === false) {
        return ['failed', "$number.v2.aes does not verify, and $number.aes cannot replace it ({$legacy[1]}); both kept"];
    }
    if ($legacy[0] !== 'ok') {
        return [$legacy[0], $legacy[0] === 'skipped' ? 'skipped, legacy kept: ' . $legacy[1] : $legacy[1]];
    }
    [, $id, $plain, $note] = $legacy;

    if ($options['delete-legacy']) {
        if ($verified === null) {
            return ['pending', "not migrated yet (run the migration first); $number.aes kept"];
        }
        if (receipt_v2_read_with($key, $number, $v2Path) !== $plain) {
            return ['failed', "$number.v2.aes does not decrypt to the content of $number.aes with this key; both kept"];
        }
        if ($options['dry-run']) {
            return ['deleted', "verified; would delete $number.aes"];
        }
        // One statement: deleting cannot be made to fail in the tests (root).
        return @unlink(receipt_legacy_path($options['files'], $number)) ? ['deleted', "verified; deleted $number.aes"] : ['failed', "verified, but $number.aes could not be deleted"];
    }

    $recreate = $verified === false ? "$number.v2.aes does not verify; " : '';
    if ($options['dry-run']) {
        return ['migrated', $recreate === '' ? "would migrate (voucher $id$note)" : $recreate . "would re-create it (voucher $id$note)"];
    }
    $problem = receipt_migration_write($v2Path, receipt_v2_encrypt($key, $number, $plain), receipt_legacy_path($options['files'], $number));
    if ($problem !== '') {
        return ['failed', "$number.v2.aes $problem; $number.aes kept"];
    }
    // Read back. One statement: a written file cannot be made to differ in the
    // tests. The file is this run's own, so it may be removed.
    return receipt_v2_read_with($key, $number, $v2Path) !== $plain ? (@unlink($v2Path) ? ['failed', "$number.v2.aes did not verify and was removed; $number.aes kept"] : ['failed', "$number.v2.aes did not verify and could not be removed; $number.aes kept"]) : ['migrated', ($recreate === '' ? 'migrated' : $recreate . 're-created it') . " (voucher $id$note); $number.aes kept until --delete-legacy"];
}

// Migrates the listed files; returns the exit status.
function receipt_migration_run($connection, $options, $key, $names, $out, $err)
{
    $counts = ['migrated' => 0, 'present' => 0, 'deleted' => 0, 'pending' => 0, 'skipped' => 0, 'failed' => 0];
    $lists = ['skipped' => [], 'pending' => [], 'failed' => []];
    $changed = 0;
    [$numbers, $others] = receipt_migration_numbers($options['files'], $names);
    // Every existing <n>.v2.aes is checked like a download, before anything
    // is written or deleted.
    $verified = [];
    foreach ($numbers as $number) {
        $v2Path = receipt_v2_path($options['files'], $number);
        $verified[$number] = is_file($v2Path) ? receipt_v2_verify_with($key, $number, $v2Path) : null;
    }
    $existing = count(array_filter($verified, 'is_bool'));
    $unverified = count(array_keys($verified, false, true));
    if ($unverified >= 2 && $unverified * 2 >= $existing) {
        fwrite($err, "$unverified of the $existing existing <n>.v2.aes files do not verify with the key of this run "
            . '(key fingerprint ' . receipt_key_fingerprint($key) . '): the key is probably not the one they were written with. '
            . "Nothing was changed. Check \$key in {$options['constants']}; if it is right, move the damaged files away "
            . "(keep a copy) and run again.\n");
        return 2;
    }
    if ($options['delete-legacy']) {
        fwrite($out, "*** --delete-legacy: only after `sync` and after checking several migrated receipts in the browser. "
            . "Deleted legacy files can only be restored from the backup. ***\n");
    }
    foreach ($others as $name) {
        $counts['skipped']++;
        $lists['skipped'][] = $name;
        fwrite($out, "$name: skipped, legacy kept: not a file number\n");
    }
    foreach ($numbers as $number) {
        if ($options['limit'] > 0 && $changed >= $options['limit']) {
            fwrite($out, "Stopped after --limit={$options['limit']}; run again for the remaining files.\n");
            break;
        }
        [$status, $message] = receipt_migration_file($connection, $options, $key, $number, $verified[$number]);
        $counts[$status]++;
        if (isset($lists[$status])) {
            $lists[$status][] = $number;
        }
        if ($status === 'migrated' || $status === 'deleted') {
            $changed++;
        }
        fwrite($out, "$number.aes: $message\n");
    }
    $labels = ['skipped' => 'Skipped, legacy kept', 'pending' => 'Not migrated yet', 'failed' => 'Failed'];
    foreach ($lists as $status => $list) {
        if ($list !== []) {
            fwrite($out, "{$labels[$status]}: " . implode(', ', $list) . "\n");
        }
    }
    fwrite($out, ($options['dry-run'] ? 'Dry run, nothing written: ' : '')
        . "{$counts['migrated']} migrated, {$counts['present']} already migrated, {$counts['deleted']} legacy deleted, "
        . "{$counts['pending']} not yet migrated, {$counts['skipped']} skipped, {$counts['failed']} failed; "
        . 'key fingerprint ' . receipt_key_fingerprint($key) . "\n");
    return $counts['failed'] > 0 ? 1 : ($counts['skipped'] + $counts['pending'] > 0 ? 3 : 0);
}

// Runs the flush command; true if it succeeded.
function receipt_migration_sync($command)
{
    exec($command . ' 2>&1', $output, $status);
    return $status === 0;
}

// The effective user ID (POSIX extension, else Linux's /proc), or false.
function receipt_migration_euid()
{
    return function_exists('posix_geteuid') ? posix_geteuid() : @fileowner('/proc/self');
}

function receipt_migration_main($arguments, $out, $err)
{
    $options = receipt_migration_options($arguments);
    if ($options === false) {
        fwrite($err, receipt_migration_usage());
        return 2;
    }
    if (receipt_migration_euid() === 0) {
        fwrite($err, "Warning: running as root. Each new <n>.v2.aes gets the owner, group and mode of its <n>.aes; "
            . "running as the web server's user is preferred.\n");
    }
    $key = receipt_migration_key($options['constants']);
    if (!receipt_key_is_valid($key)) {
        fwrite($err, "No valid receipt key: \$key in {$options['constants']} must have at least 16 characters "
            . "and not be the CHANGE-ME placeholder. Nothing was changed.\n");
        return 2;
    }
    $fingerprint = receipt_key_fingerprint($key);
    if ($options['key-fingerprint'] === null && !$options['dry-run']) {
        fwrite($err, "A run that writes or deletes needs --key-fingerprint=<8 hex digits>: run with --dry-run first, "
            . "check that the key fingerprint it prints is the one of the earlier runs, and pass it. Nothing was changed.\n");
        return 2;
    }
    if ($options['key-fingerprint'] !== null && $options['key-fingerprint'] !== $fingerprint) {
        fwrite($err, "--key-fingerprint={$options['key-fingerprint']} does not match the key fingerprint $fingerprint "
            . "of \$key in {$options['constants']}. Nothing was changed.\n");
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
    $status = $connection === false || !$flushed ? 2 : receipt_migration_run($connection, $options, $key, $names, $out, $err);
    if ($lock !== null) {
        fclose($lock);
    }
    return $status;
}

if (isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    exit(receipt_migration_main(array_slice($_SERVER['argv'], 1), STDOUT, STDERR));
}
