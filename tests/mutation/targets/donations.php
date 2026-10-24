<?php
/*
 * page_donations() in donations.php: the array initializations that keep the
 * donor list from passing undefined variables to foreach and
 * array_multisort() (TypeError on PHP 8), the disabled donor publication,
 * and the captcha that guards the list. Tested with tests/http-donations.php
 * (2024 has a donor above the threshold, so the sort block runs). See
 * tests/mutation/README.md for the format.
 */
return array(
    'description' => 'donations.php page_donations() list initialization, disabled publication, captcha (HTTP)',
    'file' => 'donations.php',
    'region' => array('start' => 'function page_donations($year)', 'end' => 'echo "<br /><br /><br />";'),
    'tests' => array(
        array('type' => 'http', 'script' => 'tests/http-donations.php'),
    ),
    'timeout' => 60,
    'mutations' => array(
        array(
            'id' => 'donations-initialization-removed',
            'search' => "\$donations = array();\n",
            'replace' => "true;\n",
            'rationale' => 'The pre-fix state: foreach over an undefined $donations (TypeError in array_multisort() on PHP 8).',
        ),
        array(
            'id' => 'sort-sum-initialization-removed',
            'search' => '$sort_sum = array();',
            'replace' => 'true;',
            'rationale' => 'array_multisort() receives null as its first array.',
        ),
        array(
            'id' => 'sort-name-initialization-removed',
            'search' => '$sort_name = array();',
            'replace' => 'true;',
            'rationale' => 'array_multisort() receives null as its third argument.',
        ),
        array(
            'id' => 'publication-re-enabled',
            'search' => '//$donations[] = $line;',
            'replace' => '$donations[] = $line;',
            'rationale' => 'Donor publication is disabled for legal reasons; no donor may be listed.',
        ),
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
    ),
);
