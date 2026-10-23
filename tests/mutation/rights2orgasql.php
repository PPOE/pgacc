<?php

// Targeted mutation test of the authorization helper. Only disposable copies
// are modified; no database or network is needed for the selected PHPUnit test.
$root = $argv[1];
$output = $argv[2];
$runner = $argv[3];
if (!is_dir($output) && !mkdir($output, 0777, true)) {
    throw new RuntimeException('Cannot create report directory');
}

function run_process($arguments, $log)
{
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $process = proc_open($command, array(
        0 => array('pipe', 'r'),
        1 => array('file', $log, 'w'),
        2 => array('redirect', 1),
    ), $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start test process');
    }
    fclose($pipes[0]);
    return proc_close($process);
}

function replace_once($source, $from, $to)
{
    $position = strpos($source, $from);
    if ($position === false) {
        throw new RuntimeException('Mutation does not match source: ' . $from);
    }
    return substr_replace($source, $to, $position, strlen($from));
}

$original = file_get_contents($root . '/functions.php');
$source = str_replace("\r\n", "\n", $original);
$start = strpos($source, 'function rights2orgasql(');
$end = strpos($source, 'function konto2lo(', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Cannot locate the complete helper');
}
$function = substr($source, $start, $end - $start);

$mutations = array(
    array('array-initialization-removed', '    $rights2 = array();' . "\n", ''),
    array('privilege-and-to-or', " && !in_array('Rbgf',\$rights_r)", " || !in_array('Rbgf',\$rights_r)"),
    array('remove-bgf-exemption', "!in_array('bgf',\$rights_r)", 'true'),
    array('remove-Rbgf-exemption', "!in_array('Rbgf',\$rights_r)", 'true'),
    array('grant-root-exemption', " && !in_array('Rbgf',\$rights_r)", " && !in_array('Rbgf',\$rights_r) && !in_array('root',\$rights_r)"),
    array('bgf-only-as-entire-input', "!in_array('bgf',\$rights_r)", "\$rights !== 'bgf'"),
    array('Rbgf-only-as-entire-input', "!in_array('Rbgf',\$rights_r)", "\$rights !== 'Rbgf'"),
    array('bgf-substring-grants-access', "!in_array('bgf',\$rights_r)", "strpos(\$rights, 'bgf') === false"),
    array('Rbgf-substring-grants-access', "!in_array('Rbgf',\$rights_r)", "strpos(\$rights, 'Rbgf') === false"),
    array('bgf-matching-case-insensitive', "!in_array('bgf',\$rights_r)", "!in_array('bgf', array_map('strtolower', \$rights_r))"),
    array('numeric-validation-always-false', "preg_match('/^\\d+$/', \$right) == 1", 'false'),
    array('numeric-validation-always-true', "preg_match('/^\\d+$/', \$right) == 1", 'true'),
    array('numeric-start-anchor-removed', "'/^\\d+$/'", "'/\\d+$/'"),
    array('numeric-end-anchor-removed', "'/^\\d+$/'", "'/^\\d+/'"),
    array('empty-token-accepted-as-id', "'/^\\d+$/'", "'/^\\d*$/'"),
    array('negative-id-accepted', "'/^\\d+$/'", "'/^-?\\d+$/'"),
    array('decimal-id-accepted', "'/^\\d+$/'", "'/^\\d+(?:\\.\\d+)?$/'"),
    array('single-organization-denied', 'count($rights2) > 0', 'count($rights2) > 1'),
    array('count-condition-inverted', 'count($rights2) > 0', 'count($rights2) == 0'),
    array('restriction-uses-or', ' AND orga IN (', ' OR orga IN ('),
    array('restriction-excludes-authorized-orgs', ' AND orga IN (', ' AND orga NOT IN ('),
    array('unknown-rights-fail-open', 'return " AND false ";', 'return "";'),
    array('privileged-rights-denied', 'return "";', 'return " AND false ";'),
    array('retain-only-last-organization', '$rights2[] = "\'".$right."\'";', '$rights2 = array("\'".$right."\'");'),
    array('id-quotes-removed', '$rights2[] = "\'".$right."\'";', '$rights2[] = $right;'),
    array('zero-id-rejected', "preg_match('/^\\d+$/', \$right) == 1", "preg_match('/^\\d+$/', \$right) == 1 && \$right !== '0'"),
    array('leading-zero-id-rejected', "'/^\\d+$/'", "'/^[1-9]\\d*$/'"),
    array('privilege-delimiter-changed', 'explode(",", $rights)', 'explode(";", $rights)'),
    array('organization-delimiter-changed', "\$rights_r = explode(\",\", \$rights);\n    \$rights2 = array();", "\$rights_r = explode(\";\", \$rights);\n    \$rights2 = array();"),
    array('mixed-roles-drop-valid-ids', "preg_match('/^\\d+$/', \$right) == 1", "preg_match('/^\\d+(,\\d+)*$/', \$rights) == 1 && preg_match('/^\\d+$/', \$right) == 1"),
    array('empty-rights-fail-open', 'return " AND false ";', 'return $rights === "" ? "" : " AND false ";'),
);

$work = $output . '/workspace';
foreach (array('/tests/unit') as $directory) {
    if (!is_dir($work . $directory)) {
        mkdir($work . $directory, 0777, true);
    }
}
foreach (array('tests/phpunit-bootstrap.php', 'tests/functions-bootstrap.php', 'tests/FunctionsTestCase.php', 'tests/unit/FunctionsTest.php') as $file) {
    $contents = file_get_contents($root . '/' . $file);
    file_put_contents($work . '/' . $file, $contents);
}

$arguments = array(PHP_BINARY, $runner, '--no-configuration', '--no-coverage',
    '--do-not-cache-result', '--colors=never', '--bootstrap', $work . '/tests/phpunit-bootstrap.php',
    '--filter', 'testRights2orgasql', $work . '/tests/unit/FunctionsTest.php');
$prefix = substr($source, 0, $start);
$suffix = substr($source, $end);
file_put_contents($work . '/functions.php', $prefix . $function . $suffix);
$baselineStatus = run_process($arguments, $output . '/baseline.log');
echo file_get_contents($output . '/baseline.log');
if ($baselineStatus !== 0) {
    throw new RuntimeException('Baseline must pass before mutation testing; see baseline.log');
}

$results = array();
$killed = 0;
$invalid = 0;
foreach ($mutations as $mutation) {
    list($name, $from, $to) = $mutation;
    $mutant = replace_once($function, $from, $to);
    file_put_contents($work . '/functions.php', $prefix . $mutant . $suffix);
    $lintStatus = run_process(array(PHP_BINARY, '-l', $work . '/functions.php'), $output . '/' . $name . '.lint.log');
    if ($lintStatus !== 0) {
        $status = 'invalid';
        $invalid++;
    } else {
        $exitCode = run_process($arguments, $output . '/' . $name . '.log');
        $status = $exitCode === 0 ? 'survived' : 'killed';
        if ($status === 'killed') {
            $killed++;
        }
    }
    $results[] = array('name' => $name, 'status' => $status, 'from' => $from, 'to' => $to);
    echo strtoupper($status) . ': ' . $name . PHP_EOL;
}

file_put_contents($output . '/results.json', json_encode(array(
    'php' => PHP_VERSION,
    'source_sha256' => hash('sha256', $original),
    'killed' => $killed,
    'total' => count($mutations),
    'invalid' => $invalid,
    'mutations' => $results,
), JSON_PRETTY_PRINT) . PHP_EOL);
printf("Targeted mutation result: %d/%d killed; %d invalid\n", $killed, count($mutations), $invalid);
exit($killed === count($mutations) && $invalid === 0 ? 0 : 1);
