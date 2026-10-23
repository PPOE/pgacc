<?php
/*
 * Null guards in functions.php that keep PHP 8.1+ from reporting
 * "Passing null to parameter ... is deprecated" for strlen(), explode() and
 * str_replace(). checklogin() returns null for anonymous visitors (acc_header,
 * acc_footer, rights2orgasql via the listing pages), and listings pass
 * nullable columns to tag(). Removing a guard changes no return value or
 * output, only the diagnostic, so that mutant is an accepted equivalent
 * before PHP 8.1. See tests/mutation/README.md for the format.
 */
$guardRemovedBefore81 = array(
    'php' => array('5.6', '7.4', '8.0'),
    'reason' => 'Before PHP 8.1 the internal function accepts null silently, with the same result as for an '
        . 'empty string; only PHP 8.1+ reports the deprecation that the tests assert is absent.',
);

return array(
    'description' => 'Null-argument guards in tag(), acc_header(), acc_footer() and rights2orgasql() (PHPUnit only)',
    'file' => 'functions.php',
    'tests' => array(
        array('type' => 'phpunit', 'filter' => 'testTag|testAccHeader|testAccFooter|testRights2orgasql'),
    ),
    'timeout' => 120,
    'mutations' => array(
        array('id' => 'tag-guard-removed',
            'search' => "array(' / '),\$text === null ? '' : \$text);", 'replace' => "array(' / '),\$text);",
            'rationale' => 'CSV cells of null columns must not raise a str_replace() deprecation.',
            'equivalent' => $guardRemovedBefore81),
        array('id' => 'tag-guard-inverted',
            'search' => "array(' / '),\$text === null ? '' : \$text);", 'replace' => "array(' / '),\$text !== null ? '' : \$text);",
            'rationale' => 'Only null is replaced; other text is still flattened.'),
        array('id' => 'tag-null-not-empty',
            'search' => "array(' / '),\$text === null ? '' : \$text);", 'replace' => "array(' / '),\$text === null ? 'NULL' : \$text);",
            'rationale' => 'A null CSV cell stays empty.'),

        array('id' => 'header-guard-removed',
            'search' => "if (strlen(\$rights === null ? '' : \$rights) > 0)", 'replace' => 'if (strlen($rights) > 0)',
            'rationale' => 'Anonymous page headers must not raise a strlen() deprecation.',
            'equivalent' => $guardRemovedBefore81),
        array('id' => 'header-guard-inverted',
            'search' => "if (strlen(\$rights === null ? '' : \$rights) > 0)", 'replace' => "if (strlen(\$rights !== null ? '' : \$rights) > 0)",
            'rationale' => 'Logged-in users still get the administration links.'),
        array('id' => 'header-null-shows-admin-links',
            'search' => "if (strlen(\$rights === null ? '' : \$rights) > 0)", 'replace' => "if (strlen(\$rights === null ? 'x' : \$rights) > 0)",
            'rationale' => 'Anonymous visitors get no administration links.'),

        array('id' => 'footer-login-guard-removed',
            'search' => "if (strlen(\$rights === null ? '' : \$rights) == 0)", 'replace' => 'if (strlen($rights) == 0)',
            'rationale' => 'Anonymous page footers must not raise a strlen() deprecation.',
            'equivalent' => $guardRemovedBefore81),
        array('id' => 'footer-login-guard-inverted',
            'search' => "if (strlen(\$rights === null ? '' : \$rights) == 0)", 'replace' => "if (strlen(\$rights !== null ? '' : \$rights) == 0)",
            'rationale' => 'Logged-in users get no login link.'),
        array('id' => 'footer-login-null-hidden',
            'search' => "if (strlen(\$rights === null ? '' : \$rights) == 0)", 'replace' => "if (strlen(\$rights === null ? 'x' : \$rights) == 0)",
            'rationale' => 'Anonymous visitors get the login link.'),
        array('id' => 'footer-logout-guard-removed',
            'search' => "if (strlen(\$rights === null ? '' : \$rights) != 0)", 'replace' => 'if (strlen($rights) != 0)',
            'rationale' => 'Anonymous page footers must not raise a strlen() deprecation.',
            'equivalent' => $guardRemovedBefore81),
        array('id' => 'footer-logout-guard-inverted',
            'search' => "if (strlen(\$rights === null ? '' : \$rights) != 0)", 'replace' => "if (strlen(\$rights !== null ? '' : \$rights) != 0)",
            'rationale' => 'Logged-in users get the logout link.'),
        array('id' => 'footer-logout-null-shown',
            'search' => "if (strlen(\$rights === null ? '' : \$rights) != 0)", 'replace' => "if (strlen(\$rights === null ? 'x' : \$rights) != 0)",
            'rationale' => 'Anonymous visitors get no logout link.'),

        array('id' => 'rights-privilege-guard-removed',
            'search' => "\$rights_r = explode(\",\", \$rights === null ? '' : \$rights);\n  if (",
            'replace' => "\$rights_r = explode(\",\", \$rights);\n  if (",
            'rationale' => 'Anonymous listing requests must not raise an explode() deprecation.',
            'equivalent' => $guardRemovedBefore81),
        array('id' => 'rights-privilege-guard-inverted',
            'search' => "\$rights_r = explode(\",\", \$rights === null ? '' : \$rights);\n  if (",
            'replace' => "\$rights_r = explode(\",\", \$rights !== null ? '' : \$rights);\n  if (",
            'rationale' => 'Privileges of logged-in users are still read.'),
        array('id' => 'rights-null-is-bgf',
            'search' => "\$rights_r = explode(\",\", \$rights === null ? '' : \$rights);\n  if (",
            'replace' => "\$rights_r = explode(\",\", \$rights === null ? 'bgf' : \$rights);\n  if (",
            'rationale' => 'Null rights must not be exempt from the organization restriction.'),
        array('id' => 'rights-organization-guard-removed',
            'search' => "\$rights_r = explode(\",\", \$rights === null ? '' : \$rights);\n    \$rights2",
            'replace' => "\$rights_r = explode(\",\", \$rights);\n    \$rights2",
            'rationale' => 'Anonymous listing requests must not raise an explode() deprecation.',
            'equivalent' => $guardRemovedBefore81),
        array('id' => 'rights-organization-guard-inverted',
            'search' => "\$rights_r = explode(\",\", \$rights === null ? '' : \$rights);\n    \$rights2",
            'replace' => "\$rights_r = explode(\",\", \$rights !== null ? '' : \$rights);\n    \$rights2",
            'rationale' => 'Organization IDs of logged-in users are still read.'),
        array('id' => 'rights-null-gets-organization',
            'search' => "\$rights_r = explode(\",\", \$rights === null ? '' : \$rights);\n    \$rights2",
            'replace' => "\$rights_r = explode(\",\", \$rights === null ? '1' : \$rights);\n    \$rights2",
            'rationale' => 'Null rights must not grant an organization.'),
    ),
);
