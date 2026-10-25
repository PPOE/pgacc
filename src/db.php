<?php

/*
 * Database helpers for the pages (index.php and mbapi.php load this file
 * after functions.php). The connection is always passed explicitly. A failed
 * statement ends the request as the pages always did: die() with
 * $message followed by pg_last_error(). The file only defines functions, so
 * a direct request prints nothing.
 */

// Runs $sql and returns its result.
function db_query($dbconn, $sql, $message = 'Abfrage fehlgeschlagen: ')
{
    $result = pg_query($dbconn, $sql) or die($message . pg_last_error($dbconn));
    return $result;
}

// Runs a statement whose rows are not read, frees its result and returns the
// number of affected rows.
function db_exec($dbconn, $sql, $message = 'Abfrage fehlgeschlagen: ')
{
    $result = db_query($dbconn, $sql, $message);
    $rows = pg_affected_rows($result);
    pg_free_result($result);
    return $rows;
}

// The next value of $sequence (a fixed sequence name, not request input).
function db_next_value($dbconn, $sequence)
{
    $result = db_query($dbconn, "SELECT nextval('$sequence') AS num;");
    $line = pg_fetch_array($result, null, PGSQL_ASSOC);
    pg_free_result($result);
    return $line['num'];
}
