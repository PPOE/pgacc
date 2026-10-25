<?php

/*
 * Reviewed differences from the reference recording (tests/reference/php85/)
 * that the comparison accepts. The recording and the comparison run on the
 * same runtime (PHP 8.5 / PostgreSQL 18), so every other difference fails
 * tests/reference/run.sh compare.
 *
 * An intended behaviour change (a fix the owner decided on) is never
 * allowlisted: re-record the reference (sh tests/reference/run.sh record),
 * review the diff and commit it with the change. An entry here may only
 * accept real nondeterminism that the normalizations of tests/reference/lib.php
 * cannot remove; adding one is a reviewed decision, like an entry in
 * tests/known-diagnostics.php.
 *
 * Each entry:
 *   'id'     => unique ID, e.g. 'RD-SOMETHING'
 *   'php'    => list of lane versions it applies to, e.g. array('8.5')
 *   'files'  => fnmatch() glob of recorded files, e.g. 'http/import-*.txt',
 *               or a list of globs
 *   'lines'  => regular expression that every accepted changed line must
 *               match; the line includes its leading "-" (only in the
 *               reference) or "+" (only in the lane's recording)
 *   'reason' => why the difference cannot be normalized, with the plan/PR reference
 *   'category' => 'accepted' or 'todo' (counted separately in the summary)
 *
 * A changed line is accepted by the first entry that matches it. An entry that
 * accepts nothing in a comparison on one of its PHP versions is stale and
 * fails the comparison too.
 */

// The PHP 5 entries were removed when the reference was re-recorded on PHP
// 8.5; their audit is in tests/COMPATIBILITY_PLAN.md, "Reference re-baseline
// on PHP 8.5".
return array();
