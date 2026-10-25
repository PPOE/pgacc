<?php
/*
 * Reviewed differences from the PHP 5 reference (tests/reference/php5/) that
 * the comparison accepts. Every other difference fails tests/reference/run.sh
 * compare. Adding an entry is a reviewed decision, like an entry in
 * tests/known-diagnostics.php: it records an intended behaviour change (for
 * example a fix the owner decided on), never a regression or a lane that
 * should just be green.
 *
 * Each entry:
 *   'id'     => unique ID, e.g. 'RD-IMPORT-PHPSECLIB'
 *   'php'    => list of lane versions it applies to, e.g. array('7.4', '8.5')
 *   'pg'     => optional list of PostgreSQL servers (PGACC_TEST_PG) it applies
 *               to, e.g. array('18'); without it, the entry applies on every
 *               server. The reference is recorded on PHP 5.3 / PostgreSQL 8.4.
 *   'files'  => fnmatch() glob of recorded files, e.g. 'http/import-*.txt'
 *   'lines'  => regular expression that every accepted changed line must
 *               match; the line includes its leading "-" (only in the
 *               reference) or "+" (only in the lane's recording)
 *   'reason' => why the difference is intended, with the plan/PR reference
 *   'category' => 'accepted': an expected environment difference (php.ini,
 *               SAPI, PHP version formats, 32- vs 64-bit integers, PostgreSQL
 *               versions);
 *               'todo': a real defect or behaviour difference that must be
 *               fixed later (tracked in tests/COMPATIBILITY_PLAN.md). The
 *               comparison summary counts both.
 *
 * A changed line is accepted by the first entry that matches it. An entry that
 * accepts nothing in a comparison on one of its PHP versions is stale and
 * fails the comparison too: remove it, or re-record the reference.
 *
 * Must stay compatible with PHP 5.6.
 */

return array(
    // ---------------------------------------------------------------- accepted
    array(
        'id' => 'RD-CHARSET-DEFAULT',
        'php' => array('7.4', '8.5'),
        'files' => 'http/*.txt',
        'lines' => '/^(-header: Content-type: text\/html|\+header: Content-type: text\/html; charset=UTF-8)$/',
        'reason' => 'Production (PHP 5.3, stock squeeze php.ini) has no default_charset and sends text/html '
            . 'without a charset; PHP 5.6+ default to default_charset=UTF-8. The pages declare UTF-8 themselves.',
        'category' => 'accepted',
    ),

    // ---------------------------------------------------------------- todo
    array(
        'id' => 'RD-INTVAL-EXPONENT-VALUES',
        'php' => array('7.4', '8.5'),
        'files' => 'helpers/idioms-values.txt',
        'lines' => '/^[-+](import\\.php:118 amount|number conversion|in_array\\/array_search|sprintf money) string\\(\\d\\) "(1e3|1E3|1e1000|1e3,2)" /',
        'reason' => 'intval()/(int) of numeric strings in exponent form: PHP 5 reads "1e3" as 1, PHP 7.1+ as 1000 (import.php:118 amounts). Owner decision on the intended parsing.',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-INTVAL-EXPONENT-DATES',
        'php' => array('7.4', '8.5'),
        'files' => 'helpers/page-helpers.txt',
        'lines' => '/^[-+]date_condition\\(string\\(\\d\\) "(1e3|1E3|1e1000|1e3,2)"/',
        'reason' => 'date_condition() adds 1 to the year: "1e3" + 1 is 2 on PHP 5 and 1001 on PHP 7.1+ (exponent numeric strings).',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-HEX-NUMERIC-STRING',
        'php' => array('7.4', '8.5'),
        'files' => 'helpers/idioms-values.txt',
        'lines' => '/^[-+](number conversion|in_array\\/array_search|sprintf money) string\\(4\\) "0x1A" /',
        'reason' => 'Hexadecimal numeric strings: "0x1A" is numeric (26) in arithmetic on PHP 5, not since PHP 7.0 (sprintf money: 0.26 vs 0.00).',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-SORT-ORDER',
        'php' => array('7.4', '8.5'),
        'files' => 'helpers/idioms-sort.txt',
        'lines' => '/^[-+]sort set \\d+ SORT_(REGULAR|STRING|NUMERIC) returns /',
        'reason' => 'sort() order of equal or incomparable elements: PHP 7 changed the algorithm and PHP 8 made it stable and changed string-number comparison. Check the application sorts that can see ties.',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-PHP8-NONNUMERIC-ARITHMETIC',
        'php' => array('8.5'),
        'files' => 'helpers/idioms-values.txt',
        'lines' => '/^[-+](sprintf money|new\\.php:46 amount) string\\(\\d+\\) ".*" (returns (string\\(4\\) "0\\.00"|array\\(int\\(0\\) => float\\(0\\), int\\(1\\) => string\\(1\\) "0"\\))|THROWS TypeError)$/',
        'reason' => 'PHP 8 throws TypeError for arithmetic on non-numeric strings (amount parsing in new.php:46, money formatting); PHP 5 used 0.',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-PHP84-ROUND',
        'php' => array('8.5'),
        'files' => 'helpers/idioms-values.txt',
        'lines' => '/^([-+]new\\.php:46 amount string\\(5\\) "1[.,]005" returns array\\(int\\(0\\) => float\\(10[01]\\), int\\(1\\) => string\\(3\\) "10[01]"\\)'
            . '|[-+]round\\(float\\(1000000000000000\\.2\\), 0\\) returns float\\(1000000000000000(\\.2)?\\))$/',
        'reason' => 'PHP 8.4 round() no longer pre-rounds: an entered amount of 1,005 EUR is stored as 100 instead of 101 cents (new.php:46); round(1e15 + 0.2) also differs. Decide the intended rounding before PHP 8.4.',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-PHP8-STRING-NUMBER-COMPARE',
        'php' => array('8.5'),
        'files' => 'helpers/idioms-compare.txt',
        'lines' => '/ (==|<) /',
        'reason' => 'PHP 8 string-number comparison ("abc" == 0 is false, numeric strings with trailing whitespace). Review the application comparisons (konto2lo, rights, filters).',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-PHP8-KONTO2LO-WHITESPACE',
        'php' => array('8.5'),
        'files' => 'helpers/konto2lo.txt',
        'lines' => '/^[-+]konto2lo\\(string\\(12\\) "50110117300 "\\) returns (string\\(12\\) "50110117300 "|int\\(2\\))$/',
        'reason' => 'PHP 8 numeric strings may have trailing whitespace: switch() in konto2lo() maps "50110117300 " to organization 2, PHP 5/7 fall through and return the input. Trim account numbers (COMPATIBILITY_PLAN, "Found by the PHP 5 reference").',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-PHP8-STRING-NUMBER-SEARCH',
        'php' => array('8.5'),
        'files' => 'helpers/idioms-values.txt',
        'lines' => '/^[-+](in_array\\/array_search .* returns array\\(int\\(0\\) => (true|false), int\\(1\\) => (int\\(\\d+\\)|false)\\)|number conversion string\\(\\d\\) "( 12 |12 |12\\\\n)" returns )/',
        'reason' => 'PHP 8: in_array()/array_search() use the new string-number comparison, and numeric strings may have trailing whitespace.',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-PHP8-STRING-NUMBER-GETSORT',
        'php' => array('8.5'),
        'files' => 'helpers/getsort.txt',
        'lines' => '/^[-+]\\[GET array\\(string\\(4\\) "sort" => int\\(0\\)\\)\\] getoppsort\\(/',
        'reason' => 'getoppsort() compares $_GET[sort] with ==; for an integer 0 PHP 8 no longer equals any sort string (only reachable from tests, $_GET values are strings).',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-PHP8-ARRAY-ARG-GET',
        'php' => array('8.5'),
        'files' => 'helpers/get*.txt',
        'lines' => '/^[-+]\\[(GET|POST) array\\(string\\(\\d+\\) "\\w+" => array\\(/',
        'reason' => 'PHP 8 TypeError when a request parameter is an array (?filter_id[]=1): getfilter()/get_param() die with HTTP 500 instead of ignoring it.',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-PHP8-ARRAY-ARG-HEX',
        'php' => array('8.5'),
        'files' => 'helpers/hex.txt',
        'lines' => '/^[-+](hex2ascii|ascii2hex)\\(array\\(/',
        'reason' => 'PHP 8 TypeError for an array argument (strlen() of an array); PHP 5 warned and returned "".',
        'category' => 'todo',
    ),
    array(
        'id' => 'RD-PHP8-ARRAY-ARG-RIGHTS',
        'php' => array('8.5'),
        'files' => 'helpers/rights2orgasql.txt',
        'lines' => '/^[-+]rights2orgasql\\(array\\(/',
        'reason' => 'PHP 8 TypeError for an array argument; PHP 5 returned " AND false ".',
        'category' => 'todo',
    ),
    // Last: the todo entries above take precedence for lines that also show
    // a newer-PHP difference.
    array(
        'id' => 'RD-INT-32BIT',
        'php' => array('7.4', '8.5'),
        'files' => 'helpers/*.txt',
        // A number >= 2^31 - 1 in magnitude, or a float in E notation: the
        // corpus values that a 32-bit integer cannot hold.
        'lines' => '/(?<![0-9."])(214748364[7-9]|21474836[5-9]\d|2147483[7-9]\d\d|214748[4-9]\d{3}|21474[9]\d{4}'
            . '|2147[5-9]\d{5}|214[89]\d{6}|21[5-9]\d{7}|2[2-9]\d{8}|[3-9]\d{9}|[1-9]\d{10,})(?![0-9])|\dE\+1[5-9]/',
        'reason' => 'Production is 32-bit (PHP_INT_MAX 2147483647); the lanes are 64-bit. Corpus values at or above '
            . '2^31 are floats on 32-bit, and intval() saturates or wraps there. See "32-bit production integers" in '
            . 'tests/COMPATIBILITY_PLAN.md for the application paths where this matters.',
        'category' => 'accepted',
    ),
    // Last, so that the entries above keep the separators of their own changes.
    // Only the recordings whose requests call getusers().
    array(
        'id' => 'RD-GETUSERS-TRANSACTION-IMPORT',
        'php' => array('8.5'),
        'files' => 'sql/import-*.sql',
        'lines' => '/^\+(BEGIN;|COMMIT;|-- next)$/',
        'reason' => 'Intended fix: getusers() replaces ppmembers in one transaction (drop, create and the same '
            . 'INSERT between BEGIN and COMMIT). See "Member-list refresh" in tests/COMPATIBILITY_PLAN.md.',
        'category' => 'accepted',
    ),
    array(
        'id' => 'RD-GETUSERS-TRANSACTION-MB',
        'php' => array('8.5'),
        'files' => 'sql/mb-admin.sql',
        'lines' => '/^\+(BEGIN;|COMMIT;|-- next)$/',
        'reason' => 'As RD-GETUSERS-TRANSACTION-IMPORT, for the member-fee page.',
        'category' => 'accepted',
    ),
);
