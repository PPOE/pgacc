<?php

function fail_test($message)
{
    fwrite(STDERR, "HTTP characterization failed: $message\n");
    exit(1);
}

function http_request($path, $method = 'GET', $form = array(), $cookies = array())
{
    $baseUrl = getenv('PGACC_TEST_BASE_URL');
    if ($baseUrl === false || $baseUrl === '') {
        fail_test('PGACC_TEST_BASE_URL is not configured');
    }

    $headers = array(
        'X-Forwarded-Proto: https',
        'Host: pgacc.test',
        'Connection: close',
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

    $body = @file_get_contents(rtrim($baseUrl, '/') . $path, false, $context);
    $responseHeaders = isset($http_response_header) ? $http_response_header : array();

    if ($body === false || count($responseHeaders) === 0) {
        fail_test("request failed for $method $path");
    }

    if (!preg_match('/^HTTP\/\S+ (\d{3})/', $responseHeaders[0], $matches)) {
        fail_test("could not parse status for $method $path");
    }

    return array(
        'status' => intval($matches[1]),
        'headers' => $responseHeaders,
        'body' => $body,
    );
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

$report = http_request('/index.php?year=2024');
assert_status($report, 200, '2024 report');
assert_contains($report['body'], 'Rechenschaftsbericht der Piratenpartei Österreichs 2024', '2024 report');
assert_contains($report['body'], '160.00&nbsp;€', '2024 report income');
assert_contains($report['body'], '-65.00&nbsp;€', '2024 report expenses');
assert_no_fatal_error($report['body'], '2024 report');

$transactions = http_request('/index.php?action=transactions');
assert_status($transactions, 200, 'transactions');
assert_contains($transactions['body'], 'Kontobewegungen der Piratenpartei Österreichs', 'transactions');
assert_contains($transactions['body'], '120.00€', 'transactions');
assert_contains($transactions['body'], '-50.00€', 'transactions');
assert_not_contains($transactions['body'], '90.00€', 'transactions deleted row');
assert_no_fatal_error($transactions['body'], 'transactions');

$spendings = http_request('/index.php?action=spendings');
assert_status($spendings, 200, 'spendings');
assert_contains($spendings['body'], 'Synthetic Supplier', 'spendings');
assert_not_contains($spendings['body'], 'Synthetic Draft', 'spendings unreviewed row');
assert_not_contains($spendings['body'], 'Deleted donation', 'spendings deleted row');
assert_no_fatal_error($spendings['body'], 'spendings');

$failedLogin = http_request(
    '/index.php?action=login',
    'POST',
    array(
        'submit' => 'Anmeldung',
        'name' => 'synthetic-admin',
        'pass' => 'wrong-password',
    )
);
assert_status($failedLogin, 200, 'failed login');
assert_contains($failedLogin['body'], 'Login erforderlich', 'failed login');
if (count(response_cookies($failedLogin)) !== 0) {
    fail_test('failed login issued authentication cookies');
}

$successfulLogin = http_request(
    '/index.php?action=login',
    'POST',
    array(
        'submit' => 'Anmeldung',
        'name' => 'synthetic-admin',
        'pass' => 'correct-password',
    )
);
assert_status($successfulLogin, 302, 'successful login');
$authCookies = response_cookies($successfulLogin);
if (!isset($authCookies['pp_pgacc_login']) || !isset($authCookies['pp_pgacc_id'])) {
    fail_test('successful login did not issue both authentication cookies');
}

$ledger = http_request(
    '/index.php?action=all&filter_date=2024',
    'GET',
    array(),
    $authCookies
);
assert_status($ledger, 200, 'authenticated ledger');
assert_contains($ledger['body'], 'Synthetic Donor', 'authenticated ledger');
assert_contains($ledger['body'], 'Synthetic Draft', 'authenticated ledger');
assert_not_contains($ledger['body'], 'Deleted donation', 'authenticated ledger');
assert_no_fatal_error($ledger['body'], 'authenticated ledger');

echo "PHP/PostgreSQL HTTP characterization tests passed\n";
