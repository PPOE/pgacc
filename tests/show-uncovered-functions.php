<?php

$coverage = simplexml_load_file($argv[1]);
$uncovered = [];
$statementCount = 0;
$coverageFileFound = false;

foreach ($coverage->project->file as $file) {
    if (basename((string) $file['name']) !== 'functions.php') {
        continue;
    }

    $coverageFileFound = true;
    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }

        $statementCount++;
        if ((int) $line['count'] === 0) {
            $uncovered[] = (int) $line['num'];
        }
    }
}

if (!$coverageFileFound || $statementCount === 0) {
    fwrite(STDERR, "No functions.php coverage data found\n");
    exit(1);
}

// The statement after getfilter()'s invalid legacy amount regex never runs
// (KF-AMOUNT-FILTER-REGEX); found by its text so reformatting cannot move it.
$known = 0;
foreach (file(dirname(__DIR__) . '/functions.php') as $index => $sourceLine) {
    if (str_contains($sourceLine, "preg_match('/^*-?")) {
        $known = $index + 2;
    }
}
sort($uncovered);
$unexpectedUncovered = array_values(array_diff($uncovered, [$known]));
if (count($unexpectedUncovered) > 0) {
    fwrite(STDERR, 'Unexpected uncovered functions.php lines: ' . implode(', ', $unexpectedUncovered) . PHP_EOL);
    exit(1);
}

$covered = $statementCount - count($uncovered);
printf("functions.php executable-line coverage: %d/%d (%.2f%%)\n", $covered, $statementCount, $covered * 100 / $statementCount);
if (in_array($known, $uncovered, true)) {
    echo "Known unreachable statement remains at functions.php:$known (invalid legacy regex in getfilter)\n";
}
