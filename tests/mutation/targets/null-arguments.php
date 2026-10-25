<?php

/*
 * Null guards in functions.php that keep PHP 8.1+ from reporting
 * "Passing null to parameter ... is deprecated" for strlen(), explode() and
 * str_replace(). checklogin() returns null for anonymous visitors (acc_header,
 * acc_footer, rights2orgasql via the listing pages), and listings pass
 * nullable columns to tag(). Removing a guard changes no return value or
 * output, only the diagnostic, which the tests assert is absent (PHP 8.5,
 * the only lane, kills every guard removal). See tests/mutation/README.md for
 * the format.
 */
return [
    'description' => 'Null-argument guards in tag(), acc_header(), acc_footer() and rights2orgasql() (PHPUnit only)',
    'file' => 'functions.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'testTag|testAccHeader|testAccFooter|testRights2orgasql'],
    ],
    'timeout' => 120,
    'mutations' => [
        ['id' => 'tag-guard-removed',
            'search' => "[' / ', ' / ', ' / '], \$text ?? '');", 'replace' => "[' / ', ' / ', ' / '], \$text);",
            'rationale' => 'CSV cells of null columns must not raise a str_replace() deprecation.'],
        ['id' => 'tag-guard-inverted',
            'search' => "[' / ', ' / ', ' / '], \$text ?? '');", 'replace' => "[' / ', ' / ', ' / '], \$text !== null ? '' : \$text);",
            'rationale' => 'Only null is replaced; other text is still flattened.'],
        ['id' => 'tag-null-not-empty',
            'search' => "[' / ', ' / ', ' / '], \$text ?? '');", 'replace' => "[' / ', ' / ', ' / '], \$text ?? 'NULL');",
            'rationale' => 'A null CSV cell stays empty.'],

        ['id' => 'header-guard-removed',
            'search' => "if (strlen(\$rights ?? '') > 0)", 'replace' => 'if (strlen($rights) > 0)',
            'rationale' => 'Anonymous page headers must not raise a strlen() deprecation.'],
        ['id' => 'header-guard-inverted',
            'search' => "if (strlen(\$rights ?? '') > 0)", 'replace' => "if (strlen(\$rights !== null ? '' : \$rights) > 0)",
            'rationale' => 'Logged-in users still get the administration links.'],
        ['id' => 'header-null-shows-admin-links',
            'search' => "if (strlen(\$rights ?? '') > 0)", 'replace' => "if (strlen(\$rights ?? 'x') > 0)",
            'rationale' => 'Anonymous visitors get no administration links.'],

        ['id' => 'footer-login-guard-removed',
            'search' => "if (strlen(\$rights ?? '') == 0)", 'replace' => 'if (strlen($rights) == 0)',
            'rationale' => 'Anonymous page footers must not raise a strlen() deprecation.'],
        ['id' => 'footer-login-guard-inverted',
            'search' => "if (strlen(\$rights ?? '') == 0)", 'replace' => "if (strlen(\$rights !== null ? '' : \$rights) == 0)",
            'rationale' => 'Logged-in users get no login link.'],
        ['id' => 'footer-login-null-hidden',
            'search' => "if (strlen(\$rights ?? '') == 0)", 'replace' => "if (strlen(\$rights ?? 'x') == 0)",
            'rationale' => 'Anonymous visitors get the login link.'],
        ['id' => 'footer-logout-guard-removed',
            'search' => "if (strlen(\$rights ?? '') != 0)", 'replace' => 'if (strlen($rights) != 0)',
            'rationale' => 'Anonymous page footers must not raise a strlen() deprecation.'],
        ['id' => 'footer-logout-guard-inverted',
            'search' => "if (strlen(\$rights ?? '') != 0)", 'replace' => "if (strlen(\$rights !== null ? '' : \$rights) != 0)",
            'rationale' => 'Logged-in users get the logout link.'],
        ['id' => 'footer-logout-null-shown',
            'search' => "if (strlen(\$rights ?? '') != 0)", 'replace' => "if (strlen(\$rights ?? 'x') != 0)",
            'rationale' => 'Anonymous visitors get no logout link.'],

        // The null guard of the rights helpers lives in rights_tokens() since the
        // tokens are trimmed (used by rights2orgasql(), has_right(), write_rights()).
        ['id' => 'rights-tokens-guard-removed',
            'search' => "explode(\",\", \$rights ?? ''));",
            'replace' => "explode(\",\", \$rights));",
            'rationale' => 'Anonymous listing requests must not raise an explode() deprecation.'],
        ['id' => 'rights-tokens-guard-inverted',
            'search' => "explode(\",\", \$rights ?? ''));",
            'replace' => "explode(\",\", \$rights !== null ? '' : \$rights));",
            'rationale' => 'Rights of logged-in users are still read.'],
        ['id' => 'rights-null-is-bgf',
            'search' => "explode(\",\", \$rights ?? ''));",
            'replace' => "explode(\",\", \$rights ?? 'bgf'));",
            'rationale' => 'Null rights must not be exempt from the organization restriction.'],
        ['id' => 'rights-null-gets-organization',
            'search' => "explode(\",\", \$rights ?? ''));",
            'replace' => "explode(\",\", \$rights ?? '1'));",
            'rationale' => 'Null rights must not grant an organization.'],
    ],
];
