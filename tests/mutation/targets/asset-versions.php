<?php

/*
 * Versioned stylesheet and script URLs (?v=PGACC_VERSION): the stylesheet of
 * functions.php acc_header() and the chart scripts of vreport.php v_graph(),
 * so that browsers load them again after an upgrade. Tested with
 * tests/http-page-views.php (every page) and the acc_header() unit test. See
 * tests/mutation/README.md for the format.
 */
return [
    'description' => 'Release version in the stylesheet and script URLs (acc_header(), vreport.php)',
    'file' => 'functions.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'testAccHeaderLinksTheStylesheetWithTheReleaseVersion'],
        ['type' => 'http', 'script' => 'tests/http-page-views.php'],
    ],
    'timeout' => 120,
    'mutations' => [
        [
            'id' => 'stylesheet-unversioned',
            'search' => "href=\"style.css?v=' . PGACC_VERSION . '\"",
            'replace' => "href=\"style.css\"",
            'rationale' => 'The pre-fix stylesheet URL: browsers keep a cached style.css after an upgrade.',
        ],
        [
            'id' => 'stylesheet-version-fixed',
            'search' => "href=\"style.css?v=' . PGACC_VERSION . '\"",
            'replace' => "href=\"style.css?v=1\"",
            'rationale' => 'A fixed version does not change with the release.',
        ],
        [
            'id' => 'chart-library-unversioned',
            'file' => 'vreport.php',
            'search' => "<script src=\"js/chart.umd.min.js?v=' . PGACC_VERSION . '\">",
            'replace' => "<script src=\"js/chart.umd.min.js\">",
            'rationale' => 'The pre-fix Chart.js URL stays cached after an upgrade.',
        ],
        [
            'id' => 'chart-script-unversioned',
            'file' => 'vreport.php',
            'search' => "<script src=\"js/vreport-chart.js?v=' . PGACC_VERSION . '\">",
            'replace' => "<script src=\"js/vreport-chart.js\">",
            'rationale' => 'The pre-fix chart script URL stays cached after an upgrade.',
        ],
    ],
];
