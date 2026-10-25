<?php

/*
 * The database helpers in src/db.php: db_query() ends the request with the
 * pages' message on failure (naming the statement only when asked) and logs
 * the caller, db_exec() returns the affected rows, db_next_value() reads the
 * next value of the sequence and fails without a row. Killed by
 * DbHelpersTest. Not mutated: pg_free_result() in db_exec() and
 * db_next_value() (the result is local, so freeing it is not observable).
 * See tests/mutation/README.md for the format.
 */
return [
    'description' => 'db_query(), db_exec() and db_next_value() in src/db.php',
    'file' => 'src/db.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'DbHelpersTest', 'file' => 'tests/unit/DbHelpersTest.php'],
    ],
    'timeout' => 60,
    'mutations' => [
        [
            'id' => 'query-failure-continues',
            'search' => '    $result = pg_query($dbconn, $sql) or db_fail($dbconn, $show_sql ? $sql : null);',
            'replace' => '    $result = pg_query($dbconn, $sql);',
            'rationale' => 'A failed statement ends the request instead of continuing without a result.',
        ],
        [
            'id' => 'query-statement-never-shown',
            'search' => '    $result = pg_query($dbconn, $sql) or db_fail($dbconn, $show_sql ? $sql : null);',
            'replace' => '    $result = pg_query($dbconn, $sql) or db_fail($dbconn, null);',
            'rationale' => 'With show_sql the message names the statement (the bank import).',
        ],
        [
            'id' => 'query-statement-always-shown',
            'search' => '    $result = pg_query($dbconn, $sql) or db_fail($dbconn, $show_sql ? $sql : null);',
            'replace' => '    $result = pg_query($dbconn, $sql) or db_fail($dbconn, $sql);',
            'rationale' => 'Without show_sql the message does not name the statement.',
        ],
        [
            'id' => 'query-result-dropped',
            'search' => '    return $result;',
            'replace' => '    return true;',
            'rationale' => 'db_query() returns the result of the statement.',
        ],
        [
            'id' => 'exec-show-sql-dropped',
            'search' => '    $result = db_query($dbconn, $sql, $show_sql);',
            'replace' => '    $result = db_query($dbconn, $sql);',
            'rationale' => 'db_exec() passes show_sql on.',
        ],
        [
            'id' => 'exec-counts-returned-rows',
            'search' => '    $rows = pg_affected_rows($result);',
            'replace' => '    $rows = pg_num_rows($result);',
            'rationale' => 'db_exec() returns the rows the statement changed (merge, receipt updates).',
        ],
        [
            'id' => 'exec-returns-nothing',
            'search' => '    return $rows;',
            'replace' => '    return null;',
            'rationale' => 'db_exec() returns the affected rows.',
        ],
        [
            'id' => 'next-value-skips-one',
            'search' => '    $result = db_query($dbconn, "SELECT nextval(\'$sequence\') AS num;");',
            'replace' => '    $result = db_query($dbconn, "SELECT nextval(\'$sequence\') + 1 AS num;");',
            'rationale' => 'db_next_value() returns the value nextval() gives, not a later one.',
        ],
        [
            'id' => 'next-value-no-row-continues',
            'search' => '    $line = pg_fetch_array($result, null, PGSQL_ASSOC) or db_fail($dbconn, null);',
            'replace' => '    $line = pg_fetch_array($result, null, PGSQL_ASSOC);',
            'rationale' => 'Without a row db_next_value() ends the request instead of returning null into the SQL of the page.',
        ],
        [
            'id' => 'next-value-other-column',
            'search' => '    return $line[\'num\'];',
            'replace' => '    return $line[0] ?? null;',
            'rationale' => 'db_next_value() returns the num column of the fetched row.',
        ],
        [
            'id' => 'fail-not-logged',
            'search' => '    error_log(\'pgacc: query failed, called from \' . db_caller());',
            'replace' => '    true;',
            'rationale' => 'A failure is logged with the calling page, which PHP reports as src/db.php.',
        ],
        [
            'id' => 'fail-message-swapped',
            'search' => '    die(($sql === null ? \'Abfrage fehlgeschlagen: \' : \'Abfrage (\' . $sql . \') fehlgeschlagen: \') . pg_last_error($dbconn));',
            'replace' => '    die(($sql !== null ? \'Abfrage fehlgeschlagen: \' : \'Abfrage (\' . $sql . \') fehlgeschlagen: \') . pg_last_error($dbconn));',
            'rationale' => 'The statement is named only when it is given.',
        ],
        [
            'id' => 'fail-without-error',
            'search' => '    die(($sql === null ? \'Abfrage fehlgeschlagen: \' : \'Abfrage (\' . $sql . \') fehlgeschlagen: \') . pg_last_error($dbconn));',
            'replace' => '    die(($sql === null ? \'Abfrage fehlgeschlagen: \' : \'Abfrage (\' . $sql . \') fehlgeschlagen: \'));',
            'rationale' => 'The message ends with the PostgreSQL error.',
        ],
        [
            'id' => 'caller-inside-src-db',
            'search' => '        if (isset($frame[\'file\']) && $frame[\'file\'] !== __FILE__) {',
            'replace' => '        if (isset($frame[\'file\'])) {',
            'rationale' => 'The logged caller is the page, not the helper that called db_fail().',
        ],
        [
            'id' => 'caller-absolute-path',
            'search' => '            $file = strpos($frame[\'file\'], $root) === 0 ? substr($frame[\'file\'], strlen($root)) : $frame[\'file\'];',
            'replace' => '            $file = $frame[\'file\'];',
            'rationale' => 'The caller is logged relative to the application directory.',
        ],
    ],
];
