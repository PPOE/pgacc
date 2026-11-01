<?php

/*
 * Internal sync endpoint (internal/mbapi.php): the membership system fetches
 * the acknowledged membership fees as JSON, over the internal TLS listener
 * (deploy/README.md, "Membership system"). A request must come from a peer
 * of the configuration file sync-secrets.php in the release root and carry its
 * token ("Authorization: Bearer <token>"); otherwise, and without a valid
 * configuration, it gets 403 with an empty body. Errors answer 503 with an
 * empty body. The file only defines functions, so a direct request prints
 * nothing (src/ is outside every document root anyway).
 */

// The configuration $file (resolved like the include of constants.php):
// ['payments_token' => non-empty string, 'payments_peers' => non-empty list
// of addresses, ...], or null if it is missing, does not load (a parse error
// or an exception: logged by class only) or is not of that form.
function sync_api_config($file)
{
    if (stream_resolve_include_path($file) === false) {
        return null;
    }
    try {
        $config = include $file;
    } catch (Throwable $e) {
        // Only the class: the message of a parse error can quote the file.
        error_log('sync-api: sync-secrets.php cannot be loaded (' . get_class($e) . ')');
        return null;
    }
    if (!is_string($config['payments_token'] ?? null) || $config['payments_token'] === ''
        || !is_array($config['payments_peers'] ?? null) || $config['payments_peers'] === []) {
        return null;
    }
    return $config;
}

// True if the request ($_SERVER) comes from a configured peer and carries
// the configured token. The token is never logged.
function sync_api_allowed($config, $server)
{
    return $config !== null
        && in_array($server['REMOTE_ADDR'] ?? null, $config['payments_peers'], true)
        && hash_equals('Bearer ' . $config['payments_token'], (string) ($server['HTTP_AUTHORIZATION'] ?? ''));
}

// The members whose acknowledged (ack1), not deleted membership fees
// (type 1) since 1 October of the year before $year add up to at least
// 20.00, ordered by member: [['id' => int, 'amount_cents' => int,
// 'paid_until' => '<$year>-12-31'], ...]. Null if the query fails (PHP has
// logged the reason).
function sync_api_payments($dbconn, $year)
{
    $result = pg_query_params(
        $dbconn,
        'SELECT member_id, SUM(amount) AS amount FROM vouchers'
        . ' WHERE type = 1 AND date >= $1 AND NOT deleted AND ack1 IS NOT NULL AND member_id IS NOT NULL'
        . ' GROUP BY member_id HAVING SUM(amount) >= 2000 ORDER BY member_id',
        [($year - 1) . '-10-01'],
    );
    if ($result === false) {
        return null;
    }
    $payments = [];
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        $payments[] = ['id' => intval($line['member_id']), 'amount_cents' => intval($line['amount']), 'paid_until' => $year . '-12-31'];
    }
    return $payments;
}

// The request: 403 unless allowed, 503 without the database or if the query
// fails, otherwise the payments as JSON.
function sync_api_main()
{
    if (!sync_api_allowed(sync_api_config('sync-secrets.php'), $_SERVER)) {
        http_response_code(403);
        return;
    }
    $dbconn = pg_connect('dbname=accounting');
    $payments = $dbconn === false ? null : sync_api_payments($dbconn, intval(date('Y')));
    if ($payments === null) {
        http_response_code(503);
        return;
    }
    header('Content-Type: application/json');
    echo json_encode($payments);
}
