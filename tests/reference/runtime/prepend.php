<?php

/*
 * Reference runs only (tests/reference/compose.yaml): tags the request's
 * PostgreSQL connection with application_name "ref:<X-Pgacc-Test-Request>"
 * through libpq's PGAPPNAME, so that the statement log can be split per
 * request, then chains the usual diagnostics recorder. PHP restores the
 * environment at the end of each request. The application cannot observe
 * the tag (it never reads application_name). Defines no globals.
 */
putenv('PGAPPNAME=ref:' . ((isset($_SERVER['HTTP_X_PGACC_TEST_REQUEST'])
    && preg_match('/^[A-Za-z0-9_-]{1,59}$/', $_SERVER['HTTP_X_PGACC_TEST_REQUEST']) === 1)
    ? $_SERVER['HTTP_X_PGACC_TEST_REQUEST'] : 'untagged'));
require '/app/tests/runtime/diagnostics-prepend.php';
