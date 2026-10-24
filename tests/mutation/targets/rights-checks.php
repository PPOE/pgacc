<?php
/*
 * Organization scope of voucher writes: the rights that voucher creation and
 * revision pass to get_voucher() (default organization), the check of posted
 * organizations against them, and the rights SQL
 * of approval in edit.php. Users of one organization must
 * not change rows of another one. Tested with tests/write-workflows.php
 * (users of organizations 1 and 2, bgf, admin, empty rights). See
 * tests/mutation/README.md for the format.
 */
$ack = array('start' => 'function page_edit_ack($rights)', 'end' => 'function page_edit_save($rights)');
$dropAcks = array('start' => 'function page_edit_drop_acks($rights)', 'end' => 'function page_edit_ack($rights)');

return array(
    'description' => 'Organization scope of voucher creation, revision and approval '
        . '(write-workflow tests)',
    'file' => 'edit.php',
    'tests' => array(
        array('type' => 'http', 'script' => 'tests/write-workflows.php'),
    ),
    'timeout' => 180,
    'mutations' => array(
        array(
            'id' => 'new-save-rights-dropped',
            'file' => 'new.php',
            'search' => 'function page_new_save($rights)',
            'replace' => 'function page_new_save($unused)',
            'rationale' => 'Voucher creation uses the rights of the logged-in user (as before the fix: '
                . 'undefined, so an organization user\'s voucher went to organization 10).',
        ),
        array(
            'id' => 'new-save-rights-null',
            'file' => 'new.php',
            'search' => 'page_save_buchung($voucher_number, $rights, $i);',
            'replace' => 'page_save_buchung($voucher_number, null, $i);',
            'rationale' => 'Voucher creation passes the user\'s rights to page_save_buchung(), so an '
                . 'organization user\'s voucher is saved in that organization, not organization 10.',
        ),
        array(
            'id' => 'default-organization-fixed',
            'file' => 'new.php',
            'search' => '  $default_lo = intval($rights);',
            'replace' => '  $default_lo = 10;',
            'rationale' => 'Without a posted organization, a user of one organization saves into that '
                . 'organization (creation and revision).',
        ),
        array(
            'id' => 'default-organization-for-any-rights',
            'file' => 'new.php',
            'search' => 'if (count($rights2) == 1 && is_numeric($rights))',
            'replace' => 'if (count($rights2) == 1)',
            'rationale' => 'Only a single numeric organization is a default: a bgf user\'s voucher '
                . 'without organization goes to organization 10, not intval(\'bgf\') = 0.',
        ),
        array(
            'id' => 'posted-organization-ignored',
            'file' => 'new.php',
            'search' => "\$voucher['lo'] = get_param(\$part, 'lo', \$default_lo,",
            'replace' => "\$voucher['lo'] = get_param(\$part, 'lx', \$default_lo,",
            'rationale' => 'A posted organization is saved as given.',
        ),
        array(
            'id' => 'new-posted-organization-unchecked',
            'file' => 'new.php',
            'search' => 'if (!posted_organizations_allowed($parts, $rights))',
            'replace' => 'if (false)',
            'rationale' => 'Fail-open: a new voucher is saved in any posted organization.',
        ),
        array(
            'id' => 'revision-posted-organization-unchecked',
            'search' => 'if (!posted_organizations_allowed($parts, $rights))',
            'replace' => 'if (false)',
            'rationale' => 'Fail-open: a revision is saved in any posted organization.',
        ),
        array(
            'id' => 'organization-bgf-exemption-removed',
            'file' => 'functions.php',
            'search' => "  if (has_right(\$rights, 'bgf') || has_right(\$rights, 'root'))\n    return true;",
            'replace' => "  if (has_right(\$rights, 'root'))\n    return true;",
            'rationale' => 'bgf users may post any organization.',
        ),
        array(
            'id' => 'organization-root-exemption-removed',
            'file' => 'functions.php',
            'search' => "  if (has_right(\$rights, 'bgf') || has_right(\$rights, 'root'))\n    return true;",
            'replace' => "  if (has_right(\$rights, 'bgf'))\n    return true;",
            'rationale' => 'root users may post any organization (the form offers them all).',
        ),
        array(
            'id' => 'organization-loose-comparison',
            'file' => 'functions.php',
            'search' => '  return has_right($rights, strval($lo));',
            'replace' => '  return in_array($lo, explode(",", $rights));',
            'rationale' => 'Organizations are compared as strings: a padded ID is not one of the user\'s.',
        ),
        array(
            'id' => 'posted-organization-default-checked',
            'file' => 'new.php',
            'search' => 'if ($lo !== null && !organization_allowed($lo, $rights))',
            'replace' => 'if (!organization_allowed($lo, $rights))',
            'rationale' => 'Without a posted organization the default applies and is not refused.',
        ),
        array(
            'id' => 'posted-organization-first-part-only',
            'file' => 'new.php',
            'search' => "for (\$part = 0; \$part < \$parts; \$part++)\n{\n  \$lo = get_param(",
            'replace' => "for (\$part = 0; \$part < 1; \$part++)\n{\n  \$lo = get_param(",
            'rationale' => 'Every part of a voucher is checked, not only the first.',
        ),
        array(
            'id' => 'new-account-creator-rights',
            'file' => 'accounts.php',
            'search' => "crypt('\$pass', gen_salt('md5')), '\$nrights');",
            'replace' => "crypt('\$pass', gen_salt('md5')), '\$rights');",
            'rationale' => 'A new user gets the entered rights, not those of the creating root user.',
        ),
        array(
            'id' => 'new-account-rights-unescaped',
            'file' => 'accounts.php',
            'search' => '? pg_escape_string($_POST["rights$id"]) :',
            'replace' => '? $_POST["rights$id"] :',
            'rationale' => 'Removed escape: entered rights are inserted as SQL.',
        ),
        array(
            'id' => 'ack-finalized-check-removed',
            'region' => $ack,
            'search' => 'WHERE NOT deleted AND receipt_received AND voucher_id = $id',
            'replace' => 'WHERE NOT deleted AND false AND voucher_id = $id',
            'rationale' => 'Fail-open: a finalized voucher can be approved.',
        ),
        array(
            'id' => 'ack-visibility-check-removed',
            'region' => $ack,
            'search' => 'if (!pg_fetch_array($result, null, PGSQL_ASSOC)) {',
            'replace' => 'if (false) {',
            'rationale' => 'A user of another organization learns whether a voucher is finalized.',
        ),
        array(
            'id' => 'ack-visibility-check-unscoped',
            'region' => $ack,
            'search' => 'SELECT 1 FROM vouchers WHERE NOT deleted AND voucher_id = $id $rightssql;',
            'replace' => 'SELECT 1 FROM vouchers WHERE NOT deleted AND voucher_id = $id;',
            'rationale' => 'The visibility check uses the organization scope of the user.',
        ),
        array(
            'id' => 'ack-visibility-check-counts-deleted',
            'region' => $ack,
            'search' => 'SELECT 1 FROM vouchers WHERE NOT deleted AND voucher_id = $id $rightssql;',
            'replace' => 'SELECT 1 FROM vouchers WHERE voucher_id = $id $rightssql;',
            'rationale' => 'Deleted rows (old revisions) do not make a voucher visible for approval.',
        ),
        array(
            'id' => 'ack-finalized-check-scoped',
            'region' => $ack,
            'search' => 'WHERE NOT deleted AND receipt_received AND voucher_id = $id;',
            'replace' => 'WHERE NOT deleted AND receipt_received AND voucher_id = $id $rightssql;',
            'rationale' => 'A finalized row of another organization still blocks the approval of the '
                . 'user\'s rows of the same voucher.',
        ),
        array(
            'id' => 'ack-receipt-check-unscoped',
            'region' => $ack,
            'search' => 'WHERE (ack1 IS NULL OR file != 0) AND voucher_id = $id $rightssql;',
            'replace' => 'WHERE (ack1 IS NULL OR file != 0) AND voucher_id = $id;',
            'rationale' => 'The receipt check counts only the user\'s rows: an approved row without a '
                . 'receipt is refused although a row of another organization is open.',
        ),
        array(
            'id' => 'ack1-update-unscoped',
            'region' => $ack,
            'search' => 'WHERE ack1 IS NULL AND voucher_id = $id $rightssql;',
            'replace' => 'WHERE ack1 IS NULL AND voucher_id = $id;',
            'rationale' => 'Wrong organization: the first approval sets ack1 on rows of other '
                . 'organizations of the same voucher.',
        ),
        array(
            'id' => 'ack2-update-unscoped',
            'region' => $ack,
            'search' => "ack1 != '\$name' AND voucher_id = \$id \$rightssql;",
            'replace' => "ack1 != '\$name' AND voucher_id = \$id;",
            'rationale' => 'Wrong organization: the second approval sets ack2 on rows of other '
                . 'organizations of the same voucher.',
        ),
        array(
            'id' => 'ack2-same-user',
            'region' => $ack,
            'search' => "AND ack1 IS NOT NULL AND ack1 != '\$name' AND",
            'replace' => 'AND ack1 IS NOT NULL AND',
            'rationale' => 'Four-eyes principle: the first approver cannot give the second approval.',
        ),
        array(
            'id' => 'merge-root-check-removed',
            'search' => "if (!has_right(\$rights, 'root'))",
            'replace' => 'if (false)',
            'rationale' => 'Fail-open: users without root merge vouchers.',
        ),
        array(
            'id' => 'merge-root-substring',
            'search' => "if (!has_right(\$rights, 'root'))",
            'replace' => "if (strpos(\$rights, 'bgf') === false && !has_right(\$rights, 'root'))",
            'rationale' => 'bgf without root may not merge.',
        ),
        array(
            'id' => 'drop-acks-bgf-substring',
            'region' => $dropAcks,
            'search' => "if (!has_right(\$rights, 'bgf'))",
            'replace' => "if (strpos(\$rights, 'bgf') === false)",
            'rationale' => 'Read-only bgf (Rbgf) does not reset approvals.',
        ),
        array(
            'id' => 'finalization-write-restored',
            'file' => 'index.php',
            'search' => '  else if (isset($_POST["belegfehler"]))',
            'replace' => '  else if (isset($_POST["beleg"]) && pg_query("UPDATE vouchers SET receipt_received = true WHERE voucher_id = " . intval($_POST["id"])) && false) {} else if (isset($_POST["belegfehler"]))',
            'rationale' => 'The removed finalization: a POST with "beleg" writes nothing.',
        ),
        array(
            'id' => 'accounts-other-user-root-substring',
            'file' => 'accounts.php',
            'search' => "&& !has_right(\$rights, 'root'))\n      {",
            'replace' => "&& strpos(\$rights,'root') === false)\n      {",
            'rationale' => 'Only the exact root right changes other users.',
        ),
        array(
            'id' => 'accounts-own-password-root-substring',
            'file' => 'accounts.php',
            'search' => "else if (!has_right(\$rights, 'root') && \$pass",
            'replace' => "else if (strpos(\$rights,'root') === false && \$pass",
            'rationale' => 'Users without the exact root right change only their password.',
        ),
        array(
            'id' => 'accounts-rights-update-root-substring',
            'file' => 'accounts.php',
            'search' => "      else if (has_right(\$rights, 'root')) ",
            'replace' => "      else if (strpos(\$rights,'root') !== false) ",
            'rationale' => 'Only the exact root right changes rights.',
        ),
        array(
            'id' => 'accounts-help-root-substring',
            'file' => 'accounts.php',
            'search' => "if (has_right(\$rights, 'root'))\n{\n  echo '<p>1: Burgenland",
            'replace' => "if (strpos(\$rights,'root') !== false)\n{\n  echo '<p>1: Burgenland",
            'rationale' => 'The rights help is shown to root only.',
        ),
        array(
            'id' => 'accounts-rights-column-root-substring',
            'file' => 'accounts.php',
            'search' => "<td><b>Passwort</b></td>'.(has_right(\$rights, 'root') ?",
            'replace' => "<td><b>Passwort</b></td>'.(strpos(\$rights,'root') !== false ?",
            'rationale' => 'The rights column is shown to root only.',
        ),
        array(
            'id' => 'accounts-user-list-root-substring',
            'file' => 'accounts.php',
            'search' => "if (!has_right(\$rights, 'root'))\n{\n  \$restrict",
            'replace' => "if (strpos(\$rights,'root') === false)\n{\n  \$restrict",
            'rationale' => 'Only root lists every user.',
        ),
        array(
            'id' => 'accounts-rights-input-root-substring',
            'file' => 'accounts.php',
            'search' => "value=\"******\"></td>'.(has_right(\$rights, 'root') ? '<td><input type=\"text\" name=\"rights'.\$i",
            'replace' => "value=\"******\"></td>'.(strpos(\$rights,'root') !== false ? '<td><input type=\"text\" name=\"rights'.\$i",
            'rationale' => 'The rights inputs are shown to root only.',
        ),
        array(
            'id' => 'accounts-create-row-root-substring',
            'file' => 'accounts.php',
            'search' => "if (has_right(\$rights, 'root'))\n  echo '<tr><td><input type=\"text\" name=\"name-1\"",
            'replace' => "if (strpos(\$rights,'root') !== false)\n  echo '<tr><td><input type=\"text\" name=\"name-1\"",
            'rationale' => 'The new-user row is shown to root only.',
        ),
        array(
            'id' => 'recover-root-substring',
            'file' => 'recover.php',
            'search' => "if (!has_right(\$rights, 'root'))",
            'replace' => "if (strpos(\$rights,'root') === false)",
            'rationale' => 'Only the exact root right recovers rows.',
        ),
        array(
            'id' => 'member-fees-bgf-substring',
            'file' => 'mb.php',
            'search' => "if (!has_right(\$rights, 'bgf') && !has_right(\$rights, 'Rbgf'))",
            'replace' => "if (strpos(\$rights,\"bgf\") === false)",
            'rationale' => 'Only bgf and Rbgf open the member-fee page.',
        ),
        array(
            'id' => 'merge-link-root-substring',
            'file' => 'edit.php',
            'search' => "if (has_right(\$rights, 'root'))\n{\n  echo '<script",
            'replace' => "if (strpos(\$rights, 'root') !== false)\n{\n  echo '<script",
            'rationale' => 'The merge link is shown to root only.',
        ),
        array(
            'id' => 'drop-acks-reset-removed',
            'region' => $dropAcks,
            'search' => 'vouchers_reset_ack($id, $rights);',
            'replace' => 'true;',
            'rationale' => 'The approval reset of a bgf user runs the reset query (which still fails: '
                . 'known failure KF-DROP-ACKS-BGF).',
        ),
        array(
            'id' => 'drop-acks-bgf-check-removed',
            'region' => $dropAcks,
            'search' => "if (!has_right(\$rights, 'bgf'))",
            'replace' => 'if (false)',
            'rationale' => 'Fail-open: users without bgf reset approvals (with empty rights of any '
                . 'organization).',
        ),
        array(
            'id' => 'drop-acks-bgf-check-fail-closed-for-bgf',
            'region' => $dropAcks,
            'search' => "if (!has_right(\$rights, 'bgf'))",
            'replace' => 'if (true)',
            'rationale' => 'bgf users still reach the reset (characterized by KF-DROP-ACKS-BGF).',
        ),
    ),
);
