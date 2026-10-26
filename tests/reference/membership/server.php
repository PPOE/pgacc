<?php

/*
 * Local stand-in for the membership system (lane and reference runs). The
 * compose network alias mitglieder.piratenpartei.at points at this server,
 * so getusers() never reaches the real service. It speaks HTTPS on port 443
 * with the synthetic server certificate that tests/php-runtime.Dockerfile
 * generates per build, signed by a test CA that the same image trusts, so the
 * application verifies it with PHP's default settings. Port 80 (plain HTTP)
 * serves only the test controls below. One request per connection, one
 * connection at a time.
 *
 * GET https://.../adm_api/adm_names.php serves the synthetic, encrypted
 * member list (adm_names.b64) and is logged to PGACC_REFERENCE_STUB_LOG, so
 * the recorder can prove where getusers() went. The tests switch the
 * response with GET http://.../__stub/mode?set=<mode> (port 80, not logged):
 *   ok            the member list (default)
 *   short         the first 200 bytes of it
 *   garbage       1,536 bytes of base64 that decrypt to noise (wrong key or
 *                 damaged data)
 *   empty         a list encrypted like adm_names.b64 (key: $datakey of the
 *                 image's constants.php) whose lines are all too short
 *   flow          flow.tsv, encrypted the same way (GetUsersTest)
 *   overflow      flow.tsv plus a member id out of the integer range, so the
 *                 INSERT fails after DROP and CREATE
 *   body          the body last sent with POST http://.../__stub/body
 *                 (which also selects this mode; the unit tests)
 *   redirect      302 to https://mitglieder.piratenpartei.at/__stub/list
 *   redirect-http 302 to http://mitglieder.piratenpartei.at/__stub/list
 *   selfsigned    HTTPS with a self-signed certificate for the host
 *   wrongname     HTTPS with a test-CA certificate for another host name
 *   error         HTTP 500
 * /__stub/list serves the member list on both ports (the redirect targets)
 * and counts its requests: GET http://.../__stub/list-requests answers the
 * count since the last mode switch, so the tests can prove that no redirect
 * was followed. GET http://.../__stub/names-requests counts the member-list
 * requests (adm_names.php) the same way, so the tests can prove that a
 * refused save did not fetch the list. /__stub/health answers 200 for the
 * health check; anything
 * else is 404.
 *
 * Response format, as getusers.php reads it (inferred from the code, never
 * from a real sample): base64 of Rijndael-256/CBC ciphertext, key
 * md5($datakey), IV md5(md5($datakey)), zero (NUL) padding to 32 bytes. The
 * plain text is lines separated by "\n"; each line is tab-separated fields
 * [0] (unused), [1] member id, [2] name in ISO-8859-15 with backslash
 * escapes (stripslashes()), [3] organization code (lo), [4] paid (0/1).
 * Lines shorter than 5 bytes (such as an empty last line) are skipped.
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
$namesRequestsFile = '/tmp/pgacc-membership-stub-names-requests';
$modes = ['ok', 'short', 'garbage', 'empty', 'flow', 'overflow', 'body', 'redirect', 'redirect-http',
    'selfsigned', 'wrongname', 'error'];
$certificates = [
    'selfsigned' => '/etc/pgacc-test-tls/selfsigned.pem',
    'wrongname' => '/etc/pgacc-test-tls/wrongname.pem',
];

// As membership_encrypt() of functions.php: Rijndael-256/CBC, key
// md5($datakey), IV md5(md5($datakey)), zero padding.
function membership_stub_encrypt($plain)
{
    require_once '/app/vendor/autoload.php';
    require '/app/constants.php';
    $cipher = new \phpseclib\Crypt\Rijndael(\phpseclib\Crypt\Base::MODE_CBC);
    $cipher->setBlockLength(256);
    $cipher->setKey(md5($datakey));
    $cipher->setIV(md5(md5($datakey)));
    $cipher->disablePadding();
    $length = max(1, intval(ceil(strlen($plain) / 32))) * 32;
    return base64_encode($cipher->encrypt(str_pad($plain, $length, "\0")));
}

function membership_stub_mode($modeFile)
{
    return is_file($modeFile) ? file_get_contents($modeFile) : 'ok';
}

function membership_stub_response($secure, $method, $uri, $body, $modeFile, $bodyFile, $modes)
{
    global $listRequestsFile, $namesRequestsFile;
    $path = parse_url($uri, PHP_URL_PATH);
    if ($path === '/__stub/health') {
        return ['200 OK', "ok\n"];
    }
    if ($path === '/__stub/list') {
        file_put_contents($listRequestsFile, "x", FILE_APPEND);
        return ['200 OK', file_get_contents(__DIR__ . '/adm_names.b64')];
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
            @unlink($namesRequestsFile);
            return ['200 OK', "mode $mode\n"];
        }
        if ($path === '/__stub/list-requests') {
            clearstatcache();
            return ['200 OK', (is_file($listRequestsFile) ? filesize($listRequestsFile) : 0) . "\n"];
        }
        if ($path === '/__stub/names-requests') {
            clearstatcache();
            return ['200 OK', (is_file($namesRequestsFile) ? filesize($namesRequestsFile) : 0) . "\n"];
        }
        if ($path === '/__stub/body' && $method === 'POST') {
            file_put_contents($bodyFile, $body);
            file_put_contents($modeFile, 'body');
            return ['200 OK', "mode body\n"];
        }
        return ['404 Not Found', "not found\n"];
    }
    if ($path !== '/adm_api/adm_names.php') {
        return ['404 Not Found', "not found\n"];
    }
    file_put_contents($namesRequestsFile, "x", FILE_APPEND);
    $list = file_get_contents(__DIR__ . '/adm_names.b64');
    switch (membership_stub_mode($modeFile)) {
        case 'short':
            return ['200 OK', substr($list, 0, 200)];
        case 'garbage':
            $bytes = '';
            for ($i = 0; strlen($bytes) < 1536; $i++) {
                $bytes .= hash('sha256', "pgacc synthetic garbage $i", true);
            }
            return ['200 OK', base64_encode($bytes)];
        case 'empty':
            return ['200 OK', membership_stub_encrypt(str_repeat("abc\n", 300))];
        case 'flow':
        case 'overflow':
            // flow.tsv is UTF-8; the membership system sends ISO-8859-15.
            $plain = iconv('UTF-8', 'ISO-8859-15', file_get_contents(__DIR__ . '/flow.tsv'));
            if (membership_stub_mode($modeFile) === 'overflow') {
                $plain .= "99\t99999999999\tSynthetic Overflow Member\t40\t0\n";
            }
            return ['200 OK', membership_stub_encrypt($plain)];
        case 'body':
            return ['200 OK', (string) @file_get_contents($bodyFile)];
        case 'redirect':
            return ["302 Found\r\nLocation: https://mitglieder.piratenpartei.at/__stub/list", "moved\n"];
        case 'redirect-http':
            return ["302 Found\r\nLocation: http://mitglieder.piratenpartei.at/__stub/list", "moved\n"];
        case 'error':
            return ['500 Internal Server Error', "synthetic failure\n"];
        default:
            return ['200 OK', $list];
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
        while (($header = fgets($client)) !== false && rtrim($header, "\r\n") !== '') {
            if (stripos($header, 'Content-Length:') === 0) {
                $length = intval(trim(substr($header, 15)));
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
        list($status, $responseBody) = membership_stub_response($secure, $method, $uri, $body, $modeFile, $bodyFile, $modes);
        @fwrite($client, "HTTP/1.0 $status\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($responseBody)
            . "\r\nConnection: close\r\n\r\n" . $responseBody);
        @fclose($client);
    }
}
