<?php

/*
 * Member lookup on the edit page (new.php member_lookup_html(), getusers.php
 * member_datalist_html()/member_suggestions(), edit.php
 * edit_next_open_link() and the member-unknown note of page_edit_save())
 * with a synthetic member list served by the membership stub of
 * compose.test.yaml (POST /__stub/body). Only users with the exact bgf right
 * get the member list on the number fields (one datalist per page), the
 * suggestions, the hints, the note on an unknown number and the "Nächste
 * offene Buchung" link; other users (an organization, Rbgf) see the form as
 * before and cause no member list request. The rows are synthetic (voucher
 * numbers 97001-97004); every change to the shared database is undone by a
 * shutdown function, also when a check fails.
 */
require __DIR__ . '/http-test-helpers.php';

$database = @pg_connect(pgacc_test_connection_string());
if ($database === false) {
    fail_test('could not connect to the fixture database');
}

function lookup_query($database, $query, $parameters = [])
{
    $result = @pg_query_params($database, $query, $parameters);
    if ($result === false) {
        fail_test("query failed: $query: " . pg_last_error($database));
    }
    return $result;
}

function lookup_stub_mode($mode)
{
    $answer = @file_get_contents('http://mitglieder.piratenpartei.at/__stub/mode?set=' . $mode);
    if ($answer !== "mode $mode\n") {
        fail_test("membership stub mode $mode: " . var_export($answer, true));
    }
}

// The member list requests of the stub since its last mode switch.
function lookup_stub_requests()
{
    $answer = @file_get_contents('http://mitglieder.piratenpartei.at/__stub/members-requests');
    if (!is_string($answer) || preg_match('/^\d+\n$/D', $answer) !== 1) {
        fail_test('membership stub request count: ' . var_export($answer, true));
    }
    return intval($answer);
}

// Serves the members [[id, name, lo, paid], ...] as the member list.
function lookup_stub_members($members)
{
    $list = [];
    foreach ($members as [$id, $name, $lo, $paid]) {
        $list[] = ['id' => $id, 'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id), 'name' => $name, 'lo' => $lo, 'paid' => $paid];
    }
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: text/plain\r\n",
        'content' => json_encode($list)]]);
    if (@file_get_contents('http://mitglieder.piratenpartei.at/__stub/body', false, $context) !== "mode body\n") {
        fail_test('membership stub did not accept the member list');
    }
}

function lookup_page($path, $cookies, $method = 'GET', $form = [])
{
    $response = http_request($path, $method, $form, $cookies);
    assert_status($response, 200, "$method $path");
    assert_no_fatal_error($response['body'], "$method $path");
    return $response['body'];
}

// The current row of voucher $voucher.
function lookup_row($database, $voucher)
{
    $result = lookup_query($database, 'SELECT id, member, member_id, name, type, ack1, ack2, ack1_old FROM vouchers WHERE NOT deleted AND voucher_id = $1 ORDER BY id', [$voucher]);
    $rows = pg_fetch_all($result);
    pg_free_result($result);
    if (count($rows) !== 1) {
        fail_test("voucher $voucher: " . var_export($rows, true));
    }
    return $rows[0];
}

// The posted edit form of the one-row voucher $voucher (row $bid), as the
// page sends it, with $extra.
function lookup_form($voucher, $bid, $lo, $name, $comment, $extra)
{
    return $extra + ['id' => (string) $voucher, 'changed' => '1', 'parts' => '1', 'bid0' => (string) $bid,
        'dir0' => 'in', 'in_type0' => '8', 'out_type0' => '20', 'wk_type0' => '20', 'date0' => '2024-09-01',
        'person_type0' => '2', 'lo0' => (string) $lo, 'member0' => '1', 'mitgliedsnummer0' => '0',
        'gegenkonto0' => '', 'amount0' => '50.00', 'konto0' => 'TESTACCOUNT1', 'comment0' => $comment,
        'commentgf0' => '', 'vkonto0' => '', 'name0' => $name, 'street0' => '', 'plz0' => '', 'city0' => '',
        'file0' => '0'];
}

// Undone at the end, also after a failed check (fail_test() exits): the
// synthetic rows, the organization name, the hidden open rows of other tests
// and the stub mode.
$cleanup = ['lo' => null, 'others' => null];
register_shutdown_function(function () use ($database, &$cleanup) {
    if ($cleanup['lo'] !== null) {
        @pg_query_params($database, 'UPDATE lo SET name = $1 WHERE id = 2', [$cleanup['lo']]);
    }
    if ($cleanup['others'] !== null) {
        @pg_query_params($database, 'UPDATE vouchers SET member_id = 0 WHERE id = ANY($1::integer[])', [$cleanup['others']]);
    }
    @pg_query($database, 'DELETE FROM vouchers WHERE voucher_id BETWEEN 97001 AND 97004');
    @file_get_contents('http://mitglieder.piratenpartei.at/__stub/mode?set=ok');
    pg_close($database);
});

$members = [
    [7801, 'Mira Lookupova', 38, 1],
    [7802, 'Otto Lookupova', 40, 0],
    [7803, 'Eve <b>Tagged</b>', 38, 1],
    // Part of the booking text ("Beitrag"), but shorter than 5 characters.
    [7805, 'Beit', 38, 1],
];
for ($i = 1; $i <= 25; $i++) {
    $members[] = [7900 + $i, sprintf('Bulk Searchname %02d', $i), 38, 1];
}

lookup_query($database, 'DELETE FROM vouchers WHERE voucher_id BETWEEN 97001 AND 97004');
$insert = 'INSERT INTO vouchers (voucher_id, type, orga, member, member_id, name, amount, account, comment, committed,'
    . ' receipt_received, deleted, date, ack1, ack2, person_type, file, vaccount)'
    . ' VALUES ($1, 8, $2, true, 0, $3, 5000, \'TEST-ACCOUNT-1\', $4, false, $5, false, timestamp \'2024-09-01 00:00:00\', $6, NULL, 2, 0, \'\')';
// An imported donation of a member without a number: the text names member
// 7802, the name is member 7801's.
pg_free_result(lookup_query($database, $insert, [97001, 1, 'Mira Lookupova', 'Beitrag Mitgliedsnummer 7802 danke', 'false', 'reviewer-a']));
pg_free_result(lookup_query($database, $insert, [97002, 2, 'Synthetic Unknown Payer', 'Synthetic open member row', 'false', null]));
// Finalized: locked, no lookup, never the next open booking.
pg_free_result(lookup_query($database, $insert, [97003, 1, 'Mira Lookupova', 'Synthetic finalized member row', 'true', 'reviewer-a']));
// Two rows: one member list for both number fields.
pg_free_result(lookup_query($database, $insert, [97004, 1, 'Synthetic Payer A', 'Synthetic row A', 'false', null]));
pg_free_result(lookup_query($database, $insert, [97004, 1, 'Synthetic Payer B', 'Synthetic row B', 'false', null]));
$row1 = lookup_row($database, 97001);
$row2 = lookup_row($database, 97002);

$bgf = login_user('synthetic-reviewer', 'reviewer-password');
$unit = login_user('synthetic-unit-one', 'unit-password');
$unitTwo = login_user('synthetic-unit-two', 'unit-two-password');
$readOnly = login_user('synthetic-read-only', 'read-only-password');
$readOnlyTwo = login_user('synthetic-read-only-two', 'read-only-two-password');

lookup_stub_members($members);

// bgf: the member list on the number field (all members, escaped, with
// organization and "(nicht bezahlt)"), the suggestions from the booking's
// text (number 7802) and name (7801), the notes.
$page = lookup_page('/index.php?action=edit&id=97001', $bgf);
assert_contains($page, '<input type="text" name="mitgliedsnummer0" value="0" list="mitgliederliste" onchange="clicked();" />', 'bgf number field');
assert_contains($page, '<datalist id="mitgliederliste"><option value="7805">7805 – Beit (Synthetic National Unit)</option>', 'bgf member list');
assert_contains($page, '<option value="7802">7802 – Otto Lookupova (Synthetic Regional Unit) (nicht bezahlt)</option>', 'bgf member list unpaid');
assert_contains($page, '<option value="7803">7803 – Eve &lt;b&gt;Tagged&lt;/b&gt; (Synthetic National Unit)</option>', 'bgf member list escaped');
assert_contains($page, '<option value="7925">7925 – Bulk Searchname 25 (Synthetic National Unit)</option>', 'bgf member list complete');
assert_not_contains($page, '<b>Tagged', 'bgf member list escaped');
if (substr_count($page, '<option value="79') !== 25 || substr_count($page, '<datalist') !== 1) {
    fail_test('bgf member list: every member once, one list');
}
assert_contains($page, '<div class="field_note">Vorschläge: 7801 – Mira Lookupova (Synthetic National Unit), 7802 – Otto Lookupova (Synthetic Regional Unit) (nicht bezahlt)</div>' . "\n</div>", 'bgf suggestions');
// The notes behind one info marker, right after the number field.
$hint = 'Mit einer Nummer ersetzt das Speichern den Namen durch den Mitgliedsnamen; der Zahlername bleibt im Buchungstext.'
    . ' Die Art der Einnahme wird nicht automatisch geändert. Ist dies ein Mitgliedsbeitrag, bitte „Mitgliedsbeitrag“ selbst wählen.'
    . ' Laut Bundesfinanzordnung §2 legen Mitglieder ihren Beitrag zwischen 20 € und 1000 € pro Jahr fest; der Teil über 1000 € pro Jahr gilt als Spende.';
assert_contains($page, 'list="mitgliederliste" onchange="clicked();" /> <details class="hint"><summary title="' . $hint . '">ⓘ</summary><span>' . $hint
    . '</span></details><div class="field_note">Vorschläge: ', 'bgf notes');
assert_not_contains($page, 'Nächste offene Buchung', 'bgf edit page without a save');
assert_not_contains($page, 'name="mq0"', 'no search field');
assert_not_contains($page, 'mitgliedpick', 'no pick');

// Two rows: both number fields use the one member list.
$two = lookup_page('/index.php?action=edit&id=97004', $bgf);
if (substr_count($two, 'list="mitgliederliste"') !== 2 || substr_count($two, '<datalist') !== 1) {
    fail_test('two rows: two number fields, one member list');
}

// Redraws of posted values: no type hint for a membership fee or an
// outgoing row; invalid UTF-8 skips the suggestions (no database error).
$redraw = function ($extra) use ($bgf, $row1) {
    return lookup_page(
        '/index.php?action=edit',
        $bgf,
        'POST',
        lookup_form(97001, $row1['id'], 1, 'Mira Lookupova', 'Beitrag Mitgliedsnummer 7802 danke', $extra),
    );
};
$fee = $redraw(['in_type0' => '1']);
assert_contains($fee, 'Vorschläge: 7801', 'membership fee redraw');
assert_not_contains($fee, 'Bundesfinanzordnung', 'membership fee: no type hint');
$outgoing = $redraw(['dir0' => 'out']);
assert_contains($outgoing, 'Vorschläge: 7801', 'outgoing redraw');
assert_not_contains($outgoing, 'Bundesfinanzordnung', 'outgoing: no type hint');
$invalid = $redraw(['name0' => "Mira \xff Lookupova", 'comment0' => "Beitrag 7802 \xfe"]);
assert_not_contains($invalid, 'Vorschläge', 'invalid UTF-8: no suggestions');
assert_contains($invalid, 'list="mitgliederliste"', 'invalid UTF-8: member list');

// Everyone else: the form as before, without a member list request.
$requests = lookup_stub_requests();
foreach (['organization' => $unit, 'Rbgf' => $readOnly, 'Rbgf,2' => $readOnlyTwo] as $label => $cookies) {
    $other = lookup_page('/index.php?action=edit&id=97001', $cookies);
    assert_contains($other, '<input type="text" name="mitgliedsnummer0" value="0" onchange="clicked();" />', "$label form");
    foreach (['datalist', 'list="', 'Otto Lookupova', 'Vorschläge', 'Bundesfinanzordnung', 'Mitgliedsnamen', 'Mitgliederliste nicht erreichbar'] as $unexpected) {
        assert_not_contains($other, $unexpected, "$label form");
    }
}
if (lookup_stub_requests() !== $requests) {
    fail_test('users without the exact bgf right requested the member list');
}
// Another organization's row is not shown at all.
$foreign = lookup_page('/index.php?action=edit&id=97001', $unitTwo);
assert_contains($foreign, 'Buchung nicht gefunden', 'other organization');
assert_not_contains($foreign, 'Lookupova', 'other organization');
// A finalized voucher: no lookup.
$finalized = lookup_page('/index.php?action=edit&id=97003', $bgf);
assert_not_contains($finalized, 'datalist', 'finalized voucher');
assert_not_contains($finalized, 'list="', 'finalized voucher');
assert_not_contains($finalized, 'Vorschläge', 'finalized voucher');

// An escaped organization name.
$result = lookup_query($database, 'SELECT name FROM lo WHERE id = 2');
$cleanup['lo'] = pg_fetch_result($result, 0, 0);
pg_free_result($result);
pg_free_result(lookup_query($database, 'UPDATE lo SET name = $1 WHERE id = 2', ['Synthetic <i>Regional</i> Unit']));
$loEscaped = lookup_page('/index.php?action=edit&id=97001', $bgf);
pg_free_result(lookup_query($database, 'UPDATE lo SET name = $1 WHERE id = 2', [$cleanup['lo']]));
$cleanup['lo'] = null;
assert_contains($loEscaped, '7802 – Otto Lookupova (Synthetic &lt;i&gt;Regional&lt;/i&gt; Unit) (nicht bezahlt)', 'escaped organization');

// The suggestions are limited to 20.
pg_free_result(lookup_query($database, 'UPDATE vouchers SET comment = $1 WHERE NOT deleted AND voucher_id = 97002', ['Bulk Searchname 01 bis 25: '
    . implode(' ', range(7901, 7925))]));
$many = lookup_page('/index.php?action=edit&id=97002', $bgf);
assert_contains($many, '7920 – Bulk Searchname 20 (Synthetic National Unit), …</div>' . "\n</div>", 'suggestion limit');
if (substr_count($many, '7921 – Bulk Searchname 21') !== 1) {
    fail_test('suggestion limit: 7921 only in the member list');
}
pg_free_result(lookup_query($database, 'UPDATE vouchers SET comment = $1 WHERE NOT deleted AND voucher_id = 97002', ['Synthetic open member row']));

// A number from the member list: member and number set, the member's name,
// the approvals reset, no note, the next open booking (97002) linked after
// the save.
$picked = http_request('/index.php?action=edit', 'POST', lookup_form(
    97001,
    $row1['id'],
    1,
    'Synthetic Payer',
    'Beitrag Mitgliedsnummer 7802 danke',
    ['speichern' => 'Speichern', 'mitgliedsnummer0' => '7802'],
), $bgf);
assert_status($picked, 302, 'number from the list');
assert_location($picked, 'index.php?action=edit&id=97001&hint=5', 'number from the list');
$stored = lookup_row($database, 97001);
if ($stored['member'] !== 't' || $stored['member_id'] !== '7802' || $stored['name'] !== 'Otto Lookupova'
    || $stored['ack1'] !== null || $stored['ack2'] !== null || $stored['ack1_old'] !== 'reviewer-a' || $stored['type'] !== '8') {
    fail_test('number from the list: ' . var_export($stored, true));
}
$saved = lookup_page('/index.php?action=edit&id=97001&hint=5', $bgf);
assert_contains($saved, '<div class="slot_notice"><a href="index.php?action=edit&id=97002">Nächste offene Buchung</a></div>', 'next open booking');
assert_not_contains($saved, 'Vorschläge', 'member number set');
assert_not_contains($saved, 'Mitgliedsnamen', 'member number set');
assert_contains($saved, 'list="mitgliederliste"', 'member list after the save');
assert_contains($saved, '<details class="hint"><summary title="Die Art der Einnahme', 'type hint after the save');
// (Rbgf,2 may change 97002, which would be its next open booking.)
foreach (['organization' => $unit, 'Rbgf' => $readOnly, 'Rbgf,2' => $readOnlyTwo] as $label => $cookies) {
    assert_not_contains(lookup_page('/index.php?action=edit&id=97001&hint=5', $cookies), 'Nächste offene Buchung', "$label next open booking");
}
// The last open booking: no link (97003 is finalized). Open rows of other
// tests on the lane's database are hidden meanwhile.
$result = lookup_query($database, 'SELECT id FROM vouchers WHERE NOT deleted AND member AND member_id = 0 AND voucher_id NOT BETWEEN 97001 AND 97003');
$cleanup['others'] = '{' . implode(',', pg_fetch_all_columns($result, 0)) . '}';
pg_free_result($result);
pg_free_result(lookup_query($database, 'UPDATE vouchers SET member_id = NULL WHERE id = ANY($1::integer[])', [$cleanup['others']]));
pg_free_result(lookup_query($database, 'UPDATE vouchers SET member_id = 7801 WHERE NOT deleted AND voucher_id = 97002'));
assert_not_contains(lookup_page('/index.php?action=edit&id=97001&hint=5', $bgf), 'Nächste offene Buchung', 'no open booking left');
pg_free_result(lookup_query($database, 'UPDATE vouchers SET member_id = 0 WHERE id = ANY($1::integer[])', [$cleanup['others']]));
$cleanup['others'] = null;
pg_free_result(lookup_query($database, 'UPDATE vouchers SET member_id = 0 WHERE NOT deleted AND voucher_id = 97002'));

// A number that the member list does not have: saved as typed, with a note
// for bgf; an organization user's save stays as before.
$unknown = http_request('/index.php?action=edit', 'POST', lookup_form(
    97002,
    $row2['id'],
    2,
    'Synthetic Unknown Payer',
    'Synthetic open member row',
    ['speichern' => 'Speichern', 'mitgliedsnummer0' => '7999'],
), $bgf);
assert_status($unknown, 302, 'unknown number');
assert_location($unknown, 'index.php?action=edit&id=97002&hint=5&warn=member-unknown', 'unknown number');
$stored = lookup_row($database, 97002);
if ($stored['member'] !== 't' || $stored['member_id'] !== '7999' || $stored['name'] !== 'Synthetic Unknown Payer') {
    fail_test('unknown number: ' . var_export($stored, true));
}
assert_contains(
    lookup_page('/index.php?action=edit&id=97002&hint=5&warn=member-unknown', $bgf),
    'Gespeichert, aber: eine eingetragene Mitgliedsnummer ist nicht in der Mitgliederliste – bitte prüfen.',
    'unknown number note',
);
$stored1 = lookup_row($database, 97001);
$unitUnknown = http_request('/index.php?action=edit', 'POST', lookup_form(
    97001,
    $stored1['id'],
    1,
    'Synthetic Payer',
    'Beitrag',
    ['speichern' => 'Speichern', 'mitgliedsnummer0' => '7999'],
), $unit);
assert_status($unitUnknown, 302, 'organization user unknown number');
assert_location($unitUnknown, 'index.php?action=edit&id=97001&hint=5', 'organization user unknown number');
pg_free_result(lookup_query($database, 'UPDATE vouchers SET member_id = 0 WHERE NOT deleted AND voucher_id = 97002'));

// Member list unavailable: a note, the plain field, no member list; a
// member's save is refused as before.
lookup_stub_mode('error');
$unavailable = lookup_page('/index.php?action=edit&id=97002', $bgf);
assert_contains($unavailable, 'Mitgliederliste nicht erreichbar – Nummer manuell eintragen', 'member list unavailable');
assert_contains($unavailable, '<input type="text" name="mitgliedsnummer0" value="0" onchange="clicked();" />', 'member list unavailable');
assert_not_contains($unavailable, 'datalist', 'member list unavailable');
$refused = lookup_page('/index.php?action=edit', $bgf, 'POST', lookup_form(
    97002,
    lookup_row($database, 97002)['id'],
    2,
    'Synthetic Unknown Payer',
    'Synthetic open member row',
    ['speichern' => 'Speichern', 'mitgliedsnummer0' => '7801'],
));
assert_contains($refused, 'Die Mitgliederverwaltung ist nicht erreichbar. Bitte später erneut speichern.', 'save without member list');
if (lookup_row($database, 97002)['member_id'] !== '0') {
    fail_test('save without member list changed the row');
}
lookup_stub_mode('ok');

assert_no_unexpected_diagnostics();
echo "Member lookup HTTP tests passed\n";
