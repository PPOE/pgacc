<?php

/*
 * Redirects answered before any output: the login redirect that index.php
 * sends visitors who are not logged in before the CSV byte order mark or
 * acc_header(), the early member search redirect, the output buffer that
 * index.php starts for edit and merge requests, and relocate() in edit.php
 * with the redirect targets of its callers. This is page code that only the
 * HTTP tests reach. See tests/mutation/README.md for the format.
 */
$early = ['start' => '// Before any output (the CSV byte order mark', 'end' => 'global $make_csv;'];
$search = ['start' => 'if ($page == "search")', 'end' => 'global $make_csv;'];
$relocate = ['start' => 'function relocate($loc)', 'end' => 'function page_edit_merge($rights)'];

$protectedPages = ['all', 'mb', 'open', 'merge', 'edit', 'new', 'closed', 'deleted', 'recover', 'accounts',
    'import', 'import2'];
$protectedList = '["' . implode('","', $protectedPages) . '"]';

// The form token check of index.php (between the login check and the
// member search).
$csrfBlock = "// Form tokens (CSRF): every POST request except the login form and the\n"
    . "// public captcha forms must carry the form token of its session\n"
    . "// (csrf_field()), checked before anything is written. Merge, recovery, the\n"
    . "// column preferences and logout write only on POST.\n"
    . "\$csrf_exempt = in_array(\$page, [\"login\",\"donations\",\"kdonations\"]);\n"
    . "if (request_is_post() && !\$csrf_exempt) {\n    checklogin('id', false);\n    if (!csrf_request_valid()) {\n"
    . "        acc_header(\$dbconn, \$page, date('Y'));\n"
    . "        echo '<div class=\"slot_error\" id=\"slot_error\">FEHLER: Ungültiges Formular-Token. Bitte die Seite neu laden.</div><br /><br /><br />';\n"
    . "        acc_footer();\n        pg_close(\$dbconn);\n        return;\n    }\n}\n";
// The early blocks and the CSV block that follows them, used to move the
// early blocks back after the byte order mark.
$earlyBlocks = "if (in_array(\$page, $protectedList) && checklogin('id') === null) {\n    pg_close(\$dbconn);\n"
    . "    return;\n}\n" . $csrfBlock . "if (\$page == \"search\") {\n    \$rights = checklogin('rights', false);\n"
    . "    page_search(\$rights);\n    pg_close(\$dbconn);\n    return;\n}\n";
$csvBlock = "global \$make_csv;\n\$make_csv = false;\n"
    . "if (isset(\$_GET[\"format\"]) && \$_GET[\"format\"] == 'csv' && in_array(\$_GET[\"action\"], [\"search\","
    . "\"all\",\"open\",\"transactions\",\"spendings\",\"closed\",\"deleted\"])) {\n"
    . "    header('Content-Encoding: UTF-8');\n    header('Content-type: text/csv; charset=UTF-8');\n"
    . "    header(\"Content-Disposition: attachment; filename=\$page.csv\");\n"
    . "    echo \"\\xEF\\xBB\\xBF\"; // UTF-8 BOM\n    \$make_csv = true;\n}\n";

$mutations = [
    [
        'id' => 'login-check-removed',
        'region' => $early,
        'search' => "&& checklogin('id') === null)",
        'replace' => '&& false)',
        'rationale' => 'Fail-open: visitors who are not logged in reach the page code (a POST to ?action=new '
            . 'creates a voucher, a revision adds a row) and the login redirect follows the page.',
    ],
    [
        'id' => 'login-check-inverted',
        'region' => $early,
        'search' => "&& checklogin('id') === null)",
        'replace' => "&& checklogin('id') !== null)",
        'rationale' => 'Logged-in users get the protected pages; only visitors are sent to the login page.',
    ],
    [
        'id' => 'login-redirect-continues',
        'region' => $early,
        'search' => "    pg_close(\$dbconn);\n    return;\n}\n// Form tokens (CSRF)",
        'replace' => "    pg_close(\$dbconn);\n    true;\n}\n// Form tokens (CSRF)",
        'rationale' => 'The login redirect ends the request: an empty body, no page code on a closed connection.',
    ],
    [
        'id' => 'login-redirect-after-csv-bom',
        'search' => $earlyBlocks . "\n" . $csvBlock,
        'replace' => $csvBlock . "\n" . $earlyBlocks,
        'rationale' => 'The early redirects precede the CSV headers and byte order mark (a CSV listing '
            . 'requested by a visitor gets an empty redirect body).',
    ],
    [
        'id' => 'search-block-removed',
        'region' => $search,
        'search' => 'if ($page == "search")',
        'replace' => 'if (false)',
        'rationale' => 'The member search redirects (302 to the filtered ledger) instead of rendering the report.',
    ],
    [
        'id' => 'search-continues',
        'region' => $search,
        'search' => "    pg_close(\$dbconn);\n    return;",
        'replace' => "    pg_close(\$dbconn);\n    true;",
        'rationale' => 'The search redirect ends the request (empty body).',
    ],
    [
        'id' => 'search-requires-login',
        'region' => $search,
        'search' => "checklogin('rights', false)",
        'replace' => "checklogin('rights')",
        'rationale' => 'Visitors who are not logged in get the search redirect, as before the fix.',
        'equivalent' => [
            'reason' => 'checklogin() then sends the login Location first, but page_search() replaces it with '
                . 'its own Location header (header() replaces by default), so the response is identical. '
                . 'This is how the search answered visitors before the fix.',
        ],
    ],
    [
        'id' => 'search-member-ignored',
        'file' => 'search.php',
        'search' => 'filter_member_id=$member',
        'replace' => 'filter_member_id=0',
        'rationale' => 'The search redirect filters the ledger by the requested member.',
    ],
    [
        'id' => 'edit-merge-buffer-removed',
        'search' => "    ob_start();",
        'replace' => "    true;",
        'rationale' => 'Without the buffer the redirects of relocate() follow the page output and fail '
            . 'without output buffering ("headers already sent").',
    ],
    [
        'id' => 'edit-buffer-missing',
        'search' => 'if ($page == "edit" || $page == "merge")',
        'replace' => 'if ($page == "merge")',
        'rationale' => 'Approval and revision redirects need the buffer.',
    ],
    [
        'id' => 'merge-buffer-missing',
        'search' => 'if ($page == "edit" || $page == "merge")',
        'replace' => 'if ($page == "edit")',
        'rationale' => 'The merge redirect needs the buffer.',
    ],
    [
        'id' => 'relocate-page-kept',
        'file' => 'edit.php',
        'region' => $relocate,
        'search' => '    ob_end_clean();',
        'replace' => '    true;',
        'rationale' => 'The buffered page is discarded: write redirects have an empty body.',
    ],
    [
        'id' => 'relocate-continues',
        'file' => 'edit.php',
        'region' => $relocate,
        'search' => '    exit;',
        'replace' => '    true;',
        'rationale' => 'relocate() ends the request; otherwise the footer queries the closed connection.',
    ],
    [
        'id' => 'relocate-redirect-removed',
        'file' => 'edit.php',
        'region' => $relocate,
        'search' => '    header("Location: " . $loc);',
        'replace' => '    true;',
        'rationale' => 'Write actions answer with a 302 to the voucher.',
    ],
    [
        'id' => 'merge-target',
        'file' => 'edit.php',
        'search' => 'relocate("index.php?action=edit&id=$bid1&hint=6");',
        'replace' => 'relocate("index.php?action=edit&id=$bid2&hint=6");',
        'rationale' => 'The merge redirects to the voucher that received the rows.',
    ],
    [
        'id' => 'approval-target',
        'file' => 'edit.php',
        'search' => 'relocate("index.php?action=edit&id=$id&hint=4");',
        'replace' => 'relocate("index.php?action=edit&id=$id&hint=5");',
        'rationale' => 'The approval redirect shows the approval hint.',
    ],
    [
        'id' => 'revision-target',
        'file' => 'edit.php',
        'search' => 'relocate("index.php?action=edit&id=$id&hint=5$warn");',
        'replace' => 'relocate("index.php?action=edit&id=$id&hint=4$warn");',
        'rationale' => 'The revision redirect shows the revision hint.',
    ],
    [
        'id' => 'revision-warn-dropped',
        'file' => 'edit.php',
        'search' => 'if (count($voucher_warnings) > 0)',
        'replace' => 'if (false)',
        'rationale' => 'A revision with corrected values names them in the redirect (warn=).',
    ],
    [
        'id' => 'revision-warn-always',
        'file' => 'edit.php',
        'search' => 'if (count($voucher_warnings) > 0)',
        'replace' => 'if (true)',
        'rationale' => 'A revision without corrections redirects without warn=.',
    ],
    [
        'id' => 'revision-warn-repeated',
        'file' => 'edit.php',
        'search' => "implode(',', array_unique(\$voucher_warnings))",
        'replace' => "implode(',', \$voucher_warnings)",
        'rationale' => 'Each corrected value is named once, also when several parts were corrected.',
    ],
    [
        'id' => 'save-warning-date-missing',
        'file' => 'new.php',
        'search' => "        \$warnings[] = 'date';",
        'replace' => '    true;',
        'rationale' => 'A missing date is reported for the redirect.',
    ],
    [
        'id' => 'save-warning-refund-missing',
        'file' => 'new.php',
        'search' => "        \$warnings[] = 'refund';",
        'replace' => '    true;',
        'rationale' => 'A cleared refund flag is reported for the redirect.',
    ],
    [
        'id' => 'save-warning-purpose-missing',
        'file' => 'new.php',
        'search' => "            \$warnings[] = 'purpose';",
        'replace' => '            true;',
        'rationale' => 'A cleared purpose flag is reported for the redirect.',
    ],
    [
        'id' => 'save-warning-purpose-beleg',
        'file' => 'new.php',
        'search' => "        if (\$voucher['dir'] != 'bel')",
        'replace' => '        if (true)',
        'rationale' => 'A Beleg part clears the purpose it copied from a donation without a warning.',
    ],
    [
        'id' => 'warn-note-unlisted',
        'file' => 'edit.php',
        'search' => 'if (in_array($code, $warn_codes, true))',
        'replace' => 'if (true)',
        'rationale' => 'The edit page shows only the notes of the codes in warn=.',
    ],
    [
        'id' => 'warn-note-request-text',
        'file' => 'edit.php',
        'search' => "echo '<div class=\"slot_error\" id=\"slot_error\">' . \$note . '</div><br />';",
        'replace' => "echo '<div class=\"slot_error\" id=\"slot_error\">' . \$_GET[\"warn\"] . '</div><br />';",
        'rationale' => 'The notes are fixed texts; request text is not echoed.',
    ],
];

// Each protected page needs the login check. Not for import: an unprotected
// import page would call the external membership system (getusers()), which
// tests must never contact; tests/http-redirects.php requests it only after
// every other protected page has passed.
foreach (array_diff($protectedPages, ['import']) as $page) {
    $remaining = array_values(array_diff($protectedPages, [$page]));
    $mutations[] = [
        'id' => 'unprotected-' . $page,
        'region' => $early,
        'search' => $protectedList,
        'replace' => '["' . implode('","', $remaining) . '"]',
        'rationale' => "A visitor who is not logged in gets only the login redirect for ?action=$page.",
    ];
}

return [
    'description' => 'Early redirects: login redirect of protected pages and member search before output, '
        . 'edit/merge buffer, relocate() and its targets (HTTP redirect and write-workflow tests)',
    'file' => 'index.php',
    'tests' => [
        // Protected pages, CSV listings and ?hide= when not logged in, and
        // the member search, against the normal and an unbuffered server.
        ['type' => 'http', 'script' => 'tests/http-redirects.php'],
        // Approval, revision and merge redirects (normal and
        // unbuffered) and write actions when not logged in.
        ['type' => 'http', 'script' => 'tests/write-workflows.php'],
    ],
    'timeout' => 120,
    'mutations' => $mutations,
];
