<?php

/*
 * Reviewed differences from the reference recording (tests/reference/php85/)
 * that the comparison accepts. Every other difference fails tests/reference/run.sh
 * compare. Adding an entry is a reviewed decision, like an entry in
 * tests/known-diagnostics.php: it records an intended behaviour change (for
 * example a fix the owner decided on), never a regression or a lane that
 * should just be green.
 *
 * Each entry:
 *   'id'     => unique ID, e.g. 'RD-IMPORT-PHPSECLIB'
 *   'php'    => list of lane versions it applies to, e.g. array('8.5')
 *   'pg'     => optional list of PostgreSQL servers (PGACC_TEST_PG) it applies
 *               to, e.g. array('18'); without it, the entry applies on every
 *               server. The reference is recorded on PHP 8.5 / PostgreSQL 18.
 *   'files'  => fnmatch() glob of recorded files, e.g. 'http/import-*.txt',
 *               or a list of globs
 *   'lines'  => regular expression that every accepted changed line must
 *               match; the line includes its leading "-" (only in the
 *               reference) or "+" (only in the lane's recording)
 *   'transform' => instead of 'lines': 'crlf-to-lf' reads the matching
 *               reference files with CRLF as LF before they are compared
 *               (ref_crlf_to_lf(): HTTP text/html bodies throughout, other
 *               text bodies only their final CRLF, binary bodies never; other
 *               recordings throughout, exported strings included; lengths
 *               recomputed). The lane's recording is not changed. Used while
 *               it converts at least one file.
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
 */

// The PHP 5 entries (RD-CHARSET-DEFAULT, RD-FORMAT-CRLF-TO-LF, RD-INT-32BIT,
// the RD-PHP8-*, RD-INTVAL-*, RD-GETUSERS-TRANSACTION-* entries, ...) were
// removed when the reference was re-recorded on PHP 8.5: the recording and
// the comparison run on the same runtime, and the normalizations of
// tests/reference/lib.php remove the nondeterminism. Their audit is in
// tests/COMPATIBILITY_PLAN.md, "Reference re-baseline on PHP 8.5".
return array();
