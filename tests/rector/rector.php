<?php

/*
 * Rector configuration for the mechanical syntax steps of the PHP 8.5-only
 * migration (tests/rector/run.sh). Only rules whose result has the same
 * syntax tree apart from the array/list spelling, proven per file by
 * tests/coverage/ast-equivalence.sh:
 * - LongArrayToShortArrayRector: array(...) -> [...]
 * - ListToArrayDestructRector: list(...) = ... -> [...] = ...
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
