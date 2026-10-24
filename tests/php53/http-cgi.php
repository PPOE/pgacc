<?php
/*
 * PHP 5.3 lane only: checks that the HTTP tests really talk to the
 * production-like server (Lighttpd + PHP-CGI 5.3.3-7+squeeze19, not the
 * PHP 5.6 client running them), that the diagnostics recorder runs there,
 * and the TLS-proxy redirect of index.php: without "X-Forwarded-Proto: https"
 * it redirects to https://<Host><REQUEST_URI>.
 *
 * Runs in the PHP 5.6 client image. Must stay compatible with PHP 5.6.
 */
require dirname(__DIR__) . '/http-test-helpers.php';
require_once dirname(__DIR__) . '/http-legacy-headers.php';

// The ID that next_diagnostics_request_id() gave the latest request.
function last_request_id()
{
    return preg_replace('/[^A-Za-z0-9]/', '', basename($_SERVER['SCRIPT_NAME'], '.php'))
        . '-' . getmypid() . '-' . $GLOBALS['pgacc_test_request_counter'];
}

$servers = array(
    'buffered' => getenv('PGACC_TEST_BASE_URL'),
    'output_buffering=0' => getenv('PGACC_TEST_UNBUFFERED_BASE_URL'),
);
foreach ($servers as $name => $baseUrl) {
    if ($baseUrl === false || $baseUrl === '') {
        fail_test("no base URL for the $name PHP 5.3 server");
    }
    $response = http_request('/index.php?action=transactions', 'GET', array(), array(), $baseUrl);
    assert_status($response, 200, "$name server");
    $poweredBy = response_header($response, 'X-Powered-By');
    if ($poweredBy !== 'PHP/5.3.3-7+squeeze19') {
        fail_test("$name server sent X-Powered-By " . var_export($poweredBy, true));
    }
    $server = response_header($response, 'Server');
    if ($server !== 'lighttpd/1.4.28') {
        fail_test("$name server sent Server " . var_export($server, true));
    }

    // The recorder must have run under PHP 5.3 (known diagnostics are keyed
    // by the server's PHP version, not the client's).
    $log = diagnostics_directory() . '/' . last_request_id() . '.jsonl';
    $records = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $end = json_decode($records[count($records) - 1], true);
    if (!is_array($end) || $end['type'] !== 'REQUEST_END' || $end['php'] !== '5.3') {
        fail_test("$name server: diagnostics recorder did not run on PHP 5.3: " . end($records));
    }
}

// Behind production's TLS proxy every request carries X-Forwarded-Proto:
// https. A plain-HTTP request is redirected before anything else runs.
$context = stream_context_create(array(
    'http' => array(
        'method' => 'GET',
        'header' => "Host: pgacc.test\r\nConnection: close\r\nX-Pgacc-Test-Request: " . next_diagnostics_request_id(),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ),
));
list($body, $headers) = legacy_file_get_contents_with_headers(
    rtrim($servers['buffered'], '/') . '/index.php?year=2024',
    $context
);
if ($body === false || count($headers) === 0 || !preg_match('/^HTTP\/\S+ 302 /', $headers[0])) {
    fail_test('plain-HTTP request was not redirected: ' . var_export($headers, true));
}
$redirect = array('headers' => $headers, 'body' => $body);
assert_location($redirect, 'https://pgacc.test/index.php?year=2024', 'plain-HTTP request');
collect_response_diagnostics(
    last_request_id(),
    'GET /index.php?year=2024 without X-Forwarded-Proto',
    $redirect
);

assert_no_unexpected_diagnostics();
echo "PHP 5.3 Lighttpd/PHP-CGI server checks passed\n";
