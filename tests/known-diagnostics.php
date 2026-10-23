<?php
/*
 * Reviewed list of PHP diagnostics that the HTTP tests tolerate for now.
 *
 * Any diagnostic the application raises during an HTTP test fails the run
 * unless it matches an entry here exactly: PHP minor version, type, file,
 * line, message, and whether the runtime reported it ("reported" true means
 * the runtime's own error_reporting let it be displayed/logged; false means
 * it was hidden by the default error_reporting or by "@").
 *
 * Adding an entry is a reviewed decision. Each entry needs an ID, a reason,
 * and a follow-up in tests/COMPATIBILITY_PLAN.md. Remove entries as soon as
 * the application is fixed. Never add entries just to make a lane green.
 *
 * Must stay compatible with PHP 5.6.
 */

return array(
    // Scoped to the lanes that passed before diagnostics were checked
    // (PHP 5.6 and 7.4). PHP 8.0/8.5 have no entries on purpose: every
    // diagnostic there is reported as a failure. See tests/README.md.
    array(
        'id' => 'KD-PREFS-HIDE',
        'php' => array('5.6', '7.4'),
        'type' => 'E_NOTICE',
        'file' => 'closed.php',
        'lines' => array(54, 95, 98, 101, 104, 107, 110, 113, 116, 119, 122, 125, 128, 131, 134, 137, 140, 143, 146, 167, 176, 180, 187, 196, 202, 211, 215, 219, 223, 227, 231, 235, 239, 243, 247, 251, 255),
        'message' => '/^Undefined offset: \\d+$/',
        'reported' => false,
        'reason' => 'listing column helpers read $user_prefs_hide[$n] for users '
            . 'without hide preferences; null == 1 is false, so columns show',
    ),
    array(
        'id' => 'KD-REPORT-PREVCOND',
        'php' => array('5.6', '7.4'),
        'type' => 'E_NOTICE',
        'file' => 'report.php',
        'lines' => array(340),
        'message' => '/^Undefined variable: prevcond$/',
        'reported' => false,
        'reason' => 'page_report() passes an unset $prevcond to unit_report()',
    ),
    array(
        'id' => 'KD-REPORT-SQL-PG15',
        'php' => array('5.6', '7.4'),
        'type' => 'E_WARNING',
        'file' => 'report.php',
        'lines' => array(58),
        'message' => '/^pg_query\\(\\): Query failed: ERROR:  trailing junk after numeric literal at or near &quot;8AND&quot;/',
        'reported' => true,
        'reason' => 'unit_report() builds "type = 8AND date ..." (no space before '
            . '$cond); PostgreSQL 15+ rejects it and the "or die" ends the year '
            . 'report early. Displayed in the response body',
    ),
    array(
        'id' => 'KD-NEW-RIGHTS',
        'php' => array('5.6', '7.4'),
        'type' => 'E_NOTICE',
        'file' => 'new.php',
        'lines' => array(184),
        'message' => '/^Undefined variable: rights$/',
        'reported' => false,
        'reason' => 'voucher creation passes an unset $rights to page_save_buchung()',
    ),
    array(
        'id' => 'KD-EDIT-RIGHTSSQL',
        'php' => array('5.6', '7.4'),
        'type' => 'E_NOTICE',
        'file' => 'edit.php',
        'lines' => array(117),
        'message' => '/^Undefined variable: rightssql$/',
        'reported' => false,
        'reason' => 'the finalized-voucher check interpolates an unset $rightssql '
            . '(no organization restriction in that query)',
    ),
    array(
        'id' => 'KD-EDIT-RELOCATE-CLOSED-LINK',
        'php' => array('5.6', '7.4'),
        'type' => 'E_WARNING',
        'file' => 'index.php',
        'lines' => array(78),
        'message' => '/^pg_query\\(\\): No PostgreSQL link opened yet$/',
        'reported' => true,
        'reason' => 'edit.php relocate() closes the connection and returns; the '
            . 'request continues and checklogin() queries without a link. '
            . 'Displayed in the redirect body',
    ),
    array(
        'id' => 'KD-EDIT-RELOCATE-CLOSED-LINK',
        'php' => array('5.6', '7.4'),
        'type' => 'E_WARNING',
        'file' => 'index.php',
        'lines' => array(328),
        'message' => '/^pg_close\\(\\): (supplied resource|\\d+) is not a valid PostgreSQL link resource$/',
        'reported' => true,
        'reason' => 'after relocate() closed the connection, index.php closes it '
            . 'again. Displayed in the redirect body',
    ),
);
