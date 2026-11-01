<?php

/*
 * The default year and the kept year of the report and the donation list:
 * the year of the menu links (index.php $nav_year, functions.php
 * acc_header()), the current year as the default and the replacement year of
 * the donation list (donations.php donations_year(), the index.php dispatch).
 * Tested with the acc_header() unit test, tests/http-smoke.php and
 * tests/http-donations.php. See tests/mutation/README.md for the format.
 */
return [
    'description' => 'Default year and menu links that keep the year of the report and the donation list',
    'file' => 'functions.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'testAccHeaderMenuKeepsTheYearOfTheReportAndTheDonations'],
        ['type' => 'http', 'script' => 'tests/http-smoke.php'],
        ['type' => 'http', 'script' => 'tests/http-donations.php'],
    ],
    'timeout' => 120,
    'mutations' => [
        [
            'id' => 'menu-year-ignored',
            'search' => "\$yearQuery = \$navYear === null ? '' : '?year=' . intval(\$navYear);",
            'replace' => "\$yearQuery = '';",
            'rationale' => 'The pre-fix menu: another year page shows the current year again.',
        ],
        [
            'id' => 'report-link-without-year',
            'search' => "<a href=\"/' . \$yearQuery . '\"'",
            'replace' => "<a href=\"/\"'",
            'rationale' => 'The link to the report keeps the year.',
        ],
        [
            'id' => 'donations-link-without-year',
            'search' => "'<a href=\"donations' . \$yearQuery . '\"'",
            'replace' => "'<a href=\"donations\"'",
            'rationale' => 'The link to the donation list keeps the year.',
        ],
        [
            'id' => 'report-year-not-passed',
            'file' => 'public/index.php',
            'search' => "    \$nav_year = \$year;\n",
            'replace' => "    \$nav_year = null;\n",
            'rationale' => 'The report passes its year to the menu links.',
        ],
        [
            'id' => 'donations-year-unchecked',
            'file' => 'public/index.php',
            'search' => "\$nav_year = donations_year(intval(\$_GET[\"year\"]));",
            'replace' => "\$nav_year = intval(\$_GET[\"year\"]);",
            'rationale' => 'The donation list passes the year it shows, not a year out of range.',
        ],
        [
            'id' => 'report-year-without-choice',
            'file' => 'public/index.php',
            'search' => "if (isset(\$_GET[\"year\"]) && (\$page == \"index\" || \$page == \"report\")) {",
            'replace' => "if (\$page == \"index\" || \$page == \"report\") {",
            'rationale' => 'Without a chosen year the menu links carry no year.',
        ],
        [
            'id' => 'donations-year-without-choice',
            'file' => 'public/index.php',
            'search' => "} elseif (isset(\$_GET[\"year\"]) && \$page == \"donations\") {",
            'replace' => "} elseif (\$page == \"donations\") {",
            'rationale' => 'Without a chosen year the menu links carry no year.',
        ],
        [
            'id' => 'year-passed-by-other-pages',
            'file' => 'public/index.php',
            'search' => "(\$page == \"index\" || \$page == \"report\")",
            'replace' => "(\$page != \"donations\")",
            'rationale' => 'Pages without a year (here the spendings) pass none.',
        ],
        [
            'id' => 'unknown-action-report-year-not-passed',
            'file' => 'public/index.php',
            'search' => "(\$page == \"index\" || \$page == \"report\")",
            'replace' => "(\$page == \"index\")",
            'rationale' => 'The report of an unknown action also passes its year.',
        ],
        [
            'id' => 'ledger-links-chosen-year',
            'file' => 'public/index.php',
            'search' => "acc_header(\$dbconn, \$page, date('Y'), \$nav_year);",
            'replace' => "acc_header(\$dbconn, \$page, \$year, \$nav_year);",
            'rationale' => 'The ledger links of the logged-in menu keep the current year.',
        ],
        [
            'id' => 'donations-default-year-zero',
            'file' => 'public/index.php',
            'search' => "page_donations(intval(\$_GET[\"year\"] ?? date('Y')));",
            'replace' => "page_donations(intval(\$_GET[\"year\"] ?? 0));",
            'rationale' => 'The pre-fix default: without a year the donation list shows no captcha.',
        ],
        [
            'id' => 'donations-replacement-year-2013',
            'file' => 'donations.php',
            'search' => "return \$year < 2012 || \$year > 9999 ? intval(date('Y')) : \$year;",
            'replace' => "return \$year < 2012 || \$year > 9999 ? 2013 : \$year;",
            'rationale' => 'The pre-fix replacement year: 2013 instead of the current year.',
        ],
        [
            'id' => 'donations-year-kept-out-of-range',
            'file' => 'donations.php',
            'search' => "if (\$year !== donations_year(\$year)) {",
            'replace' => "if (false) {",
            'rationale' => 'A year before 2012 is replaced and shown without the captcha.',
        ],
        [
            'id' => 'donations-year-not-replaced',
            'file' => 'donations.php',
            'search' => "        \$year = donations_year(\$year);\n",
            'replace' => "        \$year = \$year;\n",
            'rationale' => 'A year before 2012 shows the current year.',
        ],
    ],
];
