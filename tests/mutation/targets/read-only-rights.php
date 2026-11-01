<?php

/*
 * Read-only bgf ("Rbgf", accounts.php): write_rights() and the checks of the
 * write actions (voucher creation, receipt upload, revision, approval,
 * password and user changes, receipt import) and of the write controls in
 * the menu and the voucher form. Finalization, approval reset and merge need
 * the exact bgf or root right, which Rbgf is not (rights-checks).
 * Combined with an organization ("Rbgf,2") the user writes only there.
 * Tested with tests/write-workflows.php (users synthetic-read-only and
 * synthetic-read-only-two). Not mutated: the check of the bank import page,
 * whose removal would call the external membership system (getusers()),
 * which tests must never contact. See tests/mutation/README.md for the
 * format.
 */
$check = "if (\$rights === false) {\n        echo '<div class=\"slot_error\" id=\"slot_error\">FEHLER: Keine Zugriffsrechte.";
$checkHelper = "if (\$rights === false) {\n        save_refused('Keine Zugriffsrechte.');";
$checkAck = "if (\$rights === false) {\n        edit_ack_refused(";
$scoped = '$rights = write_rights($rights);';
$unscoped = '$rights = write_rights($rights) === false ? false : $rights;';
$mutations = [];
$regions = [
    'ack' => ['edit.php', 'function page_edit_ack($rights)', 'function page_edit_save($rights)'],
    'save' => ['edit.php', 'function page_edit_save($rights)', 'function page_edit_buchung('],
    'new-save' => ['new.php', 'function page_new_save($rights)', 'function page_new_file($rights)'],
    'upload' => ['new.php', 'function page_new_file($rights)', 'function page_edit_form('],
];
foreach ($regions as $name => $region) {
    $mutations[] = [
        'id' => "$name-read-only-allowed",
        'file' => $region[0],
        'region' => ['start' => $region[1], 'end' => $region[2]],
        'search' => in_array($name, ['save', 'new-save'], true) ? $checkHelper : ($name === 'ack' ? $checkAck : $check),
        'replace' => str_replace('if ($rights === false)', 'if (false)', in_array($name, ['save', 'new-save'], true) ? $checkHelper : ($name === 'ack' ? $checkAck : $check)),
        'rationale' => 'Fail-open: read-only bgf changes data.',
    ];
    if ($name === 'new-save') {
        $mutations[count($mutations) - 1]['equivalent'] = ['reason' => 'Rbgf alone has no organization '
            . 'right, so the organization check of the save refuses every voucher of it as well.'];
    }
}
foreach (['ack', 'new-save'] as $name) {
    $mutations[] = [
        'id' => "$name-read-only-rights-kept",
        'file' => $regions[$name][0],
        'region' => ['start' => $regions[$name][1], 'end' => $regions[$name][2]],
        'search' => $scoped,
        'replace' => $unscoped,
        'rationale' => 'Wrong organization: with "Rbgf,2" the write uses the read rights (all '
            . 'organizations, or the default organization 10) instead of organization 2.',
    ];
}
$mutations[] = [
    'id' => 'accounts-read-only-allowed',
    'file' => 'accounts.php',
    'search' => 'if ($rights === false || $id != $ownId',
    'replace' => 'if ($id != $ownId',
    'rationale' => 'Fail-open: read-only bgf changes its password.',
];
$mutations[] = [
    'id' => 'import2-read-only-allowed',
    'file' => 'public/index.php',
    'region' => ['start' => 'elseif ($page == "import2")', 'end' => 'elseif ($page == "statistics")'],
    'search' => 'if (write_rights($rights) === false)',
    'replace' => 'if (false)',
    'rationale' => 'Fail-open: read-only bgf opens the receipt import.',
];
$mutations[] = [
    'id' => 'write-rights-keeps-read-only',
    'file' => 'functions.php',
    'search' => "        if (\$right !== 'Rbgf' && \$right !== '')",
    'replace' => "        if (\$right !== '')",
    'rationale' => 'Rbgf does not count as a write right.',
];
$mutations[] = [
    'id' => 'write-rights-ignores-read-only',
    'file' => 'functions.php',
    'search' => "    if (!in_array('Rbgf', \$tokens, true)) {",
    'replace' => "    if (true) {",
    'rationale' => 'Fail-open: Rbgf counts as a write right.',
];
$mutations[] = [
    'id' => 'write-rights-empty-not-refused',
    'file' => 'functions.php',
    'search' => "    if (count(\$rights_w) == 0) {\n        return false;\n    }",
    'replace' => "    if (count(\$rights_w) == 0) {\n        return '';\n    }",
    'rationale' => 'Fail-open: without other rights read-only bgf would write as a user without '
        . 'rights (voucher creation in organization 10).',
];
$mutations[] = [
    'id' => 'menu-write-links-for-read-only',
    'file' => 'functions.php',
    'search' => "        if (write_rights(\$rights) !== false) {",
    'replace' => "        if (true) {",
    'rationale' => 'The menu offers no write pages to read-only bgf.',
];
$mutations[] = [
    'id' => 'form-buttons-for-read-only',
    'file' => 'new.php',
    'search' => "if (write_rights(\$rights) !== false && (has_right(\$rights, 'bgf')",
    'replace' => "if (true && (has_right(\$rights, 'bgf')",
    'rationale' => 'The voucher form offers no save, approval or reset buttons to read-only bgf.',
    'equivalent' => ['reason' => 'Rbgf alone is neither bgf nor an organization right, so the '
        . 'right check that follows hides the buttons as well.'],
];

return [
    'description' => 'Read-only bgf (Rbgf): refused writes and hidden write controls '
        . '(write-workflow and PHPUnit tests)',
    'file' => 'edit.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'testWriteRights|testAccHeaderOffersNoWritePagesToReadOnlyRights'],
        ['type' => 'http', 'script' => 'tests/write-workflows.php'],
    ],
    'timeout' => 240,
    'mutations' => $mutations,
];
