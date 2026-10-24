<?php
/*
 * Reference runs only (tests/reference/compose.yaml): tags the request's
 * PostgreSQL connection with application_name "ref:<X-Pgacc-Test-Request>"
 * through libpq's PGAPPNAME, so that the statement log can be split per
 * request, then chains the usual diagnostics recorder. PHP restores the
 * environment at the end of each request. The application cannot observe
 * the tag (it never reads application_name). Defines no globals.
 * libpq before 9.0 (the PHP 5.3 lane's 8.4) ignores PGAPPNAME; against a
 * 9.0+ server PGACC_REFERENCE_PGOPTIONS makes it send the same setting as a
 * startup option instead (PGOPTIONS, which libpq 8.4 supports). A PostgreSQL
 * 8.4 server has no application_name at all; the recorder then attributes
 * statements by backend process (tests/reference/lib.php).
 * Must stay compatible with PHP 5.3.
 */
putenv('PGAPPNAME=ref:' . ((isset($_SERVER['HTTP_X_PGACC_TEST_REQUEST'])
    && preg_match('/^[A-Za-z0-9_-]{1,59}$/', $_SERVER['HTTP_X_PGACC_TEST_REQUEST']) === 1)
    ? $_SERVER['HTTP_X_PGACC_TEST_REQUEST'] : 'untagged'));
if (getenv('PGACC_REFERENCE_PGOPTIONS') === '1') {
    putenv('PGOPTIONS=-c application_name=' . getenv('PGAPPNAME'));
}
require '/app/tests/runtime/diagnostics-prepend.php';
