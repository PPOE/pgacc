<?php

/*
 * page_donations() in donations.php: the captcha that guards the donor list
 * (disabled for legal reasons, header row only), which logged-in users skip
 * (also in kdonations.php page_kdonations()). Tested with
 * tests/http-donations.php. See tests/mutation/README.md for the format.
 */
return array(
    'description' => 'donations.php page_donations() captcha and login bypass; kdonations.php login bypass (HTTP)',
    'file' => 'donations.php',
    'region' => array('start' => 'function page_donations($year)', 'end' => 'echo "<br /><br /><br />";'),
    'tests' => array(
        array('type' => 'http', 'script' => 'tests/http-donations.php'),
    ),
    'timeout' => 60,
    'mutations' => array(
        array(
            'id' => 'captcha-check-bypassed',
            'search' => "intval(\$_POST['captcha_input']) == intval(\$_POST['captcha']) + 37",
            'replace' => 'true',
            'rationale' => 'A wrong captcha answer must not show the list.',
        ),
        array(
            'id' => 'captcha-offset-changed',
            'search' => "intval(\$_POST['captcha']) + 37",
            'replace' => "intval(\$_POST['captcha']) + 36",
            'rationale' => 'The correct answer to the displayed sum must show the list.',
        ),
        array(
            'id' => 'captcha-hidden-value-changed',
            'search' => '$c = $a + $b - 37;',
            'replace' => '$c = $a + $b - 36;',
            'rationale' => 'The hidden field must encode the displayed sum.',
        ),
        array(
            'id' => 'donations-login-bypass-removed',
            'search' => "if (checklogin('id', false) > 0 || (",
            'replace' => "if (false || (",
            'rationale' => 'A logged-in user sees the donor list without the captcha.',
        ),
        array(
            'id' => 'donations-login-bypass-always',
            'search' => "if (checklogin('id', false) > 0 || (",
            'replace' => "if (true || (",
            'rationale' => 'An anonymous visitor must still solve the captcha.',
        ),
        array(
            'id' => 'donations-login-bypass-without-session',
            'search' => "if (checklogin('id', false) > 0 || (",
            'replace' => "if (checklogin('id', false) >= 0 || (",
            'rationale' => 'Without a valid session (checklogin() returns null) the captcha stays.',
        ),
        array(
            'id' => 'kdonations-login-bypass-removed',
            'file' => 'kdonations.php',
            'search' => "if (checklogin('id', false) > 0 || (",
            'replace' => "if (false || (",
            'rationale' => 'A logged-in user sees the in-kind donation list without the captcha.',
        ),
        array(
            'id' => 'kdonations-login-bypass-always',
            'file' => 'kdonations.php',
            'search' => "if (checklogin('id', false) > 0 || (",
            'replace' => "if (true || (",
            'rationale' => 'An anonymous visitor must still solve the captcha.',
        ),
        array(
            'id' => 'kdonations-login-bypass-without-session',
            'file' => 'kdonations.php',
            'search' => "if (checklogin('id', false) > 0 || (",
            'replace' => "if (checklogin('id', false) >= 0 || (",
            'rationale' => 'Without a valid session (checklogin() returns null) the captcha stays.',
        ),
    ),
);
