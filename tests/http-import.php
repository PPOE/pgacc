<?php

/*
 * Bank CSV import (import.php, ?action=import) with the synthetic CSV files of
 * tests/reference/fixtures/import/ and the membership stub of
 * compose.test.yaml (getusers()). Covers the checks of every imported line:
 * dates must be dates, and the organization must be one the user may use
 * (organization_allowed(): any for bgf, otherwise the user's numeric
 * organizations). Offending lines get the existing "Ungültige Buchungszeile"
 * report with their line number and are skipped; the remaining lines and
 * files are imported. Runs after
 * write-workflows.php on the same database.
 */
require __DIR__ . '/http-test-helpers.php';

$database = @pg_connect(pgacc_test_connection_string());
if ($database === false) {
    fail_test('could not connect to the fixture database');
}

function import_count($database, $comment)
{
    $result = @pg_query_params($database, 'SELECT COUNT(*) AS count FROM vouchers WHERE comment LIKE $1', [$comment]);
    if ($result === false) {
        fail_test('count query failed: ' . pg_last_error($database));
    }
    $count = intval(pg_fetch_result($result, 0, 0));
    pg_free_result($result);
    return $count;
}

function import_orga($database, $comment)
{
    $result = @pg_query_params($database, 'SELECT orga, date FROM vouchers WHERE comment LIKE $1', [$comment]);
    if ($result === false || pg_num_rows($result) !== 1) {
        fail_test("expected one imported voucher for $comment");
    }
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    return $row;
}

function import_csv($name, $content, $cookies)
{
    return import_request($name, $content, $cookies);
}

function import_request($name, $content, $cookies)
{
    return http_request(
        '/index.php?action=import',
        'POST',
        ['MAX_FILE_SIZE' => '1000000'],
        $cookies,
        null,
        ['uploadcsv[]' => ['name' => $name, 'type' => 'text/csv', 'content' => $content]],
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
// The import form on the result page carries the form token of the session.
assert_contains($imported['body'], 'method="POST">' . "\n" . '<input type="hidden" name="csrf_token" value="'
    . session_csrf_token($adminCookies) . '" />', 'import form');
assert_status($imported, 200, 'handkassa fixture');
assert_contains($imported['body'], 'wurde erfolgreich importiert', 'handkassa fixture');
$kaffee = import_orga($database, "Kaffee\nMilch");
if ($kaffee['orga'] !== '2' || $kaffee['date'] !== '2024-02-15 00:00:00') {
    fail_test('handkassa fixture row: ' . var_export($kaffee, true));
}
$paypal = file_get_contents(__DIR__ . '/reference/fixtures/import/paypal.csv');
$importedPaypal = import_csv('paypal.csv', $paypal, $adminCookies);
assert_contains($importedPaypal['body'], 'wurde erfolgreich importiert', 'paypal fixture');
// Sparkasse statements (lo 6, account 20815 6659403): the comment joins
// Umsatzzeile1, Zusatztext and Auftraggeber.
$sparkasse = "Bezeichnung;Valutadatum;Betrag;Währung;Buchungsdatum;Umsatzzeile1;Zusatztext;Auftraggeber;"
    . "Kundendaten/Zahlungsreferenz;Umsatzzeile 2;Ersterfassungsreferenz;Auftraggeber Kontonr./IBAN;"
    . "Auftraggeber BLZ/BIC;Partnername;Partner Kontonr./IBAN;Partner BLZ/BIC\r\n"
    . "Synthetic;15.05.2024;12,34;EUR;15.05.2024;Synthetic Sparkasse line;Zusatz;Auftraggeber;;;;;;"
    . "Synthetic Partner;AT00 0000;SYNTATWW\r\n";
$importedSparkasse = import_csv('sparkasse.csv', $sparkasse, $adminCookies);
assert_contains($importedSparkasse['body'], 'wurde erfolgreich importiert', 'sparkasse import');
$sparkasseRow = import_orga($database, 'Synthetic Sparkasse line; Zusatz; Auftraggeber');
if ($sparkasseRow['orga'] !== '6' || $sparkasseRow['date'] !== '2024-05-15 00:00:00') {
    fail_test('sparkasse row: ' . var_export($sparkasseRow, true));
}

// An unknown account is reported the same way (unchanged).
$unknown = file_get_contents(__DIR__ . '/reference/fixtures/import/bawag-unknown-account.csv');
$unknownImport = import_csv('bawag-unknown-account.csv', $unknown, $adminCookies);
assert_contains($unknownImport['body'], 'Ungültige Buchungszeile in Datei 1 (bawag-unknown-account.csv) <br />', 'BAWAG unknown account');
assert_not_contains($unknownImport['body'], 'wurde erfolgreich importiert', 'BAWAG unknown account');

// Dates must be dates (format_date() passes other text through).
$badDates = ["15.02.2024' x", 'x 2024-02-15', '2024-02-15 x', 'Montag', '2024-13-45', '9999-99-99', '31.02.2024', '2023-02-29'];
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
$mixedImport = call_user_func(function () use ($mixed, $second, $unitOneCookies) {
    return http_request(
        '/index.php?action=import',
        'POST',
        ['MAX_FILE_SIZE' => '1000000'],
        $unitOneCookies,
        null,
        [
            'uploadcsv[0]' => ['name' => 'mixed.csv', 'type' => 'text/csv', 'content' => $mixed],
            'uploadcsv[1]' => ['name' => 'second.csv', 'type' => 'text/csv', 'content' => $second],
        ],
    );
});
assert_contains($mixedImport['body'], 'Ungültige Buchungszeile in Datei 1 (mixed.csv), Zeile 3', 'mixed import');
assert_contains($mixedImport['body'], 'Ungültige Buchungszeile in Datei 1 (mixed.csv), Zeile 4', 'mixed import');
assert_contains($mixedImport['body'], 'Datei 1 (mixed.csv): 2 Buchungszeilen übersprungen (Zeilen 3, 4)', 'mixed import');
assert_contains($mixedImport['body'], 'Datei 2 (second.csv) wurde erfolgreich importiert', 'mixed import');
foreach (['before a skipped line' => 1, 'of a foreign line' => 0, 'with an impossible date' => 0, 'after skipped lines' => 1, 'from a second file' => 1] as $label => $expected) {
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
$memberName = @pg_query_params($database, 'SELECT name, member_id FROM vouchers WHERE comment = $1', ['Synthetic member line Synthetica']);
$memberFields = pg_fetch_assoc($memberName);
if ($memberFields['name'] !== 'Synthetica' || $memberFields['member_id'] !== '9035') {
    fail_test('member line: ' . var_export($memberFields, true));
}

// The organization of every line must be one the user may use.
$orgCases = [
    'own organization' => [$unitOneCookies, '1', true],
    'foreign organization' => [$unitOneCookies, '2', false],
    'default organization 10' => [$unitOneCookies, '0', false],
    'organization of Rbgf,2' => [$readOnlyTwoCookies, '2', true],
    'foreign organization of Rbgf,2' => [$readOnlyTwoCookies, '1', false],
    'admin, any organization' => [$adminCookies, '7', true],
];
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

// A BAWAG line with a voucher ID (XY/123456789) imports without diagnostics
// (the former known failure KF-IMPORT-REGEX-G: a voucher-ID check with an
// invalid /g regex warned on every line and never ran; it was removed). The
// same line again is skipped by the import.line check.
$bawagId = "AT606000050110117300;Synthetic BAWAG voucher XY/123456789|BAWAATWW AT611904300234573201 Synthetic Spender;06.04.2024;06.04.2024;+7,00;EUR\n";
$firstId = import_csv('bawag-id.csv', $bawagId, $adminCookies);
assert_contains($firstId['body'], 'wurde erfolgreich importiert', 'BAWAG voucher ID import');
assert_not_contains($firstId['body'], 'fehlgeschlagen', 'BAWAG voucher ID import');
$againId = import_csv('bawag-id.csv', $bawagId, $adminCookies);
assert_contains($againId['body'], 'wurde erfolgreich importiert', 'BAWAG voucher ID import again');
if (import_count($database, 'Synthetic BAWAG voucher XY/123456789%') !== 1) {
    fail_test('BAWAG voucher ID import: expected one voucher after importing the same line twice');
}

// A synthetic BAWAG export shaped like a real one: no header, CRLF line ends,
// "|" line breaks, padded text, backslashes, card payments and transfers, and
// an empty line at the end. Every line is imported and the empty line is
// skipped (before, it stopped the import as an invalid line).
$bawagExport = "AT606000050110117300;Bezahlung Karte                              MC/000001001|POS          1234  K001 10.03. 09:15|SYNTHETIC SHOP GMBH\\\\WIEN\\1;12.03.2024;10.03.2024;-18,40;EUR\r\n"
    . "AT606000050110117300;Synthetic Strom Kd.Nr. 1000001 Anl.Nr. 2000002Synthetic Mitglied Mus|terweg 9 1010 Wien|OG/000001002|BAWAATWWXXX AT611904300234573201 Synthetic Energie GmbH;13.03.2024;13.03.2024;-55,00;EUR\r\n"
    . "AT606000050110117300;Synthetic Spende März 2024|IG/000001003|BAWAATWWXXX AT611904300234573201|Synthetic Spenderin;14.03.2024;14.03.2024;+30,50;EUR\r\n"
    . "AT606000050110117300;Synthetic Festnetz Rechnung 100000000003 vom 01.03.2024 - D|etails unter example.invalid|OG/000001004|BAWAATWWXXX AT611904300234573201 Synthetic Telekom GmbH;15.03.2024;15.03.2024;-24,99;EUR\r\n"
    . "\r\n";
$exportImport = import_csv('bawag-export.csv', $bawagExport, $adminCookies);
assert_contains($exportImport['body'], 'Datei 1 (bawag-export.csv) wurde erfolgreich importiert', 'BAWAG export');
assert_not_contains($exportImport['body'], 'Ungültige Buchungszeile', 'BAWAG export');
$exportRows = @pg_query($database, "SELECT amount, date::date AS date, comment FROM vouchers WHERE comment LIKE '%/00000100_%' ORDER BY id");
if ($exportRows === false) {
    fail_test('BAWAG export query failed: ' . pg_last_error($database));
}
$exportFound = [];
while (($row = pg_fetch_array($exportRows, null, PGSQL_ASSOC)) !== false) {
    $exportFound[] = $row['amount'] . ' ' . $row['date'];
    if (strpos($row['comment'], 'SYNTHETIC SHOP') !== false
        && $row['comment'] !== "Bezahlung Karte                              MC/000001001\nPOS          1234  K001 10.03. 09:15\nSYNTHETIC SHOP GMBH\\\\WIEN\\1") {
        fail_test('BAWAG export: card payment text stored as ' . var_export($row['comment'], true));
    }
}
pg_free_result($exportRows);
if ($exportFound !== ['-1840 2024-03-12', '-5500 2024-03-13', '3050 2024-03-14', '-2499 2024-03-15']) {
    fail_test('BAWAG export: imported ' . var_export($exportFound, true));
}

// Amounts of every format are parsed exactly (amount_cents()), each in its
// own style: BAWAG "+1.234,56", Sparkasse and Paypal "1.234,56" or "1,234.56"
// (the last separator is the decimal one; exactly two decimals), Handkassa
// whole euros or as Sparkasse with one or two decimals. An exponent, another
// style or an out-of-range amount makes the line invalid: reported with its
// number and skipped. A zero amount skips a Handkassa, Sparkasse or Paypal
// line silently, as before (expected rows: none); a BAWAG line with zero is
// imported. Each case: the amount (Paypal: gross and fee), and the stored
// amounts or null for an invalid line.
function import_amount_cases($database, $cookies, $name, $header, $cases, $line, $like)
{
    $content = $header;
    $lineno = $header === '' ? 0 : 1;
    $skipped = [];
    foreach ($cases as $index => $case) {
        $lineno++;
        $content .= $line($index, $case[0]);
        if ($case[1] === null) {
            $skipped[] = $lineno;
        }
    }
    $response = import_csv($name, $content, $cookies);
    assert_status($response, 200, $name);
    assert_not_contains($response['body'], 'fehlgeschlagen', $name);
    foreach ($skipped as $number) {
        assert_contains($response['body'], "Ungültige Buchungszeile in Datei 1 ($name), Zeile $number <br />", $name);
    }
    assert_contains($response['body'], "Datei 1 ($name): " . count($skipped) . ' Buchungszeilen übersprungen (Zeilen ' . implode(', ', $skipped) . ')', $name);
    foreach ($cases as $index => $case) {
        $result = @pg_query_params($database, 'SELECT amount FROM vouchers WHERE comment LIKE $1 ORDER BY id', [$like($index)]);
        $rows = pg_fetch_all_columns($result, 0);
        if ($rows !== ($case[1] === null ? [] : $case[1])) {
            fail_test("$name amount " . var_export($case[0], true) . ': imported ' . var_export($rows, true));
        }
    }
}
import_amount_cases($database, $adminCookies, 'bawag-amounts.csv', '', [
    ['+5,00', ['500']],
    ['+0,00', ['0']],
    ['-1.234,56', ['-123456']],
    ['+21.474.836,47', ['2147483647']],
    ['1e3', null],
    ['1e3,2', null],
    ['1e1000', null],
    ['9223372036854775808', null],
    ['+21.474.836,48', null],
    ['+5,5', null],
    ['25.00', null],
    ['1.234.56', null],
], function ($index, $amount) {
    return "AT606000050110117300;Synthetic BAWAG amount $index|BAWAATWW AT611904300234573201 Synthetic Spender;07.04.2024;07.04.2024;$amount;EUR\n";
}, function ($index) {
    return "Synthetic BAWAG amount $index\n%";
});
import_amount_cases($database, $adminCookies, 'handkassa-amounts.csv', $handkassaHeader, [
    ['12,50', ['1250']],
    ['7', ['700']],
    ['12,5', ['1250']],
    ['-3.5', ['-350']],
    ['-3.10', ['-310']],
    [' 3,00 ', ['300']],
    ['0', []],
    ['1e3', null],
    ['abc', null],
    ['', null],
    ['1,005', null],
    ['1.234,56', ['123456']],
    ['1,234.56', ['123456']],
    ['1.234', null],
], function ($index, $amount) {
    return "08.04.2024;$amount;Handkassa;Synthetic Handkassa amount $index;;;Nein;Nein;Synthetic Importer;0;2;;;\r\n";
}, function ($index) {
    return "Synthetic Handkassa amount $index";
});
import_amount_cases($database, $adminCookies, 'sparkasse-amounts.csv', "Bezeichnung;Valutadatum;Betrag;Währung;Buchungsdatum;Umsatzzeile1;Zusatztext;Auftraggeber;"
    . "Kundendaten/Zahlungsreferenz;Umsatzzeile 2;Ersterfassungsreferenz;Auftraggeber Kontonr./IBAN;"
    . "Auftraggeber BLZ/BIC;Partnername;Partner Kontonr./IBAN;Partner BLZ/BIC\r\n", [
        ['+99,99', ['9999']],
        ['-1.234,56', ['-123456']],
        ['0,00', []],
        ['1e3', null],
        ['99,9', null],
        ['12.34', ['1234']],
        ['1,234.56', ['123456']],
        ['1.234.56', null],
    ], function ($index, $amount) {
        return "Synthetic;16.05.2024;$amount;EUR;16.05.2024;Synthetic Sparkasse amount $index;Zusatz;Auftraggeber;;;;;;Synthetic Partner;AT00 0000;SYNTATWW\r\n";
    }, function ($index) {
        return "Synthetic Sparkasse amount $index; %";
    });
import_amount_cases($database, $adminCookies, 'paypal-amounts.csv', $paypalLines[0] . "\n", [
    [['25.00', '-0.78'], ['2500', '-78']],
    [['"1,234.56"', '0.00'], ['123456', '0']],
    [['"25,00"', '"-0,78"'], ['2500', '-78']],
    [['"-1.234,56"', '"0,00"'], ['-123456', '0']],
    [['"25,0"', '0.00'], null],
    [['"1.234.56"', '0.00'], null],
    [['0.00', '0.00'], []],
    [['1e3', '0.00'], null],
    [['25', '0.00'], null],
    [['25.00', 'x'], null],
], function ($index, $amount) {
    return "17.03.2024,10:00:00,CET,Synthetic PayPal Donor,Spende,Abgeschlossen,EUR,{$amount[0]},{$amount[1]},24.22,donor@example.invalid,spende@piratenpartei.at,SYNTH000000010$index,,,Synthetic Paypal amount $index,,,,,,,,,,,,,,,,,,,0.00,,,,,,,,\n";
}, function ($index) {
    return "Synthetic Paypal amount $index; %";
});

// Blank lines (empty fields only, or nothing) after a header are skipped
// silently, as before; a line with an empty amount is reported.
$blankLines = $handkassaHeader . ";;;;;;;;;;;;;\r\n" . "\r\n"
    . "09.04.2024;4,00;Handkassa;Synthetic Handkassa line after blank lines;;;Nein;Nein;Synthetic Importer;0;2;;;\r\n"
    . "09.04.2024;;Handkassa;Synthetic Handkassa line without an amount;;;Nein;Nein;Synthetic Importer;0;2;;;\r\n";
$blankImport = import_csv('blank-lines.csv', $blankLines, $adminCookies);
assert_contains($blankImport['body'], 'Ungültige Buchungszeile in Datei 1 (blank-lines.csv), Zeile 5 <br />', 'blank lines');
assert_contains($blankImport['body'], 'Datei 1 (blank-lines.csv): 1 Buchungszeilen übersprungen (Zeilen 5)', 'blank lines');
if (import_count($database, 'Synthetic Handkassa line after blank lines') !== 1 || import_count($database, 'Synthetic Handkassa line without an amount') !== 0) {
    fail_test('blank lines: wrong lines imported');
}

// getusers() replaces the member list (ppmembers) only with a complete,
// valid response of the membership stub (HTTPS, compose.test.yaml). A failed
// fetch (an HTTP error, a redirect, a refused certificate), a short or an
// undecryptable response, or an SQL error stops the page with an error and
// leaves the table as it was. The member-fee and import pages drop the
// table after each view, so a table left in place (such as one of a
// concurrent request) is planted here.
function membership_stub_mode($mode)
{
    $answer = @file_get_contents('http://mitglieder.piratenpartei.at/__stub/mode?set=' . $mode);
    if ($answer !== "mode $mode\n") {
        fail_test("membership stub mode $mode: " . var_export($answer, true));
    }
}

function member_rows($database)
{
    $result = @pg_query($database, 'SELECT id, name, lo, paid FROM ppmembers ORDER BY id, name');
    if ($result === false) {
        fail_test('ppmembers query failed: ' . pg_last_error($database));
    }
    $rows = pg_fetch_all($result);
    pg_free_result($result);
    return $rows === false ? [] : $rows;
}

membership_stub_mode('ok');
$memberPage = http_request('/index.php?action=mb', 'GET', [], $adminCookies);
assert_status($memberPage, 200, 'member-fee page');
assert_contains($memberPage['body'], '<td>Anna Synthetisch-Müller</td>', 'member-fee page');
// A bank import matches a line's name against the list it just fetched (the
// stub's "flow" list; its rows are checked in GetUsersTest): the voucher
// becomes a membership fee (type 1) of that member.
membership_stub_mode('flow');
$flowImport = import_csv('flow.csv', $handkassaHeader
    . "07.04.2024;7,00;Handkassa;Synthetic flow member line;;;Nein;Nein;Jürgen Größe-Weiß;0;1;;;\r\n", $adminCookies);
assert_contains($flowImport['body'], 'wurde erfolgreich importiert', 'import with the flow list');
$flowRow = @pg_query_params($database, 'SELECT name, member_id, type FROM vouchers WHERE comment = $1', ['Synthetic flow member line']);
$flowFields = $flowRow === false ? false : pg_fetch_all($flowRow);
if ($flowFields !== [['name' => 'Jürgen Größe-Weiß', 'member_id' => '9111', 'type' => '1']]) {
    fail_test('member line with the flow list: ' . var_export($flowFields, true));
}
membership_stub_mode('ok');
foreach (['DROP TABLE IF EXISTS ppmembers', 'CREATE TABLE ppmembers (id integer, name text, lo integer, paid integer)',
    "INSERT INTO ppmembers VALUES (8801, 'Synthetic Kept Member', 1, 1), (8802, 'Synthetic Kept Other', 10, 0)"] as $query) {
    if (@pg_query($database, $query) === false) {
        fail_test('planting ppmembers failed: ' . pg_last_error($database));
    }
}
$members = member_rows($database);
$failures = [
    'short' => 'Daten von Mitgliederverwaltung zu kurz!',
    'garbage' => 'Daten von Mitgliederverwaltung ungültig!',
    'empty' => 'Daten von Mitgliederverwaltung ungültig!',
    'error' => 'Mitgliederliste konnte nicht abgerufen werden!',
    // Redirects are not followed, also not to the same host over HTTPS.
    'redirect' => 'Mitgliederliste konnte nicht abgerufen werden!',
    'redirect-http' => 'Mitgliederliste konnte nicht abgerufen werden!',
    // The INSERT fails (member id out of range) after DROP and CREATE: the
    // transaction is rolled back.
    'overflow' => 'Abfrage fehlgeschlagen: ',
    // Certificate verification: PHP's warnings of the refused handshake
    // (logged by getusers(), hidden from the page by "@") are expected.
    'selfsigned' => 'Mitgliederliste konnte nicht abgerufen werden!',
    'wrongname' => 'Mitgliederliste konnte nicht abgerufen werden!',
];
foreach ($failures as $mode => $message) {
    membership_stub_mode($mode);
    $requests = function () use ($mode, $message, $adminCookies, $database, $members) {
        foreach (['mb', 'import'] as $action) {
            $failed = http_request("/index.php?action=$action", 'GET', [], $adminCookies);
            assert_contains($failed['body'], $message, "$action with a $mode member list");
            if (member_rows($database) !== $members) {
                fail_test("$action with a $mode member list changed ppmembers: " . var_export(array_slice(member_rows($database), 0, 2), true));
            }
        }
    };
    if ($mode === 'selfsigned' || $mode === 'wrongname') {
        with_expected_diagnostics("refused $mode certificate", [['getusers.php', 'file_get_contents(']], $requests);
    } else {
        $requests();
    }
    // A followed redirect would reach the stub's /__stub/list.
    if (strpos($mode, 'redirect') === 0) {
        $followed = @file_get_contents('http://mitglieder.piratenpartei.at/__stub/list-requests');
        if ($followed !== "0\n") {
            fail_test("$mode: the redirect was followed (" . var_export($followed, true) . ' requests to its target)');
        }
    }
}
membership_stub_mode('ok');
// An SQL error in the refresh (here: ppmembers is a view, which DROP TABLE
// refuses) rolls the transaction back.
foreach (['DROP TABLE ppmembers', "CREATE VIEW ppmembers AS SELECT 8803 AS id, 'Synthetic View Member'::text AS name, 1 AS lo, 1 AS paid"] as $query) {
    if (@pg_query($database, $query) === false) {
        fail_test('planting the ppmembers view failed: ' . pg_last_error($database));
    }
}
$members = member_rows($database);
$failed = http_request('/index.php?action=mb', 'GET', [], $adminCookies);
assert_contains($failed['body'], 'Abfrage fehlgeschlagen: ', 'mb with a ppmembers view');
if (member_rows($database) !== $members) {
    fail_test('mb with a ppmembers view changed it');
}
if (@pg_query($database, 'DROP VIEW ppmembers') === false) {
    fail_test('dropping the ppmembers view failed: ' . pg_last_error($database));
}

// The BAWAG fixtures: every known account (organizations 2-10), an
// ISO-8859-15 file (converted to UTF-8, application/vnd.ms-excel), the
// contra account and name taken from the comment's second line, membership
// fees ("MB", "Mitgliedsbeitrag") and names with academic titles. The
// imported rows are removed again, so that the later scripts see the same
// data.
function import_single_value($database, $query)
{
    $result = @pg_query($database, $query);
    if ($result === false) {
        fail_test('query failed: ' . pg_last_error($database));
    }
    $rows = pg_fetch_all_columns($result, 0);
    pg_free_result($result);
    return $rows;
}

// vouchers.id has no unique constraint (the sequence can repeat fixture
// IDs): the imported rows are those written after this transaction ID.
$importStart = import_single_value($database, 'SELECT txid_current()')[0];
$importLines = import_single_value($database, 'SELECT line FROM import');
$latin9 = http_request(
    '/index.php?action=import',
    'POST',
    ['MAX_FILE_SIZE' => '1000000'],
    $adminCookies,
    null,
    [
        'uploadcsv[0]' => ['name' => '', 'type' => 'text/csv', 'content' => ''],
        'uploadcsv[1]' => ['name' => 'bawag-latin9.csv', 'type' => 'application/vnd.ms-excel',
            'content' => file_get_contents(__DIR__ . '/reference/fixtures/import/bawag-latin9.csv')],
    ],
);
assert_status($latin9, 200, 'BAWAG ISO-8859-15 fixture');
assert_contains($latin9['body'], "<table>\n<h2>Importiere bawag-latin9.csv</h2>\n", 'BAWAG ISO-8859-15 fixture');
assert_contains($latin9['body'], 'Datei 2 (bawag-latin9.csv) wurde erfolgreich importiert', 'BAWAG ISO-8859-15 fixture');
$bawagFixture = import_csv('bawag.csv', file_get_contents(__DIR__ . '/reference/fixtures/import/bawag.csv'), $adminCookies);
assert_contains($bawagFixture['body'], 'Datei 1 (bawag.csv) wurde erfolgreich importiert', 'BAWAG fixture');
assert_not_contains($bawagFixture['body'], 'Ungültige Buchungszeile', 'BAWAG fixture');
// A second comment line "<name> <x> <text>" gives the name and the contra
// account "<x> <text>".
$bawagName = import_csv('bawag-name.csv', "AT606000050110117300;Synthetic transfer|Synthetic Sender a Zahlung;14.01.2024;14.01.2024;+6,00;EUR\n", $adminCookies);
assert_contains($bawagName['body'], 'Datei 1 (bawag-name.csv) wurde erfolgreich importiert', 'BAWAG name before the contra account');
$importedRows = 'FROM vouchers WHERE xmin::text::bigint > $1';
$imported = @pg_query_params($database, "SELECT orga, type, member, contra_account, name, amount $importedRows ORDER BY id, comment", [$importStart]);
$summary = [];
foreach ($imported === false ? [] : pg_fetch_all($imported) as $row) {
    $summary[] = implode('|', [$row['orga'], $row['type'], $row['member'], $row['contra_account'], $row['name'], $row['amount']]);
}
// The two lines of the ISO-8859-15 file come first; bawag.csv repeats them
// at its end, and they are skipped there (import.line).
$expected = [
    '6|8|f|BAWAATWW AT666666666666666666|Jörg Österreicher|1234',
    '6|28|t|BAWAATWW AT777777777777777777|Zoë Synthetisch|-500',
    '2|8|f|BAWAATWW AT611904300234573201|Synthetic Spender|5000',
    '2|1|t|OPSKATWW AT111111111111111111|Testa Mitglied|2400',
    '2|28|f|BAWAATWW AT222222222222222222|Synthetic Lieferant GmbH|-123456',
    '10|1|t|||2000',
    '3|1|t|BAWAATWW AT444444444444444444|Dr. Gerlinde Titelträgerin|1500',
    '4|14|f|||5',
    '5|1|t|12345 67890|Maria Synthetisch|100000000',
    '6|8|f|BAWAATWW AT555555555555555555|Synthetic O\'Quote|10',
    '8|28|f|||-1',
    '9|1|t||Synthetica|300',
    '2|14|f|||400',
    '2|8|f|a Zahlung|Synthetic Sender|600',
];
if ($summary !== $expected) {
    fail_test('BAWAG fixture rows: ' . var_export($summary, true));
}
$cleanup = [["DELETE $importedRows", [$importStart]]];
foreach (array_diff(import_single_value($database, 'SELECT line FROM import'), $importLines) as $line) {
    $cleanup[] = ['DELETE FROM import WHERE line = $1', [$line]];
}
foreach ($cleanup as $query) {
    if (@pg_query_params($database, $query[0], $query[1]) === false) {
        fail_test('removing the BAWAG fixture rows failed: ' . pg_last_error($database));
    }
}

// check.php: the voucher-number check of a cash account ("Handkassa"
// comments "Nr.<n>.").
$check = @pg_query($database, "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, date, person_type, file, vaccount)
    VALUES (9140, 4770, 20, 2, false, -100, 'Handkassa SYNTH', 'Synthetic cash Nr.2.', timestamp '2024-09-15 00:00:00', 1, 0, '')");
if ($check === false) {
    fail_test('inserting the cash voucher failed: ' . pg_last_error($database));
}
$checkPage = http_request('/check.php?kto=Handkassa%20SYNTH&max=3');
assert_status($checkPage, 200, 'check.php for a cash account');
assert_contains($checkPage['body'], 'Habe 1 von 1 Buchungen gefunden.<br /><br />', 'check.php for a cash account');
assert_contains($checkPage['body'], "Buchung 2 OK<br />\nBuchung 3 FEHLT (0 results)<br />\n", 'check.php for a cash account');
if (@pg_query($database, 'DELETE FROM vouchers WHERE id = 9140') === false) {
    fail_test('removing the cash voucher failed: ' . pg_last_error($database));
}

pg_close($database);
assert_no_unexpected_diagnostics();
echo "Bank import tests passed\n";
