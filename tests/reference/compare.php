<?php

/*
 * Compares a lane's recording with the PHP 5 reference.
 *
 *   php tests/reference/compare.php <reference dir> <actual dir> <report dir>
 *
 * Every recorded file must be byte-identical, unless each changed line is
 * accepted by a reviewed entry in tests/reference/allowlist.php for this PHP
 * version (PGACC_REFERENCE_LANE). Missing and extra files count as all lines
 * removed or added. 'transform' entries rewrite the reference before the
 * comparison (CRLF read as LF). Stale allowlist entries fail as well. Files under
 * diagnostics/ are informational: their differences are reported but do not
 * fail, because PHP diagnostics are already gated per lane by
 * tests/known-diagnostics.php (and their wording changes between versions).
 *
 * Writes <report dir>/report.txt and one <report dir>/diffs/<file>.diff per
 * differing file. Exit status 1 on any unaccepted difference.
 * Must stay compatible with PHP 5.6.
 */
require __DIR__ . '/lib.php';

if (count($argv) !== 4) {
    ref_fail('usage: compare.php <reference dir> <actual dir> <report dir>');
}
list(, $referenceDir, $actualDir, $reportDir) = $argv;
$php = getenv('PGACC_REFERENCE_LANE');
if ($php === false || $php === '') {
    $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
}
if (!is_file("$referenceDir/manifest.txt")) {
    ref_fail("no reference recording in $referenceDir (the historical PHP 5 recording; see tests/README.md)");
}
$entries = require __DIR__ . '/allowlist.php';
$problems = ref_allowlist_problems($entries);
if (count($problems) > 0) {
    ref_fail("invalid tests/reference/allowlist.php:\n  " . implode("\n  ", $problems));
}
// Entries with a 'pg' key apply only on those PostgreSQL servers.
$pg = getenv('PGACC_TEST_PG');
if ($pg === false || $pg === '') {
    ref_fail('PGACC_TEST_PG is not set (tests/reference/run.sh sets it)');
}
$entries = ref_entries_for_pg($entries, $pg);

$files = array_unique(array_merge(ref_list_files($referenceDir), ref_list_files($actualDir)));
sort($files, SORT_STRING);
$same = 0;
$used = array();
$failed = array();
$accepted = array();
$informational = array();
foreach ($files as $file) {
    $reference = "$referenceDir/$file";
    $actual = "$actualDir/$file";
    $referenceExists = is_file($reference);
    $actualExists = is_file($actual);
    // 'transform' entries (RD-FORMAT-CRLF-TO-LF) rewrite the reference first;
    // the rewritten copy is compared and kept in the report.
    $transformedBy = array();
    if ($referenceExists) {
        $text = file_get_contents($reference);
        $transformedBy = $used;
        $transformed = ref_transform_reference($php, $file, $text, $entries, $used);
        $transformedBy = array_keys(array_diff_assoc($used, $transformedBy));
        if ($transformed !== $text) {
            $reference = "$reportDir/transformed-reference/$file";
            ref_write($reference, $transformed);
        }
    }
    if ($referenceExists && $actualExists && file_get_contents($reference) === file_get_contents($actual)) {
        if (count($transformedBy) > 0) {
            $accepted[] = "$file (differs) accepted by " . implode(', ', $transformedBy);
        } else {
            $same++;
        }
        continue;
    }
    $changed = ref_changed_lines($referenceExists ? $reference : '/dev/null', $actualExists ? $actual : '/dev/null');
    $state = !$referenceExists ? 'only in this lane' : (!$actualExists ? 'missing in this lane' : 'differs');
    $diff = array();
    exec('diff -u ' . escapeshellarg($referenceExists ? $reference : '/dev/null') . ' '
        . escapeshellarg($actualExists ? $actual : '/dev/null') . ' 2>&1', $diff);
    ref_write("$reportDir/diffs/$file.diff", implode("\n", $diff) . "\n");
    if (strpos($file, 'diagnostics/') === 0) {
        $informational[] = "$file ($state, " . count($changed) . ' changed lines)';
        continue;
    }
    list($ids, $unmatched) = ref_classify_changes($php, $file, $changed, $entries);
    foreach ($ids as $id => $count) {
        $used[$id] = isset($used[$id]) ? $used[$id] + $count : $count;
    }
    if (count($unmatched) === 0) {
        $accepted[] = "$file ($state) accepted by " . implode(', ', array_merge($transformedBy, array_keys($ids)));
    } else {
        $failed[$file] = array($state, $unmatched, count($changed));
    }
}
$stale = ref_stale_entries($php, $entries, $used);

// Failures grouped by top-level category (http, sql, db, helpers, ...).
$categories = array();
foreach ($failed as $file => $failure) {
    $category = strpos($file, '/') === false ? $file : substr($file, 0, strpos($file, '/'));
    $categories[$category] = isset($categories[$category]) ? $categories[$category] + 1 : 1;
}
$report = "PHP 5 reference comparison on PHP $php\n"
    . count($files) . " files: $same identical, " . count($accepted) . ' accepted by the allowlist, '
    . count($failed) . ' with unaccepted differences, ' . count($informational) . " informational\n";
foreach ($categories as $category => $count) {
    $report .= "  $category: $count differing files\n";
}
// Allowlisted differences by category: "todo" ones are known defects to fix.
foreach (ref_category_summary($entries, $used) as $category => $ids) {
    $report .= "  allowlist $category: " . count($ids) . ' entries, ' . array_sum($ids) . ' changed lines'
        . (count($ids) > 0 ? ' (' . implode(', ', array_keys($ids)) . ')' : '') . "\n";
}
if (count($stale) > 0) {
    $report .= "\nStale allowlist entries (accepted nothing on PHP $php, PostgreSQL $pg): " . implode(', ', $stale) . "\n";
}
if (count($failed) > 0) {
    $report .= "\nUnaccepted differences (first lines; full diffs in diffs/):\n";
    foreach ($failed as $file => $failure) {
        list($state, $unmatched, $total) = $failure;
        $report .= "  $file ($state, " . count($unmatched) . " of $total changed lines unaccepted)\n";
        foreach (array_slice($unmatched, 0, 6) as $line) {
            $report .= '      ' . (strlen($line) > 200 ? substr($line, 0, 200) . '...' : $line) . "\n";
        }
    }
}
if (count($accepted) > 0) {
    $report .= "\nAccepted differences:\n  " . implode("\n  ", $accepted) . "\n";
}
if (count($informational) > 0) {
    $report .= "\nInformational (diagnostics, gated by tests/known-diagnostics.php):\n  " . implode("\n  ", $informational) . "\n";
}
ref_write("$reportDir/report.txt", $report);

// The console gets the first 300 report lines.
$lines = explode("\n", $report);
echo implode("\n", array_slice($lines, 0, 300)) . (count($lines) > 300 ? "\n... (see report.txt)\n" : "\n");
if (count($failed) > 0 || count($stale) > 0) {
    fwrite(STDERR, "PHP 5 reference comparison failed on PHP $php: " . count($failed) . ' files differ, '
        . count($stale) . " stale allowlist entries. Report: build/reference/php" . str_replace('.', '', $php) . "/report.txt\n");
    exit(1);
}
echo "PHP 5 reference comparison passed on PHP $php\n";
