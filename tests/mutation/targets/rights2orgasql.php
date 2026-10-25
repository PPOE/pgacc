<?php

/*
 * rights2orgasql() in functions.php: privilege checks, exact role matching,
 * numeric validation, single-organization handling, SQL restrictions, and
 * fail-open behavior. Ported unchanged from the former hand-picked runner
 * (tests/mutation/rights2orgasql.php); the only edits add surrounding context
 * where a search string occurred twice in the function (the old runner
 * silently mutated the first occurrence) and keep the line count when the
 * initialization is removed. See tests/mutation/README.md for the format.
 */
return array(
    'description' => 'Authorization helper rights2orgasql() (PHPUnit only)',
    'file' => 'functions.php',
    'region' => array('start' => 'function rights2orgasql(', 'end' => 'function konto2lo('),
    'tests' => array(
        array('type' => 'phpunit', 'filter' => 'testRights2orgasql'),
    ),
    'timeout' => 120,
    'mutations' => array(
        array(
            'id' => 'array-initialization-removed',
            'search' => array("    \$rights2 = array();\n", "    \$rights2 = [];\n"),
            'replace' => "\n",
            // Formerly an accepted equivalent on PHP 5.6 (its E_NOTICE is hidden by
            // the default error_reporting); the null-rights test captures it.
            'rationale' => 'Without the initialization, rights without numeric IDs count() an undefined variable.',
        ),
        array('id' => 'privilege-and-to-or', 'search' => " && !in_array('Rbgf',\$rights_r)", 'replace' => " || !in_array('Rbgf',\$rights_r)",
            'rationale' => 'Either privilege alone must exempt from the organization restriction.'),
        array('id' => 'remove-bgf-exemption', 'search' => "!in_array('bgf',\$rights_r)", 'replace' => 'true',
            'rationale' => 'bgf must see all organizations.'),
        array('id' => 'remove-Rbgf-exemption', 'search' => "!in_array('Rbgf',\$rights_r)", 'replace' => 'true',
            'rationale' => 'Rbgf must see all organizations.'),
        array('id' => 'grant-root-exemption', 'search' => " && !in_array('Rbgf',\$rights_r)", 'replace' => " && !in_array('Rbgf',\$rights_r) && !in_array('root',\$rights_r)",
            'rationale' => 'root alone is not an organization exemption.'),
        array('id' => 'bgf-only-as-entire-input', 'search' => "!in_array('bgf',\$rights_r)", 'replace' => "\$rights !== 'bgf'",
            'rationale' => 'bgf combined with other rights must still exempt.'),
        array('id' => 'Rbgf-only-as-entire-input', 'search' => "!in_array('Rbgf',\$rights_r)", 'replace' => "\$rights !== 'Rbgf'",
            'rationale' => 'Rbgf combined with other rights must still exempt.'),
        array('id' => 'bgf-substring-grants-access', 'search' => "!in_array('bgf',\$rights_r)", 'replace' => "strpos(\$rights, 'bgf') === false",
            'rationale' => 'Only the exact bgf token exempts, not a substring.'),
        array('id' => 'Rbgf-substring-grants-access', 'search' => "!in_array('Rbgf',\$rights_r)", 'replace' => "strpos(\$rights, 'Rbgf') === false",
            'rationale' => 'Only the exact Rbgf token exempts, not a substring.'),
        array('id' => 'bgf-matching-case-insensitive', 'search' => "!in_array('bgf',\$rights_r)", 'replace' => "!in_array('bgf', array_map('strtolower', \$rights_r))",
            'rationale' => 'Role matching is case-sensitive.'),
        array('id' => 'numeric-validation-always-false', 'search' => "preg_match('/^\\d+$/', \$right) == 1", 'replace' => 'false',
            'rationale' => 'Numeric organization IDs must be collected.'),
        array('id' => 'numeric-validation-always-true', 'search' => "preg_match('/^\\d+$/', \$right) == 1", 'replace' => 'true',
            'rationale' => 'Non-numeric tokens must not become organization IDs.'),
        array('id' => 'numeric-start-anchor-removed', 'search' => "'/^\\d+$/'", 'replace' => "'/\\d+$/'",
            'rationale' => 'Tokens with a non-numeric prefix are not IDs.'),
        array('id' => 'numeric-end-anchor-removed', 'search' => "'/^\\d+$/'", 'replace' => "'/^\\d+/'",
            'rationale' => 'Tokens with a non-numeric suffix are not IDs.'),
        array('id' => 'empty-token-accepted-as-id', 'search' => "'/^\\d+$/'", 'replace' => "'/^\\d*$/'",
            'rationale' => 'Empty tokens are not IDs.'),
        array('id' => 'negative-id-accepted', 'search' => "'/^\\d+$/'", 'replace' => "'/^-?\\d+$/'",
            'rationale' => 'Negative numbers are not IDs.'),
        array('id' => 'decimal-id-accepted', 'search' => "'/^\\d+$/'", 'replace' => "'/^\\d+(?:\\.\\d+)?$/'",
            'rationale' => 'Decimals are not IDs.'),
        array('id' => 'single-organization-denied', 'search' => 'count($rights2) > 0', 'replace' => 'count($rights2) > 1',
            'rationale' => 'One organization ID is enough for a restriction.'),
        array('id' => 'count-condition-inverted', 'search' => 'count($rights2) > 0', 'replace' => 'count($rights2) == 0',
            'rationale' => 'Restriction only with IDs, deny without.'),
        array('id' => 'restriction-uses-or', 'search' => ' AND orga IN (', 'replace' => ' OR orga IN (',
            'rationale' => 'The organization restriction must narrow the query.'),
        array('id' => 'restriction-excludes-authorized-orgs', 'search' => ' AND orga IN (', 'replace' => ' AND orga NOT IN (',
            'rationale' => 'Authorized organizations are included, not excluded.'),
        array('id' => 'unknown-rights-fail-open', 'search' => 'return " AND false ";', 'replace' => 'return "";',
            'rationale' => 'Rights without IDs must deny everything.'),
        array('id' => 'privileged-rights-denied', 'search' => 'return "";', 'replace' => 'return " AND false ";',
            'rationale' => 'Privileged rights must not be restricted.'),
        array('id' => 'retain-only-last-organization', 'search' => '$rights2[] = "\'".$right."\'";', 'replace' => '$rights2 = array("\'".$right."\'");',
            'rationale' => 'All listed organizations are kept.'),
        array('id' => 'id-quotes-removed', 'search' => '$rights2[] = "\'".$right."\'";', 'replace' => '$rights2[] = $right;',
            'rationale' => 'IDs are quoted SQL literals.'),
        array('id' => 'zero-id-rejected', 'search' => "preg_match('/^\\d+$/', \$right) == 1", 'replace' => "preg_match('/^\\d+$/', \$right) == 1 && \$right !== '0'",
            'rationale' => 'Organization 0 is a valid ID.'),
        array('id' => 'leading-zero-id-rejected', 'search' => "'/^\\d+$/'", 'replace' => "'/^[1-9]\\d*$/'",
            'rationale' => 'IDs with leading zeros (and 0) stay accepted.'),
        // The tokens come from rights_tokens() (comma-separated, trimmed; its
        // delimiter is mutated in rights-checks).
        array('id' => 'privilege-tokens-untrimmed',
            'search' => "  \$rights_r = rights_tokens(\$rights);\n  if (",
            'replace' => "  \$rights_r = explode(\",\", \$rights === null ? '' : \$rights);\n  if (",
            'rationale' => 'Rights are trimmed: "2, bgf" is bgf and "1, 2" reads organization 2.'),
        array('id' => 'mixed-roles-drop-valid-ids', 'search' => "preg_match('/^\\d+$/', \$right) == 1", 'replace' => "preg_match('/^\\d+(,\\d+)*$/', \$rights) == 1 && preg_match('/^\\d+$/', \$right) == 1",
            'rationale' => 'Valid IDs are kept when other roles are present.'),
        array('id' => 'empty-rights-fail-open', 'search' => 'return " AND false ";', 'replace' => 'return $rights === "" ? "" : " AND false ";',
            'rationale' => 'Empty rights must deny everything.'),
        array('id' => 'null-rights-fail-open', 'search' => 'return " AND false ";', 'replace' => 'return $rights === null ? "" : " AND false ";',
            'rationale' => 'Null rights (anonymous requests) must deny everything.'),
    ),
);
