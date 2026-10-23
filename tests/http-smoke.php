<?php
require __DIR__ . '/http-test-helpers.php';

$report = http_request('/index.php?year=2024');
assert_status($report, 200, '2024 report');
assert_contains($report['body'], 'Rechenschaftsbericht der Piratenpartei Österreichs 2024', '2024 report');
assert_contains($report['body'], '160.00&nbsp;€', '2024 report income');
assert_contains($report['body'], '-65.00&nbsp;€', '2024 report expenses');
assert_no_fatal_error($report['body'], '2024 report');

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

assert_no_unexpected_diagnostics();
echo "PHP/PostgreSQL HTTP characterization tests passed\n";
