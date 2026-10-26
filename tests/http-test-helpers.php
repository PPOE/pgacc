<?php

// Shared HTTP test helpers.

require_once __DIR__ . '/http-diagnostics.php';
require_once __DIR__ . '/db-helpers.php';

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

// $files: field name => array('name' => ..., 'type' => ..., 'content' => ...),
// sent with $form as multipart/form-data (POST only).
// POST requests of a session carry its form token, as the forms of the pages
// do, unless $form sets csrf_token itself (null: no token).
function http_request($path, $method = 'GET', $form = [], $cookies = [], $baseUrl = null, $files = [])
{
    if ($method === 'POST' && isset($cookies['pp_pgacc_login']) && !array_key_exists('csrf_token', $form)) {
        $form['csrf_token'] = session_csrf_token($cookies, $baseUrl);
    }
    if ($baseUrl === null) {
        $baseUrl = getenv('PGACC_TEST_BASE_URL');
    }
    if ($baseUrl === false || $baseUrl === '') {
        fail_test('PGACC_TEST_BASE_URL is not configured');
    }

    $requestId = next_diagnostics_request_id();
    $headers = [
        'X-Forwarded-Proto: https',
        'Host: pgacc.test',
        'Connection: close',
        'X-Pgacc-Test-Request: ' . $requestId,
    ];

    if (count($cookies) > 0) {
        $cookieParts = [];
        foreach ($cookies as $name => $value) {
            $cookieParts[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $cookieParts);
    }

    $content = '';
    if ($method === 'POST' && count($files) > 0) {
        $boundary = 'pgacc-test-' . md5(uniqid('', true));
        foreach ($form as $name => $value) {
            if ($value === null) {
                continue;
            }
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        foreach ($files as $name => $file) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"{$file['name']}\"\r\n"
                . "Content-Type: {$file['type']}\r\n\r\n{$file['content']}\r\n";
        }
        $content .= "--$boundary--\r\n";
        $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
        $headers[] = 'Content-Length: ' . strlen($content);
    } elseif ($method === 'POST') {
        $content = http_build_query($form, '', '&');
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($content);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ],
    ]);

    $url = rtrim($baseUrl, '/') . $path;
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = http_get_last_response_headers();
    if (!is_array($responseHeaders)) {
        $responseHeaders = [];
    }

    if ($body === false || count($responseHeaders) === 0) {
        fail_test("request failed for $method $path");
    }

    if (!preg_match('/^HTTP\/\S+ (\d{3})/', $responseHeaders[0], $matches)) {
        fail_test("could not parse status for $method $path");
    }

    $response = [
        'status' => intval($matches[1]),
        'headers' => $responseHeaders,
        'body' => $body,
    ];
    collect_response_diagnostics($requestId, "$method $path", $response);
    return $response;
}

// The form token of the session of $cookies as its forms carry it (the
// user list, shown to every logged-in user, also without rights), or null
// without a valid session.
function session_csrf_token($cookies, $baseUrl = null)
{
    static $tokens = [];
    $cacheKey = strval($baseUrl) . '|' . (isset($cookies['pp_pgacc_id']) ? $cookies['pp_pgacc_id'] : '')
        . '|' . (isset($cookies['pp_pgacc_login']) ? $cookies['pp_pgacc_login'] : '');
    if (!array_key_exists($cacheKey, $tokens)) {
        $page = http_request('/index.php?action=accounts', 'GET', [], $cookies, $baseUrl);
        $tokens[$cacheKey] = preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $page['body'], $matches) === 1
            ? $matches[1] : null;
    }
    return $tokens[$cacheKey];
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
    $cookies = [];
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

// The year selector (year_nav()) of the report and the donations page,
// $selected marked, every year from the current one down to 2012 linked as
// "$url$year", the previous and next year linked or disabled.
function expected_year_nav($selected, $url)
{
    $last = intval(date('Y'));
    $prev = '<span class="year_nav_step year_nav_prev" role="link" aria-disabled="true" aria-label="Vorheriges Jahr">&lsaquo;</span>';
    if ($selected > 2012) {
        $y = $selected - 1;
        $prev = "<a class=\"year_nav_step year_nav_prev\" href=\"$url$y\" aria-label=\"Vorheriges Jahr: $y\" title=\"Vorheriges Jahr: $y\">&lsaquo;</a>";
    }
    $next = '<span class="year_nav_step year_nav_next" role="link" aria-disabled="true" aria-label="Nächstes Jahr">&rsaquo;</span>';
    if ($selected < $last) {
        $y = $selected + 1;
        $next = "<a class=\"year_nav_step year_nav_next\" href=\"$url$y\" aria-label=\"Nächstes Jahr: $y\" title=\"Nächstes Jahr: $y\">&rsaquo;</a>";
    }
    $items = '';
    for ($y = $last; $y >= 2012; $y--) {
        $current = $y === $selected ? ' aria-current="page"' : '';
        $items .= "<li><a href=\"$url$y\"$current>$y</a></li>";
    }
    return '<nav class="year_nav" aria-label="Jahr">' . $prev
        . "<details class=\"year_nav_menu\"><summary aria-label=\"Jahr wählen: $selected\">$selected</summary><ul>"
        . $items . '</ul></details>' . $next . '</nav>';
}

function login_request($name, $password, $target = null, $baseUrl = null)
{
    $form = [
        'submit' => 'Anmeldung',
        'name' => $name,
        'pass' => $password,
    ];
    if ($target !== null) {
        $form['target'] = strtoupper(bin2hex($target));
    }
    return http_request('/index.php?action=login', 'POST', $form, [], $baseUrl);
}

// Returns a list of problems (empty when the response is a valid login
// redirect), so callers can assert it or record it as a known failure.
function login_response_problems($response, $expectedLocation, $expectedUserId = null)
{
    $problems = [];
    if ($response['status'] !== 302) {
        $problems[] = "returned HTTP {$response['status']}, expected 302";
    }
    $location = response_header($response, 'Location');
    if ($location !== $expectedLocation) {
        $problems[] = 'Location was ' . var_export($location, true)
            . ', expected ' . var_export($expectedLocation, true);
    }
    $cookies = response_cookies($response);
    // The session token: 64 lowercase hex digits (an HMAC-SHA256).
    if (!isset($cookies['pp_pgacc_login']) || preg_match('/^[0-9a-f]{64}$/', $cookies['pp_pgacc_login']) !== 1) {
        $problems[] = 'missing pp_pgacc_login cookie or not 64 hex digits';
    }
    if (!isset($cookies['pp_pgacc_id']) || preg_match('/^\d+$/', $cookies['pp_pgacc_id']) !== 1) {
        $problems[] = 'missing or non-numeric pp_pgacc_id cookie';
    }
    if ($expectedUserId !== null && isset($cookies['pp_pgacc_id'])
        && $cookies['pp_pgacc_id'] !== strval($expectedUserId)) {
        $problems[] = "pp_pgacc_id cookie was {$cookies['pp_pgacc_id']}, expected $expectedUserId";
    }
    return $problems;
}

function logout_response_problems($response)
{
    $problems = [];
    if ($response['status'] !== 302) {
        $problems[] = "returned HTTP {$response['status']}, expected 302";
    }
    $location = response_header($response, 'Location');
    if ($location !== 'index.php') {
        $problems[] = 'Location was ' . var_export($location, true) . ", expected 'index.php'";
    }
    $cookies = response_cookies($response);
    foreach (['pp_pgacc_login', 'pp_pgacc_id'] as $cookie) {
        // PHP sends a cleared cookie as "deleted" with an expiry in the past.
        if (!isset($cookies[$cookie]) || !in_array($cookies[$cookie], ['', 'deleted'], true)) {
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
    $problems = [];
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
    $log = unbuffered_server_log($port);
    $command = 'exec php -d output_buffering=0'
        . ' -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/runtime/diagnostics-prepend.php')
        . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(dirname(__DIR__));
    $server = proc_open(
        $command,
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ],
        $pipes,
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

// Standard output and error (error_log()) of the server on $port.
function unbuffered_server_log($port)
{
    return '/tmp/unbuffered-server-' . $port . '.log';
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
