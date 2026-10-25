<?php

/*
 * Resets the state the HTTP tests change, before each test file of
 * tests/dev.sh (development loop only; tests/run.sh and CI start a fresh
 * stack instead):
 *
 *   php tests/dev/reset.php [directory...]
 *
 * The database PGDATABASE is recreated from a template database, which is
 * built from the same fixture files as tests/database-smoke.sh (in one
 * transaction, as the mutation runner does) and rebuilt whenever one of these
 * files changes (their SHA-256 is the template's comment). The contents of
 * every given directory (receipt files, diagnostics, the test client's /tmp)
 * are removed.
 */

require __DIR__ . '/../mutation/lib.php';

function dev_fail($message)
{
    fwrite(STDERR, "tests/dev/reset.php: $message\n");
    exit(2);
}

function dev_connect($database)
{
    $connection = false;
    // The server may report healthy before it accepts TCP connections.
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $connection = @pg_connect(sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s',
            getenv('PGHOST'),
            getenv('PGPORT'),
            $database,
            getenv('PGUSER'),
            getenv('PGPASSWORD'),
        ), PGSQL_CONNECT_FORCE_NEW);
        if ($connection !== false) {
            return $connection;
        }
        sleep(1);
    }
    dev_fail("cannot connect to database $database");
}

function dev_sql($connection, $query, $parameters = [])
{
    $result = @pg_query_params($connection, $query, $parameters);
    if ($result === false) {
        dev_fail("SQL failed: " . substr($query, 0, 200) . "\n" . pg_last_error($connection));
    }
    return $result;
}

function dev_empty_directory($directory)
{
    if (!is_dir($directory)) {
        dev_fail("no such directory: $directory");
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $removed = $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        if (!$removed) {
            dev_fail('cannot remove ' . $entry->getPathname());
        }
    }
}

$database = getenv('PGDATABASE');
if ($database === false || preg_match('/^[a-z_][a-z0-9_]*$/', $database) !== 1) {
    dev_fail('PGDATABASE must name the application database');
}
$template = $database . '_dev_template';
$root = dirname(__DIR__, 2);

$sql = '';
foreach (pgacc_mutation_fixture_files() as $file) {
    $contents = file_get_contents($root . '/' . $file);
    if ($contents === false) {
        dev_fail("cannot read $file");
    }
    $sql .= "\n" . $contents;
}
$hash = hash('sha256', $sql);

$maintenance = dev_connect('postgres');
$row = pg_fetch_row(dev_sql(
    $maintenance,
    "SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = $1",
    [$template],
));
if ($row === false || $row[0] !== $hash) {
    echo "Building the template database $template from the fixtures\n";
    dev_sql($maintenance, "DROP DATABASE IF EXISTS $template WITH (FORCE)");
    dev_sql($maintenance, "CREATE DATABASE $template");
    $connection = dev_connect($template);
    // Several statements: pg_query(), as pg_query_params() takes only one.
    if (@pg_query($connection, "SET client_min_messages = warning;\nBEGIN;$sql\nCOMMIT;") === false) {
        $error = pg_last_error($connection);
        pg_close($connection);
        dev_sql($maintenance, "DROP DATABASE IF EXISTS $template WITH (FORCE)");
        dev_fail("loading the fixtures failed\n$error");
    }
    pg_close($connection);
    dev_sql($maintenance, "COMMENT ON DATABASE $template IS '$hash'");
}
foreach (pgacc_mutation_drop_database_sql($database) as $query) {
    dev_sql($maintenance, $query);
}
dev_sql($maintenance, "CREATE DATABASE $database TEMPLATE $template");
pg_close($maintenance);

foreach (array_slice($argv, 1) as $directory) {
    dev_empty_directory($directory);
}
