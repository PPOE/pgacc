<?php

/*
 * Test-only page for tests/http-smoke.php: a statement that fails through
 * the helper selected by ?helper=, as a page would run it, so the failure
 * path of src/db.php runs on the test server (with coverage). Synthetic
 * statements only; never deployed (tests/ is export-ignored).
 */
require dirname(__DIR__) . '/db-helpers.php';
require dirname(__DIR__, 2) . '/src/db.php';

$dbconn = pg_connect(pgacc_test_connection_string());
switch ($_GET['helper'] ?? '') {
    case 'query':
        db_query($dbconn, 'SELECT * FROM db_helpers_missing');
        break;
    case 'query-sql':
        db_query($dbconn, 'SELECT * FROM db_helpers_missing', show_sql: true);
        break;
    case 'exec':
        db_exec($dbconn, 'UPDATE db_helpers_missing SET n = 1');
        break;
    case 'next-value-no-row':
        // Closes the nextval() call early: the statement returns no row.
        db_next_value($dbconn, "voucher_number') WHERE false --");
        break;
}
echo 'not ended';
