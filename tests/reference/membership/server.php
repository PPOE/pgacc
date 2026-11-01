<?php

/*
 * Local stand-in for the membership system (lane and reference runs). The
 * compose network alias mitglieder.piratenpartei.at points at this server,
 * so getusers() never reaches a real service. It speaks HTTPS on port 443
 * with the synthetic server certificate that tests/php-runtime.Dockerfile
 * generates per build, signed by a test CA; the members_cafile of the test
 * configuration (tests/runtime/test-sync-secrets.php) is that CA. Port 80
 * (plain HTTP) serves only the test controls below. One request per
 * connection, one connection at a time.
 *
 * GET https://.../members.php with "Authorization: Bearer <members_token of
 * the test configuration>" serves the synthetic member list (members.json,
 * the format of the membership system's internal/members.php); without that
 * token it answers 403 with an empty body. Requests to it are logged to
 * PGACC_REFERENCE_STUB_LOG, so the recorder can prove where getusers() went.
 * The tests switch the response with GET http://.../__stub/mode?set=<mode>
 * (port 80, not logged):
 *   ok            the member list (default)
 *   malformed     a body that is not JSON
 *   empty         an empty list ([])
 *   invalid-row   the member list with one row without its uuid
 *   duplicate     the member list with its first member twice
 *   bad-uuid      the member list with a uuid that is not a UUID
 *   flow          flow.json (GetUsersTest)
 *   overflow      flow.json plus a member id out of the integer range, so the
 *                 INSERT fails after DELETE
 *   body          the body last sent with POST http://.../__stub/body
 *                 (which also selects this mode; the unit tests)
 *   redirect      302 to https://mitglieder.piratenpartei.at/__stub/list
 *   redirect-http 302 to http://mitglieder.piratenpartei.at/__stub/list
 *   selfsigned    HTTPS with a self-signed certificate for the host
 *   wrongname     HTTPS with a test-CA certificate for another host name
 *   error         HTTP 500
 *   stall         the member list, but only its first half, then nothing
 *                 for 3 seconds before the connection closes (a read
 *                 timeout; GetUsersTest)
 *   short         the member list with its full Content-Length, but only
 *                 its first half, then the connection closes at once (a
 *                 cut-off body, no timeout; GetUsersTest)
 *   long          the member list with a Content-Length one byte less
 *   badlength     the member list with the Content-Length "<length>x"
 *   trickle       the member list in 8 parts, one per second (each read
 *                 is quick, the whole response is not; GetUsersTest)
 *   tricklehead   as trickle, but 8 extra header lines, one per second
 * /__stub/list serves the member list on both ports (the redirect targets)
 * and counts its requests: GET http://.../__stub/list-requests answers the
 * count since the last mode switch, so the tests can prove that no redirect
 * was followed. GET http://.../__stub/members-requests counts the
 * member-list requests (members.php) the same way, so the tests can prove
 * that a refused save did not fetch the list. /__stub/health answers 200 for
 * the health check; anything else is 404.
 */
$https = stream_socket_server('tcp://0.0.0.0:443', $errno, $errstr);
$http = $https === false ? false : stream_socket_server('tcp://0.0.0.0:80', $errno, $errstr);
if ($http === false) {
    fwrite(STDERR, "membership stub: $errstr\n");
    exit(1);
}
$modeFile = '/tmp/pgacc-membership-stub-mode';
$bodyFile = '/tmp/pgacc-membership-stub-body';
$listRequestsFile = '/tmp/pgacc-membership-stub-list-requests';
$membersRequestsFile = '/tmp/pgacc-membership-stub-members-requests';
$modes = ['ok', 'malformed', 'empty', 'invalid-row', 'duplicate', 'bad-uuid', 'flow', 'overflow', 'body',
    'redirect', 'redirect-http', 'selfsigned', 'wrongname', 'error', 'stall', 'short', 'long', 'badlength', 'trickle', 'tricklehead'];
$membersToken = (require '/app/tests/runtime/test-sync-secrets.php')['members_token'];
$certificates = [
    'selfsigned' => '/etc/pgacc-test-tls/selfsigned.pem',
    'wrongname' => '/etc/pgacc-test-tls/wrongname.pem',
];

function membership_stub_mode($modeFile)
{
    return is_file($modeFile) ? file_get_contents($modeFile) : 'ok';
}

// The list $members as JSON, as the membership system sends it.
function membership_stub_json($members)
{
    return json_encode($members, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function membership_stub_response($secure, $method, $uri, $authorization, $body, $modeFile, $bodyFile, $modes)
{
    global $listRequestsFile, $membersRequestsFile, $membersToken;
    $path = parse_url($uri, PHP_URL_PATH);
    if ($path === '/__stub/health') {
        return ['200 OK', "ok\n"];
    }
    if ($path === '/__stub/list') {
        file_put_contents($listRequestsFile, "x", FILE_APPEND);
        return ['200 OK', file_get_contents(__DIR__ . '/members.json')];
    }
    if (!$secure) {
        if ($path === '/__stub/mode') {
            parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
            $mode = isset($query['set']) ? $query['set'] : '';
            if (!in_array($mode, $modes, true)) {
                return ['400 Bad Request', "unknown mode\n"];
            }
            file_put_contents($modeFile, $mode);
            @unlink($listRequestsFile);
            @unlink($membersRequestsFile);
            return ['200 OK', "mode $mode\n"];
        }
        if ($path === '/__stub/list-requests') {
            clearstatcache();
            return ['200 OK', (is_file($listRequestsFile) ? filesize($listRequestsFile) : 0) . "\n"];
        }
        if ($path === '/__stub/members-requests') {
            clearstatcache();
            return ['200 OK', (is_file($membersRequestsFile) ? filesize($membersRequestsFile) : 0) . "\n"];
        }
        if ($path === '/__stub/body' && $method === 'POST') {
            file_put_contents($bodyFile, $body);
            file_put_contents($modeFile, 'body');
            return ['200 OK', "mode body\n"];
        }
        return ['404 Not Found', "not found\n"];
    }
    if ($path !== '/members.php') {
        return ['404 Not Found', "not found\n"];
    }
    file_put_contents($membersRequestsFile, "x", FILE_APPEND);
    if (!hash_equals('Bearer ' . $membersToken, $authorization)) {
        return ['403 Forbidden', ''];
    }
    $members = json_decode(file_get_contents(__DIR__ . '/members.json'), true);
    switch (membership_stub_mode($modeFile)) {
        case 'malformed':
            return ['200 OK', '[{"id": 9001, "uuid": '];
        case 'empty':
            return ['200 OK', '[]'];
        case 'invalid-row':
            unset($members[1]['uuid']);
            return ['200 OK', membership_stub_json($members)];
        case 'duplicate':
            $members[] = $members[0];
            return ['200 OK', membership_stub_json($members)];
        case 'bad-uuid':
            $members[1]['uuid'] = 'javascript:alert(1)';
            return ['200 OK', membership_stub_json($members)];
        case 'flow':
        case 'overflow':
            $flow = json_decode(file_get_contents(__DIR__ . '/flow.json'), true);
            if (membership_stub_mode($modeFile) === 'overflow') {
                $flow[] = ['id' => 99999999999, 'uuid' => '00000000-0000-4000-8000-099999999999',
                    'name' => 'Synthetic Overflow Member', 'lo' => 40, 'paid' => 0];
            }
            return ['200 OK', membership_stub_json($flow)];
        case 'body':
            return ['200 OK', (string) @file_get_contents($bodyFile)];
        case 'redirect':
            return ["302 Found\r\nLocation: https://mitglieder.piratenpartei.at/__stub/list", "moved\n"];
        case 'redirect-http':
            return ["302 Found\r\nLocation: http://mitglieder.piratenpartei.at/__stub/list", "moved\n"];
        case 'error':
            return ['500 Internal Server Error', "synthetic failure\n"];
        default:
            return ['200 OK', file_get_contents(__DIR__ . '/members.json')];
    }
}

while (true) {
    $read = [$https, $http];
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, null) < 1) {
        continue;
    }
    foreach ($read as $listener) {
        $client = @stream_socket_accept($listener, 5);
        if ($client === false) {
            continue;
        }
        stream_set_timeout($client, 5);
        $secure = $listener === $https;
        if ($secure) {
            $mode = membership_stub_mode($modeFile);
            $certificate = isset($certificates[$mode]) ? $certificates[$mode] : '/etc/pgacc-test-tls/membership.pem';
            stream_context_set_option($client, 'ssl', 'local_cert', $certificate);
            // A failed handshake (a plain TCP probe, a refused certificate).
            if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                @fclose($client);
                continue;
            }
        }
        $requestLine = (string) fgets($client);
        $length = 0;
        $authorization = '';
        while (($header = fgets($client)) !== false && rtrim($header, "\r\n") !== '') {
            if (stripos($header, 'Content-Length:') === 0) {
                $length = intval(trim(substr($header, 15)));
            } elseif (stripos($header, 'Authorization:') === 0) {
                $authorization = trim(substr($header, 14));
            }
        }
        $body = '';
        while (strlen($body) < $length && !feof($client)) {
            $chunk = fread($client, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        $parts = explode(' ', trim($requestLine));
        $method = $parts[0];
        $uri = isset($parts[1]) ? $parts[1] : '';
        $pgaccStubLog = getenv('PGACC_REFERENCE_STUB_LOG');
        if ($pgaccStubLog !== false && $pgaccStubLog !== '' && strpos($uri, '/__stub/') !== 0) {
            file_put_contents($pgaccStubLog, $method . ' ' . $uri . "\n", FILE_APPEND | LOCK_EX);
        }
        list($status, $responseBody) = membership_stub_response($secure, $method, $uri, $authorization, $body, $modeFile, $bodyFile, $modes);
        $length = (string) strlen($responseBody);
        if ($secure && $status === '200 OK' && strpos($uri, '/members.php') === 0) {
            $lengths = ['long' => (string) (strlen($responseBody) - 1), 'badlength' => $length . 'x'];
            $length = $lengths[membership_stub_mode($modeFile)] ?? $length;
        }
        $head = "HTTP/1.0 $status\r\nContent-Type: text/plain\r\nContent-Length: " . $length
            . "\r\nConnection: close\r\n\r\n";
        if ($secure && $status === '200 OK' && strpos($uri, '/members.php') === 0 && membership_stub_mode($modeFile) === 'stall') {
            @fwrite($client, $head . substr($responseBody, 0, intdiv(strlen($responseBody), 2)));
            sleep(3);
        } elseif ($secure && $status === '200 OK' && strpos($uri, '/members.php') === 0 && membership_stub_mode($modeFile) === 'short') {
            @fwrite($client, $head . substr($responseBody, 0, intdiv(strlen($responseBody), 2)));
        } elseif ($secure && $status === '200 OK' && strpos($uri, '/members.php') === 0
            && in_array(membership_stub_mode($modeFile), ['trickle', 'tricklehead'], true)) {
            $parts = membership_stub_mode($modeFile) === 'trickle'
                ? array_merge([$head], str_split($responseBody, (int) ceil(strlen($responseBody) / 8)))
                : array_merge(["HTTP/1.0 $status\r\n"], array_fill(0, 8, "X-Pad: a\r\n"), [substr($head, strlen("HTTP/1.0 $status\r\n")) . $responseBody]);
            foreach ($parts as $i => $part) {
                if ($i > 0) {
                    sleep(1);
                }
                if (@fwrite($client, $part) === false) {
                    break;
                }
            }
        } else {
            @fwrite($client, $head . $responseBody);
        }
        @fclose($client);
    }
}
