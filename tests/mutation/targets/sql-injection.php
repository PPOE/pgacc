<?php
/*
 * Request values used in SQL: the voucher ID of the edit actions (approval,
 * finalization, approval reset, revision, receipt upload) accepts digits
 * only, old approval names and the old-approval filter are escaped, and the
 * voucher date must match its pattern as a whole. Tested with
 * tests/write-workflows.php. See tests/mutation/README.md for the format.
 */
$finalize = array('start' => 'function page_edit_finalize($rights)', 'end' => 'function page_edit_merge($rights)');
$dropAcks = array('start' => 'function page_edit_drop_acks($rights)', 'end' => 'function page_edit_ack($rights)');
$ack = array('start' => 'function page_edit_ack($rights)', 'end' => 'function page_edit_save($rights)');
$save = array('start' => 'function page_edit_save($rights)', 'end' => 'function page_edit_buchung(');
$check = 'if (!is_string($id) || preg_match(\'/^\d+$/D\', $id) != 1)';

$mutations = array();
foreach (array('finalize' => $finalize, 'drop-acks' => $dropAcks, 'ack' => $ack, 'save' => $save) as $name => $region) {
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
    'search' => '"\'" . pg_escape_string($ack1_old) . "\'"',
    'replace' => '"\'" . $ack1_old . "\'"',
    'rationale' => 'Removed escape: a posted first old approval is inserted as SQL.',
);
$mutations[] = array(
    'id' => 'old-ack2-unescaped',
    'file' => 'new.php',
    'search' => '"\'" . pg_escape_string($ack2_old) . "\'"',
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
    'id' => 'old-ack-filter-unescaped',
    'file' => 'functions.php',
    'search' => "LIKE '%\" . pg_escape_string(isset(\$_GET['filter_ack']) ? \$_GET['filter_ack'] : '') . \"%' OR ack2_old",
    'replace' => "LIKE '%\" . (isset(\$_GET['filter_ack']) ? \$_GET['filter_ack'] : '') . \"%' OR ack2_old",
    'rationale' => 'Removed escape: the old-approval filter inserts filter_ack, which is not '
        . 'checked against a pattern there, as SQL.',
);
$mutations[] = array(
    'id' => 'old-ack2-filter-unescaped',
    'file' => 'functions.php',
    'search' => "ack2_old LIKE '%\" . pg_escape_string(isset(\$_GET['filter_ack']) ? \$_GET['filter_ack'] : '')",
    'replace' => "ack2_old LIKE '%\" . (isset(\$_GET['filter_ack']) ? \$_GET['filter_ack'] : '')",
    'rationale' => 'Removed escape in the second column of the old-approval filter.',
);
$mutations[] = array(
    'id' => 'old-ack-filter-unset',
    'file' => 'functions.php',
    'search' => "ack1_old LIKE '%\" . pg_escape_string(isset(\$_GET['filter_ack']) ? \$_GET['filter_ack'] : '')",
    'replace' => "ack1_old LIKE '%\" . pg_escape_string(\$_GET['filter_ack'])",
    'rationale' => 'Without filter_ack the old-approval filter matches every old approval, without '
        . 'an undefined-index diagnostic.',
);

return array(
    'description' => 'Request values in the SQL of the voucher edit actions, the voucher form and '
        . 'the listing filter (write-workflow tests)',
    'file' => 'edit.php',
    'tests' => array(
        array('type' => 'http', 'script' => 'tests/write-workflows.php'),
    ),
    'timeout' => 240,
    'mutations' => $mutations,
);
