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
 *   'php'    => list of lane versions it applies to, e.g. array('7.4', '8.0', '8.5')
 *   'files'  => fnmatch() glob of recorded files, e.g. 'http/import-*.txt'
 *   'lines'  => regular expression that every accepted changed line must
 *               match; the line includes its leading "-" (only in the
 *               reference) or "+" (only in the lane's recording)
 *   'reason' => why the difference is intended, with the plan/PR reference
 *
 * A changed line is accepted by the first entry that matches it. An entry that
 * accepts nothing in a comparison on one of its PHP versions is stale and
 * fails the comparison too: remove it, or re-record the reference.
 *
 * Must stay compatible with PHP 5.6.
 */

return array(
);
