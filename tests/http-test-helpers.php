<?php

function fail_test($message)
{
    fwrite(STDERR, "Application characterization failed: $message\n");
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

function login_user($name, $password)
{
    $response = http_request(
        '/index.php?action=login',
        'POST',
        array(
            'submit' => 'Anmeldung',
            'name' => $name,
            'pass' => $password,
        )
    );
    assert_status($response, 302, "login for $name");

    $cookies = response_cookies($response);
    if (!isset($cookies['pp_pgacc_login']) || !isset($cookies['pp_pgacc_id'])) {
        fail_test("login for $name did not issue both authentication cookies");
    }

    return $cookies;
}
