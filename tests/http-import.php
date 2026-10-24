<?php
/*
 * Bank CSV import (import.php, ?action=import) with the synthetic CSV files of
 * tests/reference/fixtures/import/ and the membership stub of
 * compose.test.yaml (getusers()). Covers the checks of every imported line:
 * dates must be dates, and the organization must be one the user may use
 * (organization_allowed(): any for bgf and root, otherwise the user's numeric
 * organizations). Offending lines get the existing "Ungültige Buchungszeile"
 * report with their line number and are skipped; the remaining lines and
 * files are imported. Runs after
 * write-workflows.php on the same database. Must stay compatible with PHP 5.6.
 */
require __DIR__ . '/http-test-helpers.php';

$database = @pg_connect(pgacc_test_connection_string());
if ($database === false) {
    fail_test('could not connect to the fixture database');
}

function import_count($database, $comment)
{
    $result = @pg_query_params($database, 'SELECT COUNT(*) AS count FROM vouchers WHERE comment LIKE $1', array($comment));
    if ($result === false) {
        fail_test('count query failed: ' . pg_last_error($database));
    }
    $count = intval(pg_fetch_result($result, 0, 0));
    pg_free_result($result);
    return $count;
}

function import_orga($database, $comment)
{
    $result = @pg_query_params($database, 'SELECT orga, date FROM vouchers WHERE comment LIKE $1', array($comment));
    if ($result === false || pg_num_rows($result) !== 1) {
        fail_test("expected one imported voucher for $comment");
    }
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    return $row;
}

// Every imported line runs preg_match() with the invalid modifier /g
// (import.php, an open finding in tests/COMPATIBILITY_PLAN.md): its warning
// is the known failure KF-IMPORT-REGEX-G, checked at the end.
function import_csv($name, $content, $cookies)
{
    return with_known_failure('KF-IMPORT-REGEX-G', function () use ($name, $content, $cookies) {
        return import_request($name, $content, $cookies);
    });
}

function import_request($name, $content, $cookies)
{
    return http_request(
        '/index.php?action=import',
        'POST',
        array('MAX_FILE_SIZE' => '1000000'),
        $cookies,
        null,
        array('uploadcsv[]' => array('name' => $name, 'type' => 'text/csv', 'content' => $content))
    );
}

function assert_import_refused($response, $label)
{
    assert_status($response, 200, $label);
    assert_contains($response['body'], 'Ungültige Buchungszeile in Datei 1', $label);
    assert_contains($response['body'], 'übersprungen', $label);
    assert_not_contains($response['body'], 'fehlgeschlagen', $label);
}

$handkassaHeader = "Datum;Betrag;Konto;Text;Kommentar;Fremdkonto;Zweckwidmung;Kostenrückerstattung;Name;Mitgliedsnummer;Lo;Adresse;PLZ;Stadt\r\n";
function handkassa_line($date, $text, $lo)
{
    return "$date;5,00;Handkassa;$text;;;Nein;Nein;Synthetic Importer;0;$lo;;;\r\n";
}

$adminCookies = login_user('synthetic-admin', 'correct-password');
$unitOneCookies = login_user('synthetic-unit-one', 'unit-password');
$readOnlyTwoCookies = login_user('synthetic-read-only-two', 'read-only-two-password');

// The fixtures import as before for an admin.
$fixture = file_get_contents(__DIR__ . '/reference/fixtures/import/handkassa.csv');
$imported = import_csv('handkassa.csv', $fixture, $adminCookies);
assert_status($imported, 200, 'handkassa fixture');
assert_contains($imported['body'], 'wurde erfolgreich importiert', 'handkassa fixture');
$kaffee = import_orga($database, "Kaffee\nMilch");
if ($kaffee['orga'] !== '2' || $kaffee['date'] !== '2024-02-15 00:00:00') {
    fail_test('handkassa fixture row: ' . var_export($kaffee, true));
}
$paypal = file_get_contents(__DIR__ . '/reference/fixtures/import/paypal.csv');
$importedPaypal = import_csv('paypal.csv', $paypal, $adminCookies);
assert_contains($importedPaypal['body'], 'wurde erfolgreich importiert', 'paypal fixture');

// An unknown account is reported the same way (unchanged).
$unknown = file_get_contents(__DIR__ . '/reference/fixtures/import/bawag-unknown-account.csv');
$unknownImport = import_csv('bawag-unknown-account.csv', $unknown, $adminCookies);
assert_contains($unknownImport['body'], 'Ungültige Buchungszeile in Datei 1 (bawag-unknown-account.csv) <br />', 'BAWAG unknown account');
assert_not_contains($unknownImport['body'], 'wurde erfolgreich importiert', 'BAWAG unknown account');

// Dates must be dates (format_date() passes other text through).
$badDates = array("15.02.2024' x", 'x 2024-02-15', '2024-02-15 x', 'Montag', '2024-13-45', '9999-99-99', '31.02.2024', '2023-02-29');
foreach ($badDates as $index => $date) {
    $comment = "Synthetic import with a bad date $index";
    $response = import_csv('bad-date.csv', $handkassaHeader . handkassa_line($date, $comment, '2'), $adminCookies);
    assert_import_refused($response, "handkassa date " . var_export($date, true));
    if (import_count($database, $comment) !== 0) {
        fail_test("handkassa line with the date " . var_export($date, true) . ' was imported');
    }
}
$paypalLines = explode("\n", $paypal);
$paypalBad = $paypalLines[0] . "\n" . str_replace('15.03.2024', "15.03.2024'", $paypalLines[1]) . "\n";
assert_import_refused(import_csv('paypal-bad.csv', $paypalBad, $adminCookies), 'paypal date with a quote');
$validDate = import_csv('good-date.csv', $handkassaHeader . handkassa_line('2024-3-1', 'Synthetic import with an ISO date', '2'), $adminCookies);
assert_contains($validDate['body'], 'wurde erfolgreich importiert', 'handkassa ISO date');
$isoRow = import_orga($database, 'Synthetic import with an ISO date');
if ($isoRow['date'] !== '2024-03-01 00:00:00') {
    fail_test('handkassa ISO date row: ' . var_export($isoRow, true));
}

// An offending line is skipped and reported with its line number; the other
// lines and files are imported, and the file ends with a summary.
$mixed = $handkassaHeader
    . handkassa_line('02.04.2024', 'Synthetic import before a skipped line', '1')
    . handkassa_line('02.04.2024', 'Synthetic import of a foreign line', '2')
    . handkassa_line('2024-02-30', 'Synthetic import with an impossible date', '1')
    . handkassa_line('03.04.2024', 'Synthetic import after skipped lines', '1');
$second = $handkassaHeader . handkassa_line('04.04.2024', 'Synthetic import from a second file', '1');
$mixedImport = with_known_failure('KF-IMPORT-REGEX-G', function () use ($mixed, $second, $unitOneCookies) {
    return http_request(
        '/index.php?action=import',
        'POST',
        array('MAX_FILE_SIZE' => '1000000'),
        $unitOneCookies,
        null,
        array(
            'uploadcsv[0]' => array('name' => 'mixed.csv', 'type' => 'text/csv', 'content' => $mixed),
            'uploadcsv[1]' => array('name' => 'second.csv', 'type' => 'text/csv', 'content' => $second),
        )
    );
});
assert_contains($mixedImport['body'], 'Ungültige Buchungszeile in Datei 1 (mixed.csv), Zeile 3', 'mixed import');
assert_contains($mixedImport['body'], 'Ungültige Buchungszeile in Datei 1 (mixed.csv), Zeile 4', 'mixed import');
assert_contains($mixedImport['body'], 'Datei 1 (mixed.csv): 2 Buchungszeilen übersprungen (Zeilen 3, 4)', 'mixed import');
assert_contains($mixedImport['body'], 'Datei 2 (second.csv) wurde erfolgreich importiert', 'mixed import');
foreach (array('before a skipped line' => 1, 'of a foreign line' => 0, 'with an impossible date' => 0, 'after skipped lines' => 1, 'from a second file' => 1) as $label => $expected) {
    if (import_count($database, "Synthetic import $label") !== $expected) {
        fail_test("mixed import: line $label imported " . import_count($database, "Synthetic import $label") . " times, expected $expected");
    }
}

// A line without a name that contains exactly one member's name (the stub's
// "Synthetica"; the line is compared without spaces) gets that member; the following lines are imported too
// (before, the member match ended the whole file and printed success).
$memberLines = $handkassaHeader
    . "05.04.2024;6,00;Handkassa;Synthetic member line Synthetica;;;Nein;Nein;;0;1;;;\r\n"
    . handkassa_line('06.04.2024', 'Synthetic line after the member line', '1');
$memberImport = import_csv('member.csv', $memberLines, $adminCookies);
assert_contains($memberImport['body'], 'wurde erfolgreich importiert', 'import with a member line');
$memberRow = import_orga($database, 'Synthetic member line Synthetica');
if (import_count($database, 'Synthetic line after the member line') !== 1) {
    fail_test('the line after the member line was not imported');
}
$memberName = @pg_query_params($database, 'SELECT name, member_id FROM vouchers WHERE comment = $1', array('Synthetic member line Synthetica'));
$memberFields = pg_fetch_assoc($memberName);
if ($memberFields['name'] !== 'Synthetica' || $memberFields['member_id'] !== '9035') {
    fail_test('member line: ' . var_export($memberFields, true));
}

// The organization of every line must be one the user may use.
$orgCases = array(
    'own organization' => array($unitOneCookies, '1', true),
    'foreign organization' => array($unitOneCookies, '2', false),
    'default organization 10' => array($unitOneCookies, '0', false),
    'organization of Rbgf,2' => array($readOnlyTwoCookies, '2', true),
    'foreign organization of Rbgf,2' => array($readOnlyTwoCookies, '1', false),
    'admin, any organization' => array($adminCookies, '7', true),
);
foreach ($orgCases as $label => $case) {
    $comment = "Synthetic import, $label";
    $response = import_csv('org.csv', $handkassaHeader . handkassa_line('01.04.2024', $comment, $case[1]), $case[0]);
    if ($case[2]) {
        assert_contains($response['body'], 'wurde erfolgreich importiert', "import, $label");
        if (import_count($database, $comment) !== 1) {
            fail_test("import, $label: line not imported");
        }
    } else {
        assert_import_refused($response, "import, $label");
        if (import_count($database, $comment) !== 0) {
            fail_test("import, $label: line imported");
        }
    }
}
$bawag = "AT606000050110117300;Synthetic BAWAG line of organization 2|BAWAATWW AT611904300234573201 Synthetic Spender;05.04.2024;05.04.2024;+5,00;EUR\n";
assert_import_refused(import_csv('bawag.csv', $bawag, $unitOneCookies), 'BAWAG line of organization 2 by organization 1');
if (import_count($database, 'Synthetic BAWAG line of organization 2%') !== 0) {
    fail_test('BAWAG line of organization 2 imported by organization 1');
}

$regexWarnings = 0;
foreach ($GLOBALS['pgacc_test_diagnostics'] as $record) {
    if ($record['known_failure'] === 'KF-IMPORT-REGEX-G') {
        if ($record['file'] !== 'import.php' || strpos($record['message'], "Unknown modifier 'g'") === false) {
            fail_test('unexpected diagnostic during the import: ' . $record['file'] . ':' . $record['line'] . ' ' . $record['message']);
        }
        $regexWarnings++;
    }
}
if ($regexWarnings === 0) {
    fail_test('KF-IMPORT-REGEX-G no longer occurs: turn it into a regular check');
}

pg_close($database);
assert_no_unexpected_diagnostics();
echo "Bank import tests passed\n";
