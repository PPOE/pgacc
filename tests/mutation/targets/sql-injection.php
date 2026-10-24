<?php
/*
 * Request values used in SQL: the voucher ID of the edit actions (approval,
 * approval reset, revision, receipt upload) accepts digits
 * only, old approval names are escaped, the old-approval filter uses its own
 * (pattern-checked) value, and the
 * voucher date must match its pattern as a whole. Tested with
 * tests/write-workflows.php. See tests/mutation/README.md for the format.
 */
$dropAcks = array('start' => 'function page_edit_drop_acks($rights)', 'end' => 'function page_edit_ack($rights)');
$ack = array('start' => 'function page_edit_ack($rights)', 'end' => 'function page_edit_save($rights)');
$save = array('start' => 'function page_edit_save($rights)', 'end' => 'function page_edit_buchung(');
$check = 'if (!is_string($id) || preg_match(\'/^\d+$/D\', $id) != 1)';

$mutations = array();
foreach (array('drop-acks' => $dropAcks, 'ack' => $ack, 'save' => $save) as $name => $region) {
    $mutations[] = array(
        'id' => "$name-id-unchecked",
        'region' => $region,
        'search' => $check,
        'replace' => 'if ($id === -1)',
        'rationale' => 'Fail-open: any posted voucher ID reaches the SQL (as before the fix).',
    );
    $mutations[] = array(
        'id' => "$name-id-trailing-newline",
        'region' => $region,
        'search' => $check,
        'replace' => 'if (!is_string($id) || preg_match(\'/^\d+$/\', $id) != 1)',
        'rationale' => 'Without D, "$" also matches before a final newline, which then reaches the '
            . 'SQL and the Location header.',
    );
}
$mutations[] = array(
    'id' => 'ack-id-array',
    'region' => $ack,
    'search' => $check,
    'replace' => 'if (preg_match(\'/^\d+$/D\', $id) != 1)',
    'rationale' => 'An array as voucher ID is refused without a diagnostic.',
);
$mutations[] = array(
    'id' => 'upload-id-unchecked',
    'file' => 'new.php',
    'search' => $check,
    'replace' => 'if ($id === -1)',
    'rationale' => 'Fail-open: the receipt upload uses any posted voucher ID in SQL.',
);
$mutations[] = array(
    'id' => 'upload-id-trailing-newline',
    'file' => 'new.php',
    'search' => $check,
    'replace' => 'if (!is_string($id) || preg_match(\'/^\d+$/\', $id) != 1)',
    'rationale' => 'The receipt upload accepts a voucher ID with a final newline.',
);
$mutations[] = array(
    'id' => 'old-ack1-unescaped',
    'file' => 'new.php',
    'search' => '"\'" . pg_escape_string($dbconn, $ack1_old) . "\'"',
    'replace' => '"\'" . $ack1_old . "\'"',
    'rationale' => 'Removed escape: a posted first old approval is inserted as SQL.',
);
$mutations[] = array(
    'id' => 'old-ack2-unescaped',
    'file' => 'new.php',
    'search' => '"\'" . pg_escape_string($dbconn, $ack2_old) . "\'"',
    'replace' => '"\'" . $ack2_old . "\'"',
    'rationale' => 'Removed escape: a posted second old approval is inserted as SQL.',
);
$mutations[] = array(
    'id' => 'date-pattern-ungrouped',
    'file' => 'new.php',
    'search' => "'/^(((\\d|(0|1|2)\\d|3(0|1))\\.(\\d|0\\d|1(0|1|2))\\.20\\d\\d)|(20\\d\\d-(\\d|0\\d|1(0|1|2))-(\\d|(0|1|2)\\d|3(0|1))))$/'",
    'replace' => "'/^((\\d|(0|1|2)\\d|3(0|1))\\.(\\d|0\\d|1(0|1|2))\\.20\\d\\d)|(20\\d\\d-(\\d|0\\d|1(0|1|2))-(\\d|(0|1|2)\\d|3(0|1)))$/'",
    'rationale' => 'The pre-fix pattern anchors each alternative at one end only, so text before or '
        . 'after a date reaches the SQL.',
);
$mutations[] = array(
    'id' => 'old-ack-filter-wrong-value',
    'file' => 'functions.php',
    'search' => "ack1_old LIKE '%\" . \$_GET['filter_ack_old'] . \"%'",
    'replace' => "ack1_old LIKE '%\" . \$_GET['filter_ack'] . \"%'",
    'rationale' => 'The old-approval filter uses its own value, which its pattern checks (before the '
        . 'fix it used filter_ack, which is not checked there).',
);
$mutations[] = array(
    'id' => 'old-ack2-filter-wrong-value',
    'file' => 'functions.php',
    'search' => "ack2_old LIKE '%\" . \$_GET['filter_ack_old'] . \"%'",
    'replace' => "ack2_old LIKE '%\" . \$_GET['filter_ack'] . \"%'",
    'rationale' => 'Both columns of the old-approval filter use its own value.',
);

$view = array('start' => 'function page_edit($rights)', 'end' => '$hint = 0;');
foreach (array('$id = intval($_GET["id"]);' => 'view-get-id-raw', '$id = intval($_POST["id"]);' => 'view-post-id-raw',
    '$bid = intval($_GET["bid"]);' => 'view-get-bid-raw', '$bid = intval($_POST["bid"]);' => 'view-post-bid-raw') as $search => $id) {
    $mutations[] = array(
        'id' => $id,
        'region' => $view,
        'search' => $search,
        'replace' => preg_replace('/intval\((.*)\);$/', '$1;', $search),
        'rationale' => 'The voucher page echoes request IDs only as numbers (reflected markup otherwise).',
    );
}

$mutations[] = array(
    'id' => 'member-name-unescaped',
    'file' => 'new.php',
    'search' => "    \$voucher['name'] = pg_escape_string(\$dbconn, \$line['name']);",
    'replace' => "    \$voucher['name'] = \$line['name'];",
    'rationale' => 'Removed escape: a member name from the membership list is inserted as SQL.',
);

$mutations[] = array(
    'id' => 'member-id-raw',
    'file' => 'getusers.php',
    'search' => '  $id = intval($values[1]);',
    'replace' => '  $id = $values[1];',
    'rationale' => 'A member id from the membership system is inserted as a number, not as SQL.',
);

return array(
    'description' => 'Request values in the SQL of the voucher edit actions, the voucher form and '
        . 'the listing filter (write-workflow tests)',
    'file' => 'edit.php',
    'tests' => array(
        array('type' => 'phpunit', 'filter' => 'testMemberIdsAreNumbers', 'file' => 'tests/unit/GetUsersTest.php'),
        array('type' => 'http', 'script' => 'tests/write-workflows.php'),
    ),
    'timeout' => 240,
    'mutations' => $mutations,
);
