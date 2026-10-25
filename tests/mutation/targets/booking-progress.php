<?php

/*
 * The booking progress table in the notice box (percent_of_bookings() in
 * functions.php): columns in workflow order and the stacked bar whose
 * segment widths repeat the rounded percentages. See tests/mutation/README.md
 * for the format.
 */
return [
    'description' => 'functions.php percent_of_bookings() progress table and bar',
    'file' => 'functions.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'testPercentOfBookings'],
    ],
    'mutations' => [
        [
            'id' => 'bar-swap-open-edited',
            'search' => '                . \'<span class="open" style="width: \' . html_escape("$dp%") . \'"></span>\'' . "\n"
                . '                . \'<span class="edited" style="width: \' . html_escape("$bp%") . \'"></span>\'',
            'replace' => '                . \'<span class="open" style="width: \' . html_escape("$bp%") . \'"></span>\'' . "\n"
                . '                . \'<span class="edited" style="width: \' . html_escape("$dp%") . \'"></span>\'',
            'rationale' => 'The unbearbeitet and bearbeitet segments get each other\'s widths.',
        ],
        [
            'id' => 'bar-drop-closed',
            'search' => '                . \'<span class="closed" style="width: \' . html_escape("$ap%") . \'"></span></span></td></tr>\';',
            'replace' => '                . \'</span></td></tr>\';',
            'rationale' => 'The abgeschlossen segment is missing from the bar.',
        ],
        [
            'id' => 'columns-swap-open-closed',
            'search' => '                . \'<td>\' . html_escape($d) . \' <span class="percent">(\' . html_escape("$dp %") . \')</span></td>\'',
            'replace' => '                . \'<td>\' . html_escape($a) . \' <span class="percent">(\' . html_escape("$ap %") . \')</span></td>\'',
            'rationale' => 'The unbearbeitet column shows the abgeschlossen count.',
        ],
        [
            'id' => 'bar-not-hidden',
            'search' => '                . \'<td class="bar"><span aria-hidden="true" title="\'',
            'replace' => '                . \'<td class="bar"><span title="\'',
            'rationale' => 'Screen readers no longer skip the decorative bar.',
        ],
        [
            'id' => 'empty-table',
            'search' => '    if ($text === "") {' . "\n" . '        return "";',
            'replace' => '    if (false) {' . "\n" . '        return "";',
            'rationale' => 'Without bookings an empty table is shown.',
        ],
    ],
];
