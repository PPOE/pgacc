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
);
