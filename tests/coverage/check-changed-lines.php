<?php

/*
 * Fails if any executable line in the given ranges is not covered.
 *
 * Usage: php tests/coverage/check-changed-lines.php [options] <clover.xml> [<ranges-file>|-]
 *
 * Options:
 *   --branches=<uncovered-branches.txt>  also list branches (basic blocks)
 *       that no test reached and that overlap a changed line (written by
 *       merge.php; includes top-level script code). Reported as warnings.
 *   --fail-on-branches  make those uncovered branches fail the check too.
 *
 * Ranges ("file:line", "file:start-end", or "file:start-end,start-end" per
 * line; "#" starts a comment) are relative to the repository root, e.g. the
 * output of tests/coverage/changed-lines.sh. Lines that are not executable
 * (comments, blank lines, HTML outside PHP tags, declarations) are ignored.
 * A top-level or src/ PHP file missing from the report is an error; other paths
 * (tests/, non-PHP files) are outside the coverage scope and are skipped.
 *
 * Needs SimpleXML; runs inside or outside the container:
 * absolute report paths are matched on their path relative to the report's
 * common root (/app in the test containers).
 */

$branchesFile = null;
$failOnBranches = false;
$arguments = [];
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--branches=') === 0) {
        $branchesFile = substr($argument, strlen('--branches='));
    } elseif ($argument === '--fail-on-branches') {
        $failOnBranches = true;
    } else {
        $arguments[] = $argument;
    }
}

if (count($arguments) < 1 || count($arguments) > 2) {
    fwrite(STDERR, "Usage: php tests/coverage/check-changed-lines.php [--branches=<file> [--fail-on-branches]] <clover.xml> [<ranges-file>|-]\n");
    exit(2);
}

$clover = @simplexml_load_file($arguments[0]);
if ($clover === false) {
    fwrite(STDERR, "Cannot read Clover report {$arguments[0]}\n");
    exit(2);
}

$uncoveredBranches = [];
if ($branchesFile !== null) {
    $branchesText = @file_get_contents($branchesFile);
    if ($branchesText === false) {
        fwrite(STDERR, "Cannot read uncovered branches from $branchesFile\n");
        exit(2);
    }
    foreach (preg_split('/\R/', $branchesText) as $entry) {
        if (preg_match('/^(.+):(\d+)-(\d+)(?:\s+#\s*(.*))?$/', trim($entry), $match)) {
            $uncoveredBranches[$match[1]][] = [
                (int) $match[2],
                (int) $match[3],
                isset($match[4]) ? $match[4] : '',
            ];
        }
    }
}

$rangesSource = count($arguments) === 2 ? $arguments[1] : '-';
$rangesText = $rangesSource === '-' ? stream_get_contents(STDIN) : @file_get_contents($rangesSource);
if ($rangesText === false) {
    fwrite(STDERR, "Cannot read ranges from $rangesSource\n");
    exit(2);
}

// relative path => [line => hit count] for executable statements
$report = [];
foreach ($clover->xpath('//file') as $file) {
    $name = (string) $file['name'];
    $lines = [];
    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt') {
            $lines[(int) $line['num']] = (int) $line['count'];
        }
    }
    $report[$name] = $lines;
}

$root = common_directory(array_keys($report));
$byRelative = [];
foreach ($report as $name => $lines) {
    $byRelative[substr($name, strlen($root))] = $lines;
}

$wanted = [];
foreach (preg_split('/\R/', $rangesText) as $number => $entry) {
    $entry = trim(preg_replace('/#.*/', '', $entry));
    if ($entry === '') {
        continue;
    }
    if (!preg_match('/^(.+):(\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*)$/', $entry, $match)) {
        fwrite(STDERR, sprintf("Invalid range on line %d: %s\n", $number + 1, $entry));
        exit(2);
    }
    $path = preg_replace('#^(\./)+#', '', $match[1]);
    foreach (explode(',', $match[2]) as $range) {
        $bounds = explode('-', $range);
        $start = (int) $bounds[0];
        $end = isset($bounds[1]) ? (int) $bounds[1] : $start;
        if ($end < $start) {
            fwrite(STDERR, "Invalid range $path:$range\n");
            exit(2);
        }
        for ($line = $start; $line <= $end; $line++) {
            $wanted[$path][$line] = true;
        }
    }
}
ksort($wanted);

$failed = false;
$branchFindings = 0;
$checked = 0;
$covered = 0;
foreach ($wanted as $path => $lines) {
    if (!isset($byRelative[$path])) {
        if (preg_match('#^(src/)?[^/]+\.php$#', $path) === 1) {
            fwrite(STDERR, "$path: changed, but not in the coverage report\n");
            $failed = true;
        } else {
            echo "$path: outside coverage scope, skipped\n";
        }
        continue;
    }

    $uncovered = [];
    $fileChecked = 0;
    foreach (array_keys($lines) as $line) {
        if (!isset($byRelative[$path][$line])) {
            continue;
        }
        $fileChecked++;
        if ($byRelative[$path][$line] === 0) {
            $uncovered[] = $line;
        }
    }
    $checked += $fileChecked;
    $covered += $fileChecked - count($uncovered);

    if (count($uncovered) > 0) {
        $failed = true;
        fwrite(STDERR, sprintf(
            "%s: %d of %d changed executable lines not covered: %s\n",
            $path,
            count($uncovered),
            $fileChecked,
            implode(', ', compress($uncovered)),
        ));
    } else {
        printf("%s: %d changed executable lines, all covered\n", $path, $fileChecked);
    }

    if (isset($uncoveredBranches[$path])) {
        foreach ($uncoveredBranches[$path] as $branch) {
            for ($line = $branch[0]; $line <= $branch[1]; $line++) {
                if (isset($lines[$line])) {
                    $branchFindings++;
                    $message = sprintf(
                        "%s:%d-%d: branch in %s not covered (overlaps changed line %d)\n",
                        $path,
                        $branch[0],
                        $branch[1],
                        $branch[2],
                        $line,
                    );
                    if ($failOnBranches) {
                        fwrite(STDERR, $message);
                    } else {
                        echo 'warning: ' . $message;
                    }
                    break;
                }
            }
        }
    }
}

printf("Changed-line coverage: %d/%d executable lines covered\n", $covered, $checked);
if ($branchesFile !== null) {
    printf("Uncovered branches overlapping changed lines: %d\n", $branchFindings);
    if ($failOnBranches && $branchFindings > 0) {
        $failed = true;
    }
}
if ($failed) {
    fwrite(STDERR, "Changed-line coverage check failed\n");
    exit(1);
}

function common_directory(array $paths)
{
    if (count($paths) === 0) {
        return '';
    }
    $prefix = dirname($paths[0]) . '/';
    foreach ($paths as $path) {
        while ($prefix !== '/' && strpos($path, $prefix) !== 0) {
            $prefix = rtrim(dirname(rtrim($prefix, '/')), '/') . '/';
        }
    }

    return $prefix;
}

function compress(array $lines)
{
    sort($lines);
    $ranges = [];
    $start = $previous = null;
    foreach ($lines as $line) {
        if ($previous !== null && $line === $previous + 1) {
            $previous = $line;
            continue;
        }
        if ($start !== null) {
            $ranges[] = $start === $previous ? (string) $start : "$start-$previous";
        }
        $start = $previous = $line;
    }
    if ($start !== null) {
        $ranges[] = $start === $previous ? (string) $start : "$start-$previous";
    }

    return $ranges;
}
