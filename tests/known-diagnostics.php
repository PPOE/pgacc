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
        'id' => 'KD-EDIT-RIGHTSSQL',
        'php' => array('5.6', '7.4'),
        'type' => 'E_NOTICE',
        'file' => 'edit.php',
        'lines' => array(120),
        'message' => '/^Undefined variable: rightssql$/',
        'reported' => false,
        'reason' => 'the finalized-voucher check interpolates an unset $rightssql '
            . '(no organization restriction in that query)',
    ),
    array(
        'id' => 'KD-RECEIPT-KEY',
        'php' => array('5.6', '7.4'),
        'type' => 'E_NOTICE',
        'file' => 'new.php',
        'lines' => array(252),
        'message' => '/^Undefined variable: key$/',
        'reported' => false,
        'reason' => 'receipt upload reads $key without global; the receipt key '
            . 'derivation depends on it being empty (mcrypt item)',
    ),
    array(
        'id' => 'KD-RECEIPT-KEY',
        'php' => array('5.6', '7.4'),
        'type' => 'E_NOTICE',
        'file' => 'import2.php',
        'lines' => array(189),
        'message' => '/^Undefined variable: key$/',
        'reported' => false,
        'reason' => 'PDF import reads $key without global, as the receipt upload',
    ),
    array(
        'id' => 'KD-RECEIPT-KEY',
        'php' => array('5.6', '7.4'),
        'type' => 'E_NOTICE',
        'file' => 'file.php',
        'lines' => array(19),
        'message' => '/^Undefined variable: key$/',
        'reported' => false,
        'reason' => 'receipt download reads $key without global, as the receipt upload',
    ),
);
