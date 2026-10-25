<?php

/*
 * Rector configuration for the syntax steps of the PHP 8.5-only migration
 * (tests/rector/run.sh).
 * Same syntax tree apart from the array/list spelling, proven per file by
 * tests/coverage/ast-equivalence.sh:
 * - LongArrayToShortArrayRector: array(...) -> [...]
 * - ListToArrayDestructRector: list(...) = ... -> [...] = ...
 * Same behaviour for every input (the replacement is defined as the old
 * expression; at most an "undefined array key" warning disappears):
 * - StrContainsRector, StrStartsWithRector, StrEndsWithRector: strpos()/
 *   substr() comparisons -> str_contains()/str_starts_with()/str_ends_with()
 * - TernaryToNullCoalescingRector: isset($a) ? $a : $b, $a === null ? $b : $a
 *   -> $a ?? $b
 * - IfToNullCoalescingAssignRector: if ($a === null) $a = $b -> $a ??= $b
 *   (parameters only)
 * - RemoveUnusedVariableInCatchRector: catch (E $e) -> catch (E)
 * - RemoveExtraParametersRector: arguments without side effects that the
 *   called function does not declare and never reads
 * - FunctionFirstClassCallableRector: 'trim' -> trim(...)
 * Not used: rules that change behaviour (switch -> match compares strictly,
 * mt_rand -> random_int, ord() of a substr() result, added return types).
 */

use Rector\Config\RectorConfig;
use Rector\Php54\Rector\Array_\LongArrayToShortArrayRector;
use Rector\Php71\Rector\List_\ListToArrayDestructRector;
use Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector;
use Rector\Php70\Rector\Ternary\TernaryToNullCoalescingRector;
use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;
use Rector\Php74\Rector\If_\IfToNullCoalescingAssignRector;
use Rector\Php80\Rector\Catch_\RemoveUnusedVariableInCatchRector;
use Rector\Php80\Rector\Identical\StrEndsWithRector;
use Rector\Php80\Rector\Identical\StrStartsWithRector;
use Rector\Php80\Rector\NotIdentical\StrContainsRector;

return RectorConfig::configure()
    ->withRules([
        LongArrayToShortArrayRector::class,
        ListToArrayDestructRector::class,
        StrContainsRector::class,
        StrStartsWithRector::class,
        StrEndsWithRector::class,
        TernaryToNullCoalescingRector::class,
        IfToNullCoalescingAssignRector::class,
        RemoveUnusedVariableInCatchRector::class,
        RemoveExtraParametersRector::class,
        FunctionFirstClassCallableRector::class,
    ])
    ->withCache('/tmp/rector')
    ->withoutParallel();
