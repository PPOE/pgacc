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
 *   empty    a list encrypted like adm_names.b64 (key: $datakey of the
 *            image's constants.php) whose lines are all too short
 *   flow     flow.tsv, encrypted the same way (GetUsersTest's end-to-end test)
 *   redirect 302 to /__stub/list, which serves the member list
 *   error    HTTP 500
 * /__stub/health answers 200 for the health check; anything else is 404.
 *
 * Response format, as getusers.php reads it (inferred from the code, never
 * from a real sample): base64 of Rijndael-256/CBC ciphertext, key
 * md5($datakey), IV md5(md5($datakey)), zero (NUL) padding to 32 bytes. The
 * plain text is lines separated by "\n"; each line is tab-separated fields
 * [0] (unused), [1] member id, [2] name in ISO-8859-15 with backslash
 * escapes (stripslashes()), [3] organization code (lo), [4] paid (0/1).
 * Lines shorter than 5 bytes (such as an empty last line) are skipped.
 */
$context = stream_context_create(array('ssl' => array('local_cert' => '/etc/pgacc-test-tls/membership.pem')));
$server = stream_socket_server('tls://0.0.0.0:443', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "membership stub: $errstr\n");
    exit(1);
}
$modeFile = '/tmp/pgacc-membership-stub-mode';
$modes = array('ok', 'short', 'garbage', 'empty', 'flow', 'redirect', 'error');

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
    if ($path === '/__stub/list') {
        return array('200 OK', file_get_contents(__DIR__ . '/adm_names.b64'));
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
        case 'empty':
            return array('200 OK', membership_stub_encrypt(str_repeat("abc\n", 300)));
        case 'flow':
            // flow.tsv is UTF-8; the membership system sends ISO-8859-15.
            $plain = iconv('UTF-8', 'ISO-8859-15', file_get_contents(__DIR__ . '/flow.tsv'));
            return array('200 OK', membership_stub_encrypt($plain));
        case 'redirect':
            return array("302 Found\r\nLocation: https://mitglieder.piratenpartei.at/__stub/list", "moved\n");
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
