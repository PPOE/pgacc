<?php

/*
 * Internal sync endpoint internal/mbapi.php (sync v2, provider side): the
 * membership system fetches the acknowledged membership fees as JSON. PHP's
 * built-in server serves internal/, the document root of the internal nginx
 * listener (deploy/ansible/templates/nginx-site.conf.j2), from 127.0.0.1;
 * the test configuration tests/runtime/test-sync.php (sync.php of the image
 * and of the development loop) allows that peer with a synthetic token.
 *
 * 1. Without the token, with a wrong one or another scheme: 403, empty.
 * 2. With the token: 200, application/json, the fees of the fixtures below
 *    (amounts, the 1 October cutoff, ack1, deleted, the 2000-cent
 *    threshold), equal to the members and amounts of public/mbapi.php.
 * 3. A release whose sync.php allows another peer, and one without
 *    sync.php: 403 (tests/unit/SyncApiTest.php covers the other
 *    configurations).
 * 4. An unreachable database: 500, empty, the reason logged.
 * 5. No release root (open_basedir): 500, empty, the reason logged.
 */
require __DIR__ . '/http-test-helpers.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/functions.php';

const SYNC_TOKEN = 'synthetic-test-only-sync-token';

// The served application: /app, or the mutant copy of the mutation runner.
$appRoot = getenv('PGACC_TEST_APP_ROOT') ? rtrim(getenv('PGACC_TEST_APP_ROOT'), '/') : '/app';

// php -S on $port with internal/ of $root as document root; $settings and
// $environment are added to the command. With $diagnostics the diagnostics
// recorder runs; with $coverage, in the coverage run, coverage is recorded as
// on the application service.
function start_internal_server($port, $root, $settings, $environment, $diagnostics, $coverage, $label)
{
    $prepend = $diagnostics ? __DIR__ . '/runtime/diagnostics-prepend.php' : '';
    $coverageDir = getenv('PGACC_COVERAGE_DIR');
    if ($coverage && $coverageDir !== false && $coverageDir !== '') {
        $environment .= ' XDEBUG_MODE=coverage PGACC_COVERAGE_DIR=' . escapeshellarg($coverageDir . '/http')
            . ' PGACC_COVERAGE_CHAINED_PREPEND=' . escapeshellarg($prepend);
        $prepend = __DIR__ . '/coverage/prepend.php';
    }
    if ($prepend !== '') {
        $settings .= ' -d auto_prepend_file=' . escapeshellarg($prepend);
    }
    return start_test_server(
        $port,
        'env' . $environment . ' php -d output_buffering=4096' . $settings
        . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root . '/internal'),
        $label,
    );
}

// GET /mbapi.php with the Authorization header $authorization (null: none).
// The servers without the diagnostics recorder ($recorded false) are asked
// directly (http_request() expects the recorder's log).
function sync_request($baseUrl, $authorization, $recorded = true)
{
    $headers = $authorization === null ? [] : ['Authorization: ' . $authorization];
    if ($recorded) {
        return http_request('/mbapi.php', 'GET', [], [], $baseUrl, [], $headers);
    }
    $context = stream_context_create(['http' => [
        'header' => implode("\r\n", array_merge(['Connection: close'], $headers)),
        'ignore_errors' => true,
        'timeout' => 20,
    ]]);
    $body = @file_get_contents($baseUrl . '/mbapi.php', false, $context);
    $responseHeaders = http_get_last_response_headers() ?? [];
    if ($body === false || preg_match('/^HTTP\/\S+ (\d{3})/', $responseHeaders[0] ?? '', $match) !== 1) {
        fail_test("request to $baseUrl failed");
    }
    return ['status' => intval($match[1]), 'headers' => $responseHeaders, 'body' => $body];
}

function assert_refused($response, $status, $label)
{
    assert_status($response, $status, $label);
    if ($response['body'] !== '') {
        fail_test("$label answered " . var_export(substr($response['body'], 0, 80), true));
    }
}

// Membership fees: the same cases as the export test of tests/http-receipts.php
// (member ids 9901xx here, so that both can run on one database).
$year = intval(date('Y'));
$database = pg_connect('');
if ($database === false) {
    fail_test('could not connect to the test database');
}
$fees = [
    [990101, 2500, "$year-01-02", 'reviewer-a', false],
    [990102, 1000, "$year-01-02", 'reviewer-a', false],
    [990102, 1000, ($year - 1) . '-10-01', 'reviewer-a', false],
    [990103, 1999, "$year-01-02", 'reviewer-a', false],
    [990104, 5000, ($year - 1) . '-09-30', 'reviewer-a', false],
    [990105, 5000, "$year-01-02", null, false],
    [990106, 5000, "$year-01-02", 'reviewer-a', true],
];
foreach ($fees as $index => $fee) {
    $result = @pg_query_params(
        $database,
        "INSERT INTO vouchers (id, voucher_id, type, orga, member, member_id, amount, comment, date, ack1, deleted)
         VALUES ($1, $2, 1, 1, true, $3, $4, 'Synthetic membership fee', $5, $6, $7)",
        [9300 + $index, 9300 + $index, $fee[0], $fee[1], $fee[2], $fee[3], $fee[4] ? 't' : 'f'],
    );
    if ($result === false) {
        fail_test("membership fee $index: " . pg_last_error($database));
    }
}

$baseUrl = start_internal_server(8090, $appRoot, '', '', true, true, 'internal');

// 1. Refused without the right token.
assert_refused(sync_request($baseUrl, null), 403, 'no token');
assert_refused(sync_request($baseUrl, 'Bearer wrong-token'), 403, 'wrong token');
assert_refused(sync_request($baseUrl, 'Bearer ' . SYNC_TOKEN . 'x'), 403, 'longer token');
assert_refused(sync_request($baseUrl, SYNC_TOKEN), 403, 'token without a scheme');
assert_refused(sync_request($baseUrl, 'Basic ' . SYNC_TOKEN), 403, 'token as Basic');

// 2. The fees.
$response = sync_request($baseUrl, 'Bearer ' . SYNC_TOKEN);
assert_status($response, 200, 'payments');
if (response_header($response, 'Content-Type') !== 'application/json') {
    fail_test('payments: Content-Type ' . var_export(response_header($response, 'Content-Type'), true));
}
$payments = json_decode($response['body'], true);
if (!is_array($payments) || !array_is_list($payments)) {
    fail_test('payments: not a JSON list: ' . var_export(substr($response['body'], 0, 200), true));
}
$ours = array_values(array_filter($payments, fn($row) => is_array($row) && intdiv($row['id'] ?? 0, 100) === 9901));
$expected = [
    ['id' => 990101, 'amount_cents' => 2500, 'paid_until' => "$year-12-31"],
    ['id' => 990102, 'amount_cents' => 2000, 'paid_until' => "$year-12-31"],
];
if ($ours !== $expected) {
    fail_test('payments: expected ' . json_encode($expected) . ', got ' . json_encode($ours));
}
// The same members and amounts as public/mbapi.php, in its order.
$export = http_request('/mbapi.php');
assert_status($export, 200, 'membership export');
$lines = explode("\n", $export['body']);
$text = rtrim((string) membership_decrypt('synthetic-reference-membership-key', (string) base64_decode($lines[1] ?? '', true)), "\0");
$converted = '';
foreach ($payments as $row) {
    $converted .= $row['id'] . "\t" . $row['amount_cents'] . "\t" . $row['paid_until'] . "\n";
}
if ($converted !== $text || $lines[0] !== sha1($text)) {
    fail_test('payments differ from public/mbapi.php: ' . var_export($converted, true) . ' vs ' . var_export($text, true));
}

// 3. A release with a sync.php of its own (another peer), and one without.
// include_path "." first: the development loop's include_path puts the test
// configuration first (tests/dev/php.ini).
$release = '/tmp/pgacc-sync-release';
exec('rm -rf ' . escapeshellarg($release));
if (!mkdir($release, 0700, true)) {
    fail_test("could not create $release");
}
foreach (['internal', 'src'] as $entry) {
    symlink($appRoot . '/' . $entry, $release . '/' . $entry);
}
file_put_contents($release . '/sync.php', "<?php\nreturn ['token' => '" . SYNC_TOKEN . "', 'peers' => ['192.0.2.1']];\n");
$releaseUrl = start_internal_server(8093, $release, ' -d include_path=.:/usr/local/lib/php', '', true, false, 'sync-release');
assert_refused(sync_request($releaseUrl, 'Bearer ' . SYNC_TOKEN), 403, 'another peer');
unlink($release . '/sync.php');
assert_refused(sync_request($releaseUrl, 'Bearer ' . SYNC_TOKEN), 403, 'no sync.php');
exec('rm -rf ' . escapeshellarg($release));

// 4. An unreachable database.
$log = '/tmp/pgacc-sync-connect-failure.log';
@unlink($log);
$failureUrl = start_internal_server(
    8091,
    $appRoot,
    ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log),
    ' PGHOST=127.0.0.1 PGPORT=1 PGCONNECT_TIMEOUT=2',
    false,
    true,
    'sync-connect-failure',
);
assert_refused(sync_request($failureUrl, 'Bearer ' . SYNC_TOKEN, false), 500, 'unreachable database');
if (strpos((string) @file_get_contents($log), 'pg_connect(): Unable to connect to PostgreSQL server') === false) {
    fail_test('unreachable database: no logged reason: ' . @file_get_contents($log));
}

// 5. No release root: open_basedir allows internal/, the test files and the
// log directory only, so chdir(dirname(__DIR__)) fails.
$logDir = '/tmp/pgacc-sync-chdir-failure';
$log = $logDir . '/error.log';
exec('rm -rf ' . escapeshellarg($logDir));
if (!mkdir($logDir, 0700)) {
    fail_test("could not create $logDir");
}
$coverageDir = getenv('PGACC_COVERAGE_DIR');
$noRootUrl = start_internal_server(
    8092,
    $appRoot,
    ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -d open_basedir=' . escapeshellarg($appRoot . '/internal/:' . $appRoot . '/tests/:' . $logDir . '/'
        . ($coverageDir !== false && $coverageDir !== '' ? ':' . $coverageDir : '')),
    '',
    false,
    true,
    'sync-no-release-root',
);
assert_refused(sync_request($noRootUrl, 'Bearer ' . SYNC_TOKEN, false), 500, 'no release root');
$logText = (string) @file_get_contents($log);
if (substr_count($logText, 'pgacc: cannot change to the release root ' . $appRoot) !== 1
    || preg_match('/Failed opening|Fatal error/', $logText) === 1) {
    fail_test("no release root: expected 1 logged failure and no include: $logText");
}
exec('rm -rf ' . escapeshellarg($logDir));

pg_query($database, 'DELETE FROM vouchers WHERE id BETWEEN 9300 AND 9306');
pg_close($database);
assert_no_unexpected_diagnostics();
echo "Internal sync endpoint tests passed\n";
