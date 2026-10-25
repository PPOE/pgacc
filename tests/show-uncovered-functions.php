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

sort($uncovered);
$unexpectedUncovered = array_values(array_diff($uncovered, [71]));
if (count($unexpectedUncovered) > 0) {
    fwrite(STDERR, 'Unexpected uncovered functions.php lines: ' . implode(', ', $unexpectedUncovered) . PHP_EOL);
    exit(1);
}

$covered = $statementCount - count($uncovered);
printf("functions.php executable-line coverage: %d/%d (%.2f%%)\n", $covered, $statementCount, $covered * 100 / $statementCount);
if (in_array(71, $uncovered, true)) {
    echo "Known unreachable statement remains at functions.php:71 (invalid legacy regex in getfilter)\n";
}
