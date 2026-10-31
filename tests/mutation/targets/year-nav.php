<?php

/*
 * The year selector: year_nav() in functions.php (range 2012 to the current
 * year, previous and next year within it, the marked year, "Alle Jahre",
 * escaped links) and its call sites in report.php page_report() and
 * donations.php page_donations(). Tested with tests/unit/YearNavTest.php,
 * tests/http-smoke.php and tests/http-donations.php. See
 * tests/mutation/README.md for the format.
 */
return [
    'description' => 'Year selector year_nav() and its call sites on the report and the donations page',
    'file' => 'functions.php',
    'region' => ['start' => 'function year_nav($selected, $urlForYear, $allowAll = false)', 'end' => '// Whether the comma-separated rights contain exactly'],
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'YearNavTest', 'file' => 'tests/unit/YearNavTest.php'],
        ['type' => 'http', 'script' => 'tests/http-smoke.php'],
        ['type' => 'http', 'script' => 'tests/http-donations.php'],
    ],
    'timeout' => 120,
    'mutations' => [
        [
            'id' => 'year-nav-first-year-changed',
            'search' => '    $first = 2012;',
            'replace' => '    $first = 2013;',
            'rationale' => 'The list and the previous-year link reach back to 2012.',
        ],
        [
            'id' => 'year-nav-last-year-changed',
            'search' => "    \$last = intval(date('Y'));",
            'replace' => "    \$last = intval(date('Y')) - 1;",
            'rationale' => 'The list and the next-year link reach the current year.',
        ],
        [
            'id' => 'year-nav-selected-not-integer',
            'search' => '    $selected = intval($selected);',
            'replace' => '    $selected = $selected;',
            'rationale' => 'The selected year is printed and compared as an integer, never as given.',
        ],
        [
            'id' => 'year-nav-all-for-every-year',
            'search' => '    $all = $allowAll && $selected === null;',
            'replace' => '    $all = $allowAll;',
            'rationale' => 'With "Alle Jahre" offered, a selected year stays selected.',
        ],
        [
            'id' => 'year-nav-prev-at-first-year',
            'search' => '$selected > $first ?',
            'replace' => '$selected >= $first ?',
            'rationale' => 'There is no previous year before 2012.',
        ],
        [
            'id' => 'year-nav-next-at-last-year',
            'search' => '$selected < $last ?',
            'replace' => '$selected <= $last ?',
            'rationale' => 'There is no next year after the current year.',
        ],
        [
            'id' => 'year-nav-next-with-all',
            'search' => '    $next = !$all && ',
            'replace' => '    $next = ',
            'rationale' => 'With "Alle Jahre" selected there is no next year.',
        ],
        [
            'id' => 'year-nav-prev-unclamped',
            'search' => 'min($selected - 1, $last)',
            'replace' => '$selected - 1',
            'rationale' => 'From a year after the current one, the previous-year link leads to the current year.',
        ],
        [
            'id' => 'year-nav-next-unclamped',
            'search' => 'max($selected + 1, $first)',
            'replace' => '$selected + 1',
            'rationale' => 'From a year before 2012, the next-year link leads to 2012.',
        ],
        [
            'id' => 'year-nav-all-never-offered',
            'search' => '    if ($allowAll) {',
            'replace' => '    if (false) {',
            'rationale' => 'With $allowAll the list starts with "Alle Jahre".',
        ],
        [
            'id' => 'year-nav-all-always-offered',
            'search' => '    if ($allowAll) {',
            'replace' => '    if (true) {',
            'rationale' => 'Without $allowAll there is no "Alle Jahre" entry.',
        ],
        [
            'id' => 'year-nav-list-ascending',
            'search' => 'for ($i = $last; $i >= $first; $i--) {',
            'replace' => 'for ($i = $first; $i <= $last; $i++) {',
            'rationale' => 'The list starts with the current year.',
        ],
        [
            'id' => 'year-nav-list-without-first-year',
            'search' => '$i >= $first; $i--',
            'replace' => '$i > $first; $i--',
            'rationale' => 'The list ends with 2012.',
        ],
        [
            'id' => 'year-nav-wrong-year-marked',
            'search' => "\$current = \$item[2] ? ' aria-current=\"page\"' : '';",
            'replace' => "\$current = !\$item[2] ? ' aria-current=\"page\"' : '';",
            'rationale' => 'Only the selected year is marked as the current page.',
        ],
        [
            'id' => 'year-nav-step-never-disabled',
            'search' => '        if ($year === null) {',
            'replace' => '        if (false) {',
            'rationale' => 'At the ends the step is a disabled span, not a link.',
        ],
        [
            'id' => 'year-nav-step-url-unescaped',
            'search' => 'href=\"" . html_escape($urlForYear($year)) . "\"',
            'replace' => 'href=\"" . $urlForYear($year) . "\"',
            'rationale' => 'The previous and next links are HTML-escaped.',
        ],
        [
            'id' => 'year-nav-item-url-unescaped',
            'search' => "'<li><a href=\"' . html_escape(\$urlForYear(\$item[0]))",
            'replace' => "'<li><a href=\"' . \$urlForYear(\$item[0])",
            'rationale' => 'The year links of the list are HTML-escaped.',
        ],
        [
            'id' => 'report-year-nav-url-changed',
            'file' => 'report.php',
            'search' => 'echo year_nav($year, fn($y) => "index.php?year=$y");',
            'replace' => 'echo year_nav($year, fn($y) => "index.php?action=report&year=$y");',
            'rationale' => 'The report keeps its year links (index.php?year=<year>).',
        ],
        [
            'id' => 'report-year-nav-selected-changed',
            'file' => 'report.php',
            'search' => 'echo year_nav($year, fn($y) => "index.php?year=$y");',
            'replace' => 'echo year_nav(2024, fn($y) => "index.php?year=$y");',
            'rationale' => 'The report marks the year it shows.',
        ],
        [
            'id' => 'donations-year-nav-url-changed',
            'file' => 'donations.php',
            'search' => 'echo year_nav($year, fn($y) => "donations?year=$y");',
            'replace' => 'echo year_nav($year, fn($y) => "index.php?action=donations&year=$y");',
            'rationale' => 'The donations page keeps its year links (donations?year=<year>).',
        ],
        [
            'id' => 'donations-year-nav-selected-changed',
            'file' => 'donations.php',
            'search' => 'echo year_nav($year, fn($y) => "donations?year=$y");',
            'replace' => 'echo year_nav(2024, fn($y) => "donations?year=$y");',
            'rationale' => 'The donations page marks the year it shows (the current year for years out of range).',
        ],
    ],
];
