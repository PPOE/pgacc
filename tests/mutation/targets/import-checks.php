<?php
/*
 * Checks of every imported bank CSV line (import.php): the date must be a
 * date and the organization one the user may use (organization_allowed());
 * offending lines stop the import with "Ungültige Buchungszeile". Tested
 * with tests/http-import.php against the membership stub of
 * compose.test.yaml. See tests/mutation/README.md for the format.
 */
return array(
    'description' => 'Date and organization checks of the bank CSV import (import tests)',
    'file' => 'import.php',
    'tests' => array(
        array('type' => 'http', 'script' => 'tests/http-import.php'),
    ),
    'timeout' => 180,
    'mutations' => array(
        array(
            'id' => 'import-date-unchecked',
            'search' => 'if (!import_date_valid($voucher[\'date\']) || !organization_allowed($voucher[\'lo\'], $rights))',
            'replace' => 'if (!organization_allowed($voucher[\'lo\'], $rights))',
            'rationale' => 'Fail-open: an imported date that is not a date reaches the SQL.',
        ),
        array(
            'id' => 'import-organization-unchecked',
            'search' => 'if (!import_date_valid($voucher[\'date\']) || !organization_allowed($voucher[\'lo\'], $rights))',
            'replace' => 'if (!import_date_valid($voucher[\'date\']))',
            'rationale' => 'Fail-open: an imported line is saved in an organization the user may not use.',
        ),
        array(
            'id' => 'import-date-pattern-unanchored',
            'search' => "preg_match('/^\\d{4}-\\d{1,2}-\\d{1,2}$/D', \$date)",
            'replace' => "preg_match('/^\\d{4}-\\d{1,2}-\\d{1,2}/', \$date)",
            'rationale' => 'The whole date must match: text after a date reaches the SQL.',
        ),
        array(
            'id' => 'import-date-pattern-unanchored-start',
            'search' => "preg_match('/^\\d{4}-\\d{1,2}-\\d{1,2}$/D', \$date)",
            'replace' => "preg_match('/\\d{4}-\\d{1,2}-\\d{1,2}$/D', \$date)",
            'rationale' => 'The whole date must match: text before a date reaches the SQL.',
        ),
    ),
);
