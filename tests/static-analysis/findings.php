<?php

// Helpers for analyse.sh. Runs only inside the static-analysis image.
//
//   php findings.php phpcs-list REPORT.json PREFIX
//       Print each PHP_CodeSniffer finding as "file<TAB>sniff<TAB>message",
//       sorted, with PREFIX removed from file paths.
//   php findings.php compare BASELINE CURRENT
//       Compare two such lists as multisets. Exit 1 on any new finding or any
//       baseline entry that no longer occurs.
//   php findings.php canaries EXPECTED PHPCS.json PHPSTAN.json PREFIX
//       Exit 1 unless every expected canary finding was reported.

function fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(2);
}

function read_json($path)
{
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        fail("Cannot parse JSON report $path");
    }
    return $data;
}

function relative_path($path, $prefix)
{
    if (strpos($path, $prefix) === 0) {
        return substr($path, strlen($prefix));
    }
    return $path;
}

function one_line($text)
{
    return trim(preg_replace('/\s+/', ' ', $text));
}

function phpcs_list($report, $prefix)
{
    $data = read_json($report);
    if (!isset($data['files']) || !is_array($data['files'])) {
        fail("PHP_CodeSniffer report $report has no file list");
    }
    $lines = [];
    foreach ($data['files'] as $file => $result) {
        foreach ($result['messages'] as $message) {
            $lines[] = relative_path($file, $prefix) . "\t" . $message['source'] . "\t" . one_line($message['message']);
        }
    }
    sort($lines);
    return $lines;
}

function read_list($path)
{
    $lines = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $lines[] = $line;
    }
    return $lines;
}

function multiset_difference($left, $right)
{
    $counts = array_count_values($right);
    $difference = [];
    foreach ($left as $line) {
        if (!empty($counts[$line])) {
            $counts[$line]--;
        } else {
            $difference[] = $line;
        }
    }
    return $difference;
}

$command = isset($argv[1]) ? $argv[1] : '';

if ($command === 'phpcs-list' && $argc === 4) {
    foreach (phpcs_list($argv[2], $argv[3]) as $line) {
        echo $line, "\n";
    }
    exit(0);
}

if ($command === 'compare' && $argc === 4) {
    $baseline = read_list($argv[2]);
    $current = read_list($argv[3]);
    $new = multiset_difference($current, $baseline);
    $fixed = multiset_difference($baseline, $current);
    foreach ($new as $line) {
        echo "NEW (not in baseline): $line\n";
    }
    foreach ($fixed as $line) {
        echo "NO LONGER REPORTED (remove from baseline): $line\n";
    }
    echo count($current), ' finding(s), ', count($baseline), ' baseline entr', count($baseline) === 1 ? 'y' : 'ies',
    ', ', count($new), ' new, ', count($fixed), " no longer reported\n";
    exit($new || $fixed ? 1 : 0);
}

if ($command === 'canaries' && $argc === 6) {
    $prefix = $argv[5];
    $reported = [];
    foreach (read_json($argv[3])['files'] as $file => $result) {
        foreach ($result['messages'] as $message) {
            $reported[] = implode("\t", ['phpcs', basename(relative_path($file, $prefix)), $message['line'], $message['source']]);
        }
    }
    $phpstan = read_json($argv[4]);
    foreach ($phpstan['files'] as $file => $result) {
        foreach ($result['messages'] as $message) {
            $identifier = isset($message['identifier']) ? $message['identifier'] : '(none)';
            $reported[] = implode("\t", ['phpstan', basename(relative_path($file, $prefix)), $message['line'], $identifier]);
        }
    }
    $missing = 0;
    foreach (read_list($argv[2]) as $expected) {
        if (in_array($expected, $reported, true)) {
            echo "canary detected: ", str_replace("\t", ' ', $expected), "\n";
        } else {
            echo "CANARY MISSED: ", str_replace("\t", ' ', $expected), "\n";
            $missing++;
        }
    }
    if ($missing) {
        echo "Reported canary findings:\n";
        foreach ($reported as $line) {
            echo '  ', str_replace("\t", ' ', $line), "\n";
        }
    }
    exit($missing ? 1 : 0);
}

fail('Usage: see the header of ' . __FILE__);
