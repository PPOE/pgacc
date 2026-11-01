<?php

/*
 * Collects the PHP diagnostics that tests/runtime/diagnostics-prepend.php
 * records on the test servers and fails the test script on any diagnostic
 * that is not listed in tests/known-diagnostics.php.
 *
 * Every HTTP request carries an X-Pgacc-Test-Request ID; the server writes
 * that request's diagnostics to PGACC_TEST_DIAGNOSTICS_DIR/<id>.jsonl on a
 * volume shared with the test container. Displayed diagnostics in the
 * response body are also detected as a cross-check.
 */

$GLOBALS['pgacc_test_diagnostics'] = [];
$GLOBALS['pgacc_test_request_counter'] = 0;
$GLOBALS['pgacc_test_known_failure'] = null;
$GLOBALS['pgacc_test_diagnostics_reported'] = false;
$GLOBALS['pgacc_test_checked_requests'] = 0;

// PHP minor version of the application server under test: the client runs
// on the same image.
function server_php_version()
{
    return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
}

function diagnostics_directory()
{
    $directory = getenv('PGACC_TEST_DIAGNOSTICS_DIR');
    if ($directory === false || $directory === '' || !is_dir($directory)) {
        fail_test('PGACC_TEST_DIAGNOSTICS_DIR is not configured or missing');
    }
    return rtrim($directory, '/');
}

function next_diagnostics_request_id()
{
    $GLOBALS['pgacc_test_request_counter']++;
    $script = preg_replace('/[^A-Za-z0-9]/', '', basename($_SERVER['SCRIPT_NAME'], '.php'));
    return $script . '-' . getmypid() . '-' . $GLOBALS['pgacc_test_request_counter'];
}

function known_diagnostics()
{
    static $known = null;
    if ($known === null) {
        $known = require __DIR__ . '/known-diagnostics.php';
    }
    return $known;
}

function known_diagnostic_reason($record)
{
    foreach (known_diagnostics() as $entry) {
        if (in_array($record['php'], $entry['php'], true)
            && $entry['type'] === $record['type']
            && $entry['file'] === $record['file']
            && in_array($record['line'], $entry['lines'], true)
            && preg_match($entry['message'], $record['message']) === 1
            && $entry['reported'] === $record['reported']) {
            return $entry['id'] . ': ' . $entry['reason'];
        }
    }
    return null;
}

// Application path relative to the application root: /app in the test images, or
// the copy the mutation runner serves (PGACC_TEST_APP_ROOT).
function displayed_relative_file($file)
{
    $roots = ['/app'];
    if (getenv('PGACC_TEST_APP_ROOT')) {
        $roots[] = rtrim(getenv('PGACC_TEST_APP_ROOT'), '/');
    }
    foreach ($roots as $root) {
        if (strpos($file, $root . '/') === 0) {
            return substr($file, strlen($root) + 1);
        }
    }
    return $file;
}

// Diagnostics displayed in the body, e.g. "<b>Deprecated</b>:  msg in
// <b>/app/new.php</b> on line <b>450</b>" or the plain-text equivalent.
function displayed_body_diagnostics($body)
{
    $pattern = '/(?:<b>)?(Fatal error|Parse error|Recoverable fatal error|Warning|Notice'
        . '|Deprecated|Strict Standards)(?:<\/b>)?:\s+(.*?) in (?:<b>)?(\S+?)(?:<\/b>)?'
        . ' on line (?:<b>)?(\d+)/';
    $found = [];
    if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $found[] = [
                'label' => $match[1],
                'message' => strip_tags($match[2]),
                'file' => displayed_relative_file($match[3]),
                'line' => intval($match[4]),
            ];
        }
    }
    return $found;
}

function collect_response_diagnostics($requestId, $label, $response)
{
    $path = diagnostics_directory() . '/' . $requestId . '.jsonl';
    $completed = false;
    $records = [];
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                fail_test("unreadable diagnostics record for $label: $line");
            }
            if ($record['type'] === 'REQUEST_END') {
                $completed = true;
                continue;
            }
            $records[] = $record;
        }
    }
    if (!$completed) {
        fail_test("diagnostics recorder did not run to completion for $label");
    }
    $GLOBALS['pgacc_test_checked_requests']++;

    // Cross-check: anything displayed in the body must also be in the log.
    foreach (displayed_body_diagnostics($response['body']) as $displayed) {
        $logged = false;
        foreach ($records as $record) {
            if ($record['reported'] && $record['file'] === $displayed['file']
                && $record['line'] === $displayed['line']) {
                $logged = true;
            }
        }
        if (!$logged) {
            $records[] = [
                'uri' => '',
                'php' => server_php_version(),
                'type' => 'DISPLAYED ' . $displayed['label'],
                'message' => $displayed['message'],
                'file' => $displayed['file'],
                'line' => $displayed['line'],
                'reported' => true,
            ];
        }
    }

    foreach ($records as $record) {
        $record['label'] = $label;
        $record['known_failure'] = $GLOBALS['pgacc_test_known_failure'];
        $record['known'] = known_diagnostic_reason($record);
        $GLOBALS['pgacc_test_diagnostics'][] = $record;
    }
}

function diagnostic_status($record)
{
    if ($record['known_failure'] !== null) {
        return 'known failure ' . $record['known_failure'];
    }
    if ($record['known'] !== null) {
        return 'known diagnostic ' . $record['known'];
    }
    return 'UNEXPECTED';
}

function unexpected_diagnostics_count()
{
    $count = 0;
    foreach ($GLOBALS['pgacc_test_diagnostics'] as $record) {
        if (diagnostic_status($record) === 'UNEXPECTED') {
            $count++;
        }
    }
    return $count;
}

function print_diagnostics_summary()
{
    $GLOBALS['pgacc_test_diagnostics_reported'] = true;
    $groups = [];
    foreach ($GLOBALS['pgacc_test_diagnostics'] as $record) {
        $key = implode('|', [
            diagnostic_status($record),
            $record['type'],
            $record['file'],
            $record['line'],
            $record['message'],
            $record['reported'] ? 'reported' : 'hidden',
        ]);
        if (!isset($groups[$key])) {
            $groups[$key] = ['record' => $record, 'labels' => []];
        }
        $groups[$key]['labels'][$record['label']] = true;
    }
    $script = basename($_SERVER['SCRIPT_NAME']);
    $php = server_php_version();
    $checked = $GLOBALS['pgacc_test_checked_requests'];
    if (count($groups) === 0) {
        echo "PHP $php diagnostics in $script: none in $checked checked request(s)\n";
        return;
    }
    $out = (unexpected_diagnostics_count() > 0) ? STDERR : STDOUT;
    fwrite($out, "PHP $php diagnostics in $script ($checked checked request(s)):\n");
    foreach ($groups as $group) {
        $record = $group['record'];
        $labels = array_keys($group['labels']);
        $more = count($labels) > 3 ? ' (+' . (count($labels) - 3) . ' more)' : '';
        fwrite($out, sprintf(
            "  [%s] %s %s:%d%s: %s\n      requests: %s%s\n",
            diagnostic_status($record),
            $record['type'],
            $record['file'],
            $record['line'],
            $record['reported'] ? '' : ' (hidden by error_reporting/@)',
            $record['message'],
            implode(', ', array_slice($labels, 0, 3)),
            $more,
        ));
    }
}

function assert_no_unexpected_diagnostics()
{
    print_diagnostics_summary();
    $count = unexpected_diagnostics_count();
    if ($count > 0) {
        fail_test("$count unexpected PHP diagnostic(s); fix them or add a reviewed "
            . 'entry to tests/known-diagnostics.php');
    }
}

// Requests made inside $callback belong to a documented known failure: their
// diagnostics are reported but attributed to it instead of failing the run.
function with_known_failure($id, $callback)
{
    $previous = $GLOBALS['pgacc_test_known_failure'];
    $GLOBALS['pgacc_test_known_failure'] = $id;
    $result = call_user_func($callback);
    $GLOBALS['pgacc_test_known_failure'] = $previous;
    return $result;
}

// Requests that raise known, documented diagnostics (known failures of code
// outside a test's scope, tests/COMPATIBILITY_PLAN.md, or a deliberately
// provoked one such as a refused certificate): they are attributed to $id,
// must all match $expected (file, message part), and must still occur.
function with_expected_diagnostics($id, $expected, $callback)
{
    $before = count($GLOBALS['pgacc_test_diagnostics']);
    $result = with_known_failure($id, $callback);
    $seen = 0;
    for ($i = $before; $i < count($GLOBALS['pgacc_test_diagnostics']); $i++) {
        $record = $GLOBALS['pgacc_test_diagnostics'][$i];
        if ($record['known_failure'] !== $id) {
            continue;
        }
        $matched = false;
        foreach ($expected as $diagnostic) {
            if ($record['file'] === $diagnostic[0] && strpos($record['message'], $diagnostic[1]) !== false) {
                $matched = true;
            }
        }
        if (!$matched) {
            fail_test("$id: unexpected diagnostic {$record['file']}:{$record['line']} {$record['message']}");
        }
        $seen++;
    }
    if ($seen === 0) {
        fail_test("$id no longer occurs: turn it into a regular check");
    }
    return $result;
}

function report_diagnostics_on_early_exit()
{
    if (!$GLOBALS['pgacc_test_diagnostics_reported']) {
        print_diagnostics_summary();
    }
}
register_shutdown_function('report_diagnostics_on_early_exit');
