<?php
/*
 * Shared code of the PHP 5 reference recordings (golden master): HTTP client,
 * normalization, statement log, database dumps, value export, and the
 * allowlist logic of the comparison. See tests/README.md, "PHP 5 reference
 * recordings".
 *
 * Nondeterminism is removed in exactly these places, and nowhere else:
 *
 * - Time: the application server runs under libfaketime with "now" fixed to
 *   2026-06-15 10:00:00 UTC (ticking from server start), so date()/time()
 *   based output (current year, report links, cookie expiry dates) is fixed.
 *   Cookie expiry times of day are replaced by {{time}} (seconds tick).
 * - Login cookie values (mt_rand()) are replaced by {{cookie}} in
 *   Set-Cookie headers, SQL texts and users.cookie.
 * - Captcha numbers (rand()) on the donation pages are replaced by
 *   {{captcha-a}}/{{captcha-b}} and {{captcha-c=a+b-37}} after checking that
 *   the hidden answer is consistent ({{captcha-c=INCONSISTENT}} otherwise).
 * - PostgreSQL's real clock: users.login values other than the fixture value
 *   become {{now}}, vouchers.save_date is always {{save_date}}, and
 *   md5-crypt password hashes (random salt) become {{md5-crypt}}.
 * - The impressum's PHP and PostgreSQL server versions (phpversion(),
 *   pg_version()) become {{php-version}} and {{pg-version}}.
 * - Response headers Date, Host, Connection and X-Powered-By (PHP version)
 *   are dropped; the status line is reduced to its code.
 * - Transport details that depend on the web server, not the application
 *   (the reference is recorded under Lighttpd + PHP-CGI on the PHP 5.3 lane,
 *   the other lanes use php -S): the Server header is dropped, and so is a
 *   Content-Length equal to the received body length (Lighttpd adds one,
 *   php -S does not; the body block records the length anyway). A
 *   Content-Length that differs from the body, e.g. file.php's ciphertext
 *   size for a missing file, is kept. Content-type, including its charset
 *   (default_charset), is application/php.ini behaviour and is kept.
 * - Xdebug is off (XDEBUG_MODE=off) and display_errors=0 in reference runs,
 *   so bodies hold only application output; diagnostics are recorded
 *   separately (diagnostics/, informational) from the diagnostics recorder.
 * - Floats in the helper golden master are exported with a fixed "%.17G"
 *   format, so var_export()'s precision change in PHP 7.1 is not reported.
 *
 * Must stay compatible with PHP 5.3 (helpers.php runs on the PHP 5.3 lane).
 */

define('REF_FIXED_NOW', '2026-06-15 10:00:00');
define('REF_FIXTURE_LOGIN', '2024-01-01 12:00:00');

function ref_fail($message)
{
    fwrite(STDERR, "Reference recording failed: $message\n");
    exit(1);
}

function ref_write($path, $content)
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
        ref_fail("could not create $directory");
    }
    if (file_put_contents($path, $content) === false) {
        ref_fail("could not write $path");
    }
}

// ---------------------------------------------------------------- HTTP

/*
 * One request with its own X-Pgacc-Test-Request ID (also used for the
 * statement log and the diagnostics file). $files is a list of
 * array(field, filename, content type, content) and makes the request
 * multipart/form-data.
 */
function ref_http($requestId, $method, $path, $form, $files, $cookies)
{
    $headers = array(
        'X-Forwarded-Proto: https',
        'Host: pgacc.test',
        'Connection: close',
        'X-Pgacc-Test-Request: ' . $requestId,
    );
    if (count($cookies) > 0) {
        $parts = array();
        foreach ($cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $parts);
    }
    $content = '';
    if ($method === 'POST') {
        if (count($files) > 0) {
            $boundary = 'pgacc-reference-boundary-' . sha1($requestId);
            foreach (ref_form_pairs($form, '') as $pair) {
                $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$pair[0]}\"\r\n\r\n{$pair[1]}\r\n";
            }
            foreach ($files as $file) {
                $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$file[0]}\"; filename=\"{$file[1]}\"\r\n"
                    . "Content-Type: {$file[2]}\r\n\r\n{$file[3]}\r\n";
            }
            $content .= "--$boundary--\r\n";
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
        } else {
            $content = http_build_query($form, '', '&');
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $headers[] = 'Content-Length: ' . strlen($content);
    }
    $context = stream_context_create(array(
        'http' => array(
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 60,
        ),
    ));
    $url = rtrim(getenv('PGACC_TEST_BASE_URL'), '/') . $path;
    if (function_exists('http_get_last_response_headers')) {
        $body = @file_get_contents($url, false, $context);
        $responseHeaders = http_get_last_response_headers();
    } else {
        require_once dirname(__DIR__) . '/http-legacy-headers.php';
        list($body, $responseHeaders) = legacy_file_get_contents_with_headers($url, $context);
    }
    if ($body === false || !is_array($responseHeaders) || count($responseHeaders) === 0) {
        ref_fail("request $requestId failed: $method $path");
    }
    if (!preg_match('/^HTTP\/\S+ (\d{3})/', $responseHeaders[0], $matches)) {
        ref_fail("could not parse the status of $requestId");
    }
    return array('status' => intval($matches[1]), 'headers' => array_slice($responseHeaders, 1), 'body' => $body);
}

function ref_form_pairs($form, $prefix)
{
    $pairs = array();
    foreach ($form as $key => $value) {
        $name = $prefix === '' ? $key : $prefix . '[' . $key . ']';
        if (is_array($value)) {
            $pairs = array_merge($pairs, ref_form_pairs($value, $name));
        } else {
            $pairs[] = array($name, (string) $value);
        }
    }
    return $pairs;
}

function ref_response_cookies($response)
{
    $cookies = array();
    foreach ($response['headers'] as $header) {
        if (stripos($header, 'Set-Cookie:') !== 0) {
            continue;
        }
        $pair = explode('=', current(explode(';', trim(substr($header, 11)), 2)), 2);
        if (count($pair) === 2) {
            $cookies[$pair[0]] = $pair[1];
        }
    }
    return $cookies;
}

function ref_normalize_set_cookie($value)
{
    $value = preg_replace('/^pp_pgacc_login=-?\d+/', 'pp_pgacc_login={{cookie}}', $value);
    return preg_replace('/(expires=\w{3}, \d{2}[- ]\w{3}[- ]\d{4} )\d{2}:\d{2}:\d{2}( GMT)/', '$1{{time}}$2', $value);
}

// Response headers that the snapshot keeps, normalized, in received order.
// $bodyLength: length of the received body (drops a Content-Length equal to it).
function ref_relevant_headers($headers, $bodyLength = null)
{
    $kept = array();
    foreach ($headers as $header) {
        $parts = explode(':', $header, 2);
        if (count($parts) !== 2) {
            $kept[] = 'unparsed: ' . $header;
            continue;
        }
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        if (in_array(strtolower($name), array('date', 'host', 'connection', 'x-powered-by', 'server'), true)) {
            continue;
        }
        if (strtolower($name) === 'content-length' && $bodyLength !== null && $value === strval($bodyLength)) {
            continue;
        }
        if (strtolower($name) === 'set-cookie') {
            $value = ref_normalize_set_cookie($value);
        }
        $kept[] = $name . ': ' . $value;
    }
    return $kept;
}

function ref_normalize_body($body)
{
    $body = preg_replace('#(<a href="http://www\.php\.net">PHP</a></td><td class=""><div>)[^<]*(</div>)#', '$1{{php-version}}$2', $body);
    $body = preg_replace('#(<a href="http://www\.postgresql\.org/">PostgreSQL</a></td><td class=""><div>)[^<]*(</div>)#', '$1{{pg-version}}$2', $body);
    $a = null;
    $b = null;
    if (preg_match('/Was ergibt die Addition (\d+) \+ (\d+)\?/', $body, $matches) === 1) {
        $a = intval($matches[1]);
        $b = intval($matches[2]);
        $body = str_replace($matches[0], 'Was ergibt die Addition {{captcha-a}} + {{captcha-b}}?', $body);
    }
    if (preg_match('/value="(-?\d+)" name="captcha"/', $body, $matches) === 1) {
        $consistent = $a !== null && $a >= 1 && $a <= 50 && $b >= 1 && $b <= 50
            && intval($matches[1]) === $a + $b - 37;
        $body = str_replace(
            $matches[0],
            'value="{{captcha-c=' . ($consistent ? 'a+b-37' : 'INCONSISTENT') . '}}" name="captcha"',
            $body
        );
    }
    return $body;
}

// Text bodies are kept verbatim; bodies that are not UTF-8 or contain NUL
// bytes (receipt downloads) are stored as base64 with length and hash.
function ref_body_block($body)
{
    $length = strlen($body);
    if (strpos($body, "\0") === false && preg_match('//u', $body) === 1) {
        return "--- body ($length bytes) ---\n" . $body;
    }
    return "--- body (binary, $length bytes, sha256 " . hash('sha256', $body) . ") ---\n"
        . chunk_split(base64_encode($body), 76, "\n");
}

function ref_http_snapshot($step, $response)
{
    $text = '# step: ' . $step['id'] . "\n"
        . '# request: ' . $step['method'] . ' ' . $step['path'] . ' as ' . $step['user'] . "\n";
    if (count($step['form']) > 0) {
        $text .= '# form: ' . http_build_query($step['form'], '', '&') . "\n";
    }
    foreach ($step['files'] as $file) {
        $text .= '# file: ' . $file[0] . ' ' . $file[1] . ' (' . $file[2] . ', ' . strlen($file[3]) . " bytes)\n";
    }
    $text .= 'status: ' . $response['status'] . "\n";
    foreach (ref_relevant_headers($response['headers'], strlen($response['body'])) as $header) {
        $text .= 'header: ' . $header . "\n";
    }
    return $text . ref_body_block(ref_normalize_body($response['body']));
}

// Diagnostics of one request as recorded by tests/runtime/diagnostics-prepend.php.
function ref_request_diagnostics($requestId)
{
    $path = rtrim(getenv('PGACC_TEST_DIAGNOSTICS_DIR'), '/') . '/' . $requestId . '.jsonl';
    $lines = array();
    $completed = false;
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                $lines[] = 'unreadable record: ' . $line;
                continue;
            }
            if ($record['type'] === 'REQUEST_END') {
                $completed = true;
                continue;
            }
            $lines[] = $record['type'] . ' ' . $record['file'] . ':' . $record['line']
                . ($record['reported'] ? '' : ' (hidden)') . ': ' . $record['message'];
        }
    }
    if (!$completed) {
        $lines[] = 'recorder did not complete (fatal error before shutdown, or no PHP request)';
    }
    return $lines;
}

// ---------------------------------------------------------------- statement log

function ref_database()
{
    $connection = @pg_connect(sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s application_name=pgacc-reference-client',
        getenv('PGHOST'),
        getenv('PGPORT'),
        getenv('PGDATABASE'),
        getenv('PGUSER'),
        getenv('PGPASSWORD')
    ));
    if ($connection === false) {
        ref_fail('could not connect to the reference database');
    }
    return $connection;
}

function ref_query($database, $query)
{
    $result = @pg_query($database, $query);
    if ($result === false) {
        ref_fail("query failed: $query: " . pg_last_error($database));
    }
    $rows = array();
    while (($row = pg_fetch_row($result)) !== false) {
        $rows[] = $row;
    }
    pg_free_result($result);
    return $rows;
}

/*
 * Reads the CSV statement log up to a marker statement that the recorder
 * sends on its own connection after each request. PostgreSQL logs a
 * statement when it receives it, through one pipe to the logging collector,
 * so every statement of a finished request precedes the marker.
 * Returns the records before the marker, as array(application_name,
 * severity, sqlstate, message, query).
 */
function ref_statement_log_until_marker($database, &$offset, $marker)
{
    ref_query($database, "SELECT 'pgacc-reference-marker:" . $marker . "'");
    $path = getenv('PGACC_REFERENCE_STATEMENT_LOG');
    $needle = "pgacc-reference-marker:$marker'";
    for ($attempt = 0; $attempt < 200; $attempt++) {
        clearstatcache();
        $data = is_file($path) ? file_get_contents($path, false, null, $offset) : '';
        if ($data !== false && strpos($data, $needle) !== false) {
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $data);
            rewind($stream);
            $records = array();
            // PostgreSQL's CSV log escapes quotes by doubling only; chr(1)
            // never occurs, so PHP's backslash escape does not interfere.
            while (($row = fgetcsv($stream, 0, ',', '"', "\x01")) !== false) {
                if (count($row) < 23) {
                    continue;
                }
                if ($row[22] === 'pgacc-reference-client' && strpos($row[13], $needle) !== false) {
                    $offset += ftell($stream);
                    fclose($stream);
                    return $records;
                }
                $records[] = array($row[22], $row[11], $row[12], $row[13], $row[19]);
            }
            fclose($stream);
        }
        usleep(50000);
    }
    ref_fail("statement log marker $marker did not appear in $path");
}

function ref_normalize_sql($sql)
{
    return preg_replace('/\bcookie = -?\d+/', 'cookie = {{cookie}}', $sql);
}

// The statements and errors of one request, normalized, one block each.
function ref_request_statements($records, $requestId)
{
    $lines = array();
    foreach ($records as $record) {
        if ($record[0] !== 'ref:' . $requestId) {
            continue;
        }
        if ($record[1] === 'LOG' && strpos($record[3], 'statement: ') === 0) {
            $lines[] = ref_normalize_sql(substr($record[3], 11)) . "\n";
        } elseif ($record[1] !== 'LOG') {
            $lines[] = '-- ' . $record[1] . ' ' . $record[2] . ': ' . ref_normalize_sql($record[3])
                . ($record[4] !== '' ? "\n-- in: " . ref_normalize_sql($record[4]) : '') . "\n";
        }
    }
    return $lines;
}

// Tables (and the sequences pseudo-table) that a request's statements write.
function ref_written_tables($statements)
{
    $tables = array();
    foreach ($statements as $statement) {
        if (preg_match_all('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|CREATE\s+TABLE|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|TRUNCATE(?:\s+TABLE)?)\s+"?([A-Za-z_][A-Za-z0-9_]*)/i', $statement, $matches)) {
            foreach ($matches[1] as $table) {
                $tables[strtolower($table)] = true;
            }
        }
        if (preg_match('/\b(?:nextval|setval)\s*\(/i', $statement) === 1) {
            $tables['(sequences)'] = true;
        }
    }
    ksort($tables);
    return array_keys($tables);
}

// ---------------------------------------------------------------- database dumps

function ref_escape_cell($value)
{
    if ($value === null) {
        return '\\N';
    }
    return str_replace(array('\\', "\t", "\n", "\r"), array('\\\\', '\\t', '\\n', '\\r'), $value);
}

function ref_normalize_cell($table, $column, $value)
{
    if ($value === null) {
        return null;
    }
    if ($table === 'users' && $column === 'cookie') {
        return '{{cookie}}';
    }
    if ($table === 'users' && $column === 'login' && $value !== REF_FIXTURE_LOGIN) {
        return '{{now}}';
    }
    if ($table === 'users' && $column === 'hash' && preg_match('/^\$1\$[.\/0-9A-Za-z]{1,8}\$[.\/0-9A-Za-z]{22}$/', $value) === 1) {
        return '{{md5-crypt}}';
    }
    if ($table === 'vouchers' && $column === 'save_date') {
        return '{{save_date}}';
    }
    return $value;
}

// A table as sorted, normalized lines: the header line first. Returns null
// when the table does not exist.
function ref_dump_table($database, $table)
{
    if ($table === '(sequences)') {
        $lines = array("sequence\tlast_value\tis_called");
        foreach (ref_query($database, "SELECT relname FROM pg_class WHERE relkind = 'S' ORDER BY relname") as $row) {
            $state = ref_query($database, 'SELECT last_value, is_called FROM "' . $row[0] . '"');
            $lines[] = $row[0] . "\t" . $state[0][0] . "\t" . $state[0][1];
        }
        return $lines;
    }
    $columns = ref_query($database, "SELECT a.attname FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid"
        . " JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r'"
        . " AND c.relname = '" . pg_escape_string($database, $table) . "' AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum");
    if (count($columns) === 0) {
        return null;
    }
    $names = array();
    foreach ($columns as $column) {
        $names[] = $column[0];
    }
    $lines = array();
    foreach (ref_query($database, 'SELECT * FROM "' . $table . '"') as $row) {
        $cells = array();
        foreach ($row as $index => $value) {
            $cells[] = ref_escape_cell(ref_normalize_cell($table, $names[$index], $value));
        }
        $lines[] = implode("\t", $cells);
    }
    sort($lines, SORT_STRING);
    array_unshift($lines, implode("\t", $names));
    return $lines;
}

// Rows removed ("-") and added ("+") between two dumps of a table.
function ref_dump_delta($before, $after)
{
    if ($before === null && $after === null) {
        return array('(absent before and after)');
    }
    if ($before === null) {
        return array_merge(array('(created) ' . $after[0]), ref_prefix_lines('+ ', array_slice($after, 1)));
    }
    if ($after === null) {
        return array_merge(array('(dropped) ' . $before[0]), ref_prefix_lines('- ', array_slice($before, 1)));
    }
    $lines = array();
    if ($before[0] !== $after[0]) {
        $lines[] = '(columns changed) ' . $before[0] . ' -> ' . $after[0];
    }
    $old = array_count_values(array_slice($before, 1));
    $new = array_count_values(array_slice($after, 1));
    $changes = array();
    foreach ($old as $row => $count) {
        $remaining = isset($new[$row]) ? $count - $new[$row] : $count;
        for ($i = 0; $i < $remaining; $i++) {
            $changes[] = array($row, '- ');
        }
    }
    foreach ($new as $row => $count) {
        $remaining = isset($old[$row]) ? $count - $old[$row] : $count;
        for ($i = 0; $i < $remaining; $i++) {
            $changes[] = array($row, '+ ');
        }
    }
    usort($changes, 'ref_compare_change');
    foreach ($changes as $change) {
        $lines[] = $change[1] . $change[0];
    }
    if (count($lines) === 0) {
        $lines[] = '(unchanged)';
    }
    return $lines;
}

function ref_compare_change($a, $b)
{
    $result = strcmp($a[0], $b[0]);
    return $result !== 0 ? $result : strcmp($a[1], $b[1]);
}

function ref_prefix_lines($prefix, $lines)
{
    $result = array();
    foreach ($lines as $line) {
        $result[] = $prefix . $line;
    }
    return $result;
}

// ---------------------------------------------------------------- value export

// A version-independent export: var_export() changed its float precision in
// PHP 7.1, which is a property of the dumper, not of the application.
function ref_export($value)
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value)) {
        return 'int(' . $value . ')';
    }
    if (is_float($value)) {
        if (is_nan($value)) {
            return 'float(NAN)';
        }
        if (is_infinite($value)) {
            return 'float(' . ($value > 0 ? '' : '-') . 'INF)';
        }
        if ($value == 0.0) {
            return 'float(' . (pack('d', $value) !== pack('d', 0.0) ? '-0' : '0') . ')';
        }
        return 'float(' . sprintf('%.17G', $value) . ')';
    }
    if (is_string($value)) {
        return 'string(' . strlen($value) . ') "' . addcslashes($value, "\0..\37\"\\\177..\377") . '"';
    }
    if (is_array($value)) {
        $parts = array();
        foreach ($value as $key => $item) {
            $parts[] = ref_export($key) . ' => ' . ref_export($item);
        }
        return 'array(' . implode(', ', $parts) . ')';
    }
    if (is_object($value)) {
        return 'object(' . get_class($value) . ')';
    }
    return gettype($value);
}

// ---------------------------------------------------------------- comparison

/*
 * Lines that differ between two texts, as "-line" (only in the reference)
 * and "+line" (only in the actual recording), from diff -U0.
 */
function ref_changed_lines($referencePath, $actualPath)
{
    $output = array();
    exec('diff -U0 ' . escapeshellarg($referencePath) . ' ' . escapeshellarg($actualPath) . ' 2>&1', $output);
    $changed = array();
    foreach ($output as $line) {
        if (strpos($line, '---') === 0 || strpos($line, '+++') === 0 || strpos($line, '@@') === 0) {
            continue;
        }
        if ($line !== '' && ($line[0] === '-' || $line[0] === '+')) {
            $changed[] = $line;
        } elseif (strpos($line, '\\ No newline') !== 0) {
            $changed[] = '?' . $line;
        }
    }
    return $changed;
}

// Problems of the allowlist itself (missing fields, invalid patterns).
function ref_allowlist_problems($entries)
{
    $problems = array();
    $ids = array();
    foreach ($entries as $index => $entry) {
        foreach (array('id', 'php', 'files', 'lines', 'reason', 'category') as $field) {
            if (!isset($entry[$field]) || $entry[$field] === '' || $entry[$field] === array()) {
                $problems[] = "entry $index: missing $field";
            }
        }
        if (isset($entry['id'])) {
            if (isset($ids[$entry['id']])) {
                $problems[] = "entry $index: duplicate id {$entry['id']}";
            }
            $ids[$entry['id']] = true;
        }
        if (isset($entry['php']) && !is_array($entry['php'])) {
            $problems[] = "entry $index: php must be a list of versions";
        }
        if (isset($entry['lines']) && is_string($entry['lines']) && @preg_match($entry['lines'], '') === false) {
            $problems[] = "entry $index: invalid lines pattern";
        }
        if (isset($entry['category']) && $entry['category'] !== ''
            && !in_array($entry['category'], array('accepted', 'todo'), true)) {
            $problems[] = "entry $index: category must be 'accepted' or 'todo'";
        }
    }
    return $problems;
}

/*
 * Classifies the changed lines of one file against the allowlist. A line is
 * accepted when an entry for this PHP version, whose "files" glob matches the
 * file, has a "lines" pattern matching the line (including its leading "-"
 * or "+"). Returns array(accepted ids => count, unmatched lines).
 */
function ref_classify_changes($php, $file, $changedLines, $entries)
{
    $used = array();
    $unmatched = array();
    foreach ($changedLines as $line) {
        $matched = false;
        foreach ($entries as $entry) {
            if (!in_array($php, $entry['php'], true) || !fnmatch($entry['files'], $file)) {
                continue;
            }
            if (preg_match($entry['lines'], $line) === 1) {
                $used[$entry['id']] = isset($used[$entry['id']]) ? $used[$entry['id']] + 1 : 1;
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $unmatched[] = $line;
        }
    }
    return array($used, $unmatched);
}

// Per category ('accepted', 'todo'): the used entry IDs and their accepted
// line counts, for the comparison summary.
function ref_category_summary($entries, $usedIds)
{
    $summary = array('accepted' => array(), 'todo' => array());
    foreach ($entries as $entry) {
        if (isset($usedIds[$entry['id']])) {
            $summary[$entry['category']][$entry['id']] = $usedIds[$entry['id']];
        }
    }
    return $summary;
}

// Entries for this PHP version that accepted nothing in a whole comparison.
function ref_stale_entries($php, $entries, $usedIds)
{
    $stale = array();
    foreach ($entries as $entry) {
        if (in_array($php, $entry['php'], true) && !isset($usedIds[$entry['id']])) {
            $stale[] = $entry['id'];
        }
    }
    return $stale;
}

// Recorded files below $directory, relative, sorted.
function ref_list_files($directory, $prefix = '')
{
    $files = array();
    if (!is_dir($directory)) {
        return $files;
    }
    foreach (scandir($directory) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (is_dir("$directory/$name")) {
            $files = array_merge($files, ref_list_files("$directory/$name", "$prefix$name/"));
        } else {
            $files[] = $prefix . $name;
        }
    }
    sort($files, SORT_STRING);
    return $files;
}
