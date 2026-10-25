<?php

/*
 * Merges PHPUnit coverage with the per-request coverage written by
 * tests/coverage/prepend.php, prints a per-file summary, and writes Clover,
 * HTML, and serialized (php-code-coverage) reports.
 *
 * Usage (inside the test container, XDEBUG_MODE=coverage):
 *   php tests/coverage/merge.php <coverage-dir> <output-dir>
 *
 * PGACC_COVERAGE_BRANCHES=0 selects line-only data (it must match the mode
 * used by prepend.php and PHPUnit). Path coverage is always discarded (branch
 * coverage only): the legacy functions have far too many paths for the metric
 * to be useful.
 *
 * <coverage-dir> contains phpunit.cov and http/*.ser. Scope: every top-level
 * application PHP file in /app, except the test-only constants.php. Files that
 * no test reaches are included with zero coverage (statically analysed, never
 * executed).
 */

require '/app/vendor/autoload.php';

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Granularity;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Node\File;
use SebastianBergmann\CodeCoverage\Report\Facade as ReportFacade;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tests/coverage/merge.php <coverage-dir> <output-dir>\n");
    exit(2);
}

$coverageDir = rtrim($argv[1], '/');
$outputDir = rtrim($argv[2], '/');
$appRoot = '/app';

$files = [];
foreach (glob($appRoot . '/*.php') as $file) {
    if (basename($file) !== 'constants.php') {
        $files[] = $file;
    }
}

$filter = new Filter();
$filter->includeFiles($files);

$branches = getenv('PGACC_COVERAGE_BRANCHES') !== '0';
$selector = new Selector();
$driver = $selector->select($filter, $branches ? Granularity::LineAndBranch : Granularity::Line);
// Files that no test reaches are added from static analysis, not executed.
$coverage = new CodeCoverage($driver, $filter);
$coverage->includeUncoveredFiles();
$coverage->cacheStaticAnalysis('/tmp/pgacc-coverage-cache');

$phpunitFile = $coverageDir . '/phpunit.cov';
if (!is_file($phpunitFile)) {
    fwrite(STDERR, "Missing PHPUnit coverage: $phpunitFile\n");
    exit(1);
}
// PHPUnit (--branch-coverage) writes its data with paths relative to the
// common directory of the covered files; restore the absolute paths.
$phpunitSerialized = (new Unserializer())->unserialize($phpunitFile);
$phpunitData = $phpunitSerialized['codeCoverage'];
if ($phpunitSerialized['basePath'] !== '') {
    foreach ($phpunitData->coveredFiles() as $file) {
        $phpunitData->renameFile($file, $phpunitSerialized['basePath'] . '/' . $file);
    }
}
$phpunitCoverage = new CodeCoverage($driver, new Filter());
$phpunitCoverage->setData($phpunitData);
$phpunitCoverage->setTests($phpunitSerialized['testResults']);
$coverage->merge($phpunitCoverage);

$requestFiles = glob($coverageDir . '/http/*.ser');
sort($requestFiles);
if (count($requestFiles) === 0) {
    fwrite(STDERR, "No HTTP request coverage found in $coverageDir/http\n");
    exit(1);
}

foreach ($requestFiles as $index => $requestFile) {
    $payload = unserialize(file_get_contents($requestFile), ['allowed_classes' => false]);
    if (!is_array($payload) || !isset($payload['coverage'], $payload['request'])) {
        fwrite(STDERR, "Malformed request coverage: $requestFile\n");
        exit(1);
    }
    $raw = $branches
        ? RawCodeCoverageData::fromXdebugWithBranchCoverage($payload['coverage'])
        : RawCodeCoverageData::fromLineCoverage($payload['coverage']);
    $coverage->append($raw, sprintf('HTTP #%03d %s', $index + 1, $payload['request']));
}

$report = $coverage->getReport();

if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
    fwrite(STDERR, "Cannot create $outputDir\n");
    exit(1);
}
remove_tree($outputDir . '/html');

$reports = ReportFacade::fromObject($coverage);
$reports->renderClover($outputDir . '/clover.xml');
$reports->renderHtml($outputDir . '/html');
(new Serializer())->serialize($outputDir . '/coverage.cov', $coverage);

if ($branches) {
    // One "file:start-end # function" line per branch (basic block) that no
    // test reached; consumed by check-changed-lines.php --branches.
    $uncoveredBranches = [];
    foreach ($coverage->getData()->functionCoverage() as $file => $functions) {
        foreach ($functions as $function => $functionData) {
            foreach ($functionData->branches as $branch) {
                if (count($branch->hit) === 0) {
                    $uncoveredBranches[] = sprintf(
                        '%s:%d-%d # %s',
                        substr($file, strlen($appRoot) + 1),
                        $branch->line_start,
                        $branch->line_end,
                        $function,
                    );
                }
            }
        }
    }
    file_put_contents($outputDir . '/uncovered-branches.txt', implode(PHP_EOL, $uncoveredBranches) . PHP_EOL);
} elseif (is_file($outputDir . '/uncovered-branches.txt')) {
    unlink($outputDir . '/uncovered-branches.txt');
}

$rows = [];
foreach ($report as $node) {
    if ($node instanceof File) {
        $rows[$node->name()] = $node;
    }
}
ksort($rows);

$lines = [];
$lines[] = sprintf(
    'Merged coverage of the application (PHPUnit + %d HTTP requests)',
    count($requestFiles),
);
$lines[] = rtrim(sprintf('%-18s %17s %17s', 'File', 'Lines', $branches ? 'Branches' : ''));
foreach ($rows as $node) {
    $lines[] = rtrim(sprintf(
        '%-18s %17s %17s',
        $node->name(),
        ratio($node->numberOfExecutedLines(), $node->numberOfExecutableLines()),
        $branches ? ratio($node->numberOfExecutedBranches(), $node->numberOfExecutableBranches()) : '',
    ));
}
$lines[] = rtrim(sprintf(
    '%-18s %17s %17s',
    'Total',
    ratio($report->numberOfExecutedLines(), $report->numberOfExecutableLines()),
    $branches ? ratio($report->numberOfExecutedBranches(), $report->numberOfExecutableBranches()) : '',
));

$summary = implode(PHP_EOL, $lines) . PHP_EOL;
file_put_contents($outputDir . '/summary.txt', $summary);
echo $summary;

function ratio($executed, $executable)
{
    if ($executable === 0) {
        return 'n/a';
    }

    return sprintf('%d/%d %5.1f%%', $executed, $executable, $executed * 100 / $executable);
}

function remove_tree($path)
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            remove_tree($path . '/' . $entry);
        }
    }
    rmdir($path);
}
