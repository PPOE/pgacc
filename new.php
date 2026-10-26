<?php

function get_param($part, $name, $default, $regex)
{
    $value = $default;
    if (is_string($_POST[$name . $part] ?? null) && (!$regex || preg_match($regex, $_POST[$name . $part]) == 1)) {
        $value = $_POST[$name . $part];
    }
    return $value;
}
function get_param_bool($part, $name, $default, $regex, $default_if_true)
{
    $value = $default;
    if (isset($_POST[$name . $part]) && (!$regex || preg_match($regex, $_POST[$name . $part]) == 1)) {
        $value = $default_if_true;
    }
    return $value;
}
// The organization of a voucher part that posts none: the user's single
// (numeric, trimmed) organization, otherwise 10.
function default_organization($rights)
{
    $default_lo = 10;
    $rights2 = rights_tokens($rights);
    if (count($rights2) == 1 && preg_match('/^\d+$/D', $rights2[0]) == 1) {
        $default_lo = intval($rights2[0]);
    }
    return $default_lo;
}
// get_voucher() text fields: SQL-escaped for the insert, as posted for a
// form that is shown again.
function voucher_text($value, $escape)
{
    global $dbconn;
    if ($escape) {
        return pg_escape_string($dbconn, $value);
    }
    return $value;
}
function get_voucher($part, $rights, $escape = true)
{
    $filepart = $part;
    $voucher['bid'] = get_param($part, 'bid', 0, '/^\d+$/');
    $voucher['dir'] = get_param($part, 'dir', 'in', '/^(in|out|wk|bel)$/');
    $voucher['in_type'] = get_param($part, 'in_type', 0, '/^\d+$/');
    $voucher['out_type'] = get_param($part, 'out_type', 0, '/^\d+$/');
    $voucher['wk_type'] = get_param($part, 'wk_type', 0, '/^\d+$/');
    if ($voucher['dir'] == 'wk') {
        $voucher['type'] = $voucher['wk_type'];
    } elseif ($voucher['dir'] == 'out') {
        $voucher['type'] = $voucher['out_type'];
    } elseif ($voucher['dir'] == 'in') {
        $voucher['type'] = $voucher['in_type'];
    } elseif ($voucher['dir'] == 'bel') {
        $voucher['type'] = 45;
        if ($part > 0) {
            $part = $part - 1;
        }
    }

    $voucher['date'] = format_date(get_param($part, 'date', null, '/^(((\d|(0|1|2)\d|3(0|1))\.(\d|0\d|1(0|1|2))\.20\d\d)|(20\d\d-(\d|0\d|1(0|1|2))-(\d|(0|1|2)\d|3(0|1))))$/'));
    $voucher['person_type'] = get_param($part, 'person_type', 2, '/^\d+$/');
    $voucher['lo'] = get_param($part, 'lo', default_organization($rights), '/^\d+$/');
    // The amount as posted (null if missing or not a string; shown again
    // when it is invalid) and in cents (amount_cents(): euros with at most two
    // decimals after "," or "."; null when invalid or missing, which refuses
    // the save). A Beleg part always has 0.00.
    $voucher['amount_input'] = get_param($part, 'amount', null, null);
    $voucher['amount'] = amount_cents($voucher['amount_input'], ',.', '', false);
    if ($voucher['type'] == 45) {
        $voucher['amount'] = "0.00";
    }
    $voucher['gegenkonto'] = voucher_text(get_param($part, 'gegenkonto', '', '/^( |\d|@|\.|[A-Z]|[@ÄÖÜäöü])+$/i'), $escape);
    $voucher['konto'] = voucher_text(get_param($part, 'konto', '', '/^( |\d|@|\.|[A-Z]|[ÄÖÜäöü])+$/i'), $escape);
    $voucher['vkonto'] = voucher_text(get_param($part, 'vkonto', '', '/^( |\d|@|\.|[A-Z]|[ÄÖÜäöü])+$/i'), $escape);
    $voucher['comment'] = voucher_text(get_param($part, 'comment', '', null), $escape);
    $voucher['commentgf'] = voucher_text(get_param($part, 'commentgf', '', null), $escape);
    $voucher['purpose'] = get_param_bool($part, 'purpose', 'false', null, 'true');
    $voucher['refund'] = get_param_bool($part, 'refund', 'false', null, 'true');
    $voucher['member'] = get_param_bool($part, 'member', 'false', null, 'true');
    $voucher['mitgliedsnummer'] = get_param($part, 'mitgliedsnummer', 0, '/^\d+$/');
    $voucher['name'] = voucher_text(get_param($part, 'name', '', null), $escape);
    $voucher['street'] = voucher_text(get_param($part, 'street', '', null), $escape);
    $voucher['plz'] = voucher_text(get_param($part, 'plz', '', null), $escape);
    $voucher['city'] = voucher_text(get_param($part, 'city', '', null), $escape);
    $voucher['file'] = get_param($filepart, 'file', -1, '/^\d+$/');
    return $voucher;
}
// Whether the organizations of the parts of a voucher may be used
// (organization_allowed()): the posted ones, or the default one of
// get_voucher() for a part that posts none.
function posted_organizations_allowed($parts, $rights)
{
    for ($part = 0; $part < $parts; $part++) {
        $lo = get_param($part, 'lo', default_organization($rights), '/^\d+$/');
        if (!organization_allowed($lo, $rights)) {
            return false;
        }
    }
    return true;
}
// Whether every posted part has a valid amount (a Beleg part has none of its
// own); otherwise the voucher is not saved.
function posted_amounts_valid($parts, $rights)
{
    for ($part = 0; $part < $parts; $part++) {
        if (get_voucher($part, $rights, false)['amount'] === null) {
            return false;
        }
    }
    return true;
}
// Whether a posted part is a member's: the save needs the member list
// (getusers()), loaded before the voucher is written.
function posted_member_part($parts, $rights)
{
    for ($part = 0; $part < $parts; $part++) {
        if (get_voucher($part, $rights, false)['member'] == 'true') {
            return true;
        }
    }
    return false;
}
// The message of a save refused because the member list could not be loaded
// ($error from getusers(), logged).
function members_unavailable($error)
{
    error_log('pgacc: member list not loaded: ' . $error);
    return 'Die Mitgliederverwaltung ist nicht erreichbar. Bitte später erneut speichern.';
}
// The posted row IDs of the parts (bid<n>): 0 for a new part.
function posted_bids($parts)
{
    $bids = [];
    for ($part = 0; $part < $parts; $part++) {
        $bids[$part] = intval(get_param($part, 'bid', 0, '/^\d+$/D'));
    }
    return $bids;
}
// The page shown instead of saving a voucher with an invalid amount.
function amount_refused()
{
    echo '<div class="slot_error" id="slot_error">FEHLER: Ungültiger Betrag (Euro mit höchstens zwei Dezimalstellen, z. B. 12,50). Die Buchung wurde nicht gespeichert.</div><br /><br /><br />';
}
// Each correction of a posted value is also added to $warnings ('date',
// 'refund', 'purpose'), for the redirect of the revision. The receipt number
// is never taken from the request: a revised row takes the one of the stored
// row it revises ($bid, posted_bids()) from $stored_files (row ID => file
// number, from the database; empty for a new voucher), at most once, and
// removes it there. Any other row starts without a receipt (the upload
// attaches one). The member list (ppmembers) is loaded by the caller.
function page_save_buchung($voucher_number, $rights, $part, $ack1_old, $ack2_old, &$warnings, &$stored_files, $bid)
{
    global $dbconn;
    $voucher = get_voucher($part, $rights);
    if ($voucher['date'] == null) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Kein Datum angegeben.</div><br /><br /><br />';
        $warnings[] = 'date';
        $voucher['date'] = '1800-01-01';
    }
    if ($voucher['refund'] == 'true' && $voucher['dir'] == 'in') {
        echo '<div class="slot_error" id="slot_error">FEHLER: Eine Kostenrückerstattung ist nur bei einer Ausgabe möglich.</div><br /><br /><br />';
        $warnings[] = 'refund';
        $voucher['refund'] = 'false';
    }

    if ($voucher['purpose'] == 'true' && ($voucher['dir'] == 'out' || $voucher['in_type'] != 8)) {
        // A Beleg part copies the purpose of the part before it: cleared
        // without a message.
        if ($voucher['dir'] != 'bel') {
            echo '<div class="slot_error" id="slot_error">FEHLER: Eine Zweckwidmung ist nur bei einer Spende möglich.</div><br /><br /><br />';
            $warnings[] = 'purpose';
        }
        $voucher['purpose'] = 'false';
    }

    if ($voucher['member'] == 'true') {
        if ($voucher['mitgliedsnummer'] == 0) {
            $queries = [];
            if (strlen($voucher['name']) > 0) {
                $n = $voucher['name'];
                $n2 = trim(str_replace(['Mag.','DI (FH)','iur.','Dipl.-Ing.','Dr.','Ing.'], ['','','','','',''], $n));
                $nt = explode(" ", $n, 2);
                $nt2 = explode(" ", $n2, 2);
                $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('$n');";
                if (count($nt) == 2) {
                    $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('{$nt[1]} {$nt[0]}');";
                }
                if ($n != $n2) {
                    $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('$n2');";
                    if (count($nt2) == 2) {
                        $queries[] = "SELECT * FROM ppmembers WHERE lower(name) = lower('{$nt2[1]} {$nt2[0]}');";
                    }
                }
            }
            $found = false;
            foreach ($queries as $query) {
                if ($result = db_query($dbconn, $query)) {
                    if (pg_num_rows($result) == 1) {
                        $line = pg_fetch_array($result, null, PGSQL_ASSOC);
                        $voucher['member'] = 'true';
                        $voucher['mitgliedsnummer'] = intval($line['id']);
                        //$voucher['lo'] = $line['lo'];
                        //$voucher['type'] = 1;
                        pg_free_result($result);
                        $found = true;
                        break;
                    }
                    pg_free_result($result);
                }
            }
        } else {
            $query = "SELECT name FROM ppmembers WHERE id = {$voucher['mitgliedsnummer']};";
            $result = db_query($dbconn, $query);
            while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
                $voucher['name'] = pg_escape_string($dbconn, $line['name']);
            }
            pg_free_result($result);
        }
    }
    $voucher['file'] = $stored_files[$bid] ?? 0;
    unset($stored_files[$bid]);
    $query = "
INSERT INTO vouchers
(voucher_id, date, type, person_type, orga, member,
member_id, contra_account, name, street, plz, city,
amount, account, vaccount, comment, commentgf,
committed, file, refund, ack1_old, ack2_old)
VALUES
($voucher_number, '{$voucher['date']}', "
      . ($voucher['dir'] == "in" ? $voucher['in_type'] : ($voucher['dir'] == "out" ? $voucher['out_type'] : ($voucher['dir'] == "wk" ? $voucher['wk_type'] : $voucher['type'])))
      . ",{$voucher['person_type']},{$voucher['lo']},{$voucher['member']},
  {$voucher['mitgliedsnummer']},'{$voucher['gegenkonto']}',
  '{$voucher['name']}','{$voucher['street']}','{$voucher['plz']}',
  '{$voucher['city']}',{$voucher['amount']},'{$voucher['konto']}',
  '{$voucher['vkonto']}','{$voucher['comment']}','{$voucher['commentgf']}',
  {$voucher['purpose']},{$voucher['file']},{$voucher['refund']},"
      . ($ack1_old == null ? "NULL" : "'" . pg_escape_string($dbconn, $ack1_old) . "'") . ","
      . ($ack2_old == null ? "NULL" : "'" . pg_escape_string($dbconn, $ack2_old) . "'")
      . ");";
    db_exec($dbconn, $query);
    return $voucher_number;
}
function page_new_save($rights)
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

    if (!posted_organizations_allowed($parts, $rights)) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
        return;
    }
    if (!posted_amounts_valid($parts, $rights)) {
        amount_refused();
        page_new($form_rights);
        return;
    }
    if (posted_member_part($parts, $rights) && ($members_error = getusers()) !== null) {
        echo '<div class="slot_error" id="slot_error">FEHLER: ' . members_unavailable($members_error) . '</div><br /><br /><br />';
        page_new($form_rights);
        return;
    }
    $voucher_number = db_next_value($dbconn, 'voucher_number');

    echo "<h1>Buchung erfasst - Buchung Nr. $voucher_number - AUF BELEG NOTIEREN!</h1>";

    // A new voucher has no stored receipts.
    $warnings = [];
    $stored_files = [];
    for ($i = 0; $i < $parts; $i++) {
        page_save_buchung($voucher_number, $rights, $i, null, null, $warnings, $stored_files, 0);
    }
}
function page_new_file($rights)
{
    global $dbconn;
    $rights = write_rights($rights);
    if ($rights === false) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
        return;
    }
    $parts = 1;
    if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1) {
        $parts = intval($_POST["parts"]);
    }

    $bid = -1;
    $file = -1;
    for ($part = 0; $part < $parts; $part++) {
        if (isset($_FILES["file$part"]) && isset($_FILES["file$part"]["name"]) && strlen($_FILES["file$part"]["name"]) > 3) {
            if (isset($_POST["bid$part"]) && intval($_POST["bid$part"]) > 0) {
                $file = $part;
                $bid = intval($_POST["bid$part"]);
                break;
            }
        }
    }
    if ($file == -1 || $_FILES["file$part"]["size"] > 100000000) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Datei Upload fehlgeschlagen.</div><br /><br /><br />';
        return;
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

    // Only a current row of this voucher that the user sees; checked before the
    // finalized check, so that other organizations learn nothing about it.
    $query = "SELECT file FROM vouchers WHERE NOT deleted AND id = $bid AND voucher_id = $id $rightssql;";
    $result = db_query($dbconn, $query);
    if (!($line = pg_fetch_array($result, null, PGSQL_ASSOC))) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Buchungszeile nicht gefunden.</div><br /><br /><br />';
        pg_free_result($result);
        return;
    }
    $previous_file = intval($line['file']);
    pg_free_result($result);

    // A finalized row blocks the upload, also a row of another organization.
    $query = "SELECT 1 FROM vouchers WHERE NOT deleted AND receipt_received AND voucher_id = $id;";
    $result = db_query($dbconn, $query);
    if (pg_fetch_array($result, null, PGSQL_ASSOC)) {
        echo '<div class="slot_error" id="slot_error">FEHLER: Die Buchung wurde bereits finalisiert und kann jetzt nicht mehr verändert werden.</div><br /><br /><br />';
        pg_free_result($result);
        return;
    }
    pg_free_result($result);

    $file_number = db_next_value($dbconn, 'file_number');

    // The scoped update must match the row (checked above) before the receipt
    // is stored and the approvals of the voucher are reset.
    $query = "UPDATE vouchers SET file = $file_number WHERE NOT deleted AND id = $bid AND voucher_id = $id AND file = $previous_file $rightssql;";
    $updated = db_exec($dbconn, $query);

    $sourcepath = $_FILES["file$file"]["tmp_name"];
    if ($updated != 1 || !receipt_store(getcwd() . '/files', $file_number, $sourcepath)) {
        db_exec($dbconn, "UPDATE vouchers SET file = $previous_file WHERE NOT deleted AND id = $bid AND file = $file_number;");
        echo '<div class="slot_error" id="slot_error">FEHLER: Beleg konnte nicht gespeichert werden.</div><br /><br /><br />';
        return;
    }

    // An upload resets the approvals of the whole voucher.
    vouchers_reset_ack($id, '');

    echo "<h1>Upload erfolgreich - Buchung Nr. $id - Buchungszeile Nr. $bid</h1>";
    block_start();
    echo "<a href=\"index.php?action=edit&id=$id&bid=$bid&hint=1\">Zurück zur Buchung</a>";
    block_end();
    relocate("index.php?action=edit&id=$id&bid=$bid&hint=1");
}
function page_edit_form($part, $voucher, $rights)
{
    global $dbconn;
    block_start();
    if (intval($voucher['bid']) == 0) {
        echo '<h3>Neue Buchungszeile</h3>';
    } else {
        echo '<h3>Buchungszeile ' . $voucher['bid'] . '</h3>';
    }
    // An invalid entered amount is shown again as entered, with the error; a
    // missing one (a new form, a NULL amount in the database) as 0.00.
    $amount_invalid = $voucher['amount'] === null && isset($voucher['amount_input']);
    $amount_value = $amount_invalid ? html_escape($voucher['amount_input']) : format_amount($voucher['amount'] ?? 0);
    $amount_error = $amount_invalid ? ' <span class="slot_error">FEHLER: Ungültiger Betrag</span>' : '';
    if ($voucher['dir'] == 'bel' && $part == 0) {
        $voucher['dir'] = 'in';
    }
    echo '
<input type="hidden" name="bid' . $part . '" value="' . $voucher['bid'] . '" />
<div id="date_block' . $part . '">
<label class="ui_field_label" for="date' . $part . '">Datum</label><input type="text" id="date' . $part . '" name="date' . $part . '" value="' . $voucher['date'] . '" onchange="clicked();" />
</div>
<div>
<label for="dir' . $part . '" class="ui_field_label">Typ
</label>
<select id="dir' . $part . '" name="dir' . $part . '" onchange="clicked();">
<option value="in"' . ($voucher['dir'] == 'in' ? ' selected' : '') . '>Einnahme</option>
<option value="out"' . ($voucher['dir'] == 'out' ? ' selected' : '') . '>Ausgabe</option>
<option value="wk"' . ($voucher['dir'] == 'wk' ? ' selected' : '') . '>Wahlkampfausgabe</option>'
    . ($part != 0 ? '<option value="bel"' . ($voucher['dir'] == 'bel' ? ' selected' : '') . '>Beleg</option>' : '') . '
</select>
</div>
<div id="not_bel_a_' . $part . '"' . ($voucher['dir'] == 'bel' ? ' style="display: none;"' : '') . '>
<div id="in_block' . $part . '"' . ($voucher['dir'] == 'in' ? '' : ' style="display: none;"') . '>
<label for="in_type' . $part . '" class="ui_field_label">Art der Einnahme</label> 
<select id="in_type' . $part . '" name="in_type' . $part . '" onchange="clicked();">
';
    $query = "SELECT id,name FROM type WHERE income = 1 ORDER BY used DESC,id ASC";
    $result = db_query($dbconn, $query);
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        echo '<option value="' . ($voucher['type'] == $line['id'] ? $line['id'] . '" selected="selected"' : $line['id'] . '"') . '>' . html_escape($line['name']) . '</option>
';
    }
    pg_free_result($result);
    echo '
</select>
</div>
<div id="out_block' . $part . '"' . ($voucher['dir'] == 'out' ? '' : ' style="display: none;"') . '>
<label for="out_type' . $part . '" class="ui_field_label">Art der Ausgabe</label> 
<select id="out_type' . $part . '" name="out_type' . $part . '" onchange="clicked();">
';
    $query = "SELECT id,name FROM type WHERE income = 0 ORDER BY used DESC,id ASC";
    $result = db_query($dbconn, $query);
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        echo '<option value="' . ($voucher['type'] == $line['id'] ? $line['id'] . '" selected="selected"' : $line['id'] . '"') . '>' . html_escape($line['name']) . '</option>
';
    }
    pg_free_result($result);
    echo '
</select>
</div>
<div id="wk_block' . $part . '"' . ($voucher['dir'] == 'wk' ? '' : ' style="display: none;"') . '>
<label for="wk_type' . $part . '" class="ui_field_label">Art der Wahlkampfausgabe</label> 
<select id="wk_type' . $part . '" name="wk_type' . $part . '" onchange="clicked();">
';
    $query = "SELECT id,name FROM type WHERE income = -1 ORDER BY used DESC,id ASC";
    $result = db_query($dbconn, $query);
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        echo '<option value="' . ($voucher['type'] == $line['id'] ? $line['id'] . '" selected="selected"' : $line['id'] . '"') . '>' . html_escape($line['name']) . '</option>
    ';
    }
    pg_free_result($result);
    echo '
</select>
</div>
<div id="person_type_block' . $part . '">
<label for="person_type' . $part . '" class="ui_field_label">Art des Geschäftspartners</label> 
<select id="person_type' . $part . '" name="person_type' . $part . '" onchange="clicked();">
';
    $query = "SELECT id,name FROM person_type ORDER BY used DESC,id ASC";
    $result = db_query($dbconn, $query);
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
        echo '<option value="' . ($voucher['person_type'] == $line['id'] ? $line['id'] . '" selected="selected"' : $line['id'] . '"') . '>' . html_escape($line['name']) . '</option>
    ';
    }
    pg_free_result($result);
    echo '
</select>
';
    // The organization field for rights with several entries (a single one is
    // the default organization).
    if (count(rights_tokens($rights)) != 1) {
        echo '
</div>
<div>
<label for="lo' . $part . '" class="ui_field_label">Teilorganisation</label> 
<select id="lo' . $part . '" name="lo' . $part . '" onchange="clicked();">
';
        $query = "SELECT id,name FROM lo ORDER BY id ASC";
        $result = db_query($dbconn, $query);
        while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
            if (organization_allowed($line['id'], $rights)) {
                echo '<option value="' . ($line['id'] == $voucher['lo'] ? $line['id'] . '" selected="selected"' : $line['id'] . '"') . '>' . html_escape($line['name']) . '</option>
';
            }
        }
        pg_free_result($result);
    }
    echo '
</select>
</div>
<div>
<label class="ui_field_label" for="member' . $part . '">Mitglied</label><input type="checkbox" id="member' . $part . '" name="member' . $part . '" value="1" ' . ($voucher['member'] == 'true' ? ' checked="checked"' : '') . ' onchange="clicked();" />' . (intval($voucher['mitgliedsnummer']) > 0 ? ' &middot; <a href="https://mitglieder.piratenpartei.at/adm_program/modules/profile/profile.php?user_id=' . $voucher['mitgliedsnummer'] . '" target="_blank">In MV öffnen</a>' : '') . '
</div>
<div id="mitgliedsnummer' . $part . '">
<label class="ui_field_label" for="mitgliedsnummer' . $part . '">Mitgliedsnummer</label><input type="text" name="mitgliedsnummer' . $part . '" value="' . $voucher['mitgliedsnummer'] . '" onchange="clicked();" />
</div>
<div id="gegenkonto' . $part . '">
<label class="ui_field_label" for="gegenkonto' . $part . '">Fremdkonto</label><input type="text" name="gegenkonto' . $part . '" value="' . html_escape($voucher['gegenkonto']) . '" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="amount' . $part . '">Betrag (in €)</label><input type="text" id="amount' . $part . '" name="amount' . $part . '" value="' . $amount_value . '" onchange="clicked();" />' . $amount_error . '
</div>
<div>
<label class="ui_field_label" for="konto' . $part . '">Konto</label><input type="text" id="konto' . $part . '" name="konto' . $part . '" value="' . html_escape($voucher['konto']) . '" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="comment' . $part . '">Buchungstext</label><input type="text" id="comment' . $part . '" name="comment' . $part . '" value="' . html_escape($voucher['comment']) . '" onchange="clicked();" />
</div>
</div>
<div>
<label class="ui_field_label" for="commentgf' . $part . '">Kommentar</label><input type="text" id="commentgf' . $part . '" name="commentgf' . $part . '" value="' . html_escape($voucher['commentgf']) . '" onchange="clicked();" />
</div>
<div>
<label class="ui_field_label" for="vkonto' . $part . '">Virtuelles Konto</label><input type="text" id="vkonto' . $part . '" name="vkonto' . $part . '" value="' . html_escape($voucher['vkonto']) . '" onchange="clicked();" />
</div>
<div id="not_bel_b_' . $part . '"' . ($voucher['dir'] == 'bel' ? ' style="display: none;"' : '') . '>
<div id="purpose_block' . $part . '">
<label class="ui_field_label" for="purpose' . $part . '">Zweckgebunden</label><input type="checkbox" id="purpose' . $part . '" name="purpose' . $part . '" value="1" ' . ($voucher['purpose'] == 'true' ? ' checked="checked"' : '') . ' onchange="clicked();" />
</div>
<div id="refund_block' . $part . '">
<label class="ui_field_label" for="refund' . $part . '">Rückerstattung</label><input type="checkbox" id="refund' . $part . '" name="refund' . $part . '" value="1" ' . ($voucher['refund'] == 'true' ? ' checked="checked"' : '') . ' onchange="clicked();" />
</div>
<div id="name' . $part . '">
<label class="ui_field_label" for="name' . $part . '">Name</label><input type="text" name="name' . $part . '" value="' . html_escape($voucher['name']) . '" onchange="clicked();" />
</div>
<div id="street' . $part . '">
<label class="ui_field_label" for="street' . $part . '">Straße</label><input type="text" name="street' . $part . '" value="' . html_escape($voucher['street']) . '" onchange="clicked();" />
</div>
<div id="plz' . $part . '">
<label class="ui_field_label" for="plz' . $part . '">PLZ</label><input type="text" name="plz' . $part . '" value="' . html_escape($voucher['plz']) . '" onchange="clicked();" />
</div>
<div id="city' . $part . '">
<label class="ui_field_label" for="city' . $part . '">Ort</label><input type="text" name="city' . $part . '" value="' . html_escape($voucher['city']) . '" onchange="clicked();" />
</div>
</div>
<div id="city' . $part . '">
<label class="ui_field_label" for="file' . $part . '">Beleg</label>';
    if (intval($voucher['file']) > 0) {
        echo '<a href="?action=file&id=' . $voucher['bid'] . '" target="_blank">Beleg Anzeigen</a><input type="hidden" name="file' . $part . '" value="' . $voucher['file'] . '" />';
    } elseif (intval($voucher['file']) == 0) {
        echo '
<input type="hidden" name="MAX_FILE_SIZE" value="100000000" />
<input name="file' . $part . '" type="file"  onchange="clicked();" accept="application/pdf"/><br />
<input type="submit" name="fileupload" value="PDF Hochladen (OHNE SPEICHERN)" />
';
    } else {
        echo 'Die Buchungszeile muss gespeichert werden bevor ein Beleg dazu hochgeladen werden kann.';
    }
    echo '
</div>';
    if (organization_allowed($voucher['lo'], $rights)) {
        echo '
<div id="del_voucher_' . $part . '" style="float: right;">
<a href="javascript:deleteB(' . $part . ');">Diese Buchungszeile löschen</a>
</div>';
    }
    echo '
<br />
';
    block_end();
}

function page_new_buchung($part, $part_to_remove, $rights, $add)
{
    if ($part_to_remove != -1 && $part >= $part_to_remove) {
        $voucher = get_voucher($part + 1, $rights, false);
    } elseif ($add) {
        $voucher = get_voucher($part - 1, $rights, false);
    } else {
        $voucher = get_voucher($part, $rights, false);
    }

    if ($add == 2) {
        $voucher['amount'] = 0;
        $voucher['dir'] = 'bel';
        $voucher['type'] = 45;
    }

    page_edit_form($part, $voucher, $rights);
}

function page_form_header($p)
{

    echo '
<script type="text/javascript">
function deleteB(part)
{
  document.getElementById("ack").style.display = "none";
  var max = parseInt(document.getElementById("parts").value);
  if (max == 1)
  {
    alert("Kann letzte Buchungszeile nicht löschen!");
    return;
  }
  if (!confirm("Sicher löschen?"))
    return;
  var e = document.createElement("div");
  e.innerHTML = "<input type=\'hidden\' name=\'remove\' value=\'" + part + "\' />";
  document.mainform.appendChild(e);
  document.mainform.submit();
}
function clicked(init)
{
  var max = 1;
  try {
    max = parseInt(document.getElementById("parts").value);
  }
  catch (e)
  {
    alert("Anzahl der Buchungszeilen konnte nicht ermittelt werden.");
  }

  if (max == 1)
  {
    document.getElementById("del_voucher_0").style.display = "none";
  }
  if (init !== true)
  {
    try { document.getElementById("ack").style.display = "none"; } catch (e) { }
    try { document.getElementById("belegdiv").style.display = "none"; } catch (e) { }
  }
  var i = 0;
  while (i < max)
  {
    if (document.getElementById("dir" + i).selectedIndex == 0)
    {
      if (document.getElementById("in_type" + i).options[document.getElementById("in_type" + i).selectedIndex].value == 8)
        document.getElementById("purpose_block" + i).style.display = "";
      else
        document.getElementById("purpose_block" + i).style.display = "none";
      document.getElementById("refund_block" + i).style.display = "none";
      document.getElementById("date_block" + i).style.display = "";
      document.getElementById("not_bel_a_" + i).style.display = "";
      document.getElementById("not_bel_b_" + i).style.display = "";
      document.getElementById("in_block" + i).style.display = "";
      document.getElementById("out_block" + i).style.display = "none";
      document.getElementById("wk_block" + i).style.display = "none";
    }
    else if (document.getElementById("dir" + i).selectedIndex == 1)
    {
      document.getElementById("purpose_block" + i).style.display = "none";
      document.getElementById("refund_block" + i).style.display = "";
      document.getElementById("date_block" + i).style.display = "";
      document.getElementById("not_bel_a_" + i).style.display = "";
      document.getElementById("not_bel_b_" + i).style.display = "";
      document.getElementById("in_block" + i).style.display = "none";
      document.getElementById("out_block" + i).style.display = "";
      document.getElementById("wk_block" + i).style.display = "none";
    }
    else if (document.getElementById("dir" + i).selectedIndex == 2)
    {
      document.getElementById("purpose_block" + i).style.display = "none";
      document.getElementById("refund_block" + i).style.display = "none";
      document.getElementById("date_block" + i).style.display = "";
      document.getElementById("not_bel_a_" + i).style.display = "";
      document.getElementById("not_bel_b_" + i).style.display = "";
      document.getElementById("in_block" + i).style.display = "none";
      document.getElementById("out_block" + i).style.display = "none";
      document.getElementById("wk_block" + i).style.display = "";
    }
    else if (document.getElementById("dir" + i).selectedIndex == 3)
    {
      document.getElementById("date_block" + i).style.display = "none";
      document.getElementById("not_bel_a_" + i).style.display = "none";
      document.getElementById("not_bel_b_" + i).style.display = "none";
    }
    else
    {
      alert("Ungültige Typ-Auswahl");
    }
    if (document.getElementById("member" + i).checked)
    {
      document.getElementById("mitgliedsnummer" + i).style.display = "";
      document.getElementById("street" + i).style.display = "none";
      document.getElementById("plz" + i).style.display = "none";
      document.getElementById("city" + i).style.display = "none";
      document.getElementById("gegenkonto" + i).style.display = "none";
      document.getElementById("person_type" + i).selectedIndex = 0;
    }
    else
    {
      document.getElementById("mitgliedsnummer" + i).style.display = "none";
      document.getElementById("street" + i).style.display = "";
      document.getElementById("plz" + i).style.display = "";
      document.getElementById("city" + i).style.display = "";
      document.getElementById("gegenkonto" + i).style.display = "";
    }
    var i = i + 1;
  }
}
</script>
<form name="mainform" class="vertical" enctype="multipart/form-data" action="index.php?action=' . $p . '" method="post">
' . csrf_field() . '
';
}

function page_new($rights)
{
    $parts = 1;
    if (isset($_POST["parts"]) && preg_match('/^\d+$/', $_POST["parts"]) == 1) {
        $parts = $_POST["parts"];
    }
    if (isset($_POST["add"]) || isset($_POST["addB"])) {
        $parts++;
    }
    $part_to_remove = -1;
    if (isset($_POST["remove"]) && intval($_POST["remove"]) >= 0 && intval($_POST["remove"]) < $parts) {
        $parts--;
        $part_to_remove = intval($_POST["remove"]);
    }

    page_form_header("new");
    echo '
<h1>Buchung erfassen</h1>
';

    $add = 0;
    for ($i = 0; $i < $parts; $i++) {
        if (isset($_POST["add"]) && $i == $parts - 1) {
            $add = 1;
        } elseif (isset($_POST["addB"]) && $i == $parts - 1) {
            $add = 2;
        }
        page_new_buchung($i, $part_to_remove, $rights, $add);
    }

    end_of_form($parts, $rights, false, $rights, true, false);
}

function end_of_form($parts, $rights, $bgfandack, $lo, $open = true, $ack = true)
{
    $rights2 = rights_tokens($rights);
    if (write_rights($rights) !== false && (has_right($rights, 'bgf') || in_array(trim($lo), konto2lo($rights2)))) {
        if ($bgfandack) {
            echo '<div id="belegdiv">';
            block_start();

            echo '
<h3>Bestätigungen zurücksetzen</h3>
<div>
Wurde die Buchung fehlerhaft durchgeführt und muss korrigiert werden?
<input name="belegfehler" id="belegfehler" value="Bestätigungen zurücksetzen!" type="submit" /><br /><br />
</div>
';
            block_end();
            echo '</div>';
        } else {

            if ($open) {
                block_start();
                echo '<input name="speichern" id="speichern" value="Speichern" type="submit" />';
                //if (strpos($rights,'bgf') !== false && $ack)
                if ($ack) {
                    echo '<input name="ack" id="ack" value="Bestätigen (OHNE SPEICHERN)" type="submit" style="margin-left: 5%"/>';
                }
                echo '<input value="Beleg hinzufügen" type="submit" name="addB" style="margin-left: 15%"/>';
                echo '<input value="Buchungszeile hinzufügen" type="submit" name="add" style="margin-left: 15%"/>';

                block_end();
            }
        }
    }
    echo '
<input value="' . $parts . '" type="hidden" id="parts" name="parts" />
';
    echo "</form>";
    echo '<script type="text/javascript">clicked(true);</script>';
}
