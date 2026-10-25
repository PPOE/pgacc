<?php

/*
 * Test-only diagnostics recorder, loaded with auto_prepend_file by the test
 * servers. It records every PHP diagnostic raised while serving a request to
 * a JSON-lines log that the HTTP tests inspect, including diagnostics that the
 * runtime's default error_reporting hides and ones silenced with "@".
 *
 * It does not change application behaviour: the handler returns false, so
 * PHP's standard handler still runs with the runtime's own error_reporting,
 * display_errors and output buffering. Only functions with a test-specific
 * prefix are defined; no global variables are left behind.
 */

function pgacc_test_diagnostics_log_path()
{
    $directory = getenv('PGACC_TEST_DIAGNOSTICS_DIR');
    if ($directory === false || $directory === '') {
        return null;
    }
    $requestId = 'untagged';
    if (isset($_SERVER['HTTP_X_PGACC_TEST_REQUEST'])
        && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $_SERVER['HTTP_X_PGACC_TEST_REQUEST']) === 1) {
        $requestId = $_SERVER['HTTP_X_PGACC_TEST_REQUEST'];
    }
    return rtrim($directory, '/') . '/' . $requestId . '.jsonl';
}

function pgacc_test_diagnostics_type_name($type)
{
    $names = array(
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    );
    // E_STRICT is deprecated as a constant on PHP 8.4+; its value is 2048.
    if ($type === 2048) {
        return 'E_STRICT';
    }
    if ($type === 0) {
        return 'REQUEST_END';
    }
    return isset($names[$type]) ? $names[$type] : 'E_UNKNOWN_' . $type;
}

function pgacc_test_diagnostics_relative_file($file)
{
    $root = rtrim(isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '', '/') . '/';
    if ($root !== '/' && strpos($file, $root) === 0) {
        return substr($file, strlen($root));
    }
    return $file;
}

function pgacc_test_diagnostics_record($type, $message, $file, $line, $reported)
{
    $path = pgacc_test_diagnostics_log_path();
    if ($path === null) {
        return;
    }
    $record = array(
        'uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
        'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        'type' => pgacc_test_diagnostics_type_name($type),
        'message' => $message,
        'file' => pgacc_test_diagnostics_relative_file($file),
        'line' => $line,
        // Whether the runtime's error_reporting (including "@") lets PHP's
        // own handler report it, i.e. whether it was displayed/logged.
        'reported' => $reported,
    );
    $json = json_encode($record);
    if ($json === false) {
        $record['message'] = 'non-UTF-8 message, hex: ' . bin2hex($message);
        $record['uri'] = bin2hex($record['uri']);
        $json = json_encode($record);
    }
    file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX);
}

function pgacc_test_diagnostics_handler($type, $message, $file, $line)
{
    pgacc_test_diagnostics_record(
        $type,
        $message,
        $file,
        $line,
        (error_reporting() & $type) !== 0
    );
    // Continue with PHP's standard error handling, unchanged.
    return false;
}

function pgacc_test_diagnostics_shutdown()
{
    // These cannot reach a user error handler; PHP already reported them.
    $unhandled = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING
        | E_COMPILE_ERROR | E_COMPILE_WARNING;
    $error = error_get_last();
    if ($error !== null && ($error['type'] & $unhandled) !== 0) {
        pgacc_test_diagnostics_record(
            $error['type'],
            $error['message'],
            $error['file'],
            $error['line'],
            true
        );
    }
    // Completion marker: lets the tests prove the recorder ran for a request
    // rather than mistaking a missing log for a clean response.
    pgacc_test_diagnostics_record(0, 'request complete', '', 0, false);
}

set_error_handler('pgacc_test_diagnostics_handler', E_ALL | 2048);
register_shutdown_function('pgacc_test_diagnostics_shutdown');
