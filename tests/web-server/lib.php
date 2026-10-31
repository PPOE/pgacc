<?php

/*
 * Helpers for the request matrix (tests/web-server/matrix.php): a raw HTTP
 * client, planting files into a release and checking a server against the
 * matrix. Used by tests/http-docroot.php and tests/web-server/check.php.
 * $fail is the caller's failure function (it must not return).
 */

// Sends "GET $target" exactly as given (no normalisation of dot segments,
// slashes or percent-encoding) and returns status, headers and body.
function web_server_request($baseUrl, $target, $fail)
{
    if (preg_match('#^http://([^/:]+):(\d+)$#', rtrim($baseUrl, '/'), $match) !== 1) {
        $fail("unsupported base URL $baseUrl");
    }
    $socket = @fsockopen($match[1], intval($match[2]), $errno, $error, 10);
    if ($socket === false) {
        $fail("could not connect to $baseUrl for GET $target: $error");
    }
    stream_set_timeout($socket, 20);
    fwrite($socket, "GET $target HTTP/1.0\r\nHost: pgacc.test\r\nX-Forwarded-Proto: https\r\nConnection: close\r\n\r\n");
    $response = stream_get_contents($socket);
    fclose($socket);
    $parts = explode("\r\n\r\n", (string) $response, 2);
    $headers = explode("\r\n", $parts[0]);
    if (count($parts) !== 2 || preg_match('#^HTTP/\S+ (\d{3})#', $headers[0], $status) !== 1) {
        $fail("no HTTP answer for GET $target: " . var_export(substr((string) $response, 0, 120), true));
    }
    return ['status' => intval($status[1]), 'headers' => $headers, 'body' => $parts[1]];
}

// Files that must never be served, planted into the release root $release
// and into its public/ (matrix 'planted'), plus public/ files outside the
// allow list (a PHP script, other file types, a symbolic link out of
// public/). Each holds $secret.
function web_server_plant($release, $matrix, $secret, $fail)
{
    $content = "<?php \$key = '" . $secret . "'; echo 'pgacc-planted-php-ran';\n";
    $files = [];
    foreach ($matrix['planted'] as $name) {
        $files[] = $release . '/' . $name;
        $files[] = $release . '/public/' . $name;
    }
    foreach (['evil.php', 'js/evil.php', 'icons/planted.svg', 'icons/planted.gif', 'js/planted.json'] as $name) {
        $files[] = $release . '/public/' . $name;
    }
    foreach ($files as $file) {
        if (file_put_contents($file, $content) === false) {
            $fail("could not plant $file");
        }
    }
    if (!symlink('../../constants.php.bak', $release . '/public/js/planted-link.js')) {
        $fail('could not plant the symbolic link public/js/planted-link.js');
    }
}

// Checks every matrix request against $baseUrl. $publicDir: the served
// public/ directory (static files are compared byte for byte).
// $checkEntry($response, $script, $query, $label): checks that the entry
// point $script ran with QUERY_STRING $query. $differences: target =>
// status, answers this server gives on purpose instead of the matrix one.
// $secret: content of the planted files, if any were planted.
function web_server_check_matrix($baseUrl, $matrix, $publicDir, $checkEntry, $differences, $secret, $fail, $label)
{
    $refused = $matrix['refused'] + ($secret === null ? [] : $matrix['planted_refused']);
    $checked = 0;
    foreach ($matrix['static'] + $matrix['entry'] + $refused as $target => $expected) {
        $response = web_server_request($baseUrl, $target, $fail);
        $want = $differences[$target] ?? (is_int($expected) ? $expected : 200);
        if ($response['status'] !== $want) {
            $fail("$label: GET $target answered {$response['status']}, expected $want");
        }
        if ($want === 200 && is_string($expected)) {
            if ($response['body'] !== file_get_contents($publicDir . '/' . $expected)) {
                $fail("$label: GET $target did not serve public/$expected");
            }
        } elseif ($want === 200) {
            $checkEntry($response, $expected[0], $expected[1], "$label: GET $target");
        } else {
            foreach (['<?php', '$key', 'pgacc-planted-php-ran', 'pgacc-stub'] as $leak) {
                if (strpos($response['body'], $leak) !== false) {
                    $fail("$label: GET $target answered $want with $leak in the body");
                }
            }
            if ($secret !== null && strpos($response['body'], $secret) !== false) {
                $fail("$label: GET $target answered $want with a planted secret");
            }
        }
        $checked++;
    }
    return $checked;
}
