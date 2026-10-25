<?php

/*
 * Prints line and branch coverage of tests/mutation/lib.php from a serialized
 * php-code-coverage file written by the self-test, and
 * lists every uncovered line and branch. Exits nonzero if any remain.
 *
 *   XDEBUG_MODE=coverage php tests/mutation/tests/coverage-summary.php /tmp/lib.cov
 *
 * The report is rebuilt through php-code-coverage, which needs a coverage
 * driver (Xdebug) even though nothing is measured.
 */
require dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';

$serialized = (new SebastianBergmann\CodeCoverage\Serialization\Unserializer())->unserialize($argv[1]);
$data = $serialized['codeCoverage'];
// Paths are stored relative to the common directory; restore them.
foreach ($data->coveredFiles() as $file) {
    $data->renameFile($file, $serialized['basePath'] . '/' . $file);
}
$filter = new SebastianBergmann\CodeCoverage\Filter();
$coverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
    (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter),
    $filter,
);
$coverage->setData($data);
$coverage->setTests($serialized['testResults']);
$report = $coverage->getReport();
$uncovered = [];
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
        $node->numberOfExecutableBranches(),
    );
    foreach ($node->lineCoverageData() as $line => $tests) {
        if (is_array($tests) && count($tests) === 0) {
            $uncovered[] = $node->name() . ":$line (line)";
        }
    }
}
foreach ($coverage->getData()->functionCoverage() as $file => $functions) {
    foreach ($functions as $function => $data) {
        foreach ($data->branches as $branch) {
            if (count($branch->hit) === 0) {
                $uncovered[] = sprintf(
                    '%s:%d-%d (branch in %s)',
                    basename($file),
                    $branch->line_start,
                    $branch->line_end,
                    $function,
                );
            }
        }
    }
}
foreach ($uncovered as $item) {
    echo "  uncovered: $item\n";
}
exit(count($uncovered) === 0 ? 0 : 1);
