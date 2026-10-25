<?php

/*
 * Issue #1: amounts with two decimals, also whole euros, and tabular digits
 * in the right-aligned table cells. report.php Anlage A printed the donation
 * sum unformatted and its two fixed columns as "0&nbsp;€"; style.css gives
 * the td rule tabular-nums. Tested with tests/http-smoke.php (2024 report,
 * stylesheet read from the mutated copy). The vreport.php chart tooltip
 * (.toFixed(2)) is client-side JavaScript that no test executes. See
 * tests/mutation/README.md for the format.
 */
return [
    'description' => 'two-decimal amounts in report.php Anlage A, tabular digits in style.css (HTTP report)',
    'file' => 'report.php',
    'tests' => [
        ['type' => 'http', 'script' => 'tests/http-smoke.php'],
    ],
    'timeout' => 60,
    'mutations' => [
        [
            'id' => 'anlage-sum-unformatted',
            'search' => '<td>" . sprintf("%1.2f", $amount) . "&nbsp;€</td><td>0.00&nbsp;€',
            'replace' => '<td>$amount&nbsp;€</td><td>0.00&nbsp;€',
            'rationale' => 'The pre-fix donation sum: a whole-euro (or zero) sum loses its cents.',
        ],
        [
            'id' => 'anlage-sum-no-decimals',
            'search' => 'sprintf("%1.2f", $amount)',
            'replace' => 'sprintf("%1.0f", $amount)',
            'rationale' => 'A format without cent places.',
        ],
        [
            'id' => 'anlage-related-zero-without-cents',
            'search' => '<td>0.00&nbsp;€</td><td>0.00&nbsp;€</td></tr>',
            'replace' => '<td>0&nbsp;€</td><td>0.00&nbsp;€</td></tr>',
            'rationale' => 'The pre-fix "related organizations" column.',
        ],
        [
            'id' => 'anlage-candidates-zero-without-cents',
            'search' => '<td>0.00&nbsp;€</td></tr>',
            'replace' => '<td>0&nbsp;€</td></tr>',
            'rationale' => 'The pre-fix "representatives and candidates" column.',
        ],
        [
            'id' => 'td-tabular-nums-removed',
            'file' => 'style.css',
            'search' => "  font-variant-numeric: tabular-nums;\n",
            'replace' => "\n",
            'rationale' => 'Proportional digits: amounts no longer line up below each other.',
        ],
        [
            'id' => 'td-left-aligned',
            'file' => 'style.css',
            'search' => "  text-align: right;\n  font-variant-numeric: tabular-nums;",
            'replace' => "  text-align: left;\n  font-variant-numeric: tabular-nums;",
            'rationale' => 'Left-aligned amount cells: digits of different lengths do not line up.',
        ],
    ],
];
