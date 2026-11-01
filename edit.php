<?php

function relocate($loc)
{
    global $dbconn;
    // Discard the page buffered by index.php and end the request.
    ob_end_clean();
    pg_close($dbconn);
    header("Location: " . $loc);
    exit;
}
function page_edit_merge($rights)
{
    global $dbconn;
    // Only the form of the edit page merges (POST with the form token, checked
    // in index.php); an old link or bookmark writes nothing.
    if (!request_is_post()) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Buchungen nur über die Schaltfläche „Merge“ der Buchung zusammenführen.</div><br /><br /><br />';
        return;
    }
    $bid1 = 0;
    $bid2 = 0;
    if (isset($_POST["bid1"])) {
        $bid1 = intval($_POST["bid1"]);
    }
    if (isset($_POST["bid2"])) {
        $bid2 = intval($_POST["bid2"]);
    }
    if ($bid1 == 0 || $bid2 == 0) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Buchungen nicht gefunden.</div><br /><br /><br />';
        return;
    }
    if (!has_right($rights, 'root')) {
        echo '<div class="slot_error" id="slot_error">WARNUNG: Diese Funktion sollte nur von Admins verwendet um Fehlerfälle zu beheben. Bitte kontaktiere einen der Admins.</div><br /><br /><br />';
        return;
    }
    $rightssql = rights2orgasql($rights);

    // Both vouchers' locks, in the order of their numbers (voucher_lock()).
    voucher_lock(min($bid1, $bid2));
    voucher_lock(max($bid1, $bid2));
    $name = pg_escape_string($dbconn, checklogin('name'));
    $query = "UPDATE vouchers SET voucher_id = $bid1 WHERE voucher_id = $bid2 $rightssql;";
    $merged = db_exec($dbconn, $query);
    // No row moved: the second voucher does not exist or is not visible.
    if ($merged == 0) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Buchungen nicht gefunden.</div><br /><br /><br />';
        return;
    }
    relocate("index.php?action=edit&id=$bid1&hint=6");
}
function page_edit_drop_acks($rights)
{
    global $dbconn;
    $parts = posted_parts();

    $id = -1;
    if (isset($_GET["id"])) {
        $id = $_GET["id"];
    } elseif (isset($_POST["id"])) {
        $id = $_POST["id"];
    }
    if (!is_string($id) || preg_match('/^\d+$/D', $id) != 1) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
        return;
    }
    // Writers of a voucher's rows take its lock (voucher_lock()).
    voucher_lock($id);

    if (!has_right($rights, 'bgf')) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
        return;
    }
    // A finalized row blocks the reset, as every other change of the voucher.
    $query = "SELECT 1 FROM vouchers WHERE NOT deleted AND receipt_received AND voucher_id = $id;";
    $result = db_query($dbconn, $query);
    if (pg_fetch_array($result, null, PGSQL_ASSOC)) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Die Buchung wurde bereits finalisiert und kann jetzt nicht mehr verändert werden.</div><br /><br /><br />';
        pg_free_result($result);
        return;
    }
    pg_free_result($result);
    echo "<h1>Bestätigungen wurden zurückgesetzt - Buchung Nr. $id</h1>";
    block_start();
    echo "<a href=\"index.php?action=edit&id=$id&hint=3\">Zurück zur Buchung</a>";
    block_end();
    $rightssql = rights2orgasql($rights);

    vouchers_reset_ack($id, $rightssql);
    relocate("index.php?action=edit&id=$id&hint=3");
}
function page_edit_ack($rights)
{
    global $dbconn;
    $rights = write_rights($rights);
    if ($rights === false) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
        return;
    }
    $parts = posted_parts();

    $id = -1;
    if (isset($_GET["id"])) {
        $id = $_GET["id"];
    } elseif (isset($_POST["id"])) {
        $id = $_POST["id"];
    }
    if (!is_string($id) || preg_match('/^\d+$/D', $id) != 1) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
        return;
    }
    // Writers of a voucher's rows take its lock (voucher_lock()).
    voucher_lock($id);

    $rightssql = rights2orgasql($rights);
    // A user who sees no current (not deleted) row of the voucher gets the
    // answer for a voucher without a receipt, whether or not it is finalized.
    $query = "SELECT 1 FROM vouchers WHERE NOT deleted AND voucher_id = $id $rightssql;";
    $result = db_query($dbconn, $query);
    if (!pg_fetch_array($result, null, PGSQL_ASSOC)) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Hier fehlt noch der Beleg!</div><br /><br /><br />';
        pg_free_result($result);
        return;
    }
    pg_free_result($result);

    // A finalized row blocks the approval of the whole voucher, also a row of
    // another organization.
    $query = "SELECT 1 FROM vouchers WHERE NOT deleted AND receipt_received AND voucher_id = $id;";
    $result = db_query($dbconn, $query);
    if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Die Buchung wurde bereits finalisiert und kann jetzt nicht mehr verändert werden.</div><br /><br /><br />';
        pg_free_result($result);
        return;
    }
    pg_free_result($result);

    $hasfile = false;
    $query = "SELECT file FROM vouchers WHERE NOT deleted AND (ack1 IS NULL OR file != 0) AND voucher_id = $id $rightssql;";
    $result = db_query($dbconn, $query);
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        $hasfile = true;
    }
    pg_free_result($result);

    if (!$hasfile) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Hier fehlt noch der Beleg!</div><br /><br /><br />';
        return;
    }
    $name = pg_escape_string($dbconn, checklogin('name'));
    $query = "UPDATE vouchers SET ack1 = '$name',ack2 = NULL, ack1_old = NULL, ack2_old = NULL WHERE NOT deleted AND ack1 IS NULL AND voucher_id = $id $rightssql;";
    db_exec($dbconn, $query);

    echo "<h1>Buchung bestätigt - Buchung Nr. $id</h1>";
    block_start();
    echo "<a href=\"index.php?action=edit&id=$id&hint=4\">Zurück zur Buchung</a>";
    block_end();

    $query = "UPDATE vouchers SET ack2 = '$name', ack1_old = NULL, ack2_old = NULL WHERE NOT deleted AND ack2 IS NULL AND ack1 IS NOT NULL AND ack1 != '$name' AND voucher_id = $id $rightssql;";
    db_exec($dbconn, $query);
    relocate("index.php?action=edit&id=$id&hint=4");
}
// The current rows of voucher $id in the order of their IDs (id, file,
// receipt_received, mine: whether the user sees the row); with $lock locked
// FOR UPDATE, and null when PostgreSQL aborts that for a deadlock (the caller
// rolls the transaction back). Any other failure ends the request
// (db_fail()).
function edit_voucher_rows($id, $rightssql, $lock)
{
    global $dbconn;
    $query = "SELECT id, file, receipt_received, (TRUE $rightssql) AS mine FROM vouchers WHERE NOT deleted AND voucher_id = $id ORDER BY id" . ($lock ? ' FOR UPDATE' : '') . ';';
    $result = pg_send_query($dbconn, $query) ? pg_get_result($dbconn) : false;
    // One statement, one result: this ends the query.
    while (pg_get_result($dbconn) !== false) {
    }
    // No result at all (a lost connection) fails like an SQL error.
    $state = $result === false ? 'no result' : pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);
    if ($state === '40P01') {
        pg_free_result($result);
        return null;
    }
    if ($state !== null) {
        db_fail($dbconn, null);
    }
    $rows = pg_fetch_all($result);
    pg_free_result($result);
    return $rows;
}
// The user's rows of edit_voucher_rows(): row ID => receipt number.
function edit_user_files($rows)
{
    $files = [];
    foreach ($rows as $line) {
        if ($line['mine'] == 't') {
            $files[intval($line['id'])] = intval($line['file']);
        }
    }
    return $files;
}
// Why a revision with the posted parts ($parts, row IDs $bids) is refused, as
// [message, whether the form is shown again], or null. $rows are the
// voucher's current rows (edit_voucher_rows()), $files the user's ones (row ID
// => receipt number). Checked without the lock before the member list is
// loaded, and again on the locked rows.
function revision_refusal($rows, $files, $parts, $bids, $organizations_allowed, $amounts_valid)
{
    // Only a voucher of which the user sees a current row; a finalized row
    // blocks the revision of the whole voucher, also a row of another
    // organization.
    if (count($files) == 0) {
        return ['Buchung nicht gefunden.', false];
    }
    if (in_array('t', array_column($rows, 'receipt_received'), true)) {
        return ['Die Buchung wurde bereits finalisiert und kann jetzt nicht mehr verändert werden.', false];
    }
    if (!$organizations_allowed) {
        return ['Keine Zugriffsrechte.', false];
    }
    // A posted row of another organization (current, but not the user's).
    $current = array_map('intval', array_column($rows, 'id'));
    foreach ($bids as $bid) {
        if ($bid > 0 && !array_key_exists($bid, $files) && in_array($bid, $current, true)) {
            return ['Keine Berechtigung für diese Buchungszeile.', false];
        }
    }
    if (!$amounts_valid) {
        return [null, true];
    }
    if ($parts < 1) {
        return ['Keine Buchungszeile angegeben.', true];
    }
    // Another posted row is no current row: the form was opened before
    // another save (page_save_buchung() takes the receipt numbers of the
    // posted current rows; posted ones are ignored).
    foreach ($bids as $bid) {
        if ($bid > 0 && !array_key_exists($bid, $files)) {
            return ['Die Buchung wurde inzwischen geändert. Bitte neu laden.', 'current'];
        }
    }
    // A row with a receipt must be posted again, so that no receipt is dropped.
    foreach ($files as $bid => $file) {
        if ($file != 0 && !in_array($bid, $bids, true)) {
            return ['Eine Buchungszeile mit Beleg kann nicht entfernt werden.', true];
        }
    }
    return null;
}
// Shows a refused revision: the message; the form with the posted values
// (true), the voucher's current form with a read-only summary of the posted
// parts (a stale form: 'current'), or nothing more (false). An invalid amount
// (message null) has its own message (amount_refused()).
function revision_refused($refusal, $parts, $rights, $form_rights)
{
    list($message, $form) = $refusal;
    if ($message === null) {
        amount_refused();
    } else {
        save_refused($message);
    }
    if ($form === 'current') {
        echo '<h2>Nicht gespeicherte Eingaben</h2><table><tr><th>Zeile</th><th>Datum</th><th>Betrag</th><th>Name</th><th>Kommentar</th><th>Kommentar GF</th></tr>';
        for ($i = 0; $i < $parts; $i++) {
            $voucher = get_voucher($i, $rights, false);
            echo '<tr><td>' . ($i + 1) . '</td><td>' . html_escape($voucher['date']) . '</td><td>' . html_escape($voucher['amount_input'])
                . '</td><td>' . html_escape($voucher['name']) . '</td><td>' . html_escape($voucher['comment']) . '</td><td>' . html_escape($voucher['commentgf']) . '</td></tr>';
        }
        echo '</table>';
        // The voucher as stored: the form ignores the posted parts.
        $_POST = array_intersect_key($_POST, ['id' => true, 'csrf_token' => true]);
        page_edit($form_rights);
    } elseif ($form) {
        page_edit($form_rights, true);
    }
}
function page_edit_save($rights)
{
    global $dbconn;
    $form_rights = $rights;
    $rights = write_rights($rights);
    if ($rights === false) {
        save_refused('Keine Zugriffsrechte.');
        return;
    }
    $parts = posted_parts();

    $id = -1;
    if (isset($_GET["id"])) {
        $id = $_GET["id"];
    } elseif (isset($_POST["id"])) {
        $id = $_POST["id"];
    }
    if (!is_string($id) || preg_match('/^\d+$/D', $id) != 1) {
        save_refused('Buchung nicht gefunden.');
        return;
    }
    $rightssql = rights2orgasql($rights);
    $organizations_allowed = posted_organizations_allowed($parts, $rights);
    $amounts_valid = posted_amounts_valid($parts, $rights);
    $bids = posted_bids($parts);

    // Checked first without the lock, so that a refused revision does not
    // contact the membership system.
    $rows = edit_voucher_rows($id, $rightssql, false);
    $refusal = revision_refusal($rows, edit_user_files($rows), $parts, $bids, $organizations_allowed, $amounts_valid);
    if ($refusal !== null) {
        revision_refused($refusal, $parts, $rights, $form_rights);
        return;
    }
    // The member list of a member's part is loaded before the transaction (no
    // external call while the voucher is locked); when it is missing, the
    // revision is refused after the other checks.
    $members_error = posted_member_part($parts, $rights) ? members_loaded(20) : null;

    // The voucher's lock (voucher_lock(), for the transaction), then its
    // current rows, locked: the checks, the approval reset, the replacement of
    // the user's rows and the new rows are one transaction on them, and rows
    // of a save that committed meanwhile are seen. A refusal rolls back and
    // writes nothing.
    db_exec($dbconn, 'BEGIN;');
    db_exec($dbconn, 'SELECT pg_advisory_xact_lock(1, ' . voucher_lock_key($id) . ');');
    $rows = edit_voucher_rows($id, $rightssql, true);
    if ($rows === null) {
        $refusal = ['Die Buchung wird gerade gleichzeitig geändert. Bitte erneut speichern.', true];
    } else {
        // The user's current rows (row ID => receipt number).
        $stored_files = edit_user_files($rows);
        $refusal = revision_refusal($rows, $stored_files, $parts, $bids, $organizations_allowed, $amounts_valid);
    }
    if ($refusal === null && $members_error !== null) {
        $refusal = [MEMBERS_UNAVAILABLE, true];
    }
    if ($refusal !== null) {
        db_exec($dbconn, 'ROLLBACK;');
        revision_refused($refusal, $parts, $rights, $form_rights);
        return;
    }

    echo "<h1>Buchung aktualisiert - Buchung Nr. $id</h1>";
    block_start();
    echo "<a href=\"index.php?action=edit&id=$id&hint=5\">Zurück zur Buchung</a>";
    block_end();

    // Every change resets the approvals of the whole voucher,
    // also of other organizations' rows (they move to ack1_old/ack2_old). The
    // new rows keep the old approvals of the user's first current row, taken
    // from the database (posted approval names are not used).
    vouchers_reset_ack($id, '');
    $ack1 = null;
    $ack2 = null;
    $query = "SELECT ack1_old, ack2_old FROM vouchers WHERE NOT deleted AND voucher_id = $id $rightssql ORDER BY id ASC LIMIT 1;";
    $result = db_query($dbconn, $query);
    if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        $ack1 = $line['ack1_old'];
        $ack2 = $line['ack2_old'];
    }
    pg_free_result($result);

    // Only the user's locked current rows.
    $query = "UPDATE vouchers SET deleted = true WHERE NOT deleted AND voucher_id = $id AND id IN (" . implode(',', array_keys($stored_files)) . ");";
    db_exec($dbconn, $query);

    $voucher_warnings = [];
    for ($i = 0; $i < $parts; $i++) {
        page_save_buchung($id, $rights, $i, $ack1, $ack2, $voucher_warnings, $stored_files, $bids[$i]);
    }
    db_exec($dbconn, 'COMMIT;');
    // Corrected values are named in the redirect (warn=) for the edit page.
    $warn = '';
    if (count($voucher_warnings) > 0) {
        $warn = '&warn=' . implode(',', array_unique($voucher_warnings));
    }
    relocate("index.php?action=edit&id=$id&hint=5$warn");
}
function page_edit_buchung($rights, $vouchers, $part = 0, $part_to_remove = -1, $new_part = 0)
{
    global $dbconn;
    if (isset($_POST["changed"])) {
        if ($part_to_remove != -1 && $part >= $part_to_remove) {
            $voucher = get_voucher($part + 1, $rights, false);
        } elseif ($new_part) {
            $voucher = get_voucher($part - 1, $rights, false);
            $voucher['file'] = -1;
            $voucher['bid'] = 0;
        } else {
            $voucher = get_voucher($part, $rights, false);
        }
    } else {
        $part_o = $part;
        if ($part_to_remove != -1 && $part >= $part_to_remove) {
            $part++;
        }
        $query = "SELECT income FROM type WHERE id = " . $vouchers[$part]['type'];
        $result = db_query($dbconn, $query);
        if ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            $voucher['dir'] = $line['income'] == 1 ? 'in' : ($line['income'] == 0 ? 'out' : 'wk');
        } else {
            // A type id without a type row: the form's default direction
            // (new.php offers the id as unknown type).
            $voucher['dir'] = 'in';
        }
        pg_free_result($result);
        $voucher['bid'] = $vouchers[$part]['id'];
        $voucher['date'] = format_date($vouchers[$part]['date']);
        $voucher['type'] = $vouchers[$part]['type'];
        if ($voucher['type'] == 45) {
            $voucher['dir'] = 'bel';
        }
        $voucher['person_type'] = $vouchers[$part]['person_type'];
        $voucher['lo'] = $vouchers[$part]['orga'];
        $voucher['amount'] = $vouchers[$part]['amount'];
        $voucher['gegenkonto'] = $vouchers[$part]['contra_account'];
        $voucher['konto'] = $vouchers[$part]['account'];
        $voucher['vkonto'] = $vouchers[$part]['vaccount'];
        $voucher['comment'] = $vouchers[$part]['comment'];
        $voucher['commentgf'] = $vouchers[$part]['commentgf'];
        $voucher['purpose'] = $vouchers[$part]['committed'] == 't' ? 'true' : 'false';
        $voucher['refund'] = $vouchers[$part]['refund'] == 't' ? 'true' : 'false';
        $voucher['member'] = $vouchers[$part]['member'] == 't' ? 'true' : 'false';
        $voucher['mitgliedsnummer'] = $vouchers[$part]['member_id'];
        $voucher['name'] = $vouchers[$part]['name'];
        $voucher['street'] = $vouchers[$part]['street'];
        $voucher['plz'] = $vouchers[$part]['plz'];
        $voucher['city'] = $vouchers[$part]['city'];
        $voucher['file'] = $vouchers[$part]['file'];
        $part = $part_o;
    }
    if ($new_part == 2) {
        $voucher['amount'] = 0;
        $voucher['dir'] = 'bel';
        $voucher['type'] = 45;
    }
    page_edit_form($part, $voucher, $rights);
}

// $unsaved: the form shows posted values that were not saved (a refused
// revision): no approval or approval reset, which would act on the stored
// rows.
function page_edit($rights, $unsaved = false)
{
    global $dbconn;
    $id = -1;
    if (isset($_GET["id"])) {
        $id = intval($_GET["id"]);
    } elseif (isset($_POST["id"])) {
        $id = intval($_POST["id"]);
    } else {
        echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
        return;
    }
    $bid = -1;
    if (isset($_GET["bid"])) {
        $bid = intval($_GET["bid"]);
    } elseif (isset($_POST["bid"])) {
        $bid = intval($_POST["bid"]);
    }
    $hint = 0;
    if (isset($_GET["hint"])) {
        $hint = intval($_GET["hint"]);
    }
    switch ($hint) {
        case 1:
            echo '<div class="slot_notice" id="slot_notice"><h1>Upload erfolgreich - Buchung Nr. ' . $id . ' - Buchungszeile Nr. ' . $bid . '</h1></div>';
            break;
        case 2:
            echo '<div class="slot_notice" id="slot_notice"><h1>Buchung finalisiert - Buchung Nr. ' . $id . '</h1></div>';
            break;
        case 3:
            echo '<div class="slot_notice" id="slot_notice"><h1>Bestätigungen wurden zurückgesetzt - Buchung Nr. ' . $id . '</h1></div>';
            break;
        case 4:
            echo '<div class="slot_notice" id="slot_notice"><h1>Buchung bestätigt - Buchung Nr. ' . $id . '</h1></div>';
            break;
        case 5:
            echo '<div class="slot_notice" id="slot_notice"><h1>Buchung aktualisiert - Buchung Nr. ' . $id . '</h1></div>';
            break;
        case 6:
            echo '<div class="slot_notice" id="slot_notice"><h1>Buchungen zusammengeführt - Buchung Nr. ' . $id . '</h1></div>';
            break;
    }
    // Values that the revision corrected (warn= of its redirect): fixed notes,
    // only for the known codes.
    $warn_notes = [
        'date' => 'Gespeichert, aber: kein Datum angegeben – das Datum wurde auf 1800-01-01 gesetzt.',
        'refund' => 'Gespeichert, aber: eine Kostenrückerstattung ist nur bei einer Ausgabe möglich – die Kostenrückerstattung wurde entfernt.',
        'purpose' => 'Gespeichert, aber: eine Zweckwidmung ist nur bei einer Spende möglich – die Zweckwidmung wurde entfernt.',
    ];
    $warn_codes = [];
    if (isset($_GET["warn"]) && is_string($_GET["warn"])) {
        $warn_codes = explode(',', $_GET["warn"]);
    }
    foreach ($warn_notes as $code => $note) {
        if (in_array($code, $warn_codes, true)) {
            echo '<div class="slot_error" id="slot_error">' . $note . '</div><br />';
        }
    }
    $rightssql = rights2orgasql($rights);
    $query = "SELECT * FROM vouchers WHERE NOT deleted AND voucher_id = " . intval($id) . " $rightssql ORDER BY id ASC;";
    $result = db_query($dbconn, $query);
    $vouchers = [];
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        $vouchers[] = $line;
    }
    pg_free_result($result);
    $parts = count($vouchers);
    // A voucher without current rows has nothing to edit, also for bgf; old
    // revisions are restored with the recovery page.
    if ($parts == 0) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
        return;
    }
    if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1) {
        $parts = $_POST["parts"];
    }
    $part_to_remove = -1;
    if (isset($_POST["remove"]) && intval($_POST["remove"]) >= 0 && intval($_POST["remove"]) < $parts) {
        $parts--;
        $part_to_remove = intval($_POST["remove"]);
    }
    // The approvals of all rows the user sees: the fewest of any row (2 only
    // when every row is approved twice), finalized when any row is.
    $acks = 2;
    $received = false;
    foreach ($vouchers as $voucher) {
        $row_acks = 0;
        if ($voucher['ack1'] != null && strlen($voucher['ack1']) > 3) {
            $row_acks++;
        }
        if ($voucher['ack2'] != null && strlen($voucher['ack2']) > 3) {
            $row_acks++;
        }
        $acks = min($acks, $row_acks);
        $received = $received || $voucher['receipt_received'] == 't';
    }
    // The merge form (POST, see merge() below) cannot be nested in mainform.
    if (has_right($rights, 'root')) {
        echo '<form id="mergeform" action="index.php?action=merge" method="post" style="display: none">' . csrf_field() . '<input type="hidden" name="bid1" value="' . intval($id) . '" /><input type="hidden" name="bid2" value="" /></form>';
    }
    page_form_header("edit");
    echo '<input type="hidden" name="id" value="' . intval($id) . '" />
<input type="hidden" name="acks" value="' . intval($acks) . '" />
<input type="hidden" name="ack1" value="' . html_escape($vouchers[0]['ack1']) . '" />
<input type="hidden" name="ack2" value="' . html_escape($vouchers[0]['ack2']) . '" />
<input type="hidden" name="ack1_old" value="' . html_escape($vouchers[0]['ack1_old']) . '" />
<input type="hidden" name="ack2_old" value="' . html_escape($vouchers[0]['ack2_old']) . '" />
<input type="hidden" name="changed" value="1" />
<h1>Buchung ' . $id . ' bearbeiten</h1>
';
    if (has_right($rights, 'root')) {
        echo '<script type="text/javascript">
function merge()
{
  // The first click asks for the other voucher, the second one merges.
  var form = document.getElementById("mergeform");
  if (form.bid2.value != "")
  {
    form.submit();
    return;
  }
  var bid = prompt("Merge mit welcher anderen Buchung?", "");
  if (bid === null || bid == "")
    return;
  form.bid2.value = bid;
  document.getElementById("mergelink").innerHTML = "Merge mit Buchung " + bid;
}
</script>
<a href="javascript:merge();" id="mergelink" name="mergelink" />Merge</a>
<br />
<br />';
    }
    // One status when all rows agree, otherwise one per row.
    $states = [];
    foreach ($vouchers as $voucher) {
        $state = html_escape($voucher['ack1']) . ' ' . html_escape($voucher['ack2']);
        if (isset($voucher['ack1_old']) || isset($voucher['ack2_old'])) {
            $state .= '<del>' . html_escape($voucher['ack1_old']) . ' ' . html_escape($voucher['ack2_old']) . '</del>';
        }
        $states[intval($voucher['id'])] = $state;
    }
    echo '<h4>Bestätigt von: ';
    if (count(array_unique($states)) == 1) {
        echo reset($states);
    } else {
        foreach ($states as $row_id => $state) {
            echo '<br />Buchungszeile ' . $row_id . ': ' . $state;
        }
    }
    echo '</h4>';

    for ($i = 0; $i < $parts; $i++) {
        page_edit_buchung($rights, $vouchers, $i, $part_to_remove);
    }
    if (isset($_POST["add"])) {
        page_edit_buchung($rights, $vouchers, $parts, $part_to_remove, 1);
        $parts++;
    }
    if (isset($_POST["addB"])) {
        page_edit_buchung($rights, $vouchers, $parts, $part_to_remove, 2);
        $parts++;
    }

    $user_name = checklogin('name');
    $bgfandack = has_right($rights, 'bgf') && ($acks == 2) && !$received;


    end_of_form($parts, $rights, $bgfandack && !$unsaved, true, !$unsaved);
}
