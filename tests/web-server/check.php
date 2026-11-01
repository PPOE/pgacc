<?php

/*
 * Command-line part of tests/web-server/run.sh (inside the php container).
 *
 *   php check.php plant <release> <secret>
 *       Plants the matrix files into <release> (web_server_plant()) and
 *       replaces public/index.php and public/mbapi.php with a stub that
 *       prints which script ran with which query string; writes
 *       entry-sentinel.php into <release> (matrix 'entry_link'); replaces
 *       internal/mbapi.php with a stub that runs the peer and token check of
 *       src/sync-api.php and answers JSON, and plants files into internal/.
 *   php check.php entry-link <nginx|apache> <base URL>
 *       Checks the 'entry_link' requests (run.sh has made public/mbapi.php
 *       a link to entry-sentinel.php).
 *   php check.php internal <base URL> <cafile> <token> <internal dir> <secret>
 *       Checks the internal sync listener (TLS verified with <cafile>):
 *       only /mbapi.php runs, and only from 127.0.0.1 with the token.
 *   php check.php <nginx|apache|apache-release-root> <base URL> <public dir>
 *       <script dir> <secret>
 *       Sends the request matrix (tests/web-server/matrix.php) to the server
 *       and checks the answers. <script dir>: the directory the stub must
 *       report as the one of SCRIPT_FILENAME.
 */

require __DIR__ . '/lib.php';

// Answers a server gives on purpose instead of the matrix one, with the
// reason.
const WEB_SERVER_DIFFERENCES = [
    'nginx' => [],
    'apache' => [
        // AllowEncodedSlashes Off (the default): an encoded "/" is refused.
        '/js%2fvreport-chart.js' => 404,
        '/index.php/..%2fconstants.php' => 404,
        '/js/..%2f..%2fconstants.php' => 404,
    ],
];

function fail_check($message)
{
    fwrite(STDERR, "Web server check failed: $message\n");
    exit(1);
}

$matrix = require __DIR__ . '/matrix.php';
$mode = $argv[1] ?? '';

if ($mode === 'plant' && $argc === 4) {
    web_server_plant($argv[2], $matrix, $argv[3], 'fail_check');
    $stub = "<?php\nheader('Content-Type: text/plain');\n"
        . "echo 'pgacc-stub ', \$_SERVER['SCRIPT_FILENAME'], ' query=', \$_SERVER['QUERY_STRING'] ?? '',"
        . " ' path_info=', \$_SERVER['PATH_INFO'] ?? '', \"\\n\";\n";
    foreach (['index.php', 'mbapi.php'] as $name) {
        if (file_put_contents($argv[2] . '/public/' . $name, $stub) === false) {
            fail_check("could not write the stub $name");
        }
    }
    $sentinel = "<?php echo 'pgacc-entry-sentinel-ran';\n";
    if (file_put_contents($argv[2] . '/entry-sentinel.php', $sentinel) === false) {
        fail_check('could not write entry-sentinel.php');
    }
    $internal = "<?php\nchdir(dirname(__DIR__)) || exit(1);\nrequire 'src/sync-api.php';\n"
        . "if (!sync_api_allowed(sync_api_config('sync.php'), \$_SERVER)) {\n    http_response_code(403);\n    return;\n}\n"
        . "header('Content-Type: application/json');\n"
        . "echo json_encode(['script' => \$_SERVER['SCRIPT_FILENAME'], 'peer' => \$_SERVER['REMOTE_ADDR']]);\n";
    $planted = "<?php \$key = '" . $argv[3] . "'; echo 'pgacc-planted-php-ran';\n";
    foreach (['mbapi.php' => $internal, 'evil.php' => $planted, 'mbapi.php~' => $planted, 'notes.txt' => $planted] as $name => $content) {
        if (file_put_contents($argv[2] . '/internal/' . $name, $content) === false) {
            fail_check("could not write internal/$name");
        }
    }
    exit(0);
}

if ($mode === 'entry-link' && $argc === 4 && isset(WEB_SERVER_DIFFERENCES[$argv[2]])) {
    foreach ($matrix['entry_link'] as $target => $statuses) {
        $want = $statuses[$argv[2]];
        $response = web_server_request($argv[3], $target, 'fail_check');
        if ($response['status'] !== $want) {
            fail_check("entry point as a symbolic link: GET $target answered {$response['status']}, expected $want");
        }
        foreach (['pgacc-entry-sentinel-ran', '<?php', 'pgacc-stub'] as $leak) {
            if (strpos($response['body'], $leak) !== false) {
                fail_check("entry point as a symbolic link: GET $target showed $leak");
            }
        }
    }
    echo 'entry point as a symbolic link: ' . count($matrix['entry_link']) . " request(s) refused\n";
    exit(0);
}

if ($mode === 'internal' && $argc === 7) {
    [, , $baseUrl, $cafile, $token, $internalDir, $secret] = $argv;
    // GET $target over TLS (the certificate verified against $cafile, for
    // the address of $baseUrl), sent from $from, exactly as given.
    $request = function ($target, $authorization, $from = '127.0.0.1') use ($baseUrl, $cafile) {
        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $cafile],
            'socket' => ['bindto' => $from . ':0'],
        ]);
        $address = 'tls://' . substr($baseUrl, strlen('https://'));
        $socket = @stream_socket_client($address, $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            fail_check("internal: could not connect to $address from $from: $error");
        }
        stream_set_timeout($socket, 20);
        fwrite($socket, "GET $target HTTP/1.0\r\nHost: 127.0.0.1\r\n"
            . ($authorization === null ? '' : "Authorization: $authorization\r\n") . "Connection: close\r\n\r\n");
        $parts = explode("\r\n\r\n", (string) stream_get_contents($socket), 2);
        fclose($socket);
        if (count($parts) !== 2 || preg_match('#^HTTP/\S+ (\d{3})#', $parts[0], $status) !== 1) {
            fail_check("internal: no HTTP answer for GET $target");
        }
        return ['status' => intval($status[1]), 'headers' => explode("\r\n", $parts[0]), 'body' => $parts[1]];
    };
    $bearer = 'Bearer ' . $token;
    $response = $request('/mbapi.php', $bearer);
    $expected = json_encode(['script' => $internalDir . '/mbapi.php', 'peer' => '127.0.0.1']);
    if ($response['status'] !== 200 || $response['body'] !== $expected
        || !in_array('Content-Type: application/json', $response['headers'], true)) {
        fail_check("internal: GET /mbapi.php answered {$response['status']} " . var_export(substr($response['body'], 0, 200), true));
    }
    $refused = [
        ['/mbapi.php', null, '127.0.0.1', 403],
        ['/mbapi.php', 'Bearer wrong', '127.0.0.1', 403],
        ['/mbapi.php', $bearer, '127.0.0.2', 403],
        ['/', $bearer, '127.0.0.1', 404],
        ['/index.php', $bearer, '127.0.0.1', 404],
        ['/evil.php', $bearer, '127.0.0.1', 404],
        ['/notes.txt', $bearer, '127.0.0.1', 404],
        ['/mbapi.php/x', $bearer, '127.0.0.1', 404],
        ['/MBAPI.PHP', $bearer, '127.0.0.1', 404],
        ['/sync.php', $bearer, '127.0.0.1', 404],
        ['/src/sync-api.php', $bearer, '127.0.0.1', 404],
        ['/public/index.php', $bearer, '127.0.0.1', 404],
        ['/mbapi.php~', $bearer, '127.0.0.1', 403],
        ['/.htaccess', $bearer, '127.0.0.1', 403],
        ['/../sync.php', $bearer, '127.0.0.1', 400],
    ];
    foreach ($refused as [$target, $authorization, $from, $want]) {
        $response = $request($target, $authorization, $from);
        if ($response['status'] !== $want) {
            fail_check("internal: GET $target from $from answered {$response['status']}, expected $want");
        }
        foreach (['<?php', 'pgacc-planted-php-ran', '"script"', $secret, $token] as $leak) {
            if (strpos($response['body'], $leak) !== false) {
                fail_check("internal: GET $target from $from showed $leak");
            }
        }
        if ($want === 403 && $target === '/mbapi.php' && $response['body'] !== '') {
            fail_check("internal: the refusal of GET /mbapi.php from $from has a body");
        }
    }
    echo 'internal: ' . (count($refused) + 1) . " requests as expected\n";
    exit(0);
}

if ($mode === 'apache-release-root' && $argc === 6) {
    // A misconfigured Apache whose document root is the release root: the
    // release root's .htaccess denies every request.
    $checked = 0;
    foreach (array_keys($matrix['static'] + $matrix['entry'] + $matrix['refused'] + $matrix['planted_refused']) as $target) {
        foreach (['', '/public'] as $prefix) {
            $response = web_server_request($argv[2], $prefix . $target, 'fail_check');
            // 403, except above the document root (400, only without the
            // /public prefix) and an encoded "/" (see above).
            $want = WEB_SERVER_DIFFERENCES['apache'][$target]
                ?? ($prefix === '' && ($matrix['refused'][$target] ?? null) === 400 ? 400 : 403);
            if ($response['status'] !== $want) {
                fail_check("release root as document root: GET $prefix$target answered {$response['status']}, expected $want");
            }
            foreach (['<?php', '$key', 'pgacc-planted-php-ran', 'pgacc-stub', $argv[5], '/*!'] as $leak) {
                if (strpos($response['body'], $leak) !== false) {
                    fail_check("release root as document root: GET $prefix$target showed $leak");
                }
            }
            $checked++;
        }
    }
    echo "apache (release root as document root): $checked requests refused\n";
    exit(0);
}

if (!isset(WEB_SERVER_DIFFERENCES[$mode]) || $argc !== 6) {
    fail_check('usage: php check.php plant <release> <secret> | entry-link <nginx|apache> <base URL> | internal <base URL> <cafile> <token> <internal dir> <secret> | <nginx|apache|apache-release-root> <base URL> <public dir> <script dir> <secret>');
}
[, , $baseUrl, $publicDir, $scriptDir, $secret] = $argv;

$checkEntry = function ($response, $script, $query, $label) use ($scriptDir, $mode) {
    $expected = "pgacc-stub $scriptDir/$script query=$query path_info=\n";
    if ($response['body'] !== $expected) {
        fail_check("$label: expected " . var_export($expected, true) . ', got ' . var_export(substr($response['body'], 0, 200), true));
    }
    if ($mode === 'nginx' && !in_array('Cache-Control: private, no-store', $response['headers'], true)) {
        fail_check("$label: no Cache-Control: private, no-store");
    }
};
$checked = web_server_check_matrix($baseUrl, $matrix, $publicDir, $checkEntry, WEB_SERVER_DIFFERENCES[$mode], $secret, 'fail_check', $mode);
if ($mode === 'nginx') {
    $response = web_server_request($baseUrl, '/style.css?v=1', 'fail_check');
    if (!in_array('Cache-Control: no-cache', $response['headers'], true)) {
        fail_check('nginx: GET /style.css?v=1 sent no Cache-Control: no-cache');
    }
}
echo "$mode: $checked requests as expected\n";
