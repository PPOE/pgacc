<?php

/*
 * The booking progress table in the notice box (percent_of_bookings() in
 * functions.php): columns in workflow order, the stacked bar whose segment
 * widths repeat the rounded percentages, and the year selection (the current
 * and the previous year, at least the two most recent years; older years in a
 * <details> table). See tests/mutation/README.md for the format.
 */
return [
    'description' => 'functions.php percent_of_bookings() progress table, bar and year selection',
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
            'search' => '    if ($rows === []) {' . "\n" . '        return "";',
            'replace' => '    if (false) {' . "\n" . '        return "";',
            'rationale' => 'Without bookings an empty table is shown.',
        ],
        [
            'id' => 'years-ascending',
            'search' => '    $rows = array_reverse($rows, true);',
            'replace' => '    $rows = $rows;',
            'rationale' => 'The years are listed oldest first, so the oldest years are shown.',
        ],
        [
            'id' => 'shown-current-year-only',
            'search' => '        if ($year >= $endYear - 2) {',
            'replace' => '        if ($year >= $endYear - 1) {',
            'rationale' => 'The previous year is hidden when the next year has bookings.',
        ],
        [
            'id' => 'shown-three-years',
            'search' => '        if ($year >= $endYear - 2) {',
            'replace' => '        if ($year >= $endYear - 3) {',
            'rationale' => 'The year before the previous one is shown too.',
        ],
        [
            'id' => 'no-fallback',
            'search' => '    $shown = max($shown, min(2, count($rows)));',
            'replace' => '    $shown = $shown;',
            'rationale' => 'Without bookings in the current and previous year no year is shown.',
        ],
        [
            'id' => 'fallback-three-years',
            'search' => '    $shown = max($shown, min(2, count($rows)));',
            'replace' => '    $shown = max($shown, min(3, count($rows)));',
            'rationale' => 'The fallback shows three years instead of two.',
        ],
        [
            'id' => 'details-always',
            'search' => '    if ($older !== []) {',
            'replace' => '    if (true) {',
            'rationale' => 'An empty <details> is shown when there are no older years.',
        ],
        [
            'id' => 'range-single-year',
            'search' => '        if (count($years) > 1) {',
            'replace' => '        if (count($years) > 0) {',
            'rationale' => 'A single older year is shown as the range 2012–2012.',
        ],
        [
            'id' => 'range-reversed',
            'search' => '        $range = html_escape(end($years));',
            'replace' => '        $range = html_escape(reset($years));',
            'rationale' => 'The summary range starts with the newest older year.',
        ],
        [
            'id' => 'older-head-visible',
            'search' => "            . progress_table('Bearbeitungsstand der Buchungszeilen, weitere Jahre', ' class=\"older\"', \$older)",
            'replace' => "            . progress_table('Bearbeitungsstand der Buchungszeilen, weitere Jahre', '', \$older)",
            'rationale' => 'The older years\' table repeats its caption and head visibly.',
        ],
    ],
];
