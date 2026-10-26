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
    $parts = 1;
    if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1) {
        $parts = $_POST["parts"];
    }

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
    $parts = 1;
    if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1) {
        $parts = $_POST["parts"];
    }

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
// A refused revision: the message, then the form with the posted values.
function revision_refused($message)
{
    echo '<div class="slot_error" id="slot_error">FEHLER: ' . $message . '</div><br /><br /><br />';
}
function edit_save_rollback($members)
{
    global $dbconn;
    db_exec($dbconn, 'ROLLBACK;');
    members_drop($members);
}
// Locks the current rows of voucher $id in the order of their IDs and returns
// them (id, file, receipt_received, mine: whether the user sees the row);
// null when PostgreSQL aborts the statement for a deadlock (the transaction
// is then rolled back by the caller).
function edit_lock_rows($id, $rightssql)
{
    global $dbconn;
    $query = "SELECT id, file, receipt_received, (TRUE $rightssql) AS mine FROM vouchers WHERE NOT deleted AND voucher_id = $id ORDER BY id FOR UPDATE;";
    pg_send_query($dbconn, $query);
    $result = pg_get_result($dbconn);
    // One statement, one result: this ends the query.
    while (pg_get_result($dbconn) !== false) {
    }
    $state = pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);
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
function page_edit_save($rights)
{
    global $dbconn;
    $form_rights = $rights;
    $rights = write_rights($rights);
    if ($rights === false) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
        return;
    }
    $parts = 1;
    if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1) {
        $parts = $_POST["parts"];
    }

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
    $rightssql = rights2orgasql($rights);

    // The member list is loaded before the transaction (no external call
    // while the rows are locked).
    $members = members_load($parts, $rights);
    // The checks, the approval reset, the replacement of the user's rows and
    // the new rows are one transaction on the locked current rows of the
    // voucher; a refusal rolls it back and writes nothing.
    db_exec($dbconn, 'BEGIN;');
    $rows = edit_lock_rows($id, $rightssql);
    if ($rows === null) {
        edit_save_rollback($members);
        revision_refused('Die Buchung wird gerade gleichzeitig geändert. Bitte erneut speichern.');
        page_edit($form_rights, true);
        return;
    }
    // The user's current rows (row ID => receipt number); a finalized row
    // blocks the revision of the whole voucher, also a row of another
    // organization.
    $stored_files = [];
    $finalized = false;
    foreach ($rows as $line) {
        if ($line['mine'] == 't') {
            $stored_files[intval($line['id'])] = intval($line['file']);
        }
        if ($line['receipt_received'] == 't') {
            $finalized = true;
        }
    }
    // Only a voucher of which the user sees a current row.
    if (count($stored_files) == 0) {
        edit_save_rollback($members);
        echo '<div class="slot_error" id="slot_error">FEHLER: Buchung nicht gefunden.</div><br /><br /><br />';
        return;
    }
    if ($finalized) {
        edit_save_rollback($members);
        echo '<div class="slot_error" id="slot_error">FEHLER: Die Buchung wurde bereits finalisiert und kann jetzt nicht mehr verändert werden.</div><br /><br /><br />';
        return;
    }

    if (!posted_organizations_allowed($parts, $rights)) {
        edit_save_rollback($members);
        echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
        return;
    }
    // Nothing is written (no revision, no approval reset): the form again.
    if (!posted_amounts_valid($parts, $rights)) {
        edit_save_rollback($members);
        amount_refused();
        page_edit($form_rights, true);
        return;
    }
    // The posted rows must be current rows of the user (page_save_buchung()
    // takes their receipt numbers, posted ones are ignored); another row
    // means a form opened before another save. A row with a receipt must be
    // posted again, so that no receipt is dropped.
    $posted = [];
    for ($i = 0; $i < $parts; $i++) {
        $posted[] = intval(get_param($i, 'bid', 0, '/^\d+$/'));
    }
    $message = null;
    if ($parts < 1) {
        $message = 'Keine Buchungszeile angegeben.';
    }
    foreach ($posted as $bid) {
        if ($bid > 0 && !array_key_exists($bid, $stored_files)) {
            $message = 'Die Buchung wurde inzwischen geändert. Bitte neu laden.';
        }
    }
    foreach ($stored_files as $bid => $file) {
        if ($message === null && $file != 0 && !in_array($bid, $posted, true)) {
            $message = 'Eine Buchungszeile mit Beleg kann nicht entfernt werden.';
        }
    }
    if ($message !== null) {
        edit_save_rollback($members);
        revision_refused($message);
        page_edit($form_rights, true);
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
        page_save_buchung($id, $rights, $i, $ack1, $ack2, $voucher_warnings, $stored_files);
    }
    db_exec($dbconn, 'COMMIT;');
    members_drop($members);
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
    $acks = 0;
    if ($vouchers[0]['ack1'] != null && strlen($vouchers[0]['ack1']) > 3) {
        $acks++;
    }
    if ($vouchers[0]['ack2'] != null && strlen($vouchers[0]['ack2']) > 3) {
        $acks++;
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
    echo '<h4>Bestätigt von: ' . html_escape($vouchers[0]['ack1']) . ' ' . html_escape($vouchers[0]['ack2']);
    if (isset($vouchers[0]['ack1_old']) || isset($vouchers[0]['ack2_old'])) {
        echo '<del>' . html_escape($vouchers[0]['ack1_old']) . ' ' . html_escape($vouchers[0]['ack2_old']) . '</del>';
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
    $bgfandack = has_right($rights, 'bgf') && ($acks == 2) && ($vouchers[0]['receipt_received'] != 't');


    end_of_form($parts, $rights, $bgfandack && !$unsaved, $rights, true, !$unsaved);
}
