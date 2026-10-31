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
// The year selector to the right of the title (year_nav()), also at
// its ends (2012, the current year) and for the default year.
assert_contains($report['body'], "<div class=\"page_title\"><h1>Rechenschaftsbericht der Piratenpartei Österreichs 2024</h1>" . expected_year_nav(2024, 'index.php?year=') . '</div><br>', '2024 report year selector');
assert_contains(http_request('/index.php?year=2012')['body'], "<div class=\"page_title\"><h1>Rechenschaftsbericht der Piratenpartei Österreichs 2012</h1>" . expected_year_nav(2012, 'index.php?year=') . '</div><br>', '2012 report year selector');
$currentYear = intval(date('Y'));
assert_contains(http_request("/index.php?year=$currentYear")['body'], "<div class=\"page_title\"><h1>Rechenschaftsbericht der Piratenpartei Österreichs {$currentYear}</h1>" . expected_year_nav($currentYear, 'index.php?year=') . '</div><br>', 'current report year selector');
assert_contains(http_request('/index.php')['body'], "<div class=\"page_title\"><h1>Rechenschaftsbericht der Piratenpartei Österreichs {$currentYear}</h1>" . expected_year_nav($currentYear, 'index.php?year=') . '</div><br>', 'default report year selector');
// The menu links to the report and the donation list keep the chosen year
// (the current one for an invalid year); without a year, and on pages without
// one, they carry none.
$menu = fn($query, $selected) => '<a href="/' . $query . '"' . ($selected ? ' class="selected"' : '') . '>Rechenschaftsbericht</a><a href="donations' . $query . '">Spenden</a>' . "\n";
assert_contains($report['body'], $menu('?year=2024', true), '2024 report menu');
assert_contains(http_request('/index.php?year=2024-1')['body'], $menu("?year=$currentYear", true), 'invalid report year menu');
assert_contains(http_request('/index.php?action=unknown&year=2019')['body'], $menu('?year=2019', false), 'unknown action report menu');
assert_contains(http_request('/index.php')['body'], $menu('', true), 'default report menu');
assert_contains(http_request('/index.php?action=spendings&year=2019')['body'], $menu('', false), 'spendings menu');
// The ledger links of the logged-in menu keep the current year.
$adminReport = http_request('/index.php?year=2019', 'GET', [], login_user('synthetic-admin', 'correct-password'))['body'];
assert_contains($adminReport, $menu('?year=2019', true), 'logged-in 2019 report menu');
assert_contains($adminReport, "<a href=\"index.php?action=open&filter_date=$currentYear\">Offen</a>", 'logged-in 2019 report ledger link');
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
// style.css is built from css/app.css (scripts/build-css.sh); the td rule is
// indented inside its cascade layer.
$css = file_get_contents(__DIR__ . '/../style.css');
if (preg_match('/\n *td \{[^}]*text-align: right;[^}]*font-variant-numeric: tabular-nums;[^}]*\}/', $css) !== 1) {
    fail_test('style.css: the td rule has no right alignment with tabular digits');
}

// The library files only define functions: a direct request prints nothing
// (the web server denies src/; the test server ignores .htaccess).
foreach (['src/db.php', 'src/format.php'] as $path) {
    $library = http_request('/' . $path);
    if ($library['body'] !== '') {
        fail_test($path . ': a direct request printed ' . var_export($library['body'], true));
    }
}

// A failed statement ends the request with the pages' message, through each
// helper (test page tests/runtime/db-failure.php). PHP reports the failed
// pg_query() at src/db.php (displayed by the test server before the
// message); db_fail() logs the caller to the error log.
$missing = "ERROR:  relation \"db_helpers_missing\" does not exist\nLINE 1: ";
$failures = [
    'query' => 'Abfrage fehlgeschlagen: ' . $missing . 'SELECT * FROM db_helpers_missing',
    'query-sql' => 'Abfrage (SELECT * FROM db_helpers_missing) fehlgeschlagen: ' . $missing . 'SELECT * FROM db_helpers_missing',
    'exec' => 'Abfrage fehlgeschlagen: ' . $missing . 'UPDATE db_helpers_missing SET n = 1',
];
with_expected_diagnostics('db-helper-failure', [['src/db.php', 'pg_query(): Query failed']], function () use ($failures) {
    foreach ($failures as $helper => $expected) {
        $failure = http_request('/tests/runtime/db-failure.php?helper=' . $helper);
        assert_status($failure, 200, "db helper failure $helper");
        if (strpos($failure['body'], "</b><br />\n" . $expected) === false || strpos($failure['body'], 'not ended') !== false) {
            fail_test("db helper failure $helper: " . var_export($failure['body'], true));
        }
    }
});
$noRow = http_request('/tests/runtime/db-failure.php?helper=next-value-no-row');
assert_status($noRow, 200, 'db_next_value() without a row');
if ($noRow['body'] !== 'Abfrage fehlgeschlagen: ') {
    fail_test('db_next_value() without a row: ' . var_export($noRow['body'], true));
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
    [
        'submit' => 'Anmeldung',
        'name' => 'synthetic-admin',
        'pass' => 'wrong-password',
    ],
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
    [],
    $authCookies,
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

// check.php, the voucher number check, is a command-line script
// (tests/unit/CheckCommandTest.php); the web server must not run it.
$check = http_request('/check.php?kto=SYNTHETIC0&max=3');
assert_status($check, 404, 'check.php over HTTP');
if ($check['body'] !== '') {
    fail_test('check.php over HTTP answered ' . var_export($check['body'], true));
}

assert_no_unexpected_diagnostics();
echo "PHP/PostgreSQL HTTP characterization tests passed\n";
