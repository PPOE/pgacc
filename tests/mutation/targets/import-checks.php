<?php
/*
 * Checks of every imported bank CSV line (import.php): the date must be a
 * date and the organization one the user may use (organization_allowed());
 * offending lines are reported ("Ungültige Buchungszeile", line number) and
 * skipped, with a summary per file; and the PDF import's voucher match
 * (import2.php: the user's organizations, no finalized row) and the row check
 * of the receipt upload (new.php page_new_file()). Tested
 * with tests/http-import.php against the membership stub of
 * compose.test.yaml. See tests/mutation/README.md for the format.
 */
return array(
    'description' => 'Date and organization checks of the bank CSV import (import tests)',
    'file' => 'import.php',
    'tests' => array(
        array('type' => 'phpunit', 'filter' => 'testImport|testUpload', 'file' => 'tests/unit/ReceiptImportTest.php'),
        array('type' => 'phpunit', 'filter' => 'ImportMemberMatchTest', 'file' => 'tests/unit/ImportMemberMatchTest.php'),
        array('type' => 'http', 'script' => 'tests/http-import.php'),
    ),
    'timeout' => 180,
    'mutations' => array(
        array(
            'id' => 'import-form-without-token',
            'search' => "method=\"POST\">\n'.csrf_field().'\n",
            'replace' => "method=\"POST\">\n'.''.'\n",
            'rationale' => 'The import form carries the form token of the session.',
        ),
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
            'search' => "preg_match('/^(\\d{4})-(\\d{1,2})-(\\d{1,2})$/D', \$date, \$parts)",
            'replace' => "preg_match('/^(\\d{4})-(\\d{1,2})-(\\d{1,2})/', \$date, \$parts)",
            'rationale' => 'The whole date must match: text after a date reaches the SQL.',
        ),
        array(
            'id' => 'import-date-pattern-unanchored-start',
            'search' => "preg_match('/^(\\d{4})-(\\d{1,2})-(\\d{1,2})$/D', \$date, \$parts)",
            'replace' => "preg_match('/(\\d{4})-(\\d{1,2})-(\\d{1,2})$/D', \$date, \$parts)",
            'rationale' => 'The whole date must match: text before a date reaches the SQL.',
        ),
        array(
            'id' => 'import-date-range-unchecked',
            'search' => '  return checkdate(intval($parts[2]), intval($parts[3]), intval($parts[1]));',
            'replace' => '  return true;',
            'rationale' => 'Out-of-range dates (month 13, February 30) do not reach the SQL.',
        ),
        array(
            'id' => 'import-skip-aborts',
            'search' => "              \$skipped[] = \$lineno;\n              continue;",
            'replace' => "              \$skipped[] = \$lineno;\n              return -1;",
            'rationale' => 'An offending line is skipped; the remaining lines and files are imported.',
        ),
        array(
            'id' => 'import-skip-unrecorded',
            'search' => "              \$skipped[] = \$lineno;",
            'replace' => "              ;",
            'rationale' => 'The summary lists the skipped lines.',
        ),
        array(
            'id' => 'import-skip-line-number-dropped',
            'search' => "import_invalid_line(\$i, \$rawcsvline, \$csvline, \$csvformat, \$handkassacsvline, \$lineno);",
            'replace' => "import_invalid_line(\$i, \$rawcsvline, \$csvline, \$csvformat, \$handkassacsvline);",
            'rationale' => 'The report of a skipped line names its line number.',
        ),
        array(
            'id' => 'import2-lookup-unscoped',
            'file' => 'import2.php',
            'search' => "AND (ack1 IS NULL OR ack2 IS NULL) \$rightssql\"",
            'replace' => "AND (ack1 IS NULL OR ack2 IS NULL)\"",
            'rationale' => 'The PDF import matches only rows of the user\'s organizations.',
        ),
        array(
            'id' => 'import2-lookup-finalized-ignored',
            'file' => 'import2.php',
            'search' => '    . " AND NOT EXISTS (SELECT 1 FROM vouchers F WHERE F.voucher_id = vouchers.voucher_id AND NOT F.deleted AND F.receipt_received)";',
            'replace' => '    . "";',
            'rationale' => 'The PDF import does not attach receipts to vouchers with a finalized row.',
        ),
        array(
            'id' => 'import2-failed-store-keeps-file',
            'file' => 'import2.php',
            'search' => '    $result = pg_query($dbconn, "UPDATE vouchers SET file = 0 WHERE NOT deleted AND id = $bid AND file = $file_number;")',
            'replace' => '    $result = pg_query($dbconn, "SELECT 1;")',
            'rationale' => 'A receipt that could not be stored leaves the voucher without a file number.',
        ),
        array(
            'id' => 'import2-reset-scoped',
            'file' => 'import2.php',
            'search' => "  vouchers_reset_ack(\$id, '');",
            'replace' => '  vouchers_reset_ack($id, $rightssql);',
            'rationale' => 'The PDF import resets the approvals of the whole voucher.',
        ),
        array(
            'id' => 'upload-row-check-removed',
            'file' => 'new.php',
            'search' => "if (\$updated != 1 || !receipt_store(",
            'replace' => "if (!receipt_store(",
            'rationale' => 'The receipt is stored only after the scoped update matched the row.',
            'equivalent' => array('reason' => 'The scoped row check before the finalized check has already '
                . 'found the row with the same conditions; the update can only miss it in a race.'),
        ),
        array(
            'id' => 'upload-update-unscoped',
            'file' => 'new.php',
            'search' => 'UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND voucher_id = $id AND file = $previous_file $rightssql;',
            'replace' => 'UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND voucher_id = $id AND file = $previous_file;',
            'rationale' => 'Wrong organization: the upload attaches a receipt to another organization\'s row.',
            'equivalent' => array('reason' => 'The scoped row check before the finalized check already '
                . 'requires a current row of this voucher in the user\'s scope.'),
        ),
        array(
            'id' => 'upload-update-any-voucher',
            'file' => 'new.php',
            'search' => 'UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND voucher_id = $id AND file = $previous_file $rightssql;',
            'replace' => 'UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND file = $previous_file $rightssql;',
            'rationale' => 'The row must belong to the posted voucher.',
            'equivalent' => array('reason' => 'The scoped row check before the finalized check already '
                . 'requires a current row of this voucher in the user\'s scope.'),
        ),
        array(
            'id' => 'upload-failed-store-keeps-file',
            'file' => 'new.php',
            'search' => '  $result = pg_query($dbconn, "UPDATE vouchers SET file = $previous_file WHERE NOT deleted AND id = $bid AND file = $file_number;")',
            'replace' => '  $result = pg_query($dbconn, "SELECT 1;")',
            'rationale' => 'A receipt that could not be stored does not stay referenced: the row gets its '
                . 'previous file number back.',
        ),
        array(
            'id' => 'upload-failed-store-clears-file',
            'file' => 'new.php',
            'search' => 'UPDATE vouchers SET file = $previous_file WHERE NOT deleted',
            'replace' => 'UPDATE vouchers SET file = 0 WHERE NOT deleted',
            'rationale' => 'A failed upload keeps the row\'s previous receipt.',
        ),
        array(
            'id' => 'import-member-match-ends-file',
            'search' => "pg_escape_string(\$dbconn, \$line['name']);\n                pg_free_result(\$result);\n                \$found = true;",
            'replace' => "pg_escape_string(\$dbconn, \$line['name']);\n                pg_free_result(\$result);\n                \$found = true; break;",
            'rationale' => 'A member match does not end the import of the file.',
        ),
        array(
            'id' => 'import-member-name-unescaped',
            'search' => "\$voucher['name'] = pg_escape_string(\$dbconn, \$line['name']);\n                pg_free_result(\$result);\n                \$found = true;",
            'replace' => "\$voucher['name'] = \$line['name'];\n                pg_free_result(\$result);\n                \$found = true;",
            'rationale' => 'Removed escape: a member name with a quote is inserted as SQL.',
        ),
        array(
            'id' => 'import-member-line-escaped-twice',
            'search' => "WHERE name <> '' AND '\$csvline' ILIKE",
            'replace' => "WHERE name <> '' AND '\" . pg_escape_string(\$dbconn, \$csvline) . \"' ILIKE",
            'rationale' => 'The line is escaped once: a quoted member name still matches.',
        ),
        array(
            'id' => 'import-member-wildcards-unescaped',
            'search' => "ILIKE '%' || replace(replace(replace(name,",
            'replace' => "ILIKE '%' || name || '%' || replace(replace(replace(name,",
            'rationale' => 'Member names are no LIKE patterns: % and _ match only themselves.',
        ),
        array(
            'id' => 'import-member-empty-name-matches',
            'search' => "WHERE name <> '' AND '\$csvline' ILIKE",
            'replace' => "WHERE '\$csvline' ILIKE",
            'rationale' => 'An empty member name matches no line.',
        ),
        array(
            'id' => 'upload-update-ignores-previous-file',
            'file' => 'new.php',
            'search' => 'AND voucher_id = $id AND file = $previous_file $rightssql;',
            'replace' => 'AND voucher_id = $id $rightssql;',
            'rationale' => 'The upload replaces only the file number it checked (no lost concurrent upload).',
            'equivalent' => array('reason' => 'Only a concurrent change of the row between the row check and '
                . 'the update tells the difference; the tests run requests one at a time.'),
        ),
        array(
            'id' => 'import2-update-ignores-file',
            'file' => 'import2.php',
            'search' => 'AND voucher_id = $id AND file = 0 $rightssql;',
            'replace' => 'AND voucher_id = $id $rightssql;',
            'rationale' => 'The PDF import attaches a receipt only to a row that still has none.',
            'equivalent' => array('reason' => 'Only a concurrent change of the row between the row check and '
                . 'the update tells the difference; the tests run requests one at a time.'),
        ),
    ),
);
