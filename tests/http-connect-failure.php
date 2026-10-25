<?php

/*
 * Database connection failure: index.php, check.php and mbapi.php answer with
 * a fixed message and log the reason (pg_last_error() without a connection
 * throws on PHP 8). A second test server with an unreachable PostgreSQL
 * (PGHOST=127.0.0.1, PGPORT=1) runs in this container; with
 * compose.coverage.yaml it records coverage like the main server. Its
 * requests bypass http_request(): no diagnostics recorder runs there, and the
 * expected pg_connect() warning is not displayed (display_errors=0).
 */
require __DIR__ . '/http-test-helpers.php';

$port = 8083;
$log = '/tmp/connect-failure-server.log';
$settings = ' -d display_errors=0 -d log_errors=0';
$environment = 'PGHOST=127.0.0.1 PGPORT=1 PGCONNECT_TIMEOUT=2';
$coverageDir = getenv('PGACC_COVERAGE_DIR');
if ($coverageDir !== false && $coverageDir !== '') {
    $settings .= ' -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/coverage/prepend.php');
    $environment .= ' XDEBUG_MODE=coverage PGACC_COVERAGE_DIR=' . escapeshellarg($coverageDir . '/http')
        . ' PGACC_COVERAGE_CHAINED_PREPEND=';
}
$server = proc_open(
    'exec env ' . $environment . ' php' . $settings . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(dirname(__DIR__)),
    array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $log, 'a'), 2 => array('file', $log, 'a')),
    $pipes
);
if (!is_resource($server)) {
    fail_test('could not start the connection-failure server');
}
register_shutdown_function('stop_unbuffered_server', $server);
for ($attempt = 0; $attempt < 50 && ($socket = @fsockopen('127.0.0.1', $port)) === false; $attempt++) {
    usleep(100000);
}
if ($socket === false) {
    fail_test('connection-failure server did not start: ' . @file_get_contents($log));
}
fclose($socket);

foreach (array('/index.php', '/check.php?kto=SYNTHETIC0&max=2', '/mbapi.php') as $path) {
    $context = stream_context_create(array('http' => array(
        'header' => "X-Forwarded-Proto: https\r\n",
        'ignore_errors' => true,
        'timeout' => 20,
    )));
    $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    if ($body !== 'Verbindungsaufbau fehlgeschlagen') {
        fail_test("$path with an unreachable database answered " . var_export($body, true));
    }
}
$logged = substr_count((string) file_get_contents($log), 'pgacc: pg_connect failed: pg_connect(): Unable to connect to PostgreSQL server');
if ($logged !== 3) {
    fail_test("expected 3 logged connection failures, found $logged: " . file_get_contents($log));
}

echo "Connection failure tests passed\n";
