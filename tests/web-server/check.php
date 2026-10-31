<?php

/*
 * Command-line part of tests/web-server/run.sh (inside the php container).
 *
 *   php check.php plant <release> <secret>
 *       Plants the matrix files into <release> (web_server_plant()) and
 *       replaces public/index.php and public/mbapi.php with a stub that
 *       prints which script ran with which query string.
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
    fail_check('usage: php check.php plant <release> <secret> | <nginx|apache|apache-release-root> <base URL> <public dir> <script dir> <secret>');
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
