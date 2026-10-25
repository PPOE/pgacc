<?php

/*
 * Fails if an application file of the merged coverage report has less line
 * coverage than the floor: 95% per file, or the lower floor of a reviewed
 * exception in file-floor-exceptions.php (files that cannot reach 95%
 * because of documented unreachable lines).
 *
 * Usage: php tests/coverage/check-file-floor.php <clover.xml>
 *
 * Line coverage is the Clover "statements" metric (executable lines), the
 * numbers of summary.txt. An exception must name a file of the report, have
 * a floor below 95 and a reason; an exception whose file reaches 95% fails
 * too, so that it is removed (a ratchet). Needs SimpleXML; runs inside or
 * outside the container (report paths are matched on their base name, the
 * application files are top-level).
 */

const PGACC_COVERAGE_FLOOR = 95.0;

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php tests/coverage/check-file-floor.php <clover.xml>\n");
    exit(2);
}

$report = @simplexml_load_file($argv[1]);
if ($report === false) {
    fwrite(STDERR, "Cannot read the coverage report {$argv[1]}\n");
    exit(2);
}
$exceptions = require __DIR__ . '/file-floor-exceptions.php';

$files = [];
foreach ($report->xpath('//file') as $file) {
    $metrics = $file->metrics;
    $files[basename((string) $file['name'])] = [intval($metrics['coveredstatements']), intval($metrics['statements'])];
}
if (count($files) === 0) {
    fwrite(STDERR, "The coverage report {$argv[1]} lists no files\n");
    exit(1);
}
ksort($files);

$failures = [];
foreach ($exceptions as $name => $exception) {
    if (!isset($files[$name])) {
        $failures[] = "$name: exception for a file that is not in the report";
    } elseif (!isset($exception['floor'], $exception['reason']) || !is_numeric($exception['floor'])
        || $exception['floor'] >= PGACC_COVERAGE_FLOOR || trim($exception['reason']) === '') {
        $failures[] = "$name: an exception needs a floor below " . PGACC_COVERAGE_FLOOR . ' and a reason';
    }
}

foreach ($files as $name => $counts) {
    list($covered, $executable) = $counts;
    $percent = $executable === 0 ? 100.0 : $covered * 100.0 / $executable;
    $floor = isset($exceptions[$name]['floor']) ? floatval($exceptions[$name]['floor']) : PGACC_COVERAGE_FLOOR;
    $line = sprintf('%s: %d/%d lines, %.1f%% (floor %.1f%%)', $name, $covered, $executable, $percent, $floor);
    // Compared on the rounded value printed in the report.
    if (round($percent, 1) < $floor) {
        $failures[] = "$line: below the floor";
    } elseif (isset($exceptions[$name]) && $percent >= PGACC_COVERAGE_FLOOR) {
        $failures[] = "$line: reaches " . PGACC_COVERAGE_FLOOR . '%, remove its exception from tests/coverage/file-floor-exceptions.php';
    }
}

if (count($failures) > 0) {
    fwrite(STDERR, "Per-file line coverage floor failed:\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
printf("Per-file line coverage floor: all %d files at or above their floor (%.0f%%, %d reviewed exceptions)\n", count($files), PGACC_COVERAGE_FLOOR, count($exceptions));
