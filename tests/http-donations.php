<?php
/*
 * Public donation list (?action=donations, donations.php page_donations()).
 *
 * The page asks a captcha ("Was ergibt die Addition A + B?", hidden field
 * captcha = A + B - 37) before it shows the list. The test reads A, B and the
 * hidden value from the form and answers it like a browser, so the
 * application's check is exercised unchanged.
 *
 * Donor publication is disabled for legal reasons (the collection of the
 * donors is commented out): even with a correct answer and a qualifying
 * donor (2024: "Synthetic Donor", 130.00 EUR in tests/fixtures/data.sql), the
 * table has only its header row, and no donor name appears in any form.
 * 2025 has a donation below the 100 EUR threshold; years before 2012 show
 * 2013 without a captcha.
 *
 * Must stay compatible with PHP 5.6.
 */
require __DIR__ . '/http-test-helpers.php';

define('DONATIONS_NOTICE', 'Aus rechtlichen Gründen werden Spenden nur mit Zustimmung Veröffentlicht.');
define('DONATIONS_CAPTCHA_INTRO', 'musst du ein Rätsel lösen');
define(
    'DONATIONS_EMPTY_TABLE',
    "\n<table id=\"donations\" style=\"min-width: 50%\" border=\"1\">\n"
    . "<tr><td><b>Name</b></td><td><b>Spendensumme</b></td></tr>\n</table>"
);

function donations_page($year, $form = null)
{
    $path = '/index.php?action=donations&year=' . $year;
    if ($form === null) {
        $response = http_request($path);
    } else {
        $response = http_request($path, 'POST', $form);
    }
    $label = "donations $year " . ($form === null ? 'GET' : 'POST');
    assert_status($response, 200, $label);
    assert_no_fatal_error($response['body'], $label);
    return $response['body'];
}

// Returns array(a, b, hidden captcha value) of the captcha form.
function donations_captcha($body, $year, $label)
{
    $pattern = '/<form class="login" action="donations\?year=' . $year . '" method="POST">'
        . '<div><label for="captcha_input" class="ui_field_label">Was ergibt die Addition (\d+) \+ (\d+)\?<\/label> '
        . '<input id="captcha_input" name="captcha_input" value="" type="text"><\/div>'
        . '<input value="(-?\d+)" name="captcha" type="hidden">'
        . '<input value="Bestätigen" name="submit" type="submit"><\/form>/';
    if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER) !== 1) {
        fail_test("$label: expected exactly one captcha form");
    }
    $a = intval($matches[0][1]);
    $b = intval($matches[0][2]);
    $hidden = intval($matches[0][3]);
    if ($a < 1 || $a > 50 || $b < 1 || $b > 50) {
        fail_test("$label: captcha operands $a and $b outside 1..50");
    }
    if ($hidden !== $a + $b - 37) {
        fail_test("$label: hidden captcha $hidden is not $a + $b - 37");
    }
    return array($a, $b, $hidden);
}

function assert_donations_heading($body, $year, $label)
{
    assert_contains($body, "<h1>Spendenliste der Piratenpartei Österreichs $year</h1>\n", $label);
    assert_contains($body, '<div class="wiki motd"><h2>', $label);
    for ($y = 2012; $y <= intval(date('Y')); $y++) {
        assert_contains($body, "<a href=\"donations?year=$y\">$y</a> ", "$label year links");
    }
    assert_contains($body, '</h2>' . DONATIONS_NOTICE . '</div>', $label);
}

function assert_no_donor_data($body, $label)
{
    $names = array('Synthetic Donor', 'Synthetic Future Donor', 'Synthetic Supplier', 'Synthetic Draft');
    foreach ($names as $name) {
        assert_not_contains($body, $name, "$label donor name");
        assert_not_contains(
            $body,
            base64_encode(htmlentities($name, ENT_COMPAT, 'UTF-8')),
            "$label encoded donor name"
        );
    }
    assert_not_contains($body, '<td id="s0">', "$label donor row");
    assert_not_contains($body, 'document.getElementById("s', "$label donor row");
    assert_not_contains($body, '130.00€', "$label donor sum");
    assert_not_contains($body, '25.00€', "$label donor sum");
}

function assert_captcha_page($body, $year, $label)
{
    assert_donations_heading($body, $year, $label);
    assert_contains($body, DONATIONS_CAPTCHA_INTRO, $label);
    assert_not_contains($body, '<table id="donations"', $label);
    assert_not_contains($body, 'function base64_decode', $label);
    assert_no_donor_data($body, $label);
    return donations_captcha($body, $year, $label);
}

function assert_list_page($body, $year, $label)
{
    assert_donations_heading($body, $year, $label);
    assert_not_contains($body, DONATIONS_CAPTCHA_INTRO, $label);
    assert_contains($body, '<script type="text/javascript">' . "\nfunction base64_decode (data) {", $label);
    if (substr_count($body, '<table id="donations"') !== 1) {
        fail_test("$label: expected exactly one donations table");
    }
    assert_contains($body, DONATIONS_EMPTY_TABLE, "$label empty table");
    assert_no_donor_data($body, $label);
}

// 2024: a qualifying donor (sum above 100 EUR), publication disabled.
$body = donations_page(2024);
list($a, $b, $hidden) = assert_captcha_page($body, 2024, 'donations 2024 captcha');

$wrong = donations_page(2024, array(
    'captcha_input' => strval($a + $b + 1),
    'captcha' => strval($hidden),
    'submit' => 'Bestätigen',
));
assert_captcha_page($wrong, 2024, 'donations 2024 wrong answer');

$missing = donations_page(2024, array('captcha' => strval($hidden), 'submit' => 'Bestätigen'));
assert_captcha_page($missing, 2024, 'donations 2024 missing answer');

$list = donations_page(2024, array(
    'captcha_input' => strval($a + $b),
    'captcha' => strval($hidden),
    'submit' => 'Bestätigen',
));
assert_list_page($list, 2024, 'donations 2024 list');

// 2025: only a donation below the threshold.
$body = donations_page(2025);
list($a, $b, $hidden) = assert_captcha_page($body, 2025, 'donations 2025 captcha');
$list = donations_page(2025, array(
    'captcha_input' => strval($a + $b),
    'captcha' => strval($hidden),
    'submit' => 'Bestätigen',
));
assert_list_page($list, 2025, 'donations 2025 list');

// Before 2012: the 2013 page, without a captcha form and without a list.
$early = donations_page(2011);
assert_donations_heading($early, 2013, 'donations 2011');
assert_not_contains($early, DONATIONS_CAPTCHA_INTRO, 'donations 2011');
assert_not_contains($early, '<table id="donations"', 'donations 2011');
assert_no_donor_data($early, 'donations 2011');

assert_no_unexpected_diagnostics();
echo "Donations HTTP characterization tests passed\n";
