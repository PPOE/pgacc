<?php
/*
 * Ledger listing column preferences in closed.php: listing_column_hidden(),
 * which replaced the direct $user_prefs_hide[$n] reads (undefined offsets for
 * columns without a stored preference, KD-PREFS-HIDE), and emptytag(), whose
 * one-argument tag() call threw ArgumentCountError on PHP 7.1+ for a hidden
 * column. Tested with tests/http-listing-prefs.php only. See
 * tests/mutation/README.md for the format.
 */
$hidden = "if (listing_column_hidden(\$tabc++))\n  echo emptytag(\"td\");\nelse\n";
$shown = "if (!listing_column_hidden(\$tabc++))\n  echo emptytag(\"td\");\nelse\n";

$mutations = array(
    array(
        'id' => 'helper-isset-removed',
        'search' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;',
        'replace' => 'return $user_prefs_hide[$col] == 1;',
        'rationale' => 'Columns without a stored preference must not read an undefined offset.',
    ),
    array(
        'id' => 'helper-and-to-or',
        'search' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;',
        'replace' => 'return isset($user_prefs_hide[$col]) || $user_prefs_hide[$col] == 1;',
        'rationale' => 'Both the key check and the value check are needed.',
    ),
    array(
        'id' => 'helper-value-check-removed',
        'search' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;',
        'replace' => 'return isset($user_prefs_hide[$col]);',
        'rationale' => '?hide=-N stores 0 for the request: that column is shown.',
    ),
    array(
        'id' => 'helper-value-inverted',
        'search' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;',
        'replace' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] != 1;',
        'rationale' => 'Only a stored 1 hides a column.',
    ),
    array(
        'id' => 'helper-never-hidden',
        'search' => 'return isset($user_prefs_hide[$col]) && $user_prefs_hide[$col] == 1;',
        'replace' => 'return false;',
        'rationale' => 'Stored preferences must hide columns.',
    ),
    array(
        'id' => 'emptytag-original-one-argument-call',
        'search' => "    echo tag(\$tag, '');",
        'replace' => '    echo tag($tag);',
        'rationale' => 'The pre-fix call: ArgumentCountError on PHP 7.1+, warning and notice on PHP 5.6.',
    ),
    array(
        'id' => 'emptytag-text-not-empty',
        'search' => "    echo tag(\$tag, '');",
        'replace' => "    echo tag(\$tag, ' ');",
        'rationale' => 'A hidden cell is an empty <td></td>.',
    ),
    array(
        'id' => 'emptytag-no-output',
        'search' => "    echo tag(\$tag, '');",
        'replace' => '    true;',
        'rationale' => 'A hidden column keeps its (empty) cell in HTML rows.',
    ),
    array(
        'id' => 'emptytag-csv-guard-inverted',
        'search' => "  if (!\$make_csv)\n    echo tag(\$tag, '');",
        'replace' => "  if (\$make_csv)\n    echo tag(\$tag, '');",
        'rationale' => 'In CSV a hidden column has no field; in HTML it has an empty cell.',
    ),
    array(
        'id' => 'gentab-csv-check-inverted',
        'search' => "    if (listing_column_hidden(\$tabc))\n      return \"\";",
        'replace' => "    if (!listing_column_hidden(\$tabc))\n      return \"\";",
        'rationale' => 'The CSV header omits exactly the hidden columns.',
    ),
    array(
        'id' => 'gentab-csv-original-read',
        'search' => "    if (listing_column_hidden(\$tabc))\n      return \"\";",
        'replace' => "    if (\$user_prefs_hide[\$tabc] == 1)\n      return \"\";",
        'rationale' => 'The pre-fix CSV header read: undefined offsets for shown columns.',
    ),
    array(
        'id' => 'gentab-html-check-inverted',
        'search' => "  if (listing_column_hidden(\$tabc))\n    return tag(\"td\",'<a href",
        'replace' => "  if (!listing_column_hidden(\$tabc))\n    return tag(\"td\",'<a href",
        'rationale' => 'The HTML header shows the add icon exactly for hidden columns.',
    ),
    array(
        'id' => 'filter-counter-not-incremented',
        'search' => "if (!listing_column_hidden(\$tabc++))\n  echo '<input type=\"text\" name=\"filter_id\"",
        'replace' => "if (!listing_column_hidden(\$tabc))\n  echo '<input type=\"text\" name=\"filter_id\"",
        'rationale' => 'Each filter cell reads its own column preference.',
    ),
    array(
        'id' => 'row-counter-not-incremented',
        'search' => $hidden . "  echo tag(\"td\", \$line[\"id\"]);",
        'replace' => "if (listing_column_hidden(\$tabc))\n  echo emptytag(\"td\");\nelse\n  echo tag(\"td\", \$line[\"id\"]);",
        'rationale' => 'Each row cell reads its own column preference.',
    ),
);

// Filter row: one inverted check per column.
$filters = array(
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
);
foreach ($filters as $col => $input) {
    $mutations[] = array(
        'id' => "filter-$col-check-inverted",
        'search' => "if (!listing_column_hidden(\$tabc++))\n  echo '$input",
        'replace' => "if (listing_column_hidden(\$tabc++))\n  echo '$input",
        'rationale' => "The filter input of column $col is shown exactly when the column is.",
    );
}

// Voucher rows: one inverted check per column. Columns 4 and 5 print the same
// statement inside their type/lo query loops, so they need a region.
$typeLoop = array('start' => '$query2 = "SELECT name FROM type', 'end' => '$query2 = "SELECT name FROM lo');
$loLoop = array('start' => '$query2 = "SELECT name FROM lo', 'end' => '? $line["member_id"] : \'Nein\');');
$cells = array(
    1 => array("{\n  if (\$make_csv)\n    echo tag(\"td\", \$line[\"voucher_id\"]);", null),
    2 => array("  echo tag(\"td\", \$line[\"id\"]);", null),
    3 => array("  echo tag(\"td\", format_date(", null),
    4 => array("  echo tag(\"td\", listing_text(\$line2[\"name\"]));", $typeLoop),
    5 => array("  echo tag(\"td\", listing_text(\$line2[\"name\"]));", $loLoop),
    6 => array("{\n  if (\$make_csv)\n    echo tag(\"td\", \$line[\"member\"]", null),
    7 => array("  echo tag(\"td\", listing_text(\$line[\"contra_account\"]));", null),
    8 => array("  echo tag(\"td\", listing_text(\$line[\"account\"]));", null),
    9 => array("  echo tag(\"td\", listing_text(\$line[\"vaccount\"]));", null),
    10 => array("  echo tag(\"td\", sprintf(", null),
    11 => array("  echo tag(\"td\", listing_text(\$line[\"comment\"]));", null),
    12 => array("  echo tag(\"td\", listing_text(\$line[\"commentgf\"]));", null),
    13 => array("  echo tag(\"td\", \$line[\"committed\"]", null),
    14 => array("  echo tag(\"td\", listing_text(\$line[\"ack1\"] . ", null),
    15 => array("  echo tag(\"td\", \"<del>\"", null),
    16 => array("  echo tag(\"td\", \$line[\"refund\"]", null),
    17 => array("  echo tag(\"td\", \$line[\"file\"]", null),
    18 => array("  echo tag(\"td\", listing_text(\$line[\"name\"] . ", null),
);
foreach ($cells as $col => $cell) {
    $mutation = array(
        'id' => "row-$col-check-inverted",
        'search' => $hidden . $cell[0],
        'replace' => $shown . $cell[0],
        'rationale' => "Row cell $col is empty exactly when the column is hidden (in CSV: omitted).",
    );
    if ($cell[1] !== null) {
        $mutation['region'] = $cell[1];
    }
    $mutations[] = $mutation;
}

return array(
    'description' => 'closed.php listing column preferences (HTTP ledger with hidden columns)',
    'file' => 'closed.php',
    'tests' => array(
        array('type' => 'http', 'script' => 'tests/http-listing-prefs.php'),
    ),
    'timeout' => 90,
    'mutations' => $mutations,
);
