<?php
// Shared HTTP test helpers. Must stay compatible with PHP 5.6.

require_once __DIR__ . '/http-diagnostics.php';

function fail_test($message)
{
    fwrite(STDERR, "Application characterization failed: $message\n");
    exit(1);
}

// Test-client diagnostics (not the application's) are failures too, unless
// deliberately silenced with "@".
function test_client_error_handler($type, $message, $file, $line)
{
    if ((error_reporting() & $type) === 0) {
        return false;
    }
    fail_test("PHP diagnostic in test client: $message in $file:$line");
}
error_reporting(E_ALL);
set_error_handler('test_client_error_handler');

function http_request($path, $method = 'GET', $form = array(), $cookies = array(), $baseUrl = null)
{
    if ($baseUrl === null) {
        $baseUrl = getenv('PGACC_TEST_BASE_URL');
    }
    if ($baseUrl === false || $baseUrl === '') {
        fail_test('PGACC_TEST_BASE_URL is not configured');
    }

    $requestId = next_diagnostics_request_id();
    $headers = array(
        'X-Forwarded-Proto: https',
        'Host: pgacc.test',
        'Connection: close',
        'X-Pgacc-Test-Request: ' . $requestId,
    );

    if (count($cookies) > 0) {
        $cookieParts = array();
        foreach ($cookies as $name => $value) {
            $cookieParts[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $cookieParts);
    }

    $content = '';
    if ($method === 'POST') {
        $content = http_build_query($form, '', '&');
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ),
    ));

    $url = rtrim($baseUrl, '/') . $path;
    if (function_exists('http_get_last_response_headers')) {
        // PHP 8.4+; PHP 8.5 deprecates the magic $http_response_header local.
        $body = @file_get_contents($url, false, $context);
        $responseHeaders = http_get_last_response_headers();
    } else {
        require_once __DIR__ . '/http-legacy-headers.php';
        list($body, $responseHeaders) = legacy_file_get_contents_with_headers($url, $context);
    }
    if (!is_array($responseHeaders)) {
        $responseHeaders = array();
    }

    if ($body === false || count($responseHeaders) === 0) {
        fail_test("request failed for $method $path");
    }

    if (!preg_match('/^HTTP\/\S+ (\d{3})/', $responseHeaders[0], $matches)) {
        fail_test("could not parse status for $method $path");
    }

    $response = array(
        'status' => intval($matches[1]),
        'headers' => $responseHeaders,
        'body' => $body,
    );
    collect_response_diagnostics($requestId, "$method $path", $response);
    return $response;
}

function response_header($response, $name)
{
    $prefix = strtolower($name) . ':';
    foreach ($response['headers'] as $header) {
        if (strpos(strtolower($header), $prefix) === 0) {
            return trim(substr($header, strlen($prefix)));
        }
    }
    return null;
}

function response_cookies($response)
{
    $cookies = array();
    foreach ($response['headers'] as $header) {
        if (stripos($header, 'Set-Cookie:') !== 0) {
            continue;
        }
        $cookie = trim(substr($header, strlen('Set-Cookie:')));
        $pair = explode('=', explode(';', $cookie, 2)[0], 2);
        if (count($pair) === 2) {
            $cookies[$pair[0]] = $pair[1];
        }
    }
    return $cookies;
}

function assert_status($response, $expected, $label)
{
    if ($response['status'] !== $expected) {
        fail_test("$label returned HTTP {$response['status']}, expected $expected");
    }
}

function assert_location($response, $expected, $label)
{
    $location = response_header($response, 'Location');
    if ($location !== $expected) {
        fail_test("$label sent Location " . var_export($location, true) . ', expected ' . var_export($expected, true));
    }
}

function assert_contains($body, $expected, $label)
{
    if (strpos($body, $expected) === false) {
        fail_test("$label did not contain: $expected");
    }
}

function assert_not_contains($body, $unexpected, $label)
{
    if (strpos($body, $unexpected) !== false) {
        fail_test("$label unexpectedly contained: $unexpected");
    }
}

function assert_no_fatal_error($body, $label)
{
    assert_not_contains($body, 'Fatal error', $label);
    assert_not_contains($body, 'Parse error', $label);
}

function login_request($name, $password, $target = null, $baseUrl = null)
{
    $form = array(
        'submit' => 'Anmeldung',
        'name' => $name,
        'pass' => $password,
    );
    if ($target !== null) {
        $form['target'] = strtoupper(bin2hex($target));
    }
    return http_request('/index.php?action=login', 'POST', $form, array(), $baseUrl);
}

// Returns a list of problems (empty when the response is a valid login
// redirect), so callers can assert it or record it as a known failure.
function login_response_problems($response, $expectedLocation, $expectedUserId = null)
{
    $problems = array();
    if ($response['status'] !== 302) {
        $problems[] = "returned HTTP {$response['status']}, expected 302";
    }
    $location = response_header($response, 'Location');
    if ($location !== $expectedLocation) {
        $problems[] = 'Location was ' . var_export($location, true)
            . ', expected ' . var_export($expectedLocation, true);
    }
    $cookies = response_cookies($response);
    foreach (array('pp_pgacc_login', 'pp_pgacc_id') as $cookie) {
        if (!isset($cookies[$cookie]) || preg_match('/^-?\d+$/', $cookies[$cookie]) !== 1) {
            $problems[] = "missing or non-numeric $cookie cookie";
        }
    }
    if ($expectedUserId !== null && isset($cookies['pp_pgacc_id'])
        && $cookies['pp_pgacc_id'] !== strval($expectedUserId)) {
        $problems[] = "pp_pgacc_id cookie was {$cookies['pp_pgacc_id']}, expected $expectedUserId";
    }
    return $problems;
}

function logout_response_problems($response)
{
    $problems = array();
    if ($response['status'] !== 302) {
        $problems[] = "returned HTTP {$response['status']}, expected 302";
    }
    $location = response_header($response, 'Location');
    if ($location !== 'index.php') {
        $problems[] = 'Location was ' . var_export($location, true) . ", expected 'index.php'";
    }
    $cookies = response_cookies($response);
    foreach (array('pp_pgacc_login', 'pp_pgacc_id') as $cookie) {
        // PHP sends a cleared cookie as "deleted" with an expiry in the past.
        if (!isset($cookies[$cookie]) || !in_array($cookies[$cookie], array('', 'deleted'), true)) {
            $problems[] = "did not clear the $cookie cookie";
        }
    }
    return $problems;
}

// Problems of a redirect that is answered before any output: HTTP 302,
// exactly this Location, and an empty body (no page, byte order mark or
// displayed diagnostic before or after the header).
function redirect_problems($response, $expectedLocation)
{
    $problems = array();
    if ($response['status'] !== 302) {
        $problems[] = "returned HTTP {$response['status']}, expected 302";
    }
    $location = response_header($response, 'Location');
    if ($location !== $expectedLocation) {
        $problems[] = 'Location was ' . var_export($location, true)
            . ', expected ' . var_export($expectedLocation, true);
    }
    if ($response['body'] !== '') {
        $problems[] = 'redirect body was not empty: ' . substr($response['body'], 0, 200);
    }
    return $problems;
}

// The redirect that checklogin() sends to visitors who are not logged in:
// the login page, with the requested URI hex-encoded as its target.
function login_redirect_location($uri)
{
    return 'index.php?action=login&target=' . strtoupper(bin2hex($uri));
}

// Starts a second application server on $port without output buffering
// (otherwise like the application service: same document root and
// diagnostics recorder), so that tests can check that headers do not
// depend on output buffering. Returns its base URL; the server is stopped
// when the test script ends.
function start_unbuffered_server($port)
{
    $log = '/tmp/unbuffered-server-' . $port . '.log';
    $command = 'exec php -d output_buffering=0'
        . ' -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/runtime/diagnostics-prepend.php')
        . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(dirname(__DIR__));
    $server = proc_open(
        $command,
        array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('file', $log, 'a'),
            2 => array('file', $log, 'a'),
        ),
        $pipes
    );
    if (!is_resource($server)) {
        fail_test('could not start the output_buffering=0 server');
    }
    register_shutdown_function('stop_unbuffered_server', $server);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port);
        if ($socket !== false) {
            fclose($socket);
            return 'http://127.0.0.1:' . $port;
        }
        usleep(100000);
    }
    fail_test('output_buffering=0 server did not start: ' . @file_get_contents($log));
}

function stop_unbuffered_server($server)
{
    proc_terminate($server);
    proc_close($server);
}

function assert_no_problems($problems, $label)
{
    if (count($problems) > 0) {
        fail_test("$label " . implode('; ', $problems));
    }
}

function login_user($name, $password)
{
    $response = login_request($name, $password);
    assert_no_problems(login_response_problems($response, 'index.php'), "login for $name");
    return response_cookies($response);
}
