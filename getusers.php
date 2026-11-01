<?php

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

// The member list settings of the configuration $file (sync-secrets.php in
// the release root, resolved like the include of constants.php):
// ['members_url' => 'https://...', 'members_token' => non-empty string,
// 'members_cafile' => the membership system's certificate], or null if the
// file is missing or one of them is missing, empty or (the URL) not https.
function getusers_config($file)
{
    $config = stream_resolve_include_path($file) === false ? null : include $file;
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
// Only 200 is accepted. ignore_errors: an HTTP error status is checked here
// instead of raising a warning. The token is never logged.
function getusers_fetch($config)
{
    $context = stream_context_create([
        'http' => [
            'header' => 'Authorization: Bearer ' . $config['members_token'] . "\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 20,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'cafile' => $config['members_cafile'],
        ],
    ]);
    http_clear_last_response_headers();
    error_clear_last();
    $body = @file_get_contents($config['members_url'], false, $context);
    $headers = http_get_last_response_headers();
    if ($body === false || !is_array($headers) || !preg_match('#^HTTP/\S+ 200( |$)#', $headers[0])) {
        $error = error_get_last();
        error_log('getusers: ' . ($body === false ? ($error === null ? 'fetch failed' : $error['message']) : $headers[0]));
        return null;
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
// pass another one).
function getUsers($file = 'sync-secrets.php')
{
    global $dbconn;
    $config = getusers_config($file);
    if ($config === null) {
        return getusers_failed('Mitgliederliste nicht konfiguriert!');
    }
    // The member list is replaced only by a complete, valid response, in one
    // transaction; on any error the connection's previous list (if any)
    // stays as it was.
    $body = getusers_fetch($config);
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
