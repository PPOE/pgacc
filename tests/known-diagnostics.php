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
 */

return array(
    // PHP 8.5 has no entries on purpose: every diagnostic there is reported as
    // a failure. See tests/README.md.
);
