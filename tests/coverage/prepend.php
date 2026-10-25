<?php

/*
 * Test-only auto_prepend_file for the coverage run.
 *
 * The application server (php -S) loads this file before every PHP request
 * when compose.coverage.yaml is active. It starts Xdebug line+branch coverage
 * (line-only with PGACC_COVERAGE_BRANCHES=0) and, after the application and
 * its own shutdown functions have run, writes the raw Xdebug data for that
 * request to PGACC_COVERAGE_DIR. tests/coverage/
 * merge.php later merges these files with the PHPUnit coverage.
 *
 * Only one auto_prepend_file is possible. Another test prepend (such as a
 * diagnostics recorder) is chained by naming it in
 * PGACC_COVERAGE_CHAINED_PREPEND; it is included after coverage has started,
 * in global scope, and its shutdown functions run before coverage is written.
 *
 * Constraints: this file must never produce output, send or alter headers,
 * define global variables, or fail the request. Everything lives inside
 * functions with a project-specific prefix, and every failure is silent
 * (reported through error_log() to the server's stderr only).
 */

function pgacc_coverage_start()
{
    $directory = getenv('PGACC_COVERAGE_DIR');
    if ($directory === false || $directory === '' || !function_exists('xdebug_start_code_coverage')) {
        return;
    }

    if (function_exists('xdebug_set_filter')) {
        // Only top-level application files are reported; skip vendor and tests.
        xdebug_set_filter(
            XDEBUG_FILTER_CODE_COVERAGE,
            XDEBUG_PATH_EXCLUDE,
            ['/app/vendor/', '/app/tests/'],
        );
    }

    $flags = XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE;
    if (getenv('PGACC_COVERAGE_BRANCHES') !== '0') {
        $flags |= XDEBUG_CC_BRANCH_CHECK;
    }
    xdebug_start_code_coverage($flags);
    register_shutdown_function('pgacc_coverage_register_last', $directory);
}

function pgacc_coverage_register_last($directory)
{
    // Registered during shutdown, so it runs after any shutdown functions
    // the application registered itself.
    register_shutdown_function('pgacc_coverage_write', $directory);
}

function pgacc_coverage_write($directory)
{
    // The application has finished; collecting branch/path data for large
    // legacy functions needs far more than the request's memory_limit.
    ini_set('memory_limit', '-1');
    $data = xdebug_get_code_coverage();
    xdebug_stop_code_coverage();

    // Keep line and branch data only. Xdebug enumerates every path through
    // each function; for the large legacy functions that is tens of MB per
    // request and the path metric is not meaningful there.
    foreach ($data as $file => $fileData) {
        if (isset($fileData['functions'])) {
            foreach ($fileData['functions'] as $function => $functionData) {
                $data[$file]['functions'][$function]['paths'] = [];
            }
        }
    }

    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        error_log("pgacc coverage: cannot create $directory");
        return;
    }

    $name = sprintf(
        '%s/%s-%d-%s',
        $directory,
        str_replace('.', '', sprintf('%.6F', microtime(true))),
        getmypid(),
        bin2hex(random_bytes(4)),
    );
    $request = isset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])
        ? $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI']
        : 'unknown request';
    $payload = serialize(['request' => $request, 'coverage' => $data]);

    if (@file_put_contents($name . '.tmp', $payload) === false || !@rename($name . '.tmp', $name . '.ser')) {
        error_log("pgacc coverage: cannot write $name.ser");
    }
}

function pgacc_coverage_chained_prepend()
{
    $file = getenv('PGACC_COVERAGE_CHAINED_PREPEND');

    return $file === false || $file === '' ? null : $file;
}

pgacc_coverage_start();
if (pgacc_coverage_chained_prepend() !== null) {
    require pgacc_coverage_chained_prepend();
}
