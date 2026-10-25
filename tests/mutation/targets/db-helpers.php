<?php

/*
 * The database helpers in src/db.php: db_query() ends the request with the
 * pages' message on failure, db_exec() returns the affected rows and passes
 * the message on, db_next_value() reads the next value of the sequence.
 * Killed by DbHelpersTest. Not mutated: pg_free_result() in db_exec() and
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
            'search' => '    $result = pg_query($dbconn, $sql) or die($message . pg_last_error($dbconn));',
            'replace' => '    $result = pg_query($dbconn, $sql);',
            'rationale' => 'A failed statement ends the request instead of continuing without a result.',
        ],
        [
            'id' => 'query-failure-default-message',
            'search' => '    $result = pg_query($dbconn, $sql) or die($message . pg_last_error($dbconn));',
            'replace' => '    $result = pg_query($dbconn, $sql) or die(\'Abfrage fehlgeschlagen: \' . pg_last_error($dbconn));',
            'rationale' => 'The message prefix of the call (the imports name the statement) is printed.',
        ],
        [
            'id' => 'query-failure-without-error',
            'search' => '    $result = pg_query($dbconn, $sql) or die($message . pg_last_error($dbconn));',
            'replace' => '    $result = pg_query($dbconn, $sql) or die($message);',
            'rationale' => 'The message ends with the PostgreSQL error.',
        ],
        [
            'id' => 'query-result-dropped',
            'search' => '    return $result;',
            'replace' => '    return true;',
            'rationale' => 'db_query() returns the result of the statement.',
        ],
        [
            'id' => 'exec-message-dropped',
            'search' => '    $result = db_query($dbconn, $sql, $message);',
            'replace' => '    $result = db_query($dbconn, $sql);',
            'rationale' => 'db_exec() passes its message prefix on.',
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
            'id' => 'next-value-other-column',
            'search' => '    return $line[\'num\'];',
            'replace' => '    return $line[0] ?? null;',
            'rationale' => 'db_next_value() returns the num column of the fetched row.',
        ],
    ],
];
