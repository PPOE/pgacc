<?php

require __DIR__ . '/http-test-helpers.php';

$report = http_request('/index.php?year=2024');
assert_status($report, 200, '2024 report');
assert_contains($report['body'], 'Rechenschaftsbericht der Piratenpartei Österreichs 2024', '2024 report');
assert_contains($report['body'], '160.00&nbsp;€', '2024 report income');
assert_contains($report['body'], '-65.00&nbsp;€', '2024 report expenses');
assert_no_fatal_error($report['body'], '2024 report');
// Every per-unit section and the appendices must render; a failing query
// ends the page early with the "or die" message.
assert_not_contains($report['body'], 'Abfrage fehlgeschlagen', '2024 report completeness');
assert_contains($report['body'], 'Ausgaben für die Wahlwerbung Länder', '2024 report completeness');
assert_contains($report['body'], 'Anlage B: Einnahmen aus Sponsoring', '2024 report completeness');
// Issue #1: every amount has two decimals, also whole euros, so that the
// digits line up. Anlage A prints one row per public person type: the
// donation sum (no public donor in 2024: 0.00) and two fixed zero columns.
$anlageStart = strpos($report['body'], 'Anlage A');
$anlageEnd = strpos($report['body'], 'Anlage B');
if ($anlageStart === false || $anlageEnd === false || $anlageEnd < $anlageStart) {
    fail_test('2024 report Anlage A not found');
}
$anlage = substr($report['body'], $anlageStart, $anlageEnd - $anlageStart);
assert_not_contains($anlage, '<td>0&nbsp;€</td>', '2024 report Anlage A zero columns');
$rowPattern = '#<tr><td><b>[^<]*</b></td><td>-?\d+\.\d\d&nbsp;€</td><td>0\.00&nbsp;€</td><td>0\.00&nbsp;€</td></tr>#';
if (preg_match_all($rowPattern, $anlage, $rows) !== 4) {
    fail_test("2024 report Anlage A: expected 4 rows with two-decimal amounts\n" . $anlage);
}
// Amount cells (right-aligned td) use tabular digits. The static stylesheet
// is read from the source tree: the diagnostics recorder only wraps PHP.
$css = file_get_contents(__DIR__ . '/../style.css');
if (preg_match('/\ntd \{[^}]*text-align: right;[^}]*font-variant-numeric: tabular-nums;[^}]*\}/', $css) !== 1) {
    fail_test('style.css: the td rule has no right alignment with tabular digits');
}

$transactions = http_request('/index.php?action=transactions');
assert_status($transactions, 200, 'transactions');
assert_contains($transactions['body'], 'Kontobewegungen der Piratenpartei Österreichs', 'transactions');
assert_contains($transactions['body'], '120.00€', 'transactions');
assert_contains($transactions['body'], '-50.00€', 'transactions');
assert_not_contains($transactions['body'], '90.00€', 'transactions deleted row');
assert_no_fatal_error($transactions['body'], 'transactions');

$spendings = http_request('/index.php?action=spendings');
assert_status($spendings, 200, 'spendings');
assert_contains($spendings['body'], 'Synthetic Supplier', 'spendings');
assert_not_contains($spendings['body'], 'Synthetic Draft', 'spendings unreviewed row');
assert_not_contains($spendings['body'], 'Deleted donation', 'spendings deleted row');
assert_no_fatal_error($spendings['body'], 'spendings');

$failedLogin = http_request(
    '/index.php?action=login',
    'POST',
    array(
        'submit' => 'Anmeldung',
        'name' => 'synthetic-admin',
        'pass' => 'wrong-password',
    )
);
assert_status($failedLogin, 200, 'failed login');
assert_contains($failedLogin['body'], 'Login erforderlich', 'failed login');
if (count(response_cookies($failedLogin)) !== 0) {
    fail_test('failed login issued authentication cookies');
}

$authCookies = login_user('synthetic-admin', 'correct-password');
$ledger = http_request(
    '/index.php?action=all&filter_date=2024',
    'GET',
    array(),
    $authCookies
);
assert_status($ledger, 200, 'authenticated ledger');
assert_contains($ledger['body'], 'Synthetic Donor', 'authenticated ledger');
assert_contains($ledger['body'], 'Synthetic Draft', 'authenticated ledger');
assert_not_contains($ledger['body'], 'Deleted donation', 'authenticated ledger');
assert_no_fatal_error($ledger['body'], 'authenticated ledger');

// Reports of years whose opening or closing balance date has no balance row
// fall back to the latest balance (2024-12-31 in the fixtures).
$report2023 = http_request('/index.php?year=2023');
assert_status($report2023, 200, '2023 report');
assert_no_fatal_error($report2023['body'], '2023 report');
assert_not_contains($report2023['body'], 'Abfrage fehlgeschlagen', '2023 report');
assert_contains($report2023['body'], '<td width="150px">Kontostand 31.12.2024</td>', '2023 report opening balance fallback');
$report2025 = http_request('/index.php?year=2025');
assert_status($report2025, 200, '2025 report');
assert_no_fatal_error($report2025['body'], '2025 report');
assert_not_contains($report2025['body'], 'Abfrage fehlgeschlagen', '2025 report');
assert_contains($report2025['body'], '<td width="150px">Kontostand 31.12.2024</td>', '2025 report opening balance');

// check.php: the ad-hoc voucher-number check (unlinked; export-ignored and
// denied by production's web server, but served by the test server).
// "SYNTHETIC0" has no vouchers, so numbers 2 and 3 are reported missing.
$check = http_request('/check.php?kto=SYNTHETIC0&max=3');
assert_status($check, 200, 'check.php');
assert_no_fatal_error($check['body'], 'check.php');
assert_contains($check['body'], 'Habe 0 von 1 Buchungen gefunden.<br /><br />', 'check.php');
assert_contains($check['body'], 'Habe insgesamt 0  Buchungen gefunden.<br /><br />', 'check.php');
assert_contains($check['body'], 'Habe 0  Buchungen gefunden die ich nicht zuordnen konnte.<br /><br />', 'check.php');
assert_contains($check['body'], "Buchung 2 FEHLT (0 results)<br />\nBuchung 3 FEHLT (0 results)<br />\n", 'check.php');

assert_no_unexpected_diagnostics();
echo "PHP/PostgreSQL HTTP characterization tests passed\n";
