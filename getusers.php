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

function getUsers()
{
    global $datakey,$dbconn;
    // Certificate verification stays on (PHP defaults). The member list is
    // replaced only by a complete, valid response, in one transaction; on any
    // error the previous ppmembers table stays as it was. Redirects are not
    // followed (a 3xx is an error), so the list never comes from another host or
    // over plain HTTP. ignore_errors: an HTTP error status is checked here
    // instead of raising a warning.
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
    http_clear_last_response_headers();
    error_clear_last();
    $sData = @file_get_contents("https://mitglieder.piratenpartei.at/adm_api/adm_names.php", false, $context);
    $headers = http_get_last_response_headers();
    if ($sData === false || !is_array($headers) || !preg_match('#^HTTP/\S+ 200( |$)#', $headers[0])) {
        $error = error_get_last();
        error_log('getusers: ' . ($sData === false ? ($error === null ? 'fetch failed' : $error['message']) : $headers[0]));
        die('Mitgliederliste konnte nicht abgerufen werden!');
    }
    if (strlen($datakey) == 0)
        die('Kein Passwort!');

    if (strlen($sData) < 1000)
        die('Daten von Mitgliederverwaltung zu kurz!');

    $decrypted = rtrim(membership_decrypt($datakey, base64_decode($sData)), "\0");
    $query = 'INSERT INTO ppmembers (id, name, lo, paid) VALUES ';
    $lines = explode("\n", $decrypted);
    $count = 0;
    foreach ($lines as $line) {
        if (strlen($line) < 5) {
            continue;
        }
        $values = explode("\t", $line);
        // A line without the five fields means a damaged or undecryptable list.
        if (count($values) < 5)
            die('Daten von Mitgliederverwaltung ungültig!');
        $id = intval($values[1]);
        $name = stripslashes(iconv('ISO-8859-15', 'UTF-8', $values[2]));
        $lo = $values[3];
        $paid = intval($values[4]);
        switch ($lo) {
            case 38: $lo_num = 1;
                break;
            case 40: $lo_num = 2;
                break;
            case 39: $lo_num = 3;
                break;
            case 41: $lo_num = 4;
                break;
            case 42: $lo_num = 5;
                break;
            case 43: $lo_num = 6;
                break;
            case 44: $lo_num = 10;
                break;
            case 45: $lo_num = 8;
                break;
            case 37: $lo_num = 9;
                break;
            default: $lo_num = 10;
                break;
        }
        //echo "insert: id=$id, name=$name, lo=$lo_num\n";
        if ($count > 0)
            $query .= ',';
        $query .= "($id,'" . pg_escape_string($dbconn, $name) . "',$lo_num,$paid)";
        $count++;
    }
    if ($count == 0)
        die('Daten von Mitgliederverwaltung ungültig!');
    $query .= ';';

    // Inside a caller's transaction (the unit tests) a savepoint, so that the
    // caller's transaction is neither committed nor ended.
    $nested = pg_transaction_status($dbconn) != PGSQL_TRANSACTION_IDLE;
    $queries = [
        $nested ? 'SAVEPOINT getusers;' : 'BEGIN;',
        'DROP TABLE IF EXISTS ppmembers;',
        'CREATE TABLE ppmembers (id integer, name text, lo integer, paid integer);',
        $query,
        $nested ? 'RELEASE SAVEPOINT getusers;' : 'COMMIT;',
    ];
    // An error before the transaction or savepoint exists (such as an aborted
    // caller's transaction) has nothing to roll back.
    $started = false;
    foreach ($queries as $query) {
        $error = getusers_query($query);
        if ($error !== null) {
            if ($started)
                getusers_query($nested ? 'ROLLBACK TO SAVEPOINT getusers;' : 'ROLLBACK;');
            die('Abfrage fehlgeschlagen: ' . $error);
        }
        $started = true;
    }

}
?>
