<?php

/*
 * Rector configuration for the syntax steps of the PHP 8.5-only migration
 * (tests/rector/run.sh).
 * Same syntax tree apart from the array/list spelling, proven per file by
 * tests/coverage/ast-equivalence.sh:
 * - LongArrayToShortArrayRector: array(...) -> [...]
 * - ListToArrayDestructRector: list(...) = ... -> [...] = ...
 * The PHP 8 rules of modern syntax step 2 (str_contains() and friends, ??,
 * ??=, catch without variable, extra arguments, trim(...)) ran once and were
 * reviewed per site (tests/COMPATIBILITY_PLAN.md). They are not kept here:
 * on other code they also rewrite loose comparisons (strpos() != false,
 * substr() == ...), strpos() with an offset, arguments with side effects
 * and repeated calls, which changes behaviour.
 */

use Rector\Config\RectorConfig;
use Rector\Php54\Rector\Array_\LongArrayToShortArrayRector;
use Rector\Php71\Rector\List_\ListToArrayDestructRector;

return RectorConfig::configure()
    ->withRules([
        LongArrayToShortArrayRector::class,
        ListToArrayDestructRector::class,
    ])
    ->withCache('/tmp/rector')
    ->withoutParallel();
