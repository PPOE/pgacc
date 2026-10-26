<?php

/*
 * Checks of every imported bank CSV line (import.php): the date must be a
 * date and the organization one the user may use (organization_allowed());
 * offending lines are reported ("Ungültige Buchungszeile", line number) and
 * skipped, with a summary per file; and the PDF import's voucher match
 * (import2.php: the user's organizations, no finalized row) and the row check
 * of the receipt upload (new.php page_new_file()); and the member-list
 * refresh (getusers.php: fetch status, list checks, one transaction).
 * Tested with tests/http-import.php against the membership stub of
 * compose.test.yaml. See tests/mutation/README.md for the format.
 */
return [
    'description' => 'Date and organization checks of the bank CSV import and the member-list refresh (import tests)',
    'file' => 'import.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'testImport|testUpload', 'file' => 'tests/unit/ReceiptImportTest.php'],
        ['type' => 'phpunit', 'filter' => 'ImportMemberMatchTest', 'file' => 'tests/unit/ImportMemberMatchTest.php'],
        ['type' => 'phpunit', 'filter' => 'GetUsersTest', 'file' => 'tests/unit/GetUsersTest.php'],
        ['type' => 'http', 'script' => 'tests/http-import.php'],
    ],
    'timeout' => 180,
    'mutations' => [
        [
            'id' => 'import-form-without-token',
            'search' => "method=\"POST\">\n' . csrf_field() . '\n",
            'replace' => "method=\"POST\">\n' . ''. '\n",
            'rationale' => 'The import form carries the form token of the session.',
        ],
        [
            'id' => 'import-date-unchecked',
            'search' => 'if (!import_date_valid($voucher[\'date\']) || !organization_allowed($voucher[\'lo\'], $rights) ||',
            'replace' => 'if (!organization_allowed($voucher[\'lo\'], $rights) ||',
            'rationale' => 'Fail-open: an imported date that is not a date reaches the SQL.',
        ],
        [
            'id' => 'import-organization-unchecked',
            'search' => 'if (!import_date_valid($voucher[\'date\']) || !organization_allowed($voucher[\'lo\'], $rights) ||',
            'replace' => 'if (!import_date_valid($voucher[\'date\']) ||',
            'rationale' => 'Fail-open: an imported line is saved in an organization the user may not use.',
        ],
        [
            'id' => 'import-date-pattern-unanchored',
            'search' => "preg_match('/^(\\d{4})-(\\d{1,2})-(\\d{1,2})$/D', \$date, \$parts)",
            'replace' => "preg_match('/^(\\d{4})-(\\d{1,2})-(\\d{1,2})/', \$date, \$parts)",
            'rationale' => 'The whole date must match: text after a date reaches the SQL.',
        ],
        [
            'id' => 'import-date-pattern-unanchored-start',
            'search' => "preg_match('/^(\\d{4})-(\\d{1,2})-(\\d{1,2})$/D', \$date, \$parts)",
            'replace' => "preg_match('/(\\d{4})-(\\d{1,2})-(\\d{1,2})$/D', \$date, \$parts)",
            'rationale' => 'The whole date must match: text before a date reaches the SQL.',
        ],
        [
            'id' => 'import-date-range-unchecked',
            'search' => '    return checkdate(intval($parts[2]), intval($parts[3]), intval($parts[1]));',
            'replace' => '    return true;',
            'rationale' => 'Out-of-range dates (month 13, February 30) do not reach the SQL.',
        ],
        [
            'id' => 'import-skip-aborts',
            'search' => "                    \$skipped[] = \$lineno;\n                    continue;",
            'replace' => "                    \$skipped[] = \$lineno;\n                    return -1;",
            'rationale' => 'An offending line is skipped; the remaining lines and files are imported.',
        ],
        [
            'id' => 'import-skip-unrecorded',
            'search' => "                    \$skipped[] = \$lineno;",
            'replace' => "                    ;",
            'rationale' => 'The summary lists the skipped lines.',
        ],
        [
            'id' => 'import-skip-line-number-dropped',
            'search' => "import_invalid_line(\$i, \$rawcsvline, \$csvline, \$csvformat, \$handkassacsvline, \$lineno);",
            'replace' => "import_invalid_line(\$i, \$rawcsvline, \$csvline, \$csvformat, \$handkassacsvline);",
            'rationale' => 'The report of a skipped line names its line number.',
        ],
        [
            'id' => 'import2-lookup-unscoped',
            'file' => 'import2.php',
            'search' => "AND (ack1 IS NULL OR ack2 IS NULL) \$rightssql\"",
            'replace' => "AND (ack1 IS NULL OR ack2 IS NULL)\"",
            'rationale' => 'The PDF import matches only rows of the user\'s organizations.',
        ],
        [
            'id' => 'import2-lookup-finalized-ignored',
            'file' => 'import2.php',
            'search' => '      . " AND NOT EXISTS (SELECT 1 FROM vouchers F WHERE F.voucher_id = vouchers.voucher_id AND NOT F.deleted AND F.receipt_received)";',
            'replace' => '      . "";',
            'rationale' => 'The PDF import does not attach receipts to vouchers with a finalized row.',
        ],
        [
            'id' => 'import2-failed-store-keeps-file',
            'file' => 'import2.php',
            'search' => '        db_exec($dbconn, "UPDATE vouchers SET file = 0 WHERE NOT deleted AND id = $bid AND file = $file_number;");',
            'replace' => '        db_exec($dbconn, "SELECT 1;");',
            'rationale' => 'A receipt that could not be stored leaves the voucher without a file number.',
        ],
        [
            'id' => 'import2-reset-scoped',
            'file' => 'import2.php',
            'search' => "    vouchers_reset_ack(\$id, '');",
            'replace' => '    vouchers_reset_ack($id, $rightssql);',
            'rationale' => 'The PDF import resets the approvals of the whole voucher.',
        ],
        [
            'id' => 'upload-row-check-removed',
            'file' => 'new.php',
            'search' => "if (\$updated != 1 || !receipt_store(",
            'replace' => "if (!receipt_store(",
            'rationale' => 'The receipt is stored only after the scoped update matched the row.',
            'equivalent' => ['reason' => 'The scoped row check before the finalized check has already '
                . 'found the row with the same conditions; the update can only miss it in a race.'],
        ],
        [
            'id' => 'upload-update-unscoped',
            'file' => 'new.php',
            'search' => 'UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND voucher_id = $id AND file = $previous_file $rightssql;',
            'replace' => 'UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND voucher_id = $id AND file = $previous_file;',
            'rationale' => 'Wrong organization: the upload attaches a receipt to another organization\'s row.',
            'equivalent' => ['reason' => 'The scoped row check before the finalized check already '
                . 'requires a current row of this voucher in the user\'s scope.'],
        ],
        [
            'id' => 'upload-update-any-voucher',
            'file' => 'new.php',
            'search' => 'UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND voucher_id = $id AND file = $previous_file $rightssql;',
            'replace' => 'UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND file = $previous_file $rightssql;',
            'rationale' => 'The row must belong to the posted voucher.',
            'equivalent' => ['reason' => 'The scoped row check before the finalized check already '
                . 'requires a current row of this voucher in the user\'s scope.'],
        ],
        [
            'id' => 'upload-failed-store-keeps-file',
            'file' => 'new.php',
            'search' => '        db_exec($dbconn, "UPDATE vouchers SET file = $previous_file WHERE NOT deleted AND id = $bid AND file = $file_number;");',
            'replace' => '        db_exec($dbconn, "SELECT 1;");',
            'rationale' => 'A receipt that could not be stored does not stay referenced: the row gets its '
                . 'previous file number back.',
        ],
        [
            'id' => 'upload-failed-store-clears-file',
            'file' => 'new.php',
            'search' => 'UPDATE vouchers SET file = $previous_file WHERE NOT deleted',
            'replace' => 'UPDATE vouchers SET file = 0 WHERE NOT deleted',
            'rationale' => 'A failed upload keeps the row\'s previous receipt.',
        ],
        [
            'id' => 'import-member-match-ends-file',
            'search' => "pg_escape_string(\$dbconn, \$line['name']);\n                            pg_free_result(\$result);\n                            \$found = true;",
            'replace' => "pg_escape_string(\$dbconn, \$line['name']);\n                            pg_free_result(\$result);\n                            \$found = true; break;",
            'rationale' => 'A member match does not end the import of the file.',
        ],
        [
            'id' => 'import-member-name-unescaped',
            'search' => "\$voucher['name'] = pg_escape_string(\$dbconn, \$line['name']);\n                            pg_free_result(\$result);\n                            \$found = true;",
            'replace' => "\$voucher['name'] = \$line['name'];\n                            pg_free_result(\$result);\n                            \$found = true;",
            'rationale' => 'Removed escape: a member name with a quote is inserted as SQL.',
        ],
        [
            'id' => 'import-member-line-escaped-twice',
            'search' => "WHERE name <> '' AND '\$csvline' ILIKE",
            'replace' => "WHERE name <> '' AND '\" . pg_escape_string(\$dbconn, \$csvline) . \"' ILIKE",
            'rationale' => 'The line is escaped once: a quoted member name still matches.',
        ],
        [
            'id' => 'import-member-wildcards-unescaped',
            'search' => "ILIKE '%' || replace(replace(replace(name,",
            'replace' => "ILIKE '%' || name || '%' || replace(replace(replace(name,",
            'rationale' => 'Member names are no LIKE patterns: % and _ match only themselves.',
        ],
        [
            'id' => 'import-member-empty-name-matches',
            'search' => "WHERE name <> '' AND '\$csvline' ILIKE",
            'replace' => "WHERE '\$csvline' ILIKE",
            'rationale' => 'An empty member name matches no line.',
        ],
        [
            'id' => 'upload-update-ignores-previous-file',
            'file' => 'new.php',
            'search' => 'AND voucher_id = $id AND file = $previous_file $rightssql;',
            'replace' => 'AND voucher_id = $id $rightssql;',
            'rationale' => 'The upload replaces only the file number it checked (no lost concurrent upload).',
            'equivalent' => ['reason' => 'Only a concurrent change of the row between the row check and '
                . 'the update tells the difference; the tests run requests one at a time.'],
        ],
        [
            'id' => 'import2-update-ignores-file',
            'file' => 'import2.php',
            'search' => 'AND voucher_id = $id AND file = 0 $rightssql;',
            'replace' => 'AND voucher_id = $id $rightssql;',
            'rationale' => 'The PDF import attaches a receipt only to a row that still has none.',
            'equivalent' => ['reason' => 'Only a concurrent change of the row between the row check and '
                . 'the update tells the difference; the tests run requests one at a time.'],
        ],
        [
            'id' => 'members-error-status-accepted',
            'file' => 'getusers.php',
            'search' => "if (\$sData === false || !is_array(\$headers) || !preg_match('#^HTTP/\\S+ 200( |\$)#', \$headers[0]))",
            'replace' => 'if ($sData === false)',
            'rationale' => 'A failed fetch (HTTP error status, redirect) stops with its own error.',
        ],
        [
            'id' => 'members-redirect-followed',
            'file' => 'getusers.php',
            'search' => "'follow_location' => 0",
            'replace' => "'follow_location' => 1",
            'rationale' => 'Redirects are not followed (no other host, no plain HTTP).',
        ],
        [
            'id' => 'members-fields-unchecked',
            'file' => 'getusers.php',
            'search' => '        if (count($values) < 5)',
            'replace' => '        if (false)',
            'rationale' => 'An undecryptable list (lines without the five fields) is not imported.',
        ],
        [
            'id' => 'members-empty-list-accepted',
            'file' => 'getusers.php',
            'search' => 'if ($count == 0)',
            'replace' => 'if (false)',
            'rationale' => 'A list without a member line is refused.',
        ],
        [
            'id' => 'members-commits-caller-transaction',
            'file' => 'getusers.php',
            'search' => '$nested = pg_transaction_status($dbconn) != PGSQL_TRANSACTION_IDLE;',
            'replace' => '$nested = false;',
            'rationale' => 'Inside a caller\'s transaction the refresh uses a savepoint, not BEGIN/COMMIT.',
        ],
        [
            'id' => 'members-savepoint-outside-transaction',
            'file' => 'getusers.php',
            'search' => '$nested = pg_transaction_status($dbconn) != PGSQL_TRANSACTION_IDLE;',
            'replace' => '$nested = true;',
            'rationale' => 'A request refreshes the list in its own transaction.',
        ],
        [
            'id' => 'members-sql-error-ignored',
            'file' => 'getusers.php',
            'search' => '    $error = pg_result_status($result) == PGSQL_FATAL_ERROR ? pg_result_error($result) : null;',
            'replace' => '    $error = null;',
            'rationale' => 'An SQL error in the refresh stops the page.',
        ],
        [
            'id' => 'members-without-transaction',
            'file' => 'getusers.php',
            'search' => "        \$nested ? 'SAVEPOINT getusers;' : 'BEGIN;',",
            'replace' => "        \$nested ? 'SAVEPOINT getusers;' : 'SELECT 1;',",
            'rationale' => 'Delete and insert run in one transaction: a failed INSERT keeps the old rows.',
            'equivalent' => ['reason' => 'The list is a temporary table of the request\'s connection and a '
                . 'failed refresh stops the page, so a partly refreshed list is never read.'],
        ],
        [
            'id' => 'members-key-unchecked',
            'file' => 'getusers.php',
            'search' => '    if (strlen($datakey) == 0) {',
            'replace' => '    if (false) {',
            'rationale' => 'Without a membership key the refresh returns its own error.',
        ],
        [
            'id' => 'members-shared-table',
            'file' => 'getusers.php',
            'search' => "        'CREATE TEMP TABLE IF NOT EXISTS ppmembers (id integer, name text, lo integer, paid integer) ON COMMIT PRESERVE ROWS;',",
            'replace' => "        'CREATE TABLE IF NOT EXISTS ppmembers (id integer, name text, lo integer, paid integer);',",
            'rationale' => 'Each request has its own member list: a shared table is neither needed nor touched.',
        ],
        [
            'id' => 'members-success-reported-as-error',
            'file' => 'getusers.php',
            'search' => "        \$started = true;\n    }\n    return null;",
            'replace' => "        \$started = true;\n    }\n    return 'Abfrage fehlgeschlagen: ';",
            'rationale' => 'A loaded member list is no error.',
        ],
        [
            'id' => 'mb-members-error-ignored',
            'file' => 'mb.php',
            'search' => '    if ($members_error !== null) {',
            'replace' => '    if (false) {',
            'rationale' => 'The member-fee page stops with the error of a failed refresh.',
        ],
        [
            'id' => 'import-members-error-ignored',
            'file' => 'import.php',
            'search' => '    if ($members_error !== null) {',
            'replace' => '    if (false) {',
            'rationale' => 'The import page stops with the error of a failed refresh.',
        ],
    ],
];
