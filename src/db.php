<?php

/*
 * Database helpers for the pages (index.php and mbapi.php load this file
 * after functions.php). The connection is always passed explicitly. A failed
 * statement ends the request as the pages always did: die() with
 * 'Abfrage fehlgeschlagen: ' and pg_last_error(). PHP reports the failed
 * pg_query() at this file, so db_fail() also logs the calling page's
 * file:line. The file only defines functions, so a direct request prints
 * nothing (src/ is outside the document root public/ anyway).
 */

// Runs $sql and returns its result. With $show_sql the failure message
// names the statement (the bank import).
function db_query($dbconn, $sql, $show_sql = false)
{
    $result = pg_query($dbconn, $sql) or db_fail($dbconn, $show_sql ? $sql : null);
    return $result;
}

// Runs a statement whose rows are not read, frees its result and returns the
// number of affected rows.
function db_exec($dbconn, $sql, $show_sql = false)
{
    $result = db_query($dbconn, $sql, $show_sql);
    $rows = pg_affected_rows($result);
    pg_free_result($result);
    return $rows;
}

// The next value of $sequence (a fixed sequence name, not request input).
function db_next_value($dbconn, $sequence)
{
    $result = db_query($dbconn, "SELECT nextval('$sequence') AS num;");
    $line = pg_fetch_array($result, null, PGSQL_ASSOC) or db_fail($dbconn, null);
    pg_free_result($result);
    return $line['num'];
}

// Ends the request after a failed statement: logs where it was called and
// prints the pages' message ($sql only when the message names it).
function db_fail($dbconn, $sql): never
{
    error_log('pgacc: query failed, called from ' . db_caller());
    die(($sql === null ? 'Abfrage fehlgeschlagen: ' : 'Abfrage (' . $sql . ') fehlgeschlagen: ') . pg_last_error($dbconn));
}

// file:line of the first caller outside this file, relative to the
// application directory (null without one).
function db_caller()
{
    $root = dirname(__DIR__) . '/';
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
        if (isset($frame['file']) && $frame['file'] !== __FILE__) {
            $file = strpos($frame['file'], $root) === 0 ? substr($frame['file'], strlen($root)) : $frame['file'];
            return $file . ':' . $frame['line'];
        }
    }
}
