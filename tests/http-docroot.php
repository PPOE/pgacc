<?php

/*
 * Document root public/ and the allow list of the web server.
 *
 * The test servers serve public/ through tests/runtime/router.php, which
 * applies the allow list of the nginx site (deploy/ansible/templates/
 * nginx-site.conf.j2). The requests and their answers are the shared matrix
 * tests/web-server/matrix.php, which tests/web-server/run.sh also runs
 * against real nginx with the rendered template and against Apache.
 *
 * 1. The application service: the matrix without planted files; the allowed
 *    addresses answer, the code, configuration and development files do not.
 * 2. A release in the layout of a deployment, built in /tmp: the code are
 *    symbolic links to the read-only /app, public/ is a copy (the router
 *    refuses files reached through a symbolic link), constants.php is a
 *    synthetic configuration of its own, and backup copies, a dump, an
 *    archive, a text file, PHP scripts and a symbolic link are planted in the
 *    release root and in public/ (web_server_plant()). Served by a server of
 *    its own like the application service: none of the planted files is
 *    served, and the entry points run in the release root (the planted
 *    release's constants.php is loaded).
 * 3. The entry points fail closed when they cannot change to the release
 *    root (open_basedir forbids it): 500, a logged reason, nothing else runs.
 *
 * Requests answered by the router or as static files bypass the diagnostics
 * recorder, so they are sent with web_server_request() (no diagnostics check);
 * requests that run index.php use http_request().
 */
require __DIR__ . '/http-test-helpers.php';
require __DIR__ . '/web-server/lib.php';

define('PLANTED_SECRET', 'pgacc-planted-secret-' . bin2hex(random_bytes(8)));
$matrix = require __DIR__ . '/web-server/matrix.php';

// The entry point ran: the page or the export the query asks for.
function check_router_entry($response, $script, $query, $label)
{
    $expected = [
        'index.php' => '<h1>Rechenschaftsbericht der Piratenpartei Österreichs',
        'index.php?action=login' => 'name="pass"',
        'index.php?action=donations&year=2024' => '<h1>Spendenliste der Piratenpartei Österreichs 2024</h1>',
        'index.php?action=vreport' => '<h1>Bericht Konto',
    ][$script . ($query === '' ? '' : '?' . $query)] ?? null;
    if ($script === 'mbapi.php') {
        if (preg_match('/^[0-9a-f]{40}\n[A-Za-z0-9+\/=]+$/D', $response['body']) !== 1) {
            fail_test("$label: mbapi.php answered " . var_export(substr($response['body'], 0, 80), true));
        }
    } elseif ($expected === null) {
        fail_test("$label: no expected page for $script?$query");
    } else {
        assert_contains($response['body'], $expected, $label);
    }
}

// 1. The application service.
$baseUrl = getenv('PGACC_TEST_BASE_URL');
web_server_check_matrix($baseUrl, $matrix, '/app/public', 'check_router_entry', [], null, 'fail_test', 'application service');
// The same pages through http_request() (diagnostics checked).
assert_contains(http_request('/')['body'], '<h1>Rechenschaftsbericht der Piratenpartei Österreichs', '/');
assert_contains(http_request('/index.php?action=login')['body'], 'name="pass"', 'login page');
assert_status(http_request('/mbapi.php'), 200, 'mbapi.php');
$donations = http_request('/donations?year=2024');
assert_status($donations, 200, '/donations?year=2024');
assert_contains($donations['body'], '<h1>Spendenliste der Piratenpartei Österreichs 2024</h1>', '/donations?year=2024');
$transactions = http_request('/transactions?filter_date=2024');
assert_contains($transactions['body'], 'Kontobewegungen der Piratenpartei Österreichs', '/transactions');

// 2. A release with planted files, in the layout of a deployment (code in
// the release root, document root public/).
$release = '/tmp/pgacc-docroot-release';
exec('rm -rf ' . escapeshellarg($release));
if (!mkdir($release, 0700, true)) {
    fail_test("could not create $release");
}
foreach (scandir('/app') as $entry) {
    if (!in_array($entry, ['.', '..', 'public', 'constants.php'], true)) {
        symlink('/app/' . $entry, $release . '/' . $entry);
    }
}
exec('cp -R /app/public ' . escapeshellarg($release . '/public'), $output, $status);
if ($status !== 0) {
    fail_test('could not copy /app/public');
}
// The release's own configuration: the test constants plus a marker header,
// so the answers show that the entry points loaded this constants.php.
file_put_contents($release . '/constants.php', "<?php\nrequire '/app/tests/runtime/test-constants.php';\n"
    . "header('X-Pgacc-Test-Constants: planted-release');\n");
web_server_plant($release, $matrix, PLANTED_SECRET, 'fail_test');
// include_path "." first: the development loop's include_path puts the
// test constants first (tests/dev/php.ini).
$releaseUrl = start_test_server(
    8087,
    'php -d output_buffering=4096 -d include_path=.:/usr/local/lib/php -S 127.0.0.1:8087 -t '
    . escapeshellarg($release . '/public') . ' ' . escapeshellarg(__DIR__ . '/runtime/router.php'),
    'planted-release',
);
web_server_check_matrix($releaseUrl, $matrix, $release . '/public', 'check_router_entry', [], PLANTED_SECRET, 'fail_test', 'planted release');
foreach (['/index.php?action=login', '/mbapi.php'] as $path) {
    $response = web_server_request($releaseUrl, $path, 'fail_test');
    if (!in_array('X-Pgacc-Test-Constants: planted-release', $response['headers'], true)) {
        fail_test("planted release: GET $path did not load the release's constants.php");
    }
}

// 3. No release root: open_basedir allows public/ (and the test files) only,
// so chdir(dirname(__DIR__)) fails.
$log = '/tmp/docroot-chdir-failure.log';
@unlink($log);
$environment = '';
$settings = ' -d display_errors=0 -d log_errors=1 -d error_log=' . $log
    . ' -d open_basedir=/app/public:/app/tests:/tmp';
$coverageDir = getenv('PGACC_COVERAGE_DIR');
if ($coverageDir !== false && $coverageDir !== '') {
    $environment = 'env XDEBUG_MODE=coverage PGACC_COVERAGE_DIR=' . escapeshellarg($coverageDir . '/http')
        . ' PGACC_COVERAGE_CHAINED_PREPEND= ';
    $settings .= ':' . $coverageDir . ' -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/coverage/prepend.php');
}
$noRootUrl = start_test_server(
    8088,
    $environment . 'php' . $settings . ' -S 127.0.0.1:8088 -t /app/public ' . escapeshellarg(__DIR__ . '/runtime/router.php'),
    'no-release-root',
);
foreach (['/index.php?action=login' => 'index.php', '/mbapi.php' => 'mbapi.php'] as $path => $script) {
    $response = web_server_request($noRootUrl, $path, 'fail_test');
    assert_status($response, 500, "no release root: GET $path");
    if ($response['body'] !== '') {
        fail_test("no release root: GET $path answered " . var_export(substr($response['body'], 0, 80), true));
    }
}
$logText = (string) @file_get_contents($log);
$logged = substr_count($logText, 'pgacc: cannot change to the release root /app');
if ($logged !== 2 || preg_match('/Failed opening|Fatal error/', $logText) === 1) {
    fail_test("no release root: expected 2 logged failures and no include, found $logged: $logText");
}
exec('rm -rf ' . escapeshellarg($release));

assert_no_unexpected_diagnostics();
echo "Document root and allow list tests passed\n";
