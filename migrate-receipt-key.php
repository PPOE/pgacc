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
 *       [--delete-legacy] [--recreate-unverified] [--ignore-unverified=N,...]
 *       [--include-unusual] [--search-voucher-ids] [--limit=N]
 *       [--max-size=BYTES] [--files=DIR] [--constants=FILE]
 *       [--database=CONNINFO] [--sync=COMMAND]
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
 *                    fingerprint (8 hex digits) that the user administration
 *                    page shows root; the run stops if the configured key
 *                    of the run has another one
 *   --delete-legacy  the second phase
 *   --recreate-unverified
 *                    re-create an existing <n>.v2.aes that does not verify
 *                    with the key of the run from <n>.aes; the key check
 *                    then reads every existing <n>.v2.aes
 *   --ignore-unverified=N,...
 *                    leave these <n>.v2.aes that do not verify alone
 *                    (skipped; by default such a file stops the run)
 *   --include-unusual
 *                    also migrate files that the voucher ID decrypts to
 *                    another recognized type (PNG, GIF, TIFF), to an empty
 *                    file, or to a PDF or JPEG that looks incomplete or has
 *                    data after its end; the download sends the same bytes
 *                    (PNG and GIF as image/png and image/gif, other types
 *                    as a download instead of image/jpeg)
 *   --search-voucher-ids
 *                    for a file that no voucher ID of its rows decrypts (a
 *                    merged voucher), try every other voucher ID of the
 *                    vouchers table and the unused ones up to the highest
 *                    voucher number; only a complete PDF or JPEG is accepted.
 *                    One single-block decryption per file and candidate ID;
 *                    IDs found are tried first for the next file
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
 * files/.migrate-receipt-key.lock. Safe to run again. Before anything is
 * written or deleted, the MAC of up to 16 existing <n>.v2.aes (first those
 * without a legacy file, e.g. uploads since the upgrade; with
 * --recreate-unverified of all) is checked; if one does not verify, the key
 * is probably wrong and the run stops (exit status 2) with the list, unless
 * --recreate-unverified or --ignore-unverified covers it. A run without
 * --limit also checks every other <n>.v2.aes it reaches; the delete phase
 * decrypts each one once before deleting. The first phase never deletes
 * anything. A new <n>.v2.aes gets the owner, group and mode of its <n>.aes
 * when run as root; otherwise its mode and, where permitted, its group, and
 * it is not written if the owner or group of <n>.aes could not read it. Before --delete-legacy deletes anything it runs --sync (default:
 * sync), because PHP 5.3 cannot fsync the new files. The summary shows the
 * key fingerprint (also shown to root on the user administration page); the
 * key itself is never printed. Exit status: 0 done, 1 a file failed, 2 not
 * run (nothing changed), 3 done, but legacy files were skipped (in the
 * delete phase: every legacy file without <n>.v2.aes); the summary lists
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
        . "       [--recreate-unverified] [--ignore-unverified=N,...] [--include-unusual] [--search-voucher-ids]\n"
        . "       [--limit=N] [--max-size=BYTES] [--files=DIR] [--constants=FILE]\n"
        . "       [--database=CONNINFO] [--sync=COMMAND]\n";
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
        'ignore-unverified' => [],
        'root' => false,
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
        } elseif (preg_match('/^--ignore-unverified=([1-9][0-9]*(,[1-9][0-9]*)*)$/', $argument, $match)) {
            $options['ignore-unverified'] = explode(',', $match[1]);
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

// A file number is canonical and fits PostgreSQL's integer (and a 32-bit
// PHP integer).
function receipt_migration_is_number($number)
{
    return preg_match('/^[1-9][0-9]{0,9}$/', $number) && (strlen($number) < 10 || strcmp($number, '2147483647') <= 0);
}

// File numbers of the legacy receipt files, ascending, and the other *.aes
// names (not <n>.v2.aes): array(numbers, names).
function receipt_migration_numbers($directory, $names)
{
    $numbers = [];
    $others = [];
    foreach ($names as $name) {
        if (!is_file("$directory/$name") || !str_ends_with($name, '.aes') || str_ends_with($name, '.v2.aes')) {
            continue;
        }
        $number = substr($name, 0, -4);
        if (receipt_migration_is_number($number)) {
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
 * The first of the voucher $ids that decrypts $legacy (of $size bytes) to a
 * complete PDF or JPEG: array(voucher ID, padded plain text, ''). The first
 * block is probed per ID (CBC: it decrypts on its own); only a PDF or JPEG
 * head needs the whole file. $mode 'complete' accepts nothing else; 'report'
 * also returns an ID whose first block shows what --include-unusual accepts
 * as array(voucher ID, null, description), without decrypting the rest;
 * 'accept' does the same with the whole plain text. Otherwise null.
 */
function receipt_migration_decrypt($ids, $legacy, $size, $mode)
{
    $found = null;
    foreach ($ids as $id) {
        $head = receipt_decrypt($id, '', substr($legacy, 0, 32));
        $pdfOrJpeg = in_array(receipt_content_type($head), ['application/pdf', 'image/jpeg'], true);
        $type = $mode === 'complete' ? null : receipt_migration_unusual_type($head, $size);
        if (!$pdfOrJpeg && $type === null) {
            continue;
        }
        $plain = $pdfOrJpeg || $mode === 'accept' ? receipt_decrypt($id, '', $legacy) : null;
        if ($pdfOrJpeg && receipt_migration_is_complete($plain)) {
            return [$id, $plain, ''];
        }
        if ($type !== null) {
            $found = $found ?? [$id, $plain, $type];
        }
    }
    return $found;
}

/*
 * Decrypts a legacy receipt: array('ok', voucher ID, padded plain text,
 * note) with a voucher that references the file and decrypts it to a
 * complete PDF or JPEG (or, with --include-unusual, to an unusual type, or
 * with --search-voucher-ids, with another voucher ID to a complete one);
 * otherwise array(status, message). $search: the run's voucher IDs for the
 * search (fetched once, when first needed) and the IDs it found, which are
 * tried first (the files of a merged voucher share its old ID).
 */
function receipt_migration_legacy($connection, $options, $number, &$search)
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
    $found = receipt_migration_decrypt($ids, $legacy, $size, $options['include-unusual'] ? 'accept' : 'report');
    if ($found !== null && ($found[2] === '' || $options['include-unusual'])) {
        return ['ok', $found[0], $found[1], $found[2] === '' ? '' : ", unusual: {$found[2]}"];
    }
    if ($found !== null) {
        return ['skipped', "voucher {$found[0]} decrypts it to {$found[2]}, not a complete PDF or JPEG (--include-unusual migrates it)"];
    }
    $message = 'no voucher with this file decrypts it to a complete PDF or JPEG';
    if (!$options['search-voucher-ids']) {
        return ['skipped', "$message (--search-voucher-ids tries the other voucher IDs)"];
    }
    $search['ids'] = $search['ids'] ?? receipt_migration_search_ids($connection);
    if ($search['ids'] === false) {
        return ['failed', 'database query failed: ' . pg_last_error($connection)];
    }
    $found = receipt_migration_decrypt(array_diff(array_unique(array_merge($search['found'], $search['ids'])), $ids), $legacy, $size, 'complete');
    if ($found === null) {
        return ['skipped', "$message, and no other voucher ID does"];
    }
    $search['found'] = array_unique(array_merge([$found[0]], $search['found']));
    $rows = $ids === [] ? 'no voucher has this file' : 'its rows have voucher ' . implode(', ', $ids);
    return ['ok', $found[0], $found[1], ", found by --search-voucher-ids; $rows"];
}

/*
 * Writes $bytes as $path (receipt_write_file()) for the web server, which
 * reads the legacy file $legacyPath: as root ($root) with its owner, group
 * and mode; otherwise with its mode and, where permitted, its group, as long
 * as its owner or group can still read the file. Set on the temporary file
 * before the rename. Returns array(problem, note): problem '' on success,
 * note '' or a warning for the output.
 */
function receipt_migration_write($path, $bytes, $legacyPath, $root)
{
    $stat = stat($legacyPath);
    $name = basename($legacyPath);
    $problem = 'could not be written';
    $note = '';
    $prepare = function ($temp) use ($stat, $name, $root, &$problem, &$note) {
        $mode = $stat['mode'] & 0777;
        if ($root) {
            $owned = @chmod($temp, $mode) && @chown($temp, $stat['uid']) && @chgrp($temp, $stat['gid']);
            // One statement: as root, this cannot be made to fail in the tests.
            $problem = $owned ? $problem : "could not be given the owner, group and mode of $name";
            return $owned;
        }
        // The run's own file: the mode can be set, the group if the run's
        // user belongs to it, the owner not.
        @chmod($temp, $mode);
        if (filegroup($temp) !== $stat['gid']) {
            @chgrp($temp, $stat['gid']);
            clearstatcache();
        }
        $owner = fileowner($temp) === $stat['uid'];
        $group = filegroup($temp) === $stat['gid'];
        $readable = $owner && ($mode & 0400) || $group && ($mode & 0040) || ($mode & 0004);
        $problem = $readable ? $problem : "would not be readable by the owner or group of $name (run as its owner, or as root)";
        $note = $owner && $group ? '' : "; warning: owner or group differ from $name, readable for "
            . ($owner ? 'its owner' : ($group && ($mode & 0040) ? 'its group' : 'everyone'));
        return $readable;
    };
    return receipt_write_file($path, $bytes, $prepare) ? ['', $note] : [$problem, ''];
}

// True if <n>.v2.aes ($path) has a valid MAC for $key: enough to tell a
// wrong key or a modified file, without decrypting it.
function receipt_migration_mac_ok($key, $number, $path)
{
    $receipt = receipt_v2_open_with($key, $number, $path);
    if ($receipt === false) {
        return false;
    }
    fclose($receipt['handle']);
    return true;
}

/*
 * One legacy file: array(status, message); status is a summary counter.
 * $verified: whether <n>.v2.aes has a valid MAC for the key of the run, from
 * the key check (receipt_migration_check_key()), null if it was not checked
 * there.
 */
function receipt_migration_file($connection, $options, $key, $number, $verified, &$search)
{
    $v2Path = receipt_v2_path($options['files'], $number);
    $hasV2 = is_file($v2Path);
    if (!$hasV2 && $options['delete-legacy']) {
        // Not decrypted: whether the migration skipped it or has not reached
        // it yet, its legacy file stays.
        return ['skipped', "skipped, legacy kept: no $number.v2.aes (skipped by the migration or not migrated yet; "
            . 'a run without --delete-legacy lists why)'];
    }
    // The first phase checks the other existing files, but not in --limit
    // batches (the run without --limit and the delete phase check each one).
    if ($hasV2 && !$options['delete-legacy']) {
        $verified = $verified ?? ($options['limit'] > 0 ? 'unchecked' : receipt_migration_mac_ok($key, $number, $v2Path));
    }
    if ($verified === 'unchecked') {
        return ['present', "already migrated (not checked again in a --limit run); $number.aes kept until --delete-legacy"];
    }
    if ($verified === true && !$options['delete-legacy']) {
        return ['present', "already migrated; $number.aes kept until --delete-legacy"];
    }
    $invalid = $hasV2 && $verified === false;
    if ($invalid && in_array($number, $options['ignore-unverified'], true)) {
        return ['skipped', "skipped, legacy kept: $number.v2.aes does not verify with this key (--ignore-unverified)"];
    }
    if ($invalid && ($options['delete-legacy'] || !$options['recreate-unverified'])) {
        return ['failed', "$number.v2.aes does not verify with this key; both kept (--recreate-unverified re-creates it "
            . "from $number.aes, --ignore-unverified=$number leaves it)"];
    }
    $legacy = receipt_migration_legacy($connection, $options, $number, $search);
    if ($legacy[0] !== 'ok' && $invalid) {
        return ['failed', "$number.v2.aes does not verify, and $number.aes cannot replace it ({$legacy[1]}); both kept"];
    }
    if ($legacy[0] !== 'ok') {
        return [$legacy[0], $legacy[0] === 'skipped' ? 'skipped, legacy kept: ' . $legacy[1] : $legacy[1]];
    }
    [, $id, $plain, $note] = $legacy;

    if ($options['delete-legacy']) {
        // The one decryption of <n>.v2.aes in this phase (MAC included).
        if (receipt_v2_read_with($key, $number, $v2Path) !== $plain) {
            return ['failed', "$number.v2.aes does not decrypt to the content of $number.aes with this key; both kept"];
        }
        if ($options['dry-run']) {
            return ['deleted', "verified; would delete $number.aes"];
        }
        // One statement: deleting cannot be made to fail in the tests (root).
        return @unlink(receipt_legacy_path($options['files'], $number)) ? ['deleted', "verified; deleted $number.aes"] : ['failed', "verified, but $number.aes could not be deleted"];
    }

    $recreate = $invalid ? "$number.v2.aes does not verify; " : '';
    if ($options['dry-run']) {
        return ['migrated', $recreate === '' ? "would migrate (voucher $id$note)" : $recreate . "would re-create it (voucher $id$note)"];
    }
    [$problem, $warning] = receipt_migration_write($v2Path, receipt_v2_encrypt($key, $number, $plain), receipt_legacy_path($options['files'], $number), $options['root']);
    if ($problem !== '') {
        return ['failed', "$number.v2.aes $problem; $number.aes kept"];
    }
    // Read back. One statement: a written file cannot be made to differ in the
    // tests. The file is this run's own, so it may be removed.
    return receipt_v2_read_with($key, $number, $v2Path) !== $plain ? (@unlink($v2Path) ? ['failed', "$number.v2.aes did not verify and was removed; $number.aes kept"] : ['failed', "$number.v2.aes did not verify and could not be removed; $number.aes kept"]) : ['migrated', ($recreate === '' ? 'migrated' : $recreate . 're-created it') . " (voucher $id$note); $number.aes kept until --delete-legacy$warning"];
}

/*
 * The key check before anything is written or deleted: the MAC of up to 16
 * existing <n>.v2.aes, first those without a legacy file (written by the
 * application, or whose legacy file was deleted), then the migrated ones,
 * by ascending number, and those listed in --ignore-unverified; with
 * --recreate-unverified of every existing one. A
 * wrong key fails every file, so a bounded sample finds it, and --limit
 * batches do not read every receipt again. Returns array(result per
 * number, message): the run stops (message not '') if a checked file does
 * not verify, unless it is listed in --ignore-unverified or, with
 * --recreate-unverified, still has a legacy file to re-create it from.
 */
function receipt_migration_check_key($options, $key, $names, $numbers)
{
    $standalone = [];
    $migrated = [];
    $legacy = array_flip($numbers);
    foreach ($names as $name) {
        $number = substr($name, 0, -7);
        if (str_ends_with($name, '.v2.aes') && receipt_migration_is_number($number) && is_file(receipt_v2_path($options['files'], $number))) {
            isset($legacy[$number]) ? $migrated[] = $number : $standalone[] = $number;
        }
    }
    sort($standalone, SORT_NUMERIC);
    sort($migrated, SORT_NUMERIC);
    $checked = array_merge($standalone, $migrated);
    $verified = [];
    // The files of --ignore-unverified are always checked, so that they are
    // skipped wherever they are.
    $sample = $options['recreate-unverified'] ? $checked : array_unique(array_merge(array_slice($checked, 0, 16), array_intersect($checked, $options['ignore-unverified'])));
    foreach ($sample as $number) {
        $verified[$number] = receipt_migration_mac_ok($key, $number, receipt_v2_path($options['files'], $number));
    }
    $failing = array_diff(array_keys($verified, false, true), $options['ignore-unverified']);
    if ($options['recreate-unverified']) {
        $failing = array_intersect($failing, $standalone);
    }
    if ($failing === []) {
        return [$verified, ''];
    }
    $list = implode(',', $failing);
    return [$verified, 'These existing <n>.v2.aes do not verify with the key of this run (key fingerprint '
        . receipt_key_fingerprint($key) . "): $list. Nothing was changed. If the key is wrong, run with the "
        . "constants.php of the application (--key-fingerprint from its user administration page). If the key is right "
        . 'and the files are damaged, keep a copy of them and either re-create those that still have a legacy file with '
        . "--recreate-unverified, or leave them alone with --ignore-unverified=$list.\n"];
}

// Migrates the listed files; returns the exit status.
function receipt_migration_run($connection, $options, $key, $names, $out, $err)
{
    $counts = ['migrated' => 0, 'present' => 0, 'deleted' => 0, 'skipped' => 0, 'failed' => 0];
    $lists = ['skipped' => [], 'failed' => []];
    $changed = 0;
    [$numbers, $others] = receipt_migration_numbers($options['files'], $names);
    [$verified, $message] = receipt_migration_check_key($options, $key, $names, $numbers);
    if ($message !== '') {
        fwrite($err, $message);
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
    $search = ['ids' => null, 'found' => []];
    foreach ($numbers as $number) {
        if ($options['limit'] > 0 && $changed >= $options['limit']) {
            fwrite($out, "Stopped after --limit={$options['limit']}; run again for the remaining files.\n");
            break;
        }
        [$status, $message] = receipt_migration_file($connection, $options, $key, $number, $verified[$number] ?? null, $search);
        $counts[$status]++;
        if (isset($lists[$status])) {
            $lists[$status][] = $number;
        }
        if ($status === 'migrated' || $status === 'deleted') {
            $changed++;
        }
        fwrite($out, "$number.aes: $message\n");
    }
    $labels = ['skipped' => 'Skipped, legacy kept', 'failed' => 'Failed'];
    foreach ($lists as $status => $list) {
        if ($list !== []) {
            fwrite($out, "{$labels[$status]}: " . implode(', ', $list) . "\n");
        }
    }
    fwrite($out, ($options['dry-run'] ? 'Dry run, nothing written: ' : '')
        . "{$counts['migrated']} migrated, {$counts['present']} already migrated, {$counts['deleted']} legacy deleted, "
        . "{$counts['skipped']} skipped, {$counts['failed']} failed; "
        . 'key fingerprint ' . receipt_key_fingerprint($key) . "\n");
    return $counts['failed'] > 0 ? 1 : ($counts['skipped'] > 0 ? 3 : 0);
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
    $options['root'] = receipt_migration_euid() === 0;
    if ($options['root']) {
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
        fwrite($err, "A run that writes or deletes needs --key-fingerprint=<8 hex digits>: the key fingerprint that the "
            . "user administration page (Benutzerverwaltung) shows root, from the application whose receipts these are. "
            . "Nothing was changed.\n");
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
