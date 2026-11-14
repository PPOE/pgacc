<?php

/*
 * Member lookup on the edit page (new.php member_lookup_html(), getusers.php
 * member_search()/member_suggestions(), edit.php edit_next_open_link()) with
 * a synthetic member list served by the membership stub of compose.test.yaml
 * (POST /__stub/body). Only users with the exact bgf right get the search,
 * the suggestions, the hints and the "Nächste offene Buchung" link; other
 * users (an organization, Rbgf) see the form as before. A picked member is
 * saved only if the member list has it; the save resets the approvals.
 * The rows are synthetic (voucher numbers 97001-97003) and removed again.
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

$members = [
    [7801, 'Mira Lookupova', 38, 1],
    [7802, 'Otto Lookupova', 40, 0],
    [7803, 'Eve <b>Tagged</b>', 38, 1],
    [7804, 'Zed Bang!Bang', 40, 1],
    // Part of the booking text ("Beitrag"), but shorter than 5 characters.
    [7805, 'Beit', 38, 1],
];
for ($i = 1; $i <= 25; $i++) {
    $members[] = [7900 + $i, sprintf('Bulk Searchname %02d', $i), 38, 1];
}

lookup_query($database, 'DELETE FROM vouchers WHERE voucher_id BETWEEN 97001 AND 97003');
$insert = 'INSERT INTO vouchers (voucher_id, type, orga, member, member_id, name, amount, account, comment, committed,'
    . ' receipt_received, deleted, date, ack1, ack2, person_type, file, vaccount)'
    . ' VALUES ($1, 8, $2, true, 0, $3, 5000, \'TEST-ACCOUNT-1\', $4, false, $5, false, timestamp \'2024-09-01 00:00:00\', $6, NULL, 2, 0, \'\')';
// An imported donation of a member without a number: the text names member
// 7802, the name is member 7801's.
pg_free_result(lookup_query($database, $insert, [97001, 1, 'Mira Lookupova', 'Beitrag Mitgliedsnummer 7802 danke', 'false', 'reviewer-a']));
pg_free_result(lookup_query($database, $insert, [97002, 2, 'Synthetic Unknown Payer', 'Synthetic open member row', 'false', null]));
// Finalized: locked, no lookup, never the next open booking.
pg_free_result(lookup_query($database, $insert, [97003, 1, 'Mira Lookupova', 'Synthetic finalized member row', 'true', 'reviewer-a']));
$row1 = lookup_row($database, 97001);
$row2 = lookup_row($database, 97002);

$bgf = login_user('synthetic-reviewer', 'reviewer-password');
$unit = login_user('synthetic-unit-one', 'unit-password');
$unitTwo = login_user('synthetic-unit-two', 'unit-two-password');
$readOnly = login_user('synthetic-read-only', 'read-only-password');
$readOnlyTwo = login_user('synthetic-read-only-two', 'read-only-two-password');

lookup_stub_members($members);

// bgf: the search, the suggestions from the booking's text (number 7802)
// and name (7801), with organization and "(nicht bezahlt)", the hints.
$page = lookup_page('/index.php?action=edit&id=97001', $bgf);
assert_contains($page, '<input type="text" id="mq0" name="mq0" value=""', 'bgf search field');
assert_contains($page, 'onkeydown="if (event.key == \'Enter\') { event.preventDefault(); this.form.submit(); }"', 'bgf search field Enter');
assert_contains($page, '<input type="button" value="Mitglied suchen" onclick="document.mainform.submit();" />', 'bgf search button');
assert_contains($page, '<select id="mitgliedpick0" name="mitgliedpick0" onchange="clicked();"><option value="">– keine Auswahl –</option>'
    . '<option value="7801">7801 – Mira Lookupova (Synthetic National Unit)</option>'
    . '<option value="7802">7802 – Otto Lookupova (Synthetic Regional Unit) (nicht bezahlt)</option></select>', 'bgf suggestions');
assert_contains($page, 'Vorschläge', 'bgf suggestions label');
assert_contains($page, 'Name wird durch den Mitgliedsnamen ersetzt; der Zahlername bleibt im Buchungstext', 'bgf name note');
assert_contains($page, 'Bundesfinanzordnung §2', 'bgf type hint');
assert_not_contains($page, 'Nächste offene Buchung', 'bgf edit page without a save');

// Everyone else: the form as before.
foreach (['organization' => $unit, 'Rbgf' => $readOnly, 'Rbgf,2' => $readOnlyTwo] as $label => $cookies) {
    $other = lookup_page('/index.php?action=edit&id=97001', $cookies);
    assert_contains($other, 'name="mitgliedsnummer0"', "$label form");
    foreach (['name="mq0"', 'mitgliedpick', 'Otto Lookupova', 'Bundesfinanzordnung', 'Mitgliedsnamen', 'Mitgliederliste nicht erreichbar'] as $unexpected) {
        assert_not_contains($other, $unexpected, "$label form");
    }
}
// Another organization's row is not shown at all.
$foreign = lookup_page('/index.php?action=edit&id=97001', $unitTwo);
assert_contains($foreign, 'Buchung nicht gefunden', 'other organization');
assert_not_contains($foreign, 'Lookupova', 'other organization');
// A finalized voucher: no lookup.
$finalized = lookup_page('/index.php?action=edit&id=97003', $bgf);
assert_not_contains($finalized, 'name="mq0"', 'finalized voucher');
assert_not_contains($finalized, 'mitgliedpick', 'finalized voucher');

// Search (POST of the form, no save): words in any order, escaped names,
// LIKE wildcards as text, the hit limit, numbers.
$search = function ($query) use ($bgf, $row1) {
    return lookup_page(
        '/index.php?action=edit',
        $bgf,
        'POST',
        lookup_form(97001, $row1['id'], 1, 'Mira Lookupova', 'Beitrag Mitgliedsnummer 7802 danke', ['mq0' => $query]),
    );
};
$hits = $search('lookupova otto');
assert_contains($hits, '<input type="text" id="mq0" name="mq0" value="lookupova otto"', 'search term kept');
assert_contains($hits, 'Treffer</label><select id="mitgliedpick0" name="mitgliedpick0" onchange="clicked();"><option value="">– keine Auswahl –</option>'
    . '<option value="7802">7802 – Otto Lookupova (Synthetic Regional Unit) (nicht bezahlt)</option></select>', 'search hits');
$escaped = $search('<b>tag');
assert_contains($escaped, 'value="&lt;b&gt;tag"', 'search term escaped');
assert_contains($escaped, '<option value="7803">7803 – Eve &lt;b&gt;Tagged&lt;/b&gt; (Synthetic National Unit)</option>', 'escaped member name');
assert_not_contains($escaped, '<b>Tagged', 'escaped member name');
$bang = $search('g!b');
assert_contains($bang, '<option value="7804">7804 – Zed Bang!Bang (Synthetic Regional Unit)</option></select>', 'search with "!"');
$result = lookup_query($database, 'SELECT name FROM lo WHERE id = 2');
$loName = pg_fetch_result($result, 0, 0);
pg_free_result($result);
pg_free_result(lookup_query($database, 'UPDATE lo SET name = $1 WHERE id = 2', ['Synthetic <i>Regional</i> Unit']));
$loEscaped = $search('otto');
pg_free_result(lookup_query($database, 'UPDATE lo SET name = $1 WHERE id = 2', [$loName]));
assert_contains($loEscaped, '7802 – Otto Lookupova (Synthetic &lt;i&gt;Regional&lt;/i&gt; Unit) (nicht bezahlt)', 'escaped organization');
foreach (['kup%va', 'Bulk_Searchname', 'Mira!'] as $wildcard) {
    $none = $search($wildcard);
    assert_contains($none, 'Keine Treffer.', "search $wildcard");
    assert_not_contains($none, 'mitgliedpick', "search $wildcard");
}
$many = $search('bulk searchname');
assert_contains($many, '<option value="7920">7920 – Bulk Searchname 20 (Synthetic National Unit)</option></select> weitere Treffer – Suche verfeinern', 'hit limit');
assert_not_contains($many, '7921', 'hit limit');
$number = $search(' 7801 ');
assert_contains($number, '<option value="7801">7801 – Mira Lookupova (Synthetic National Unit)</option></select>', 'number search');
assert_not_contains($number, 'weitere Treffer', 'number search');
$long = $search('1234567890');
assert_contains($long, 'Mitgliedsnummer zu lang (höchstens 9 Ziffern).', 'number with 10 digits');
assert_not_contains($long, 'mitgliedpick', 'number with 10 digits');
$short = $search('ab');
assert_contains($short, 'Suchbegriff zu kurz (mindestens 3 Zeichen).', 'short search');
// Non-bgf users posting a search see nothing new.
$unitSearch = lookup_page(
    '/index.php?action=edit',
    $unit,
    'POST',
    lookup_form(97001, $row1['id'], 1, 'Mira Lookupova', 'Beitrag', ['mq0' => 'lookupova']),
);
assert_not_contains($unitSearch, 'Otto Lookupova', 'organization user search');
assert_not_contains($unitSearch, 'name="mq0"', 'organization user search');

// A pick of a member not in the list, or of another user than bgf, is ignored.
$ignored = http_request('/index.php?action=edit', 'POST', lookup_form(
    97002,
    $row2['id'],
    2,
    'Synthetic Unknown Payer',
    'Synthetic open member row',
    ['speichern' => 'Speichern', 'mitgliedpick0' => '7999'],
), $bgf);
assert_status($ignored, 302, 'pick not in the list');
$stored = lookup_row($database, 97002);
if ($stored['member'] !== 't' || $stored['member_id'] !== '0' || $stored['name'] !== 'Synthetic Unknown Payer') {
    fail_test('pick not in the list: ' . var_export($stored, true));
}
$row2 = $stored;
// (A payer name that matches no member: the save's own name match stays out.)
$unitPick = http_request('/index.php?action=edit', 'POST', lookup_form(
    97001,
    $row1['id'],
    1,
    'Synthetic Payer',
    'Beitrag Mitgliedsnummer 7802 danke',
    ['speichern' => 'Speichern', 'mitgliedpick0' => '7802'],
), $unit);
assert_status($unitPick, 302, 'organization user pick');
$stored = lookup_row($database, 97001);
if ($stored['member_id'] !== '0') {
    fail_test('organization user pick: ' . var_export($stored, true));
}
$row1 = $stored;
pg_free_result(lookup_query($database, "UPDATE vouchers SET ack1 = 'reviewer-a' WHERE NOT deleted AND voucher_id = 97001"));

// A pick from the list: member and number set, the member's name, the
// approvals reset, the next open booking (97002) linked after the save.
$picked = http_request('/index.php?action=edit', 'POST', lookup_form(
    97001,
    $row1['id'],
    1,
    'Synthetic Payer',
    'Beitrag Mitgliedsnummer 7802 danke',
    ['speichern' => 'Speichern', 'mitgliedpick0' => '7802', 'member0' => null],
), $bgf);
assert_status($picked, 302, 'pick');
assert_location($picked, 'index.php?action=edit&id=97001&hint=5', 'pick');
$stored = lookup_row($database, 97001);
if ($stored['member'] !== 't' || $stored['member_id'] !== '7802' || $stored['name'] !== 'Otto Lookupova'
    || $stored['ack1'] !== null || $stored['ack2'] !== null || $stored['ack1_old'] !== 'reviewer-a' || $stored['type'] !== '8') {
    fail_test('pick: ' . var_export($stored, true));
}
$saved = lookup_page('/index.php?action=edit&id=97001&hint=5', $bgf);
assert_contains($saved, '<div class="slot_notice"><a href="index.php?action=edit&id=97002">Nächste offene Buchung</a></div>', 'next open booking');
assert_not_contains($saved, 'name="mq0"', 'member number set');
assert_contains($saved, 'Bundesfinanzordnung §2', 'type hint after the pick');
// (Rbgf,2 may change 97002, which would be its next open booking.)
foreach (['organization' => $unit, 'Rbgf' => $readOnly, 'Rbgf,2' => $readOnlyTwo] as $label => $cookies) {
    assert_not_contains(lookup_page('/index.php?action=edit&id=97001&hint=5', $cookies), 'Nächste offene Buchung', "$label next open booking");
}
// The last open booking: no link (97003 is finalized). Open rows of other
// tests on the lane's database are hidden meanwhile.
$result = lookup_query($database, 'SELECT id FROM vouchers WHERE NOT deleted AND member AND member_id = 0 AND voucher_id NOT BETWEEN 97001 AND 97003');
$others = '{' . implode(',', pg_fetch_all_columns($result, 0)) . '}';
pg_free_result($result);
pg_free_result(lookup_query($database, 'UPDATE vouchers SET member_id = NULL WHERE id = ANY($1::integer[])', [$others]));
pg_free_result(lookup_query($database, 'UPDATE vouchers SET member_id = 7801 WHERE NOT deleted AND voucher_id = 97002'));
assert_not_contains(lookup_page('/index.php?action=edit&id=97001&hint=5', $bgf), 'Nächste offene Buchung', 'no open booking left');
pg_free_result(lookup_query($database, 'UPDATE vouchers SET member_id = 0 WHERE id = ANY($1::integer[])', [$others]));
pg_free_result(lookup_query($database, 'UPDATE vouchers SET member_id = 0 WHERE NOT deleted AND voucher_id = 97002'));

// Member list unavailable: a note, the plain field, no search hits.
lookup_stub_mode('error');
$unavailable = lookup_page('/index.php?action=edit&id=97002', $bgf);
assert_contains($unavailable, 'Mitgliederliste nicht erreichbar – Nummer manuell eintragen', 'member list unavailable');
assert_contains($unavailable, 'name="mitgliedsnummer0" value="0"', 'member list unavailable');
assert_not_contains($unavailable, 'mitgliedpick', 'member list unavailable');
$unavailableSearch = lookup_page(
    '/index.php?action=edit',
    $bgf,
    'POST',
    lookup_form(97002, $row2['id'], 2, 'Synthetic Unknown Payer', 'Synthetic open member row', ['mq0' => 'lookupova']),
);
assert_contains($unavailableSearch, 'Mitgliederliste nicht erreichbar – Nummer manuell eintragen', 'search without member list');
assert_not_contains($unavailableSearch, 'mitgliedpick', 'search without member list');
lookup_stub_mode('ok');

lookup_query($database, 'DELETE FROM vouchers WHERE voucher_id BETWEEN 97001 AND 97003');
pg_close($database);

assert_no_unexpected_diagnostics();
echo "Member lookup HTTP tests passed\n";
