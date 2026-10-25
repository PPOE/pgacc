<?php

/*
 * The HTTP scenario of the reference recordings. Every request is one
 * step; for each step it records
 *   http/<step>.txt         status, relevant headers, normalized body
 *   sql/<step>.sql          the statements (and SQL errors) of that request
 *   db/<step>.txt           row changes of every table the request wrote
 *   diagnostics/<step>.txt  PHP diagnostics (informational, see compare.php)
 * plus manifest.txt (step order), db/initial.txt and db/final.txt (full
 * dumps) and membership-stub.txt (requests the membership stub received).
 *
 * Steps run in a fixed order on a fresh database, so sequence values are
 * deterministic. Steps that need mcrypt (receipt upload/download, mbapi.php,
 * the membership import, the bank-statement import) run last, so that
 * lanes without mcrypt diverge as late as possible.
 *
 * Usage: php tests/reference/scenario.php <output directory>
 */
require __DIR__ . '/lib.php';

if (!isset($argv[1])) {
    ref_fail('usage: scenario.php <output directory>');
}
$GLOBALS['ref_out'] = rtrim($argv[1], '/');
$GLOBALS['ref_db'] = ref_database();
$GLOBALS['ref_log_offset'] = 0;
$GLOBALS['ref_state'] = [];
$GLOBALS['ref_manifest'] = [];
$GLOBALS['ref_sessions'] = ['anon' => []];
$GLOBALS['ref_ids'] = [];
// The form token of each session, as its last HTML page showed it.
$GLOBALS['ref_tokens'] = [];

$GLOBALS['ref_users'] = [
    'admin' => ['synthetic-admin', 'correct-password'],
    'reviewer' => ['synthetic-reviewer', 'reviewer-password'],
    'unitone' => ['synthetic-unit-one', 'unit-password'],
    'unittwo' => ['synthetic-unit-two', 'unit-two-password'],
    'readonly' => ['synthetic-readonly', 'readonly-password'],
    'norights' => ['synthetic-norights', 'norights-password'],
    'hideodd' => ['synthetic-hide-odd', 'hide-odd-password'],
];

function all_tables()
{
    $tables = [];
    foreach (ref_query($GLOBALS['ref_db'], "SELECT relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace"
        . " WHERE n.nspname = 'public' AND c.relkind = 'r' ORDER BY relname") as $row) {
        $tables[] = $row[0];
    }
    $tables[] = '(sequences)';
    return $tables;
}

function full_dump($name)
{
    $text = '';
    foreach (all_tables() as $table) {
        $dump = ref_dump_table($GLOBALS['ref_db'], $table);
        $GLOBALS['ref_state'][$table] = $dump;
        $text .= "## $table (" . (count($dump) - 1) . " rows)\n" . implode("\n", $dump) . "\n";
    }
    ref_write($GLOBALS['ref_out'] . "/db/$name.txt", $text);
}

function step($id, $user, $method, $path, $form = [], $files = [])
{
    if (preg_match('/^[a-z0-9-]{1,55}$/', $id) !== 1 || isset($GLOBALS['ref_manifest'][$id])) {
        ref_fail("invalid or duplicate step id $id");
    }
    $cookies = isset($GLOBALS['ref_sessions'][$user]) ? $GLOBALS['ref_sessions'][$user] : [];
    // POST requests of a session carry its form token, as its forms do,
    // unless the step sets csrf_token itself (null: none). The recording
    // shows the token as {{csrf-token}}.
    $recordedForm = $form;
    if ($method === 'POST' && $user !== 'anon' && strpos($path, '/index.php?action=login') !== 0
        && !array_key_exists('csrf_token', $form)) {
        if (!isset($GLOBALS['ref_tokens'][$user])) {
            ref_fail("step $id: no form token known for $user (request an HTML page of the session first)");
        }
        $form['csrf_token'] = $GLOBALS['ref_tokens'][$user];
        $recordedForm['csrf_token'] = '{{csrf-token}}';
    }
    $response = ref_http($id, $method, $path, $form, $files, $cookies);
    if ($user !== 'anon' && preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $response['body'], $matches) === 1) {
        $GLOBALS['ref_tokens'][$user] = $matches[1];
    }
    $records = ref_statement_log_until_marker($GLOBALS['ref_db'], $GLOBALS['ref_log_offset'], $id);
    $step = ['id' => $id, 'user' => $user, 'method' => $method, 'path' => $path, 'form' => $recordedForm, 'files' => $files];
    $out = $GLOBALS['ref_out'];
    ref_write("$out/http/$id.txt", ref_http_snapshot($step, $response));

    $statements = ref_request_statements($records, $id);
    $stray = [];
    foreach ($records as $record) {
        if ($record[0] !== 'ref:' . $id && $record[0] !== 'pgacc-reference-client' && strpos($record[0], 'ref:') === 0) {
            $stray[] = $record[0];
        }
    }
    $sql = count($statements) === 0 ? "-- no statements\n" : implode("-- next\n", $statements);
    if (count($stray) > 0) {
        $sql .= '-- statements of other requests in this window: ' . implode(', ', array_unique($stray)) . "\n";
    }
    ref_write("$out/sql/$id.sql", $sql);

    $diagnostics = ref_request_diagnostics($id);
    if (count($diagnostics) > 0) {
        ref_write("$out/diagnostics/$id.txt", implode("\n", $diagnostics) . "\n");
    }

    $tables = ref_written_tables($statements);
    if (count($tables) > 0) {
        $text = '';
        foreach ($tables as $table) {
            $after = ref_dump_table($GLOBALS['ref_db'], $table);
            $before = array_key_exists($table, $GLOBALS['ref_state']) ? $GLOBALS['ref_state'][$table] : null;
            $text .= "## $table\n" . implode("\n", ref_dump_delta($before, $after)) . "\n";
            $GLOBALS['ref_state'][$table] = $after;
        }
        ref_write("$out/db/$id.txt", $text);
    }
    $GLOBALS['ref_manifest'][$id] = "$id\t$user\t$method $path\t{$response['status']}";
    return $response;
}

function login($user, $id = null)
{
    $credentials = $GLOBALS['ref_users'][$user];
    $response = step($id === null ? "login-$user" : $id, 'anon', 'POST', '/index.php?action=login', [
        'submit' => 'Anmeldung',
        'name' => $credentials[0],
        'pass' => $credentials[1],
    ]);
    $GLOBALS['ref_sessions'][$user] = ref_response_cookies($response);
    unset($GLOBALS['ref_tokens'][$user]);
}

// A voucher id looked up by its comment (0 when the step did not create it).
function voucher_id($comment)
{
    $rows = ref_query($GLOBALS['ref_db'], "SELECT voucher_id, id FROM vouchers WHERE comment = '"
        . pg_escape_string($GLOBALS['ref_db'], $comment) . "' AND NOT deleted ORDER BY id");
    return count($rows) > 0 ? $rows[0] : ['0', '0'];
}

function voucher_part($part, $dir, $lo, $amount, $comment, $extra = [])
{
    $fields = [
        'bid' => '0',
        'dir' => $dir,
        'in_type' => '8',
        'out_type' => '20',
        'wk_type' => '21',
        'date' => '15.09.2024',
        'person_type' => '1',
        'lo' => $lo,
        'amount' => $amount,
        'gegenkonto' => 'SYNTHETICREF',
        'konto' => 'TESTACCOUNT2',
        'vkonto' => 'TESTVIRTUAL2',
        'comment' => $comment,
        'commentgf' => 'Reference workflow',
        'mitgliedsnummer' => '0',
        'name' => 'Synthetic Reference Supplier',
        'street' => 'Test Street 1',
        'plz' => '0000',
        'city' => 'Test City',
        'file' => '0',
    ];
    $form = [];
    foreach (array_merge($fields, $extra) as $name => $value) {
        $form[$name . $part] = $value;
    }
    return $form;
}

function voucher_form($parts)
{
    $form = ['speichern' => 'Speichern', 'parts' => strval(count($parts))];
    foreach ($parts as $part) {
        $form = array_merge($form, $part);
    }
    return $form;
}

function fixture($name)
{
    $content = file_get_contents(__DIR__ . '/fixtures/import/' . $name);
    if ($content === false) {
        ref_fail("missing fixture $name");
    }
    return $content;
}

// ------------------------------------------------------------------ setup

$extra = file_get_contents(__DIR__ . '/fixtures/extra.sql');
if (@pg_query($GLOBALS['ref_db'], $extra) === false) {
    ref_fail('could not load fixtures/extra.sql: ' . pg_last_error($GLOBALS['ref_db']));
}
full_dump('initial');

// ------------------------------------------------------------------ logins

step('login-form', 'anon', 'GET', '/index.php?action=login');
step('login-form-target', 'anon', 'GET', '/index.php?action=login&target=' . strtoupper(bin2hex('/index.php?action=all')));
step('login-wrong-password', 'anon', 'POST', '/index.php?action=login', ['submit' => 'Anmeldung', 'name' => 'synthetic-admin', 'pass' => 'wrong']);
step('login-quote', 'anon', 'POST', '/index.php?action=login', ['submit' => 'Anmeldung', 'name' => "synthetic-admin'--", 'pass' => "x' OR '1'='1"]);
foreach (array_keys($GLOBALS['ref_users']) as $user) {
    login($user);
}
step('login-again-target', 'admin', 'POST', '/index.php?action=login', ['submit' => 'Anmeldung', 'target' => strtoupper(bin2hex('index.php?action=open'))]);
step('login-form-logged-in', 'admin', 'GET', '/index.php?action=login');

// ------------------------------------------------------------------ public pages

step('report-default-anon', 'anon', 'GET', '/index.php');
for ($year = 2011; $year <= 2027; $year++) {
    step("report-$year-anon", 'anon', 'GET', "/index.php?year=$year");
}
step('report-year-variant-anon', 'anon', 'GET', '/index.php?year=2024-1');
step('report-year-invalid-anon', 'anon', 'GET', '/index.php?year=24');
step('report-print-empty-anon', 'anon', 'GET', '/index.php?year=2024&print_empty=1');
step('report-noeyes-anon', 'anon', 'GET', '/index.php?year=2024&noeyes=1');
step('report-unknown-action-anon', 'anon', 'GET', '/index.php?action=unknown');
step('transfer-anon', 'anon', 'GET', '/index.php?action=transfer');

$public = [
    'transactions' => '/index.php?action=transactions',
    'transactions-csv' => '/index.php?action=transactions&format=csv',
    'transactions-filtered' => '/index.php?action=transactions&filter_date=2024&sort=amd',
    'spendings' => '/index.php?action=spendings',
    'spendings-csv' => '/index.php?action=spendings&format=csv',
    'spendings-filtered' => '/index.php?action=spendings&type=20&unit=2&from=2024-01-01&to=2024-12-31',
    'spendings-all-units' => '/index.php?action=spendings&unit=-1&from=2012-01-01&to=2026-12-31',
    'donations-2024' => '/index.php?action=donations&year=2024',
    'donations-2026' => '/index.php?action=donations&year=2026',
    'donations-2011' => '/index.php?action=donations&year=2011',
    'donations-no-year' => '/index.php?action=donations',
    'kdonations' => '/index.php?action=kdonations',
    'statistics' => '/index.php?action=statistics',
    'impressum' => '/index.php?action=impressum',
    'wk' => '/index.php?action=wk',
    'vreport' => '/index.php?action=vreport',
    'vreport-filtered' => '/index.php?action=vreport&konto=TEST-ACCOUNT-1&date_begin=2024-01-01&date_end=2024-12-31',
    'tinyreport' => '/index.php?action=tinyreport',
    'tinyreport-filtered' => '/index.php?action=tinyreport&konto=TEST-ACCOUNT-2,TEST-ACCOUNT-1&date_begin=2012-01-01&date_end=2026-12-31',
];
foreach ($public as $name => $path) {
    step("$name-anon", 'anon', 'GET', $path);
}
foreach (['admin', 'unitone', 'norights'] as $user) {
    foreach (['report-default' => '/index.php', 'transactions' => '/index.php?action=transactions',
        'vreport' => '/index.php?action=vreport', 'tinyreport' => '/index.php?action=tinyreport',
        'tinyreport-filtered' => $public['tinyreport-filtered']] as $name => $path) {
        step("$name-$user", $user, 'GET', $path);
    }
}
$captcha = ['captcha_input' => '37', 'captcha' => '0', 'submit' => 'Bestätigen'];
step('donations-2024-captcha-ok', 'anon', 'POST', '/index.php?action=donations&year=2024', $captcha);
step('donations-2023-captcha-ok', 'anon', 'POST', '/index.php?action=donations&year=2023', $captcha);
step('donations-2024-captcha-wrong', 'anon', 'POST', '/index.php?action=donations&year=2024', ['captcha_input' => '1', 'captcha' => '0']);
step('kdonations-captcha-ok', 'anon', 'POST', '/index.php?action=kdonations', $captcha);
step('kdonations-filtered-captcha-ok', 'anon', 'POST', '/index.php?action=kdonations&type=11&unit=-1&from=2012-01-01&to=2026-12-31', $captcha);
step('kdonations-unit-captcha-ok', 'anon', 'POST', '/index.php?action=kdonations&unit=2&from=2024-01-01&to=2024-12-31', $captcha);

// ------------------------------------------------------------------ protected pages

$protected = [
    'all' => '/index.php?action=all&filter_date=2024',
    'open' => '/index.php?action=open&filter_date=2024',
    'closed' => '/index.php?action=closed&filter_date=2024',
    'deleted' => '/index.php?action=deleted&filter_date=2024',
    'accounts' => '/index.php?action=accounts',
    'new' => '/index.php?action=new',
    'edit-1003' => '/index.php?action=edit&id=1003',
    'recover-view' => '/index.php?action=recover&id=1004&bid=105',
    'search' => '/index.php?action=search&member=9001',
];
foreach (['anon', 'admin', 'reviewer', 'unitone', 'unittwo', 'readonly', 'norights'] as $user) {
    foreach ($protected as $name => $path) {
        step("$name-$user", $user, 'GET', $path);
    }
}
foreach (['admin', 'unitone'] as $user) {
    foreach (['all', 'open', 'closed', 'deleted'] as $name) {
        step("$name-csv-$user", $user, 'GET', "/index.php?action=$name&format=csv");
    }
}
step('search-anon-csv', 'anon', 'GET', '/index.php?action=search&member=12&format=csv');
step('all-hideodd', 'hideodd', 'GET', '/index.php?action=all&filter_date=2024');
step('all-csv-hideodd', 'hideodd', 'GET', '/index.php?action=all&format=csv');

// Every listing filter (getfilter()) and sort (getsort()) on the ledger.
$filters = [
    'id' => 'filter_id=1003', 'id-invalid' => 'filter_id=10a', 'bid' => 'filter_bid=103',
    'date-year' => 'filter_date=2024', 'date-month' => 'filter_date=2024-04', 'date-day' => 'filter_date=2024-04-01',
    'date-invalid' => 'filter_date=20x4', 'type-id' => 'filter_type=20', 'type-name' => 'filter_type=expense',
    'type-umlaut' => 'filter_type=' . rawurlencode('Spende ü'), 'lo-id' => 'filter_lo=2', 'lo-name' => 'filter_lo=regional',
    'member' => 'filter_member=1', 'member-id' => 'filter_member_id=9001', 'gk' => 'filter_gk=SYNTHETIC',
    'amount-gt' => 'filter_amount=' . rawurlencode('>1000'), 'amount-le-negative' => 'filter_amount=' . rawurlencode('<=-50'),
    'amount-eq' => 'filter_amount=' . rawurlencode('=120'), 'amount-ge-decimal' => 'filter_amount=' . rawurlencode('>=12.5'),
    'amount-comma' => 'filter_amount=' . rawurlencode('<-50,00'), 'amount-plain' => 'filter_amount=120',
    'amount-plain-decimal' => 'filter_amount=' . rawurlencode('-50,00'), 'amount-invalid' => 'filter_amount=abc',
    'amount-space' => 'filter_amount=' . rawurlencode('> 100'), 'k' => 'filter_k=TEST',
    'vk' => 'filter_vk=TEST-VIRTUAL', 'text' => 'filter_text=donation', 'text-umlaut' => 'filter_text=' . rawurlencode('Österreich'),
    'comment' => 'filter_comment=Kommentar', 'comm' => 'filter_comm=1', 'ack' => 'filter_ack=reviewer',
    'ack-old' => 'filter_ack_old=reviewer', 'refund' => 'filter_refund=1', 'bel' => 'filter_bel=1',
    'name' => 'filter_name=synthetic', 'combined' => 'filter_date=2024&filter_lo=1&filter_amount=' . rawurlencode('>0') . '&filter_text=donation',
];
foreach ($filters as $name => $query) {
    step("all-filter-$name", 'admin', 'GET', "/index.php?action=all&format=csv&$query");
}
step('all-filter-html', 'admin', 'GET', '/index.php?action=all&filter_date=2024&filter_lo=1&filter_amount=' . rawurlencode('>0') . '&filter_text=donation&sort=amd');
foreach (['ida', 'idd', 'bida', 'bidd', 'dated', 'datea', 'typea', 'typed', 'loa', 'lod', 'membera', 'memberd',
    'gka', 'gkd', 'ka', 'kd', 'ama', 'amd', 'commenta', 'commentd', 'texta', 'textd', 'comma', 'commd', 'acka', 'ackd',
    'refunda', 'refundd', 'bela', 'beld', 'namea', 'named', 'invalid'] as $sort) {
    step("all-sort-$sort", 'admin', 'GET', "/index.php?action=all&format=csv&sort=$sort");
}
step('all-sort-html', 'admin', 'GET', '/index.php?action=all&filter_date=2024&sort=ida');
step('closed-filter-csv', 'unitone', 'GET', '/index.php?action=closed&format=csv&filter_date=2024&sort=amd');
step('transactions-filter-csv', 'anon', 'GET', '/index.php?action=transactions&format=csv&filter_lo=2');

// Column preferences (POST hide=N with the form token) are stored in
// users.hide; the old GET link (?hide=N) stores nothing.
step('all-hide-3-get', 'admin', 'GET', '/index.php?action=all&format=csv&filter_date=2024&hide=3');
step('all-hide-3', 'admin', 'POST', '/index.php?action=all&filter_date=2024', ['hide' => '3']);
step('all-hide-3-again', 'admin', 'GET', '/index.php?action=all&format=csv&filter_date=2024');
step('all-show-3', 'admin', 'POST', '/index.php?action=all&format=csv&filter_date=2024', ['hide' => '-3']);

// Edit views.
foreach (['1001' => 'admin', '1004' => 'admin', '9999' => 'admin', 'abc' => 'admin', '1001-' => 'unitone', '1003-' => 'unitone'] as $voucher => $user) {
    $id = rtrim($voucher, '-');
    step("edit-view-$id-$user", $user, 'GET', '/index.php?action=edit&id=' . $id);
}
step('edit-view-bid', 'admin', 'GET', '/index.php?action=edit&id=1004&bid=105');
step('edit-view-hint', 'admin', 'GET', '/index.php?action=edit&id=1004&hint=5');
step('edit-add-part', 'admin', 'POST', '/index.php?action=edit&id=1004', array_merge(
    voucher_form([voucher_part(0, 'out', '2', '-25,00', 'Unreviewed expense')]),
    ['id' => '1004', 'add' => 'Neue Buchungszeile', 'speichern' => ''],
));
step('new-add-part', 'admin', 'POST', '/index.php?action=new', array_merge(
    voucher_form([voucher_part(0, 'in', '1', '10', 'Draft')]),
    ['add' => 'Neue Buchungszeile', 'speichern' => ''],
));

// ------------------------------------------------------------------ write workflows

step('new-expense', 'admin', 'POST', '/index.php?action=new', voucher_form([
    voucher_part(0, 'out', '2', '-12,34', 'Reference created expense'),
]));
$created = voucher_id('Reference created expense');
step('new-two-parts', 'admin', 'POST', '/index.php?action=new', voucher_form([
    voucher_part(0, 'in', '1', '100.5', 'Reference two parts income', ['person_type' => '2', 'mitgliedsnummer' => '9001']),
    voucher_part(1, 'wk', '1', '-0,99', 'Reference two parts campaign', ['date' => '2024-09-16']),
]));
$twoParts = voucher_id('Reference two parts income');
step('new-unitone-own', 'unitone', 'POST', '/index.php?action=new', voucher_form([
    voucher_part(0, 'in', '1', '7', 'Reference unit one own'),
]));
step('new-unitone-other', 'unitone', 'POST', '/index.php?action=new', voucher_form([
    voucher_part(0, 'in', '2', '8', 'Reference unit one other'),
]));
step('new-save-readonly', 'readonly', 'POST', '/index.php?action=new', voucher_form([
    voucher_part(0, 'in', '1', '9', 'Reference read-only'),
]));
step('new-invalid', 'admin', 'POST', '/index.php?action=new', voucher_form([
    voucher_part(0, 'sideways', 'x', 'abc', "Reference invalid ' quote", ['date' => '31.02.2024']),
]));
step('new-umlauts', 'admin', 'POST', '/index.php?action=new', voucher_form([
    voucher_part(0, 'out', '2', '1e3', "Reference Ümlaut \"Zitat\" O'Brien\nZeile 2", ['name' => 'Jörg Ä. Österreicher']),
]));
step('edit-view-created', 'admin', 'GET', '/index.php?action=edit&id=' . $created[0]);

$ack = ['id' => $created[0], 'parts' => '1', 'ack' => 'Bestätigen'];
step('ack-cross-org', 'unitone', 'POST', '/index.php?action=edit&id=' . $created[0], $ack);
step('ack-first', 'admin', 'POST', '/index.php?action=edit&id=' . $created[0], $ack);
step('ack-duplicate', 'admin', 'POST', '/index.php?action=edit&id=' . $created[0], $ack);
step('ack-readonly', 'readonly', 'POST', '/index.php?action=edit&id=' . $created[0], $ack);
step('ack-second', 'reviewer', 'POST', '/index.php?action=edit&id=' . $created[0], $ack);
step('finalize', 'admin', 'POST', '/index.php?action=edit&id=' . $created[0], ['id' => $created[0], 'parts' => '1', 'beleg' => 'Beleg erhalten']);
$blocked = voucher_form([voucher_part(0, 'out', '2', '-99,99', 'Reference blocked revision')]);
step('edit-finalized', 'admin', 'POST', '/index.php?action=edit&id=' . $created[0], array_merge($blocked, [
    'id' => $created[0], 'changed' => '1', 'ack1' => 'synthetic-admin', 'ack2' => 'synthetic-reviewer', 'ack1_old' => '', 'ack2_old' => '',
]));
step('edit-view-finalized', 'admin', 'GET', '/index.php?action=edit&id=' . $created[0]);

$twoAck = ['id' => $twoParts[0], 'parts' => '2', 'ack' => 'Bestätigen'];
step('ack-two-parts', 'admin', 'POST', '/index.php?action=edit&id=' . $twoParts[0], $twoAck);
step('drop-acks', 'admin', 'POST', '/index.php?action=edit&id=' . $twoParts[0], ['id' => $twoParts[0], 'parts' => '2', 'belegfehler' => 'Fehler']);

step('revision', 'admin', 'POST', '/index.php?action=edit&id=1004', array_merge(
    voucher_form([voucher_part(0, 'out', '2', '-30,00', 'Reference revised draft')]),
    ['id' => '1004', 'changed' => '1', 'ack1' => '', 'ack2' => '', 'ack1_old' => '', 'ack2_old' => ''],
));
step('revision-unitone-other-org', 'unitone', 'POST', '/index.php?action=edit&id=1004', array_merge(
    voucher_form([voucher_part(0, 'out', '2', '-31,00', 'Reference revision by unit one')]),
    ['id' => '1004', 'changed' => '1', 'ack1' => '', 'ack2' => '', 'ack1_old' => '', 'ack2_old' => ''],
));
step('edit-view-revised', 'admin', 'GET', '/index.php?action=edit&id=1004');
step('deleted-after-revision', 'admin', 'GET', '/index.php?action=deleted&format=csv');

step('new-merge-target', 'admin', 'POST', '/index.php?action=new', voucher_form([voucher_part(0, 'out', '2', '-1', 'Reference merge target')]));
step('new-merge-source', 'admin', 'POST', '/index.php?action=new', voucher_form([voucher_part(0, 'out', '2', '-2', 'Reference merge source')]));
$mergeTarget = voucher_id('Reference merge target');
$mergeSource = voucher_id('Reference merge source');
// Merge and recovery write only on POST with the form token: the old GET
// links write nothing, and a missing or wrong token is refused.
$mergeForm = ['bid1' => $mergeTarget[0], 'bid2' => $mergeSource[0]];
step('merge-get', 'admin', 'GET', '/index.php?action=merge&bid1=' . $mergeTarget[0] . '&bid2=' . $mergeSource[0]);
step('merge-no-token', 'admin', 'POST', '/index.php?action=merge', array_merge($mergeForm, ['csrf_token' => null]));
step('merge-wrong-token', 'admin', 'POST', '/index.php?action=merge', array_merge($mergeForm, ['csrf_token' => str_repeat('0', 64)]));
step('merge-unitone', 'unitone', 'POST', '/index.php?action=merge', $mergeForm);
step('merge-reviewer', 'reviewer', 'POST', '/index.php?action=merge', $mergeForm);
step('merge-admin', 'admin', 'POST', '/index.php?action=merge', $mergeForm);
step('merge-admin-again', 'admin', 'POST', '/index.php?action=merge', $mergeForm);
step('merge-missing', 'admin', 'POST', '/index.php?action=merge');

step('recover-get', 'admin', 'GET', '/index.php?action=recover&id=1004&bid=105&doit=1');
step('recover-unitone', 'unitone', 'POST', '/index.php?action=recover&id=1004&bid=105', ['doit' => 'Ja, ich weiß was ich tue!']);
step('recover-admin', 'admin', 'POST', '/index.php?action=recover&id=1004&bid=105', ['doit' => 'Ja, ich weiß was ich tue!']);

step('accounts-create', 'admin', 'POST', '/index.php?action=accounts', [
    'submit' => 'Benutzer -1 erstellen', 'name-1' => 'synthetic-created', 'password-1' => 'created-password', 'rights-1' => '3',
]);
step('accounts-create-unchanged-password', 'admin', 'POST', '/index.php?action=accounts', [
    'submit' => 'Benutzer -1 erstellen', 'name-1' => 'synthetic-not-created', 'password-1' => '******', 'rights-1' => '3',
]);
step('accounts-update-rights', 'admin', 'POST', '/index.php?action=accounts', [
    'submit' => 'Benutzer 3 aktualisieren', 'password3' => '******', 'rights3' => '1,2',
]);
step('accounts-own-password', 'unitone', 'POST', '/index.php?action=accounts', [
    'submit' => 'Benutzer 3 aktualisieren', 'password3' => 'unit-password',
]);
// A password change ends the sessions of that user: log in again for the
// following unit one steps.
login('unitone', 'login-unitone-after-password');
step('accounts-unitone-after-password', 'unitone', 'GET', '/index.php?action=accounts');
step('accounts-other-user', 'unitone', 'POST', '/index.php?action=accounts', [
    'submit' => 'Benutzer 1 aktualisieren', 'password1' => 'stolen',
]);
step('accounts-after', 'admin', 'GET', '/index.php?action=accounts');

step('all-after-writes', 'admin', 'GET', '/index.php?action=all&format=csv');
step('report-2024-after-writes', 'anon', 'GET', '/index.php?year=2024');

// ------------------------------------------------------------------ mcrypt-dependent steps

$upload = [['file0', 'receipt.pdf', 'application/pdf', fixture('receipt.pdf')]];
step('receipt-upload', 'admin', 'POST', '/index.php?action=edit&id=' . $twoParts[0], [
    'id' => $twoParts[0], 'parts' => '1', 'bid0' => $twoParts[1], 'fileupload' => 'PDF Hochladen (OHNE SPEICHERN)',
], $upload);
step('receipt-upload-finalized', 'admin', 'POST', '/index.php?action=edit&id=' . $created[0], [
    'id' => $created[0], 'parts' => '1', 'bid0' => $created[1], 'fileupload' => 'PDF Hochladen (OHNE SPEICHERN)',
], $upload);
step('receipt-download-admin', 'admin', 'GET', '/index.php?action=file&id=' . $twoParts[1]);
step('receipt-download-unittwo', 'unittwo', 'GET', '/index.php?action=file&id=' . $twoParts[1]);
step('receipt-download-anon', 'anon', 'GET', '/index.php?action=file&id=' . $twoParts[1]);
step('receipt-download-missing-file', 'admin', 'GET', '/index.php?action=file&id=103');
step('receipt-download-no-file', 'admin', 'GET', '/index.php?action=file&id=101');
step('edit-view-with-receipt', 'admin', 'GET', '/index.php?action=edit&id=' . $twoParts[0]);

step('mbapi', 'anon', 'GET', '/mbapi.php');
step('mb-admin', 'admin', 'GET', '/index.php?action=mb');
step('mb-unitone', 'unitone', 'GET', '/index.php?action=mb');
step('mb-anon', 'anon', 'GET', '/index.php?action=mb');

step('import-form', 'admin', 'GET', '/index.php?action=import');
step('import-form-unitone', 'unitone', 'GET', '/index.php?action=import');
step(
    'import-bawag',
    'admin',
    'POST',
    '/index.php?action=import',
    ['MAX_FILE_SIZE' => '1000000'],
    [['uploadcsv[]', 'bawag.csv', 'text/csv', fixture('bawag.csv')]],
);
step(
    'import-bawag-again',
    'admin',
    'POST',
    '/index.php?action=import',
    ['MAX_FILE_SIZE' => '1000000'],
    [['uploadcsv[]', 'bawag.csv', 'text/csv', fixture('bawag.csv')]],
);
step('import-latin9-handkassa', 'admin', 'POST', '/index.php?action=import', ['MAX_FILE_SIZE' => '1000000'], [
    ['uploadcsv[]', 'bawag-latin9.csv', 'application/vnd.ms-excel', fixture('bawag-latin9.csv')],
    ['uploadcsv[]', 'handkassa.csv', 'text/plain', fixture('handkassa.csv')],
]);
step(
    'import-sparkasse',
    'admin',
    'POST',
    '/index.php?action=import',
    ['MAX_FILE_SIZE' => '1000000'],
    [['uploadcsv[]', 'sparkasse.csv', 'text/csv', fixture('sparkasse.csv')]],
);
step(
    'import-paypal',
    'admin',
    'POST',
    '/index.php?action=import',
    ['MAX_FILE_SIZE' => '1000000'],
    [['uploadcsv[]', 'paypal.csv', 'text/csv', fixture('paypal.csv')]],
);
step('import-rejected-files', 'admin', 'POST', '/index.php?action=import', ['MAX_FILE_SIZE' => '1000000'], [
    ['uploadcsv[]', 'page.html', 'text/html', '<html>not a csv</html>'],
    ['uploadcsv[]', 'tiny.csv', 'text/csv', 'a;b'],
]);
step(
    'import-unknown-account',
    'admin',
    'POST',
    '/index.php?action=import',
    ['MAX_FILE_SIZE' => '1000000'],
    [['uploadcsv[]', 'bawag-unknown-account.csv', 'text/csv', fixture('bawag-unknown-account.csv')]],
);
step('all-after-import', 'admin', 'GET', '/index.php?action=all&format=csv&sort=bida');
step('report-2024-after-import', 'anon', 'GET', '/index.php?year=2024');

step('import2-form', 'admin', 'GET', '/index.php?action=import2');
foreach (['single', 'cover', 'noid', 'twodates'] as $statement) {
    step(
        "import2-$statement",
        'admin',
        'POST',
        '/index.php?action=import2',
        ['MAX_FILE_SIZE' => '10000000'],
        [['upload[]', "statement-$statement.pdf", 'application/pdf', fixture("statement-$statement.pdf")]],
    );
}
step(
    'import2-single-again',
    'admin',
    'POST',
    '/index.php?action=import2',
    ['MAX_FILE_SIZE' => '10000000'],
    [['upload[]', 'statement-single.pdf', 'application/pdf', fixture('statement-single.pdf')]],
);
step('import2-rejected', 'admin', 'POST', '/index.php?action=import2', ['MAX_FILE_SIZE' => '10000000'], [
    ['upload[]', 'statement.txt', 'text/plain', 'not a pdf at all'],
    ['upload[]', 'tiny.pdf', 'application/pdf', '%PDF'],
]);
step('import2-download', 'admin', 'GET', '/index.php?action=file&id=220');
step('edit-view-imported-receipt', 'admin', 'GET', '/index.php?action=edit&id=2020');

// Logout is the POST form with the form token; GET (old link) ends nothing.
step('logout-admin-get', 'admin', 'GET', '/index.php?action=logout');
step('logout-admin', 'admin', 'POST', '/index.php?action=logout');
step('logout-anon', 'anon', 'GET', '/index.php?action=logout');
step('logout-anon-post', 'anon', 'POST', '/index.php?action=logout');

// ------------------------------------------------------------------ finish

full_dump('final');
$stub = @file_get_contents(getenv('PGACC_REFERENCE_STUB_LOG'));
ref_write($GLOBALS['ref_out'] . '/membership-stub.txt', "# requests received by the local membership stub\n" . ($stub === false ? '' : $stub));
ref_write($GLOBALS['ref_out'] . '/manifest.txt', "# step\tuser\trequest\tstatus\n" . implode("\n", $GLOBALS['ref_manifest']) . "\n");
echo 'Recorded ' . count($GLOBALS['ref_manifest']) . ' HTTP steps in ' . $GLOBALS['ref_out'] . "\n";
