<?php
/*
 * Local stand-in for the membership system (lane and reference runs). The
 * compose network alias mitglieder.piratenpartei.at points at this server,
 * so getusers() never reaches the real service. It speaks HTTPS on port 443
 * with the synthetic server certificate that tests/php-runtime.Dockerfile
 * generates per build, signed by a test CA that the same image trusts, so the
 * application verifies it with PHP's default settings. One request per
 * connection, one connection at a time.
 *
 * GET /adm_api/adm_names.php serves the synthetic, encrypted member list
 * (adm_names.b64) and is logged to PGACC_REFERENCE_STUB_LOG, so the recorder
 * can prove where getusers() went. The HTTP tests switch the response with
 * GET /__stub/mode?set=<mode> (not logged):
 *   ok       the member list (default)
 *   short    the first 200 bytes of it
 *   garbage  1,536 bytes of base64 that decrypt to noise (wrong key or
 *            damaged data)
 *   error    HTTP 500
 * /__stub/health answers 200 for the health check; anything else is 404.
 */
$context = stream_context_create(array('ssl' => array('local_cert' => '/etc/pgacc-test-tls/membership.pem')));
$server = stream_socket_server('tls://0.0.0.0:443', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "membership stub: $errstr\n");
    exit(1);
}
$modeFile = '/tmp/pgacc-membership-stub-mode';
$modes = array('ok', 'short', 'garbage', 'error');

function membership_stub_response($uri, $modeFile, $modes)
{
    $path = parse_url($uri, PHP_URL_PATH);
    if ($path === '/__stub/health') {
        return array('200 OK', "ok\n");
    }
    if ($path === '/__stub/mode') {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $mode = isset($query['set']) ? $query['set'] : '';
        if (!in_array($mode, $modes, true)) {
            return array('400 Bad Request', "unknown mode\n");
        }
        file_put_contents($modeFile, $mode);
        return array('200 OK', "mode $mode\n");
    }
    if ($path !== '/adm_api/adm_names.php') {
        return array('404 Not Found', "not found\n");
    }
    $mode = is_file($modeFile) ? file_get_contents($modeFile) : 'ok';
    $list = file_get_contents(__DIR__ . '/adm_names.b64');
    switch ($mode) {
        case 'short':
            return array('200 OK', substr($list, 0, 200));
        case 'garbage':
            $bytes = '';
            for ($i = 0; strlen($bytes) < 1536; $i++) {
                $bytes .= hash('sha256', "pgacc synthetic garbage $i", true);
            }
            return array('200 OK', base64_encode($bytes));
        case 'error':
            return array('500 Internal Server Error', "synthetic failure\n");
        default:
            return array('200 OK', $list);
    }
}

while (true) {
    // A failed handshake (such as a plain TCP probe) returns false.
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        continue;
    }
    stream_set_timeout($client, 5);
    $requestLine = (string) fgets($client);
    while (($header = fgets($client)) !== false && rtrim($header, "\r\n") !== '') {
    }
    $parts = explode(' ', trim($requestLine));
    $method = $parts[0];
    $uri = isset($parts[1]) ? $parts[1] : '';
    $pgaccStubLog = getenv('PGACC_REFERENCE_STUB_LOG');
    if ($pgaccStubLog !== false && $pgaccStubLog !== '' && strpos($uri, '/__stub/') !== 0) {
        file_put_contents($pgaccStubLog, $method . ' ' . $uri . "\n", FILE_APPEND | LOCK_EX);
    }
    list($status, $body) = membership_stub_response($uri, $modeFile, $modes);
    @fwrite($client, "HTTP/1.0 $status\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body)
        . "\r\nConnection: close\r\n\r\n" . $body);
    @fclose($client);
}
