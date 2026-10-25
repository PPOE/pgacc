<?php

/*
 * Prints line and branch coverage of tests/mutation/lib.php from a serialized
 * php-code-coverage file written by the self-test (PHP 7.4 lane only), and
 * lists every uncovered line and branch. Exits nonzero if any remain.
 *
 *   php tests/mutation/tests/coverage-summary.php /tmp/lib.cov
 */
require dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';

$coverage = include $argv[1];
$report = $coverage->getReport();
$uncovered = array();
foreach ($report as $node) {
    if (!$node instanceof SebastianBergmann\CodeCoverage\Node\File) {
        continue;
    }
    printf(
        "%s: lines %d/%d, branches %d/%d\n",
        $node->name(),
        $node->numberOfExecutedLines(),
        $node->numberOfExecutableLines(),
        $node->numberOfExecutedBranches(),
        $node->numberOfExecutableBranches()
    );
    foreach ($node->lineCoverageData() as $line => $tests) {
        if (is_array($tests) && count($tests) === 0) {
            $uncovered[] = $node->name() . ":$line (line)";
        }
    }
}
foreach ($coverage->getData()->functionCoverage() as $file => $functions) {
    foreach ($functions as $function => $data) {
        foreach ($data['branches'] as $branch) {
            if (count($branch['hit']) === 0) {
                $uncovered[] = sprintf(
                    '%s:%d-%d (branch in %s)',
                    basename($file),
                    $branch['line_start'],
                    $branch['line_end'],
                    $function
                );
            }
        }
    }
}
foreach ($uncovered as $item) {
    echo "  uncovered: $item\n";
}
exit(count($uncovered) === 0 ? 0 : 1);
