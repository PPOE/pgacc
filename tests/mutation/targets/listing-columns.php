<?php

/*
 * Ledger listing column preferences in closed.php: listing_column_hidden(),
 * which replaced the direct $user_prefs_hide[$n] reads (undefined offsets for
 * columns without a stored preference, KD-PREFS-HIDE), and emptytag(), whose
 * one-argument tag() call threw ArgumentCountError on PHP 7.1+ for a hidden
 * column. Tested with tests/http-listing-prefs.php only. See
 * tests/mutation/README.md for the format.
 */
$hidden = "if (listing_column_hidden(\$tabc++)) {\n        echo emptytag(\"td\");\n    } else {";
$shown = "if (!listing_column_hidden(\$tabc++)) {\n        echo emptytag(\"td\");\n    } else {";
// The same inside the type and lo query loops (one level deeper).
$hiddenLoop = "if (listing_column_hidden(\$tabc++)) {\n            echo emptytag(\"td\");\n        } else {";
$shownLoop = "if (!listing_column_hidden(\$tabc++)) {\n            echo emptytag(\"td\");\n        } else {";

$mutations = [
    [
        'id' => 'helper-isset-removed',
        'search' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;',
        'replace' => 'return $user_prefs_hide[$col] == 1;',
        'rationale' => 'Columns without a stored preference must not read an undefined offset.',
    ],
    [
        'id' => 'helper-and-to-or',
        'search' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;',
        'replace' => 'return isset($user_prefs_hide[$col]) || $user_prefs_hide[$col] == 1;',
        'rationale' => 'Both the key check and the value check are needed.',
    ],
    [
        'id' => 'helper-value-check-removed',
        'search' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;',
        'replace' => 'return isset($user_prefs_hide[$col]);',
        'rationale' => '?hide=-N stores 0 for the request: that column is shown.',
    ],
    [
        'id' => 'helper-value-inverted',
        'search' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;',
        'replace' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] != 1;',
        'rationale' => 'Only a stored 1 hides a column.',
    ],
    [
        'id' => 'helper-never-hidden',
        'search' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;',
        'replace' => 'return false;',
        'rationale' => 'Stored preferences must hide columns.',
    ],
    [
        'id' => 'emptytag-original-one-argument-call',
        'search' => "        echo tag(\$tag, '');",
        'replace' => '        echo tag($tag);',
        'rationale' => 'The pre-fix call: ArgumentCountError on PHP 7.1+, warning and notice on PHP 5.6.',
    ],
    [
        'id' => 'emptytag-text-not-empty',
        'search' => "        echo tag(\$tag, '');",
        'replace' => "        echo tag(\$tag, ' ');",
        'rationale' => 'A hidden cell is an empty <td></td>.',
    ],
    [
        'id' => 'emptytag-no-output',
        'search' => "        echo tag(\$tag, '');",
        'replace' => '        true;',
        'rationale' => 'A hidden column keeps its (empty) cell in HTML rows.',
    ],
    [
        'id' => 'emptytag-csv-guard-inverted',
        'search' => "    global \$make_csv;\n    if (!\$make_csv) {\n        echo tag(\$tag, '');",
        'replace' => "    global \$make_csv;\n    if (\$make_csv) {\n        echo tag(\$tag, '');",
        'rationale' => 'In CSV a hidden column has no field; in HTML it has an empty cell.',
    ],
    [
        'id' => 'gentab-csv-check-inverted',
        'search' => "        if (listing_column_hidden(\$tabc)) {",
        'replace' => "        if (!listing_column_hidden(\$tabc)) {",
        'rationale' => 'The CSV header omits exactly the hidden columns.',
    ],
    [
        'id' => 'gentab-csv-original-read',
        'search' => "        if (listing_column_hidden(\$tabc)) {",
        'replace' => "        if (\$user_prefs_hide[\$tabc] == 1) {",
        'rationale' => 'The pre-fix CSV header read: undefined offsets for shown columns.',
    ],
    [
        'id' => 'gentab-html-check-inverted',
        'search' => "    }\n    if (listing_column_hidden(\$tabc)) {\n        return tag(\"td\", listing_column_form(\$action, -\$tabc, 'add'));",
        'replace' => "    }\n    if (!listing_column_hidden(\$tabc)) {\n        return tag(\"td\", listing_column_form(\$action, -\$tabc, 'add'));",
        'rationale' => 'The HTML header shows the add icon exactly for hidden columns.',
    ],
    [
        'id' => 'filter-counter-not-incremented',
        'search' => "        global \$user_prefs_hide;\n        if (!listing_column_hidden(\$tabc++)) {\n            echo '<input type=\"text\" name=\"filter_id\" value=\"' . (isset(\$_GET['filter_id']) ? html_escape(\$_GET['filter_id']) : '') . '\" size=\"1\" />';",
        'replace' => "        global \$user_prefs_hide;\n        if (!listing_column_hidden(\$tabc)) {\n            echo '<input type=\"text\" name=\"filter_id\" value=\"' . (isset(\$_GET['filter_id']) ? html_escape(\$_GET['filter_id']) : '') . '\" size=\"1\" />';",
        'rationale' => 'Each filter cell reads its own column preference.',
    ],
    [
        'id' => 'row-counter-not-incremented',
        'search' => $hidden . "\n        echo tag(\"td\", \$line[\"id\"]);",
        'replace' => "if (listing_column_hidden(\$tabc)) {\n        echo emptytag(\"td\");\n    } else {\n        echo tag(\"td\", \$line[\"id\"]);",
        'rationale' => 'Each row cell reads its own column preference.',
    ],
];

// Filter row: one inverted check per column.
$filters = [
    1 => '<input type="text" name="filter_id"',
    2 => '<input type="text" name="filter_bid"',
    3 => '<input type="text" name="filter_date"',
    4 => '<input type="text" name="filter_type"',
    5 => '<input type="text" name="filter_lo"',
    6 => '<input type="text" name="filter_member_id"',
    7 => '<select type="text" name="filter_gk"',
    8 => '<input type="text" name="filter_k"',
    9 => '<input type="text" name="filter_vk"',
    10 => '<input type="text" name="filter_amount"',
    11 => '<input type="text" name="filter_text"',
    12 => '<input type="text" name="filter_comment"',
    13 => '<input type="checkbox" name="filter_comm"',
    14 => '<input type="text" name="filter_ack"',
    15 => '<input type="text" name="filter_ack_old"',
    16 => '<input type="checkbox" name="filter_refund"',
    17 => '<input type="checkbox" name="filter_bel"',
    18 => '<input type="text" name="filter_name"',
];
foreach ($filters as $col => $input) {
    $mutations[] = [
        'id' => "filter-$col-check-inverted",
        'search' => "if (!listing_column_hidden(\$tabc++)) {\n            echo '$input",
        'replace' => "if (listing_column_hidden(\$tabc++)) {\n            echo '$input",
        'rationale' => "The filter input of column $col is shown exactly when the column is.",
    ];
}

// Voucher rows: one inverted check per column. Columns 4 and 5 print the same
// statement inside their type/lo query loops, so they need a region.
$typeLoop = ['start' => '$query2 = "SELECT name FROM type', 'end' => '$query2 = "SELECT name FROM lo'];
$loLoop = ['start' => '$query2 = "SELECT name FROM lo', 'end' => '? $line["member_id"] : \'Nein\');'];
$cells = [
    1 => ["\n        if (\$make_csv) {\n            echo tag(\"td\", \$line[\"voucher_id\"]);", null],
    2 => ["\n        echo tag(\"td\", \$line[\"id\"]);", null],
    3 => ["\n        echo tag(\"td\", format_date(", null],
    4 => ["\n            echo tag(\"td\", listing_text(\$line2[\"name\"]));", $typeLoop],
    5 => ["\n            echo tag(\"td\", listing_text(\$line2[\"name\"]));", $loLoop],
    6 => ["\n        if (\$make_csv) {\n            echo tag(\"td\", \$line[\"member\"]", null],
    7 => ["\n        echo tag(\"td\", listing_text(\$line[\"contra_account\"]));", null],
    8 => ["\n        echo tag(\"td\", listing_text(\$line[\"account\"]));", null],
    9 => ["\n        echo tag(\"td\", listing_text(\$line[\"vaccount\"]));", null],
    10 => ["\n        echo tag(\"td\", sprintf(", null],
    11 => ["\n        echo tag(\"td\", listing_text(\$line[\"comment\"]));", null],
    12 => ["\n        echo tag(\"td\", listing_text(\$line[\"commentgf\"]));", null],
    13 => ["\n        echo tag(\"td\", \$line[\"committed\"]", null],
    14 => ["\n        echo tag(\"td\", listing_text(\$line[\"ack1\"] . ", null],
    15 => ["\n        echo tag(\"td\", \"<del>\"", null],
    16 => ["\n        echo tag(\"td\", \$line[\"refund\"]", null],
    17 => ["\n        echo tag(\"td\", \$line[\"file\"]", null],
    18 => ["\n        echo tag(\"td\", listing_text(\$line[\"name\"] . ", null],
];
foreach ($cells as $col => $cell) {
    $mutation = [
        'id' => "row-$col-check-inverted",
        'search' => ($cell[1] === null ? $hidden : $hiddenLoop) . $cell[0],
        'replace' => ($cell[1] === null ? $shown : $shownLoop) . $cell[0],
        'rationale' => "Row cell $col is empty exactly when the column is hidden (in CSV: omitted).",
    ];
    if ($cell[1] !== null) {
        $mutation['region'] = $cell[1];
    }
    $mutations[] = $mutation;
}

return [
    'description' => 'closed.php listing column preferences (HTTP ledger with hidden columns)',
    'file' => 'closed.php',
    'tests' => [
        ['type' => 'http', 'script' => 'tests/http-listing-prefs.php'],
    ],
    'timeout' => 90,
    'mutations' => $mutations,
];
