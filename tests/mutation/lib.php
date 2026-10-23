<?php
/*
 * Core logic of the target-driven mutation runner (tests/mutation/runner.php):
 * target validation, exact-match mutation application, outcome
 * classification, result summaries, and a process runner with timeouts.
 *
 * Covered by tests/mutation/tests/LibTest.php. Must stay compatible with
 * PHP 5.6: no scalar type declarations, no "??", no array proc_open commands.
 */

// Must match the application service command in compose.test.yaml
// (checked by tests/mutation/tests/LibTest.php).
function pgacc_mutation_server_ini()
{
    return array('output_buffering=4096', 'auto_prepend_file=%APP%/tests/runtime/diagnostics-prepend.php');
}

// Same files and order as tests/database-smoke.sh.
function pgacc_mutation_fixture_files()
{
    return array('tests/fixtures/schema.sql', 'tests/fixtures/data.sql', 'tests/database-characterization.sql');
}

// Statements that drop database $name even while clients are connected.
// DROP DATABASE ... WITH (FORCE) needs PostgreSQL 13 (server_version_num
// 130000); older servers (PGACC_TEST_PG=9.4) first terminate the other
// backends, which pg_stat_activity.pid names from 9.2 on. $name must already
// be a validated identifier.
function pgacc_mutation_drop_database_sql($name, $serverVersionNum)
{
    if ((int) $serverVersionNum >= 130000) {
        return array("DROP DATABASE IF EXISTS $name WITH (FORCE)");
    }
    return array(
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$name' AND pid <> pg_backend_pid()",
        "DROP DATABASE IF EXISTS $name",
    );
}

class PgaccMutationException extends RuntimeException
{
}

function pgacc_mutation_fail($message)
{
    throw new PgaccMutationException($message);
}

// Counts non-overlapping occurrences; substr_count() rejects empty needles.
function pgacc_mutation_count($haystack, $needle)
{
    if (!is_string($needle) || $needle === '') {
        pgacc_mutation_fail('search strings must be non-empty strings');
    }
    return substr_count($haystack, $needle);
}

function pgacc_mutation_is_list($value)
{
    return is_array($value) && array_keys($value) === range(0, count($value) - 1);
}

function pgacc_mutation_require_string($array, $key, $context)
{
    if (!isset($array[$key]) || !is_string($array[$key]) || trim($array[$key]) === '') {
        pgacc_mutation_fail("$context: '$key' must be a non-empty string");
    }
    return $array[$key];
}

function pgacc_mutation_check_relative_path($path, $context)
{
    if (!is_string($path) || $path === '' || $path[0] === '/'
        || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
        pgacc_mutation_fail("$context: path must be relative to the application root: "
            . var_export($path, true));
    }
    return $path;
}

function pgacc_mutation_validate_test($test, $context)
{
    if (!is_array($test) || !isset($test['type'])) {
        pgacc_mutation_fail("$context: each test needs a 'type'");
    }
    if ($test['type'] === 'http') {
        pgacc_mutation_check_relative_path(
            pgacc_mutation_require_string($test, 'script', $context),
            $context
        );
        return $test;
    }
    if ($test['type'] === 'phpunit') {
        pgacc_mutation_require_string($test, 'filter', $context);
        if (!isset($test['file'])) {
            $test['file'] = 'tests/unit/FunctionsTest.php';
        }
        pgacc_mutation_check_relative_path($test['file'], $context);
        return $test;
    }
    pgacc_mutation_fail("$context: unknown test type " . var_export($test['type'], true)
        . " (expected 'http' or 'phpunit')");
}

function pgacc_mutation_validate_region($region, $context)
{
    if (!is_array($region) || !isset($region['start']) || !isset($region['end'])
        || !is_string($region['start']) || !is_string($region['end'])
        || $region['start'] === '' || $region['end'] === '') {
        pgacc_mutation_fail("$context: 'region' needs non-empty 'start' and 'end' strings");
    }
    return $region;
}

/*
 * Validates a target definition (see tests/mutation/README.md) and fills in
 * defaults: per-mutation 'file' and 'region' from the target, 'timeout'
 * (seconds per mutant, all test commands together), and 'equivalent' => null.
 */
function pgacc_mutation_validate_target($target, $name)
{
    if (!is_array($target)) {
        pgacc_mutation_fail("target $name must return an array");
    }
    $context = "target $name";
    pgacc_mutation_require_string($target, 'description', $context);
    if (!isset($target['tests']) || !pgacc_mutation_is_list($target['tests'])
        || count($target['tests']) === 0) {
        pgacc_mutation_fail("$context: 'tests' must be a non-empty list");
    }
    foreach ($target['tests'] as $index => $test) {
        $target['tests'][$index] = pgacc_mutation_validate_test($test, "$context test #$index");
    }
    if (!isset($target['timeout'])) {
        $target['timeout'] = 120;
    }
    if (!is_int($target['timeout']) || $target['timeout'] < 1) {
        pgacc_mutation_fail("$context: 'timeout' must be a positive integer (seconds)");
    }
    if (isset($target['file'])) {
        pgacc_mutation_check_relative_path($target['file'], $context);
    }
    if (isset($target['region'])) {
        pgacc_mutation_validate_region($target['region'], $context);
    }
    if (!isset($target['mutations']) || !pgacc_mutation_is_list($target['mutations'])
        || count($target['mutations']) === 0) {
        pgacc_mutation_fail("$context: 'mutations' must be a non-empty list");
    }
    $ids = array();
    foreach ($target['mutations'] as $index => $mutation) {
        if (!is_array($mutation)) {
            pgacc_mutation_fail("$context mutation #$index must be an array");
        }
        $id = pgacc_mutation_require_string($mutation, 'id', "$context mutation #$index");
        $mutationContext = "$context mutation $id";
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $id) !== 1) {
            pgacc_mutation_fail("$mutationContext: id may only contain letters, digits, '-' and '_'");
        }
        if ($id === 'baseline') {
            pgacc_mutation_fail("$context: the mutation id 'baseline' is reserved for the unmutated run");
        }
        if (isset($ids[$id])) {
            pgacc_mutation_fail("$context: duplicate mutation id $id");
        }
        $ids[$id] = true;
        pgacc_mutation_require_string($mutation, 'rationale', $mutationContext);
        if (!isset($mutation['file'])) {
            if (!isset($target['file'])) {
                pgacc_mutation_fail("$mutationContext: no 'file' on the mutation or the target");
            }
            $mutation['file'] = $target['file'];
        }
        pgacc_mutation_check_relative_path($mutation['file'], $mutationContext);
        if (!isset($mutation['region']) && isset($target['region'], $target['file'])
            && $mutation['file'] === $target['file']) {
            $mutation['region'] = $target['region'];
        }
        if (isset($mutation['region'])) {
            pgacc_mutation_validate_region($mutation['region'], $mutationContext);
        } else {
            $mutation['region'] = null;
        }
        if (!array_key_exists('search', $mutation) || !array_key_exists('replace', $mutation)) {
            pgacc_mutation_fail("$mutationContext: needs 'search' and 'replace'");
        }
        $searches = is_array($mutation['search']) ? $mutation['search'] : array($mutation['search']);
        if (!pgacc_mutation_is_list($searches) || count($searches) === 0) {
            pgacc_mutation_fail("$mutationContext: 'search' must be a string or a list of spellings");
        }
        if (is_array($mutation['replace'])) {
            if (!pgacc_mutation_is_list($mutation['replace'])
                || count($mutation['replace']) !== count($searches)) {
                pgacc_mutation_fail("$mutationContext: a 'replace' list needs one entry per 'search' spelling");
            }
            $replaces = $mutation['replace'];
        } else {
            $replaces = array_fill(0, count($searches), $mutation['replace']);
        }
        foreach ($searches as $i => $search) {
            if (!is_string($search) || $search === '' || !is_string($replaces[$i])) {
                pgacc_mutation_fail("$mutationContext: search/replace must be strings, search non-empty");
            }
            if ($search === $replaces[$i]) {
                pgacc_mutation_fail("$mutationContext: replacement is identical to the search string");
            }
            $searchLines = substr_count(str_replace("\r\n", "\n", $search), "\n");
            $replaceLines = substr_count(str_replace("\r\n", "\n", $replaces[$i]), "\n");
            if ($searchLines !== $replaceLines) {
                // Shifted lines would move known diagnostics (file:line) and
                // be "killed" by the diagnostics check rather than the tests.
                pgacc_mutation_fail("$mutationContext: replacement must keep the line count "
                    . "($searchLines newline(s) in search, $replaceLines in replace)");
            }
        }
        $mutation['search'] = $searches;
        $mutation['replace'] = $replaces;
        if (!isset($mutation['equivalent'])) {
            $mutation['equivalent'] = null;
        } else {
            $equivalent = $mutation['equivalent'];
            pgacc_mutation_require_string($equivalent, 'reason', "$mutationContext equivalent");
            if (isset($equivalent['php']) && (!pgacc_mutation_is_list($equivalent['php'])
                || count($equivalent['php']) === 0)) {
                pgacc_mutation_fail("$mutationContext: equivalent 'php' must be a non-empty list of versions");
            }
        }
        $target['mutations'][$index] = $mutation;
    }
    return $target;
}

function pgacc_mutation_load_target($path)
{
    if (!is_file($path)) {
        pgacc_mutation_fail("target file not found: $path");
    }
    $target = require $path;
    return pgacc_mutation_validate_target($target, basename($path, '.php'));
}

// Returns the [start, end) byte range that a mutation may touch.
function pgacc_mutation_region_bounds($source, $region, $context)
{
    if ($region === null) {
        return array(0, strlen($source));
    }
    foreach (array('start', 'end') as $key) {
        $count = pgacc_mutation_count($source, $region[$key]);
        if ($count !== 1) {
            pgacc_mutation_fail("$context: region $key marker must occur exactly once, found $count: "
                . var_export($region[$key], true));
        }
    }
    $start = strpos($source, $region['start']);
    $end = strpos($source, $region['end']);
    if ($end < $start + strlen($region['start'])) {
        pgacc_mutation_fail("$context: region end marker precedes the start marker");
    }
    return array($start, $end);
}

/*
 * Applies one validated mutation. Search strings are written with "\n"; in a
 * CRLF file they match CRLF line endings and the mutant keeps CRLF, so only the
 * mutated bytes differ. Exactly one occurrence of exactly one listed spelling
 * must exist inside the region, otherwise the definition is wrong and this
 * throws. Returns the mutated source, the 1-based line, and the spelling index.
 */
function pgacc_mutation_apply($source, $mutation)
{
    $context = 'mutation ' . $mutation['id'] . ' (' . $mutation['file'] . ')';
    $lf = str_replace("\r\n", "\n", $source);
    $crlf = $lf !== $source;
    if ($crlf && substr_count($source, "\n") !== substr_count($source, "\r\n")) {
        pgacc_mutation_fail("$context: file mixes CRLF and LF line endings");
    }
    $region = $mutation['region'];
    if ($region !== null) {
        $region = array(
            'start' => str_replace("\r\n", "\n", $region['start']),
            'end' => str_replace("\r\n", "\n", $region['end']),
        );
    }
    list($start, $end) = pgacc_mutation_region_bounds($lf, $region, $context);
    $scope = substr($lf, $start, $end - $start);
    $found = array();
    foreach ($mutation['search'] as $index => $search) {
        $search = str_replace("\r\n", "\n", $search);
        $count = pgacc_mutation_count($scope, $search);
        if ($count > 0) {
            $found[] = array($index, $count);
        }
    }
    $where = $region === null ? 'the file' : 'the region';
    if (count($found) === 0) {
        pgacc_mutation_fail("$context: search string not found in $where: "
            . var_export($mutation['search'], true));
    }
    if (count($found) > 1) {
        pgacc_mutation_fail("$context: more than one listed spelling matches in $where; "
            . 'keep only one or add context');
    }
    list($index, $count) = $found[0];
    if ($count !== 1) {
        pgacc_mutation_fail("$context: search string must match exactly once in $where, found $count; "
            . 'add surrounding context: ' . var_export($mutation['search'][$index], true));
    }
    $search = str_replace("\r\n", "\n", $mutation['search'][$index]);
    $replace = str_replace("\r\n", "\n", $mutation['replace'][$index]);
    $offset = $start + strpos($scope, $search);
    $mutant = substr_replace($lf, $replace, $offset, strlen($search));
    return array(
        'source' => $crlf ? str_replace("\n", "\r\n", $mutant) : $mutant,
        'line' => substr_count(substr($lf, 0, $offset), "\n") + 1,
        'spelling' => $index,
    );
}

/*
 * $lintExit: exit status of "php -l" on the mutated file.
 * $runs: test command results in execution order, each
 * array('exit' => int, 'timed_out' => bool); the runner stops at the first
 * failing or timed-out command.
 */
function pgacc_mutation_classify($lintExit, $runs)
{
    if ($lintExit !== 0) {
        return 'invalid';
    }
    foreach ($runs as $run) {
        if ($run['timed_out']) {
            return 'timeout';
        }
        if ($run['exit'] !== 0) {
            return 'killed';
        }
    }
    return 'survived';
}

// The accepted-equivalent reason for this PHP minor version, or null.
function pgacc_mutation_equivalent_reason($mutation, $phpMinor)
{
    $equivalent = $mutation['equivalent'];
    if ($equivalent === null) {
        return null;
    }
    if (isset($equivalent['php']) && !in_array($phpMinor, $equivalent['php'], true)) {
        return null;
    }
    return $equivalent['reason'];
}

/*
 * Final status per mutant: a survivor listed as an accepted equivalent for this
 * PHP version becomes "equivalent"; a listed equivalent that is killed (or
 * times out) is "stale-equivalent" so the list cannot silently go out of date.
 */
function pgacc_mutation_final_status($status, $mutation, $phpMinor)
{
    $reason = pgacc_mutation_equivalent_reason($mutation, $phpMinor);
    if ($reason === null) {
        return $status;
    }
    if ($status === 'survived') {
        return 'equivalent';
    }
    if ($status === 'killed' || $status === 'timeout') {
        return 'stale-equivalent';
    }
    return $status;
}

// Timeouts count as detected (the test run did not complete normally).
function pgacc_mutation_summarize($results)
{
    $counts = array(
        'killed' => 0, 'timeout' => 0, 'equivalent' => 0, 'survived' => 0,
        'invalid' => 0, 'stale-equivalent' => 0,
    );
    foreach ($results as $result) {
        if (!isset($counts[$result['status']])) {
            pgacc_mutation_fail('unknown mutant status ' . var_export($result['status'], true));
        }
        $counts[$result['status']]++;
    }
    $valid = count($results) - $counts['invalid'];
    $detected = $counts['killed'] + $counts['timeout'];
    $failing = $counts['survived'] + $counts['invalid'] + $counts['stale-equivalent'];
    return array(
        'total' => count($results),
        'counts' => $counts,
        'detected' => $detected,
        'score' => $valid - $counts['equivalent'] > 0
            ? round(100 * $detected / ($valid - $counts['equivalent']), 1)
            : null,
        'exit_code' => $failing > 0 ? 1 : 0,
    );
}

function pgacc_mutation_format_summary($target, $phpVersion, $results, $summary)
{
    $lines = array();
    $lines[] = sprintf('Mutation target %s on PHP %s: %d mutant(s)', $target, $phpVersion, $summary['total']);
    foreach ($results as $result) {
        $line = sprintf('  %-17s %-40s %s:%d  %.1fs', strtoupper($result['status']), $result['id'],
            $result['file'], $result['line'], $result['seconds']);
        if (isset($result['note']) && $result['note'] !== null) {
            $line .= '  (' . $result['note'] . ')';
        }
        $lines[] = $line;
    }
    $counts = $summary['counts'];
    $lines[] = sprintf(
        'Detected %d (killed %d, timeout %d); survived %d; accepted equivalent %d; invalid %d; stale equivalent %d; score %s',
        $summary['detected'], $counts['killed'], $counts['timeout'], $counts['survived'],
        $counts['equivalent'], $counts['invalid'], $counts['stale-equivalent'],
        $summary['score'] === null ? 'n/a' : $summary['score'] . '%'
    );
    $lines[] = $summary['exit_code'] === 0
        ? 'Result: PASS'
        : 'Result: FAIL (unaccepted survivors, invalid mutants, or stale equivalents)';
    return implode("\n", $lines) . "\n";
}

/*
 * Builds a shell command that runs $arguments with extra environment variables
 * in a new session (process group), so a timeout can stop the command and
 * everything it started (e.g. the second server in tests/http-login.php).
 */
function pgacc_mutation_command($arguments, $environment)
{
    $parts = array('exec', 'env');
    foreach ($environment as $name => $value) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            pgacc_mutation_fail('invalid environment variable name: ' . $name);
        }
        $parts[] = escapeshellarg($name . '=' . $value);
    }
    $parts[] = 'setsid';
    foreach ($arguments as $argument) {
        $parts[] = escapeshellarg($argument);
    }
    return implode(' ', $parts);
}

// Requires the posix extension (present in every lane image).
function pgacc_mutation_kill_group($pid, $signal)
{
    // Fails harmlessly (ESRCH) when the group has already exited.
    @posix_kill(-$pid, $signal);
}

// Starts a command (see pgacc_mutation_command) with output appended to $log.
function pgacc_mutation_start($arguments, $environment, $cwd, $log)
{
    // "@": an unopenable log is reported by the exception below instead.
    $process = @proc_open(
        pgacc_mutation_command($arguments, $environment),
        array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('file', $log, 'a'),
            2 => array('file', $log, 'a'),
        ),
        $pipes,
        $cwd
    );
    if (!is_resource($process)) {
        pgacc_mutation_fail('could not start: ' . implode(' ', $arguments));
    }
    $status = proc_get_status($process);
    return array('process' => $process, 'pid' => $status['pid']);
}

// Terminates a started command and its whole process group.
function pgacc_mutation_stop($handle)
{
    pgacc_mutation_kill_group($handle['pid'], 15);
    for ($i = 0; $i < 50; $i++) {
        $status = proc_get_status($handle['process']);
        if (!$status['running']) {
            break;
        }
        usleep(20000);
    }
    pgacc_mutation_kill_group($handle['pid'], 9);
    proc_close($handle['process']);
}

/*
 * Runs a command to completion or until $timeout seconds have passed.
 * Returns array('exit' => int|null, 'timed_out' => bool, 'seconds' => float).
 */
function pgacc_mutation_run($arguments, $environment, $cwd, $log, $timeout)
{
    $started = microtime(true);
    $handle = pgacc_mutation_start($arguments, $environment, $cwd, $log);
    $status = proc_get_status($handle['process']);
    while ($status['running']) {
        if (microtime(true) - $started >= $timeout) {
            pgacc_mutation_stop($handle);
            file_put_contents($log, "\n[mutation runner] timed out after {$timeout}s\n", FILE_APPEND);
            return array('exit' => null, 'timed_out' => true,
                'seconds' => microtime(true) - $started);
        }
        usleep(20000);
        $status = proc_get_status($handle['process']);
    }
    // exitcode is only reported by the first call after the exit. Then stop
    // anything the command left running in its process group.
    pgacc_mutation_kill_group($handle['pid'], 9);
    proc_close($handle['process']);
    return array('exit' => $status['exitcode'], 'timed_out' => false,
        'seconds' => microtime(true) - $started);
}
