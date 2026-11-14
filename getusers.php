<?php

// The largest member list body that getusers_fetch() accepts (4 MiB).
const GETUSERS_MAX_BODY = 4194304;

// One SQL statement without a PHP warning: null, or the error message.
function getusers_query($query)
{
    global $dbconn;
    if (!pg_send_query($dbconn, $query)) {
        return pg_last_error($dbconn);
    }
    $result = pg_get_result($dbconn);
    // One statement, one result: this ends the query.
    while (pg_get_result($dbconn) !== false) {
    }
    if ($result === false) {
        return pg_last_error($dbconn);
    }
    $error = pg_result_status($result) == PGSQL_FATAL_ERROR ? pg_result_error($result) : null;
    pg_free_result($result);
    return $error;
}

// An error of getUsers(), logged once (a failed fetch logs its cause instead).
function getusers_failed($message)
{
    error_log('getusers: ' . $message);
    return $message;
}

// True if $uuid is a UUID (8-4-4-4-12 hex digits), the form of Admidio's
// user_uuid. Checked before a uuid is stored or put into a link.
function member_uuid_valid($uuid)
{
    return is_string($uuid) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $uuid) === 1;
}

// The member id, followed by the member's $name (if any), linked to the
// member's profile in the membership system if $uuid (from the member list)
// is a UUID: Admidio opens profiles by user_uuid only. Otherwise as plain
// text. HTML.
function member_link($id, $uuid, $name = null)
{
    $text = intval($id) . ($name === null ? '' : ' ' . html_escape($name));
    if (!member_uuid_valid($uuid)) {
        return $text;
    }
    return '<a href="https://mitglieder.piratenpartei.at/modules/profile/profile.php?user_uuid=' . $uuid . '">' . $text . '</a>';
}

// The member list settings of the configuration $file (sync-secrets.php in
// the release root, resolved like the include of constants.php):
// ['members_url' => 'https://...', 'members_token' => non-empty string,
// 'members_cafile' => the membership system's certificate, ...], or null if
// the file is missing, does not load (a parse error or an exception: logged
// by class only) or one of them is missing, empty or (the URL) not https.
function getusers_config($file)
{
    if (stream_resolve_include_path($file) === false) {
        return null;
    }
    try {
        $config = include $file;
    } catch (Throwable $e) {
        // Only the class: the message of a parse error can quote the file.
        error_log('getusers: sync-secrets.php cannot be loaded (' . get_class($e) . ')');
        return null;
    }
    foreach (['members_url', 'members_token', 'members_cafile'] as $name) {
        if (!is_string($config[$name] ?? null) || $config[$name] === '') {
            return null;
        }
    }
    if (strncmp($config['members_url'], 'https://', 8) !== 0) {
        return null;
    }
    return $config;
}

// The body of the member list, or null (the cause is logged). TLS is
// verified against members_cafile only (the membership system's
// certificate; with a cafile PHP does not load the system's CAs), including
// the host name or address of the URL. Redirects are not followed (a 3xx is
// an error), so the list never comes from another host or over plain HTTP.
// Only 200 is accepted, with a complete body of at most GETUSERS_MAX_BODY
// bytes (a response not complete by the deadline is refused, not returned
// in part) that matches every Content-Length header, if there is one. $timeout: seconds for connection, headers and body together
// (PHP's own stream timeout applies to each read only; the tests pass less).
// The token is never logged.
function getusers_fetch($config, $timeout = 20)
{
    $deadline = microtime(true) + $timeout;
    $url = parse_url($config['members_url']);
    // No user info (a password always comes with a user, if only an empty one).
    if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user'])) {
        error_log('getusers: not an https URL');
        return null;
    }
    $port = $url['port'] ?? 443;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => trim($url['host'], '[]'),
            'allow_self_signed' => false,
            'cafile' => $config['members_cafile'],
        ],
    ]);
    error_clear_last();
    $stream = @stream_socket_client("ssl://{$url['host']}:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if ($stream === false) {
        $error = error_get_last();
        error_log('getusers: ' . ($errstr !== '' ? $errstr : ($error === null ? 'fetch failed' : $error['message'])));
        return null;
    }
    // HTTP/1.0: no chunked body; the connection closes after the response.
    $request = 'GET ' . ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '') . " HTTP/1.0\r\n"
        . 'Host: ' . $url['host'] . (isset($url['port']) ? ':' . $port : '') . "\r\n"
        . 'Authorization: Bearer ' . $config['members_token'] . "\r\nAccept: application/json\r\nConnection: close\r\n\r\n";
    $response = '';
    $timedOut = false;
    $limit = 65536 + GETUSERS_MAX_BODY + 1;
    $written = @fwrite($stream, $request) === strlen($request);
    while ($written && !feof($stream) && strlen($response) < $limit) {
        // Microseconds to the deadline, at least 1: a timeout of 0 would
        // mean none for a TLS stream; past the deadline the read times out.
        $left = max(1, (int) (($deadline - microtime(true)) * 1000000));
        stream_set_timeout($stream, intdiv($left, 1000000), $left % 1000000);
        $chunk = @fread($stream, min(65536, $limit - strlen($response)));
        if ($chunk === false || stream_get_meta_data($stream)['timed_out']) {
            $timedOut = true;
            break;
        }
        $response .= $chunk;
    }
    $complete = feof($stream);
    fclose($stream);
    if (!$written || $timedOut) {
        error_log('getusers: ' . ($written ? 'timeout' : 'cannot send the request'));
        return null;
    }
    $end = strpos($response, "\r\n\r\n");
    $status = $end === false ? '' : strstr($response, "\r\n", true);
    if (!preg_match('#^HTTP/\S+ 200( |$)#', $status)) {
        error_log('getusers: ' . ($status !== '' ? $status : 'no status'));
        return null;
    }
    $body = substr($response, $end + 4);
    if (!$complete || strlen($body) > GETUSERS_MAX_BODY) {
        error_log('getusers: incomplete or too large response');
        return null;
    }
    // Every Content-Length header (if any) must be digits only and equal to
    // the body length (a body cut short by an early close is refused); a
    // malformed value fails closed.
    preg_match_all('/^content-length:[ \t]*([^\r\n]*?)[ \t]*\r?$/im', substr($response, 0, $end), $lengths);
    foreach ($lengths[1] as $length) {
        if (!preg_match('/^[0-9]{1,18}$/D', $length) || (int) $length !== strlen($body)) {
            error_log('getusers: body length does not match Content-Length');
            return null;
        }
    }
    return $body;
}

// The members of the JSON list $body ([{"id": int, "uuid": string, "name":
// string, "lo": int|null, "paid": 0|1}, ...]) as [id, uuid, name, pgacc
// organization, paid] rows, or null if the document is not such a non-empty
// list: every row must have exactly these fields, a positive id and a uuid
// that no other row has, a name without NUL bytes, an integer or null lo.
// lo is the membership system's organization role (37-45), mapped to the
// organization of pgacc; others and null become 10.
function getusers_parse($body)
{
    $members = json_decode($body, true);
    if (!is_array($members) || $members === [] || !array_is_list($members)) {
        return null;
    }
    $organizations = [38 => 1, 40 => 2, 39 => 3, 41 => 4, 42 => 5, 43 => 6, 44 => 10, 45 => 8, 37 => 9];
    $rows = [];
    $uuids = [];
    foreach ($members as $member) {
        if (!is_array($member) || count($member) !== 5
            || !is_int($member['id'] ?? null) || $member['id'] < 1 || isset($rows[$member['id']])
            || !member_uuid_valid($member['uuid'] ?? null) || isset($uuids[strtolower($member['uuid'])])
            || !is_string($member['name'] ?? null) || strpos($member['name'], "\0") !== false
            || !array_key_exists('lo', $member) || ($member['lo'] !== null && !is_int($member['lo']))
            || !in_array($member['paid'] ?? null, [0, 1], true)) {
            return null;
        }
        $uuids[strtolower($member['uuid'])] = true;
        $rows[$member['id']] = [$member['id'], strtolower($member['uuid']), $member['name'],
            $member['lo'] === null ? 10 : $organizations[$member['lo']] ?? 10, $member['paid']];
    }
    return array_values($rows);
}

// Loads the member list of the membership system (internal/members.php of
// the membership system, over the internal TLS listener; deploy/README.md,
// "Membership system") into ppmembers, a temporary table of this connection
// (other requests neither see nor replace it). Returns null, or the error
// message for the page (the cause of a failed fetch goes to the error log);
// the caller decides how to stop. $file: the configuration file (the tests
// pass another one). $timeout: the deadline of the fetch in seconds.
function getUsers($file = 'sync-secrets.php', $timeout = 20)
{
    global $dbconn;
    $config = getusers_config($file);
    if ($config === null) {
        return getusers_failed('Mitgliederliste nicht konfiguriert!');
    }
    // The member list is replaced only by a complete, valid response, in one
    // transaction; on any error the connection's previous list (if any)
    // stays as it was.
    $body = getusers_fetch($config, $timeout);
    if ($body === null) {
        return 'Mitgliederliste konnte nicht abgerufen werden!';
    }
    $members = getusers_parse($body);
    if ($members === null) {
        return getusers_failed('Daten von Mitgliederverwaltung ungültig!');
    }
    $values = [];
    foreach ($members as [$id, $uuid, $name, $lo, $paid]) {
        $values[] = "($id,'$uuid','" . pg_escape_string($dbconn, $name) . "',$lo,$paid)";
    }
    $query = 'INSERT INTO pg_temp.ppmembers (id, uuid, name, lo, paid) VALUES ' . implode(',', $values) . ';';

    // Inside a caller's transaction (the unit tests) a savepoint, so that the
    // caller's transaction is neither committed nor ended.
    $nested = pg_transaction_status($dbconn) != PGSQL_TRANSACTION_IDLE;
    $queries = [
        $nested ? 'SAVEPOINT getusers;' : 'BEGIN;',
        'CREATE TEMP TABLE IF NOT EXISTS ppmembers (id integer, uuid text, name text, lo integer, paid integer) ON COMMIT PRESERVE ROWS;',
        'DELETE FROM pg_temp.ppmembers;',
        $query,
        $nested ? 'RELEASE SAVEPOINT getusers;' : 'COMMIT;',
    ];
    // An error before the transaction or savepoint exists (such as an aborted
    // caller's transaction) has nothing to roll back.
    $started = false;
    foreach ($queries as $query) {
        $error = getusers_query($query);
        if ($error !== null) {
            if ($started) {
                getusers_query($nested ? 'ROLLBACK TO SAVEPOINT getusers;' : 'ROLLBACK;');
            }
            return getusers_failed('Abfrage fehlgeschlagen: ' . $error);
        }
        $started = true;
    }
    return null;

}

// getUsers() once per request: null, or its error message. The member list
// of this request for the pages that show member numbers (member_number())
// and save a member's voucher. $timeout: the deadline of the first call;
// 5 seconds for a page that only shows member numbers, so that a slow
// membership system delays it less (a save passes 20).
function members_loaded($timeout = 5)
{
    static $error = false;
    if ($error === false) {
        $error = getUsers('sync-secrets.php', $timeout);
    }
    return $error;
}

// The member list of this request (members_loaded()), read once: [id =>
// ['uuid' => ..., 'name' => ...]], or null if it cannot be loaded.
function member_list()
{
    global $dbconn;
    static $members = false;
    if ($members === false) {
        $members = null;
        if (members_loaded() === null) {
            $members = [];
            $result = db_query($dbconn, 'SELECT id, uuid, name FROM pg_temp.ppmembers;');
            while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
                $members[$line['id']] = $line;
            }
            pg_free_result($result);
        }
    }
    return $members;
}

// A member number as HTML: with the member's name and linked to the
// profile if the member list (member_list()) has the member, with a hint
// if not, as the number only if the list cannot be loaded.
function member_number($id)
{
    $id = intval($id);
    $members = member_list();
    if ($members === null) {
        return (string) $id;
    }
    if (!isset($members[$id])) {
        return "$id (nicht in der Mitgliederliste)";
    }
    return member_link($id, $members[$id]['uuid'], $members[$id]['name']);
}

// The most members that the member lookup of the edit page
// (member_lookup_html()) lists; member_lookup_rows() reads one more, which
// tells that there are more.
const MEMBER_LOOKUP_LIMIT = 20;

// The words of a member search: $q split on whitespace, at most 5 words of
// at most 50 characters each (none for invalid UTF-8).
function member_search_terms($q)
{
    $words = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY);
    if ($words === false) {
        return [];
    }
    return array_map(fn($word) => mb_substr($word, 0, 50, 'UTF-8'), array_slice($words, 0, 5));
}

// A LIKE pattern that finds $word anywhere in a text, with "!" as the escape
// character (ESCAPE '!'): "%", "_" and "!" in $word are literal.
function member_like_pattern($word)
{
    return '%' . strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

// The numbers of 3 to 6 digits in $text (not part of a longer number), each
// once: candidates for member numbers.
function member_text_numbers($text)
{
    preg_match_all('/(?<!\d)\d{3,6}(?!\d)/', $text, $matches);
    return array_values(array_unique(array_map('intval', $matches[0])));
}

// What the member search $q looks for: ['id', member number] for digits only
// (at most 9, so that the number fits an integer), ['name', words]
// (member_search_terms(), at least 3 characters together), or ['error',
// message].
function member_search_query($q)
{
    $q = trim($q);
    if (preg_match('/^\d+$/D', $q) === 1) {
        if (strlen($q) > 9) {
            return ['error', 'Mitgliedsnummer zu lang (höchstens 9 Ziffern).'];
        }
        return ['id', intval($q)];
    }
    $words = member_search_terms($q);
    if (mb_strlen(implode('', $words), 'UTF-8') < 3) {
        return ['error', 'Suchbegriff zu kurz (mindestens 3 Zeichen).'];
    }
    return ['name', $words];
}

// The members of the member list (ppmembers, loaded by members_loaded())
// that match the condition $where (placeholders $1, ... for $params), by
// name and number: at most MEMBER_LOOKUP_LIMIT + 1 rows ['id', 'name', 'lo'
// (the organization's name, '' if unknown), 'paid'].
function member_lookup_rows($where, $params)
{
    global $dbconn;
    $result = pg_query_params($dbconn, 'SELECT m.id, m.name, COALESCE(lo.name, \'\') AS lo, m.paid FROM pg_temp.ppmembers m'
        . ' LEFT JOIN lo ON lo.id = m.lo WHERE ' . $where . ' ORDER BY m.name, m.id LIMIT ' . (MEMBER_LOOKUP_LIMIT + 1) . ';', $params)
        or db_fail($dbconn, null);
    $rows = pg_fetch_all($result);
    pg_free_result($result);
    return $rows;
}

// The members found by the member search $q (member_search_query(): the
// number, or every word somewhere in the name, case-insensitive), as
// member_lookup_rows(), or the message of an invalid search.
function member_search($q)
{
    [$kind, $value] = member_search_query($q);
    if ($kind === 'error') {
        return $value;
    }
    if ($kind === 'id') {
        return member_lookup_rows('m.id = $1', [$value]);
    }
    $conditions = [];
    foreach ($value as $i => $word) {
        $conditions[] = 'm.name ILIKE $' . ($i + 1) . " ESCAPE '!'";
    }
    return member_lookup_rows(implode(' AND ', $conditions), array_map('member_like_pattern', $value));
}

// The members suggested for a booking row with the name $name and the
// booking text $comment, as member_lookup_rows(): the member numbers in the
// text (member_text_numbers()) and the members whose name (at least 5
// characters) is part of the name or the text, case-insensitive.
function member_suggestions($name, $comment)
{
    return member_lookup_rows(
        'm.id = ANY($1::integer[]) OR (length(trim(m.name)) >= 5'
        . ' AND (strpos(lower($2), lower(trim(m.name))) > 0 OR strpos(lower($3), lower(trim(m.name))) > 0))',
        ['{' . implode(',', member_text_numbers($comment)) . '}', $name, $comment],
    );
}
