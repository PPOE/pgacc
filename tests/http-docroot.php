<?php

/*
 * Document root public/ and the allow list of the web server.
 *
 * The test servers serve public/ through tests/runtime/router.php, which
 * applies the allow list of the nginx site (deploy/ansible/templates/
 * nginx-site.conf.j2): "/", index.php, mbapi.php, style.css, js/*.js,
 * icons/*.png and the short page addresses; dot files and backup copies 403;
 * anything else 404.
 *
 * 1. The application service: the allowed addresses answer, the code,
 *    configuration and development files do not.
 * 2. A release with backup copies planted in the release root and in
 *    public/ (constants.php.bak, functions.php~, a dump, an archive, a text
 *    file), built in /tmp from the read-only /app (symbolic links) and
 *    served by a server of its own like the application service: none of
 *    the planted files is served, the application still works.
 *
 * Requests answered by the router or as static files bypass the diagnostics
 * recorder, so they are sent with raw_request() (no diagnostics check);
 * requests that run index.php use http_request().
 */
require __DIR__ . '/http-test-helpers.php';

define('PLANTED_SECRET', 'pgacc-planted-secret-' . bin2hex(random_bytes(8)));

function raw_request($baseUrl, $path)
{
    $context = stream_context_create(['http' => [
        'header' => "X-Forwarded-Proto: https\r\nHost: pgacc.test\r\nConnection: close\r\n",
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = @file_get_contents(rtrim($baseUrl, '/') . $path, false, $context);
    $headers = http_get_last_response_headers();
    if ($body === false || !is_array($headers) || preg_match('/^HTTP\/\S+ (\d{3})/', $headers[0] ?? '', $match) !== 1) {
        fail_test("request failed for GET $path");
    }
    return ['status' => intval($match[1]), 'headers' => $headers, 'body' => $body];
}

// $expected: [path => status]; a served file must start with its prefix,
// a refused one must not show any file content.
function check_addresses($baseUrl, $expected, $prefixes, $label)
{
    foreach ($expected as $path => $status) {
        $response = raw_request($baseUrl, $path);
        assert_status($response, $status, "$label: GET $path");
        if ($status === 200) {
            if (strpos($response['body'], $prefixes[$path]) !== 0) {
                fail_test("$label: GET $path served " . var_export(substr($response['body'], 0, 80), true));
            }
        } else {
            assert_not_contains($response['body'], PLANTED_SECRET, "$label: GET $path");
            assert_not_contains($response['body'], '<?php', "$label: GET $path");
            assert_not_contains($response['body'], '$key', "$label: GET $path");
        }
    }
}

$static = [
    '/style.css?v=1' => '/*! tailwindcss v',
    '/js/vreport-chart.js?v=1' => "/*\n * Running-total chart",
    '/js/chart.umd.min.js' => '/*!',
    '/icons/add.png' => "\x89PNG",
];
$notServed = [
    // Code, configuration and development files of the release root.
    '/functions.php' => 404,
    '/file.php' => 404,
    '/edit.php' => 404,
    '/getusers.php' => 404,
    '/impressum.php' => 404,
    '/constants.php' => 404,
    '/check.php' => 404,
    '/migrate-receipt-key.php' => 404,
    '/src/db.php' => 404,
    '/vendor/autoload.php' => 404,
    '/composer.json' => 404,
    '/composer.lock' => 404,
    '/tests/run.sh' => 404,
    '/tests/runtime/test-constants.php' => 404,
    '/files/' => 404,
    '/README.md' => 404,
    '/public/index.php' => 404,
    // public/ files outside the allow list.
    '/js/README.md' => 404,
    '/icons/readme.html' => 404,
    '/icons/' => 404,
    '/index.php/x' => 404,
    '/.htaccess' => 403,
    '/index.php~' => 403,
    '/index.php.bak' => 403,
];

// 1. The application service.
$baseUrl = getenv('PGACC_TEST_BASE_URL');
check_addresses($baseUrl, array_fill_keys(array_keys($static), 200) + $notServed, $static, 'application service');
assert_contains(http_request('/')['body'], '<h1>Rechenschaftsbericht der Piratenpartei Österreichs', '/');
assert_contains(http_request('/index.php?action=login')['body'], 'name="pass"', 'login page');
$export = http_request('/mbapi.php');
assert_status($export, 200, 'mbapi.php');
if (preg_match('/^[0-9a-f]{40}\n[A-Za-z0-9+\/=]+$/D', $export['body']) !== 1) {
    fail_test('mbapi.php answered ' . var_export(substr($export['body'], 0, 80), true));
}
// The short page addresses, rewritten to index.php?action=<name> with the
// query string appended (as nginx does).
$donations = http_request('/donations?year=2024');
assert_status($donations, 200, '/donations?year=2024');
assert_contains($donations['body'], '<h1>Spendenliste der Piratenpartei Österreichs 2024</h1>', '/donations?year=2024');
$transactions = http_request('/transactions?filter_date=2024');
assert_contains($transactions['body'], 'Kontobewegungen der Piratenpartei Österreichs', '/transactions');

// 2. A release with planted backup copies, in the layout of a deployment
// (code in the release root, document root public/).
$release = '/tmp/pgacc-docroot-release';
exec('rm -rf ' . escapeshellarg($release));
if (!mkdir($release . '/public', 0700, true)) {
    fail_test("could not create $release");
}
foreach (scandir('/app') as $entry) {
    if ($entry !== '.' && $entry !== '..' && $entry !== 'public') {
        symlink('/app/' . $entry, $release . '/' . $entry);
    }
}
foreach (scandir('/app/public') as $entry) {
    if ($entry !== '.' && $entry !== '..') {
        symlink('/app/public/' . $entry, $release . '/public/' . $entry);
    }
}
$planted = ['constants.php.bak', 'functions.php~', 'constants.php.save', 'functions.php.orig', 'dump.sql',
    'pgacc.tar.gz', 'notes.txt', 'config.php_backup'];
foreach (['', '/public'] as $directory) {
    foreach ($planted as $name) {
        file_put_contents($release . $directory . '/' . $name, "<?php \$key = '" . PLANTED_SECRET . "';\n");
    }
}
$releaseUrl = start_test_server(
    8087,
    'php -d output_buffering=4096 -S 127.0.0.1:8087 -t ' . escapeshellarg($release . '/public')
    . ' ' . escapeshellarg(__DIR__ . '/runtime/router.php'),
    'planted-release',
);
check_addresses($releaseUrl, array_fill_keys(array_keys($static), 200) + $notServed + [
    '/constants.php.bak' => 403,
    '/functions.php~' => 403,
    '/constants.php.save' => 403,
    '/functions.php.orig' => 403,
    '/dump.sql' => 403,
    '/pgacc.tar.gz' => 403,
    '/config.php_backup' => 403,
    '/CONSTANTS.PHP.BAK' => 403,
    '/notes.txt' => 404,
    '/js/../constants.php.bak' => 403,
    '/%63onstants.php.bak' => 403,
], $static, 'planted release');
$login = raw_request($releaseUrl, '/index.php?action=login');
assert_status($login, 200, 'planted release: login page');
assert_contains($login['body'], 'name="pass"', 'planted release: login page');
exec('rm -rf ' . escapeshellarg($release));

assert_no_unexpected_diagnostics();
echo "Document root and allow list tests passed\n";
