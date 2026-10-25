<?php

/*
 * Crashes and PHP 8.5 diagnostics on edge-case requests: the removed transfer
 * action (KF-TRANSFER-UNDEFINED-FUNCTION), the ack POST on the creation form
 * (KF-NEW-ACK-UNDEFINED-FUNCTION), the year with a suffix
 * (KF-REPORT-YEAR-SUFFIX), array request parameters in getfilter() and
 * get_param(), the closed listing's columns (KF-CLOSED-MISSING-COLUMNS), the
 * member lookup without a name, and the null guards for users.hide,
 * getoppsort() and convif(). Each mutant restores the old code or drops a
 * guard. The transfer removal has no mutant: restoring only the dispatch
 * entry is equivalent (an action without a branch falls through to the
 * report), and the branch alone is unreachable; http-page-views.php checks
 * the report. See tests/mutation/README.md for the format.
 */
return array(
    'description' => 'Edge-case requests: removed actions, year and array parameters, closed listing columns, null guards',
    'file' => 'index.php',
    'tests' => array(
        array('type' => 'phpunit', 'filter' => 'testGetfilter|testGetoppsort'),
        array('type' => 'phpunit', 'filter' => 'testConvif', 'file' => 'tests/unit/ImportMemberMatchTest.php'),
        array('type' => 'http', 'script' => 'tests/http-page-views.php'),
        array('type' => 'http', 'script' => 'tests/http-listing-prefs.php'),
        array('type' => 'http', 'script' => 'tests/http-output-escaping.php'),
        array('type' => 'http', 'script' => 'tests/write-workflows.php'),
    ),
    'timeout' => 600,
    'mutations' => array(
        array('id' => 'new-ack-branch-restored',
            'search' => '    else page_new($rights);', 'replace' => '    else if (isset($_POST["ack"])) page_new_ack($rights); else page_new($rights);',
            'rationale' => 'An ack POST on the creation form shows the form again instead of calling an undefined function.'),
        array('id' => 'year-suffix-accepted',
            'search' => "preg_match('/^\\d\\d\\d\\d\$/', \$_GET[\"year\"])", 'replace' => "preg_match('/^\\d\\d\\d\\d(-\\d)?\$/', \$_GET[\"year\"])",
            'rationale' => 'A year that is not four digits is invalid input: the current year.'),
        array('id' => 'hide-null-guard-removed',
            'search' => "explode(\",\", \$line['hide'] ?? '');", 'replace' => "explode(\",\", \$line['hide']);",
            'rationale' => 'A user without stored column preferences (NULL) must not raise an explode() deprecation.'),
        array('id' => 'hide-null-hides-a-column',
            'search' => "explode(\",\", \$line['hide'] ?? '');", 'replace' => "explode(\",\", \$line['hide'] ?? '1');",
            'rationale' => 'A NULL preference hides no column.'),

        array('id' => 'getfilter-id-array-accepted', 'file' => 'functions.php',
            'search' => "is_string(\$_GET['filter_id'] ?? null)", 'replace' => "isset(\$_GET['filter_id'])",
            'rationale' => 'An array filter value is ignored, not a TypeError.'),
        array('id' => 'getfilter-date-array-accepted', 'file' => 'functions.php',
            'search' => "is_string(\$_GET['filter_date'] ?? null)", 'replace' => "isset(\$_GET['filter_date'])",
            'rationale' => 'An array date filter is ignored, not a TypeError.'),
        array('id' => 'getfilter-type-text-array-accepted', 'file' => 'functions.php',
            'search' => "else if (is_string(\$_GET['filter_type'] ?? null)", 'replace' => "else if (isset(\$_GET['filter_type'])",
            'rationale' => 'The second (text) type check also ignores an array value.'),
        array('id' => 'getfilter-name-array-accepted', 'file' => 'functions.php',
            'search' => "is_string(\$_GET['filter_name'] ?? null)", 'replace' => "isset(\$_GET['filter_name'])",
            'rationale' => 'The last filter also ignores an array value.'),
        array('id' => 'getoppsort-null-guard-removed', 'file' => 'functions.php',
            'search' => "substr(\$sortn ?? '', -1, 1)", 'replace' => 'substr($sortn, -1, 1)',
            'rationale' => 'getoppsort(null) with an empty sort parameter must not raise a substr() deprecation.'),
        array('id' => 'convif-null-guard-removed', 'file' => 'import.php',
            'search' => "iconv('ISO-8859-15', 'UTF-8', \$data ?? '')", 'replace' => "iconv('ISO-8859-15', 'UTF-8', \$data)",
            'rationale' => 'A missing CSV field converts to an empty string without an iconv() deprecation.'),

        array('id' => 'get-param-array-accepted', 'file' => 'new.php',
            'search' => 'is_string($_POST[$name . $part] ?? null)', 'replace' => 'isset($_POST[$name . $part])',
            'rationale' => 'An array form value is invalid input (the default), not a TypeError.'),
        array('id' => 'member-queries-init-in-name-branch', 'file' => 'new.php',
            'search' => "            \$queries = [];\n            if (strlen(\$voucher['name']) > 0) {",
            'replace' => "            if (strlen(\$voucher['name']) > 0) {\n                \$queries = [];",
            'rationale' => 'A member part without a number and a name looks up no member, without an undefined variable.'),

        array('id' => 'closed-vaccount-not-selected', 'file' => 'closed.php',
            'search' => 'vouchers.account, vouchers.vaccount, vouchers.comment', 'replace' => 'vouchers.account, vouchers.comment',
            'rationale' => 'The closed listing shows the virtual account.'),
        array('id' => 'closed-old-acks-not-selected', 'file' => 'closed.php',
            'search' => 'vouchers.ack2, vouchers.ack1_old, vouchers.ack2_old, vouchers.person_type', 'replace' => 'vouchers.ack2, vouchers.person_type',
            'rationale' => 'The closed listing shows the old acknowledgements.'),
    ),
);
