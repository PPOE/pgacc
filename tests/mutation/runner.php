<?php
/*
 * Target-driven mutation runner. Started by tests/mutation/run.sh inside a
 * one-off container of a lane's application image (see README.md):
 *
 *   php tests/mutation/runner.php --target=<name|path> --output=<dir>
 *       [--only=id,id] [--check] [--app=/app] [--work=/tmp/pgacc-mutation]
 *
 * For the unmutated baseline and then for every mutant it:
 *   1. copies the application to a disposable directory and applies the
 *      mutation there (the image's /app is never modified);
 *   2. runs "php -l" on the mutated file (failure: invalid);
 *   3. recreates the database from a template built once from the same
 *      fixtures as tests/database-smoke.sh;
 *   4. for HTTP tests, starts a fresh "php -S" with the application service's
 *      settings from compose.test.yaml (output_buffering and the diagnostics
 *      recorder), with a per-mutant diagnostics directory;
 *   5. runs the target's test commands until one fails, within the target's
 *      per-mutant timeout.
 * The baseline must pass, otherwise nothing is mutated (exit 2).
 *
 * --check only validates the target and that every mutation applies.
 *
 * Exit status: 0 pass, 1 unaccepted survivors / invalid / stale equivalents,
 * 2 definition, setup, or baseline failure. Must stay compatible with PHP 5.6.
 */

require __DIR__ . '/lib.php';

function out($message)
{
    echo $message . "\n";
}

function parse_options($argv)
{
    $options = array('app' => '/app', 'work' => '/tmp/pgacc-mutation', 'only' => null, 'check' => false);
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--check') {
            $options['check'] = true;
        } elseif (preg_match('/^--(target|output|only|app|work)=(.+)$/', $argument, $match) === 1) {
            $options[$match[1]] = $match[2];
        } else {
            pgacc_mutation_fail("unknown argument $argument");
        }
    }
    if (!isset($options['target'])) {
        pgacc_mutation_fail('--target is required');
    }
    if (!$options['check'] && !isset($options['output'])) {
        pgacc_mutation_fail('--output is required');
    }
    return $options;
}

function shell($command)
{
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) {
        pgacc_mutation_fail("command failed ($status): $command\n" . implode("\n", $output));
    }
}

function maintenance_connection()
{
    $connection = @pg_connect(sprintf(
        'host=%s port=%s dbname=postgres user=%s password=%s',
        getenv('PGHOST'),
        getenv('PGPORT'),
        getenv('PGUSER'),
        getenv('PGPASSWORD')
    ), PGSQL_CONNECT_FORCE_NEW);
    if ($connection === false) {
        pgacc_mutation_fail('cannot connect to the PostgreSQL maintenance database');
    }
    return $connection;
}

function sql($connection, $query)
{
    $result = @pg_query($connection, $query);
    if ($result === false) {
        pgacc_mutation_fail("SQL failed: $query\n" . pg_last_error($connection));
    }
    return $result;
}

function database_name()
{
    $name = getenv('PGDATABASE');
    if ($name === false || preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
        pgacc_mutation_fail('PGDATABASE must name the application database');
    }
    return $name;
}

// Builds the template once from the lane's fixtures (in one transaction, as
// database-smoke.sh does). index.php connects to the fixed name "accounting",
// so each run recreates that database from the template.
function create_template($app)
{
    $template = database_name() . '_mutation_template';
    $maintenance = maintenance_connection();
    sql($maintenance, "DROP DATABASE IF EXISTS $template WITH (FORCE)");
    sql($maintenance, "CREATE DATABASE $template");
    $connection = @pg_connect(sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s',
        getenv('PGHOST'),
        getenv('PGPORT'),
        $template,
        getenv('PGUSER'),
        getenv('PGPASSWORD')
    ), PGSQL_CONNECT_FORCE_NEW);
    if ($connection === false) {
        pgacc_mutation_fail("cannot connect to $template");
    }
    $sql = 'BEGIN;';
    foreach (pgacc_mutation_fixture_files() as $file) {
        $sql .= "\n" . file_get_contents($app . '/' . $file);
    }
    sql($connection, $sql . "\nCOMMIT;");
    pg_close($connection);
    return array('connection' => $maintenance, 'template' => $template);
}

function reset_database($database)
{
    $name = database_name();
    sql($database['connection'], "DROP DATABASE IF EXISTS $name WITH (FORCE)");
    sql($database['connection'], "CREATE DATABASE $name TEMPLATE {$database['template']}");
}

function drop_template($database)
{
    sql($database['connection'], "DROP DATABASE IF EXISTS {$database['template']} WITH (FORCE)");
}

// Copy of the application without vendor/ (linked) and runtime directories.
function create_pristine($app, $work)
{
    $pristine = $work . '/pristine';
    shell('rm -rf ' . escapeshellarg($work));
    shell('mkdir -p ' . escapeshellarg($pristine));
    foreach (scandir($app) as $entry) {
        if (in_array($entry, array('.', '..', 'vendor', 'files', 'build', '.git'), true)) {
            continue;
        }
        shell('cp -a ' . escapeshellarg($app . '/' . $entry) . ' ' . escapeshellarg($pristine . '/'));
    }
    if (!symlink($app . '/vendor', $pristine . '/vendor')) {
        pgacc_mutation_fail('cannot link vendor/');
    }
    return $pristine;
}

function phpunit_arguments($copy, $test)
{
    $runner = getenv('PGACC_MUTATION_PHPUNIT');
    if (PHP_VERSION_ID < 70000) {
        $runner = $runner ? $runner : '/opt/test-tools/phpunit-5.7.27.phar';
        $arguments = array(PHP_BINARY, $runner, '--no-configuration', '--colors=never',
            '--bootstrap', $copy . '/tests/php56/bootstrap.php');
    } else {
        $runner = $runner ? $runner : $copy . '/vendor/bin/phpunit';
        $arguments = array(PHP_BINARY, $runner, '--no-configuration', '--no-coverage',
            '--do-not-cache-result', '--colors=never', '--bootstrap', $copy . '/tests/phpunit-bootstrap.php');
    }
    return array_merge($arguments, array('--filter', $test['filter'], $copy . '/' . $test['file']));
}

function wait_for_port($port, $seconds)
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);
            return true;
        }
        usleep(50000);
    }
    return false;
}

/*
 * Runs one case (baseline when $mutation is null). Returns the result record;
 * the mutant directory is removed afterwards, logs are kept in $output.
 */
function run_case($context, $mutation, $mutantSource, $index)
{
    $target = $context['target'];
    $id = $mutation === null ? 'baseline' : $mutation['id'];
    $log = $context['output'] . '/' . $id . '.log';
    file_put_contents($log, '');
    $started = microtime(true);
    $copy = $context['work'] . '/' . $id;
    shell('cp -a ' . escapeshellarg($context['pristine']) . ' ' . escapeshellarg($copy));
    shell('mkdir -p ' . escapeshellarg($copy . '/files'));
    $lintExit = 0;
    $files = array();
    foreach ($target['mutations'] as $definition) {
        $files[$definition['file']] = true;
    }
    if ($mutation !== null) {
        file_put_contents($copy . '/' . $mutation['file'], $mutantSource);
        $files = array($mutation['file'] => true);
    }
    foreach (array_keys($files) as $file) {
        $lint = pgacc_mutation_run(array(PHP_BINARY, '-l', $copy . '/' . $file), array(), $copy, $log, 60);
        if ($lint['exit'] !== 0) {
            $lintExit = 1;
        }
    }
    $runs = array();
    if ($lintExit === 0) {
        if ($context['database'] !== null) {
            reset_database($context['database']);
        }
        $diagnostics = $context['work'] . '/diagnostics-' . $id;
        shell('rm -rf ' . escapeshellarg($diagnostics) . ' && mkdir -p ' . escapeshellarg($diagnostics));
        $port = 18080 + $index;
        $environment = array(
            'PGACC_TEST_DIAGNOSTICS_DIR' => $diagnostics,
            'PGACC_TEST_BASE_URL' => 'http://127.0.0.1:' . $port,
        );
        $server = null;
        if ($context['http']) {
            $arguments = array(PHP_BINARY);
            foreach (pgacc_mutation_server_ini() as $setting) {
                $arguments[] = '-d';
                $arguments[] = str_replace('%APP%', $copy, $setting);
            }
            $arguments = array_merge($arguments, array('-S', '127.0.0.1:' . $port, '-t', $copy));
            $server = pgacc_mutation_start($arguments, $environment, $copy,
                $context['output'] . '/' . $id . '.server.log');
            if (!wait_for_port($port, 15)) {
                pgacc_mutation_stop($server);
                pgacc_mutation_fail("test server for $id did not start; see $id.server.log");
            }
        }
        foreach ($target['tests'] as $test) {
            $remaining = $target['timeout'] - (microtime(true) - $started);
            if ($test['type'] === 'http') {
                $arguments = array(PHP_BINARY, $copy . '/' . $test['script']);
                $label = $test['script'];
                $testEnvironment = $environment;
            } else {
                $arguments = phpunit_arguments($copy, $test);
                $label = 'phpunit --filter ' . $test['filter'];
                // Coverage is not collected here; Xdebug only slows PHPUnit down.
                $testEnvironment = array_merge($environment, array('XDEBUG_MODE' => 'off'));
            }
            file_put_contents($log, "\n[mutation runner] $label\n", FILE_APPEND);
            if ($remaining <= 0) {
                $run = array('exit' => null, 'timed_out' => true, 'seconds' => 0.0);
            } else {
                $run = pgacc_mutation_run($arguments, $testEnvironment, $copy, $log, $remaining);
            }
            $run['command'] = $label;
            $runs[] = $run;
            file_put_contents($log, "[mutation runner] $label: "
                . ($run['timed_out'] ? 'timeout' : 'exit ' . $run['exit']) . "\n", FILE_APPEND);
            if ($run['timed_out'] || $run['exit'] !== 0) {
                break;
            }
        }
        if ($server !== null) {
            pgacc_mutation_stop($server);
        }
        shell('rm -rf ' . escapeshellarg($diagnostics));
    }
    shell('rm -rf ' . escapeshellarg($copy));
    $status = pgacc_mutation_classify($lintExit, $runs);
    $failedBy = null;
    foreach ($runs as $run) {
        if ($run['timed_out'] || $run['exit'] !== 0) {
            $failedBy = $run['command'];
        }
    }
    return array(
        'id' => $id,
        'raw_status' => $status,
        'failed_by' => $failedBy,
        'seconds' => round(microtime(true) - $started, 2),
        'log' => basename($log),
    );
}

function main($argv)
{
    $options = parse_options($argv);
    $app = rtrim($options['app'], '/');
    $targetPath = strpos($options['target'], '/') === false
        ? $app . '/tests/mutation/targets/' . $options['target'] . '.php'
        : $options['target'];
    $target = pgacc_mutation_load_target($targetPath);
    $name = basename($targetPath, '.php');
    if ($options['only'] !== null) {
        $only = explode(',', $options['only']);
        $selected = array();
        foreach ($target['mutations'] as $mutation) {
            if (in_array($mutation['id'], $only, true)) {
                $selected[] = $mutation;
            }
        }
        if (count($selected) !== count(array_unique($only))) {
            pgacc_mutation_fail('--only names unknown mutation ids');
        }
        $target['mutations'] = $selected;
    }
    $phpMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

    // Apply every mutation before running anything: definition errors abort.
    $sources = array();
    $mutants = array();
    foreach ($target['mutations'] as $mutation) {
        if (!isset($sources[$mutation['file']])) {
            $path = $app . '/' . $mutation['file'];
            if (!is_file($path)) {
                pgacc_mutation_fail("mutation {$mutation['id']}: file not found: {$mutation['file']}");
            }
            $sources[$mutation['file']] = file_get_contents($path);
        }
        $mutants[$mutation['id']] = pgacc_mutation_apply($sources[$mutation['file']], $mutation);
    }
    $http = false;
    foreach ($target['tests'] as $test) {
        $file = $test['type'] === 'http' ? $test['script'] : $test['file'];
        if (!is_file($app . '/' . $file)) {
            pgacc_mutation_fail("test file not found: $file");
        }
        $http = $http || $test['type'] === 'http';
    }
    out(sprintf('Target %s: %d mutation(s) apply exactly once', $name, count($mutants)));
    if ($options['check']) {
        foreach ($target['mutations'] as $mutation) {
            out(sprintf('  %-40s %s:%d', $mutation['id'], $mutation['file'], $mutants[$mutation['id']]['line']));
        }
        return 0;
    }

    $output = rtrim($options['output'], '/');
    if (!is_dir($output) && !mkdir($output, 0777, true)) {
        pgacc_mutation_fail("cannot create $output");
    }
    $work = rtrim($options['work'], '/');
    $context = array(
        'target' => $target,
        'output' => $output,
        'work' => $work,
        'pristine' => create_pristine($app, $work),
        'http' => $http,
        // The PHPUnit bootstrap connects to the database too when PGHOST is set.
        'database' => getenv('PGHOST') ? create_template($app) : null,
    );

    out("PHP " . PHP_VERSION . ": running baseline (" . implode(', ', array_map(function ($test) {
        return $test['type'] === 'http' ? $test['script'] : 'phpunit --filter ' . $test['filter'];
    }, $target['tests'])) . ')');
    $baseline = run_case($context, null, null, 0);
    if ($baseline['raw_status'] !== 'survived') {
        echo file_get_contents($output . '/baseline.log');
        out("Baseline FAILED ({$baseline['raw_status']}, {$baseline['failed_by']}) on PHP $phpMinor; "
            . 'the unmutated application must pass before mutation testing. See baseline.log.');
        file_put_contents($output . '/results.json', json_encode(array(
            'target' => $name, 'php' => PHP_VERSION, 'baseline' => $baseline, 'mutants' => array(),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return 2;
    }
    out(sprintf('Baseline passed in %.1fs', $baseline['seconds']));

    $results = array();
    foreach ($target['mutations'] as $index => $mutation) {
        $mutant = $mutants[$mutation['id']];
        $result = run_case($context, $mutation, $mutant['source'], $index + 1);
        $status = pgacc_mutation_final_status($result['raw_status'], $mutation, $phpMinor);
        $result['status'] = $status;
        $result['file'] = $mutation['file'];
        $result['line'] = $mutant['line'];
        $result['search'] = $mutation['search'][$mutant['spelling']];
        $result['replace'] = $mutation['replace'][$mutant['spelling']];
        $result['rationale'] = $mutation['rationale'];
        $result['equivalent_reason'] = pgacc_mutation_equivalent_reason($mutation, $phpMinor);
        $result['note'] = $status === 'killed' || $status === 'timeout'
            ? 'by ' . $result['failed_by']
            : ($result['equivalent_reason'] !== null ? 'accepted: ' . $result['equivalent_reason'] : null);
        $results[] = $result;
        out(sprintf('%-17s %s (%.1fs)', strtoupper($status), $mutation['id'], $result['seconds']));
    }
    if ($context['database'] !== null) {
        drop_template($context['database']);
    }
    shell('rm -rf ' . escapeshellarg($work));

    $summary = pgacc_mutation_summarize($results);
    $hashes = array();
    foreach ($sources as $file => $source) {
        $hashes[$file] = hash('sha256', $source);
    }
    file_put_contents($output . '/results.json', json_encode(array(
        'target' => $name,
        'description' => $target['description'],
        'php' => PHP_VERSION,
        'source_sha256' => $hashes,
        'tests' => $target['tests'],
        'timeout_seconds' => $target['timeout'],
        'baseline' => $baseline,
        'summary' => $summary,
        'mutants' => $results,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $text = pgacc_mutation_format_summary($name, PHP_VERSION, $results, $summary);
    file_put_contents($output . '/summary.txt', $text);
    echo "\n" . $text;
    return $summary['exit_code'];
}

try {
    exit(main($argv));
} catch (PgaccMutationException $exception) {
    fwrite(STDERR, 'Mutation runner error: ' . $exception->getMessage() . "\n");
    exit(2);
}
