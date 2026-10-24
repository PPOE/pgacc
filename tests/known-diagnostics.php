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
    // (PHP 5.6 and 7.4; PHP 5.3 below). PHP 8.0/8.5 have no entries on purpose: every
    // diagnostic there is reported as a failure. See tests/README.md.
    array(
        'id' => 'KD-PHPSECLIB-OPENSSL-53',
        'php' => array('5.3'),
        'type' => 'E_WARNING',
        'file' => 'vendor/phpseclib/phpseclib/phpseclib/Crypt/Base.php',
        'lines' => array(1159),
        'message' => '/^openssl_encrypt\(\): Using an empty Initialization Vector \(iv\) is potentially insecure and not recommended$/',
        'reported' => false,
        'reason' => 'phpseclib decrypts AES-CBC (receipt v2) on PHP 5.3, which lacks OPENSSL_RAW_DATA, with an '
            . 'ECB block it encrypts itself under "@" (ECB has no IV); the result is checked by the v2 vectors on PHP 5.3',
    ),
);
