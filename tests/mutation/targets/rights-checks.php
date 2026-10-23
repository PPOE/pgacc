<?php
/*
 * Organization scope of voucher writes: the rights that voucher creation and
 * revision pass to get_voucher() (default organization), the check of posted
 * organizations against them, and the rights SQL
 * of approval and finalization in edit.php. Users of one organization must
 * not change rows of another one. Tested with tests/write-workflows.php
 * (users of organizations 1 and 2, bgf, admin, empty rights). See
 * tests/mutation/README.md for the format.
 */
$ack = array('start' => 'function page_edit_ack($rights)', 'end' => 'function page_edit_save($rights)');
$finalize = array('start' => 'function page_edit_finalize($rights)', 'end' => 'function page_edit_merge($rights)');
$dropAcks = array('start' => 'function page_edit_drop_acks($rights)', 'end' => 'function page_edit_ack($rights)');

return array(
    'description' => 'Organization scope of voucher creation, revision, approval and finalization '
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
            'id' => 'posted-organization-bgf-exemption-removed',
            'file' => 'new.php',
            'search' => "if (has_right(\$rights, 'bgf'))\n  return true;",
            'replace' => "if (false)\n  return true;",
            'rationale' => 'bgf users may post any organization.',
        ),
        array(
            'id' => 'posted-organization-first-part-only',
            'file' => 'new.php',
            'search' => "for (\$part = 0; \$part < \$parts; \$part++)\n{\n  \$lo = get_param(",
            'replace' => "for (\$part = 0; \$part < 1; \$part++)\n{\n  \$lo = get_param(",
            'rationale' => 'Every part of a voucher is checked, not only the first.',
        ),
        array(
            'id' => 'posted-organization-loose-comparison',
            'file' => 'new.php',
            'search' => 'if ($lo !== null && !in_array($lo, $rights2, true))',
            'replace' => 'if ($lo !== null && !in_array($lo, $rights2))',
            'rationale' => 'Organizations are compared as strings: a padded ID is not one of the user\'s.',
        ),
        array(
            'id' => 'posted-organization-default-checked',
            'file' => 'new.php',
            'search' => 'if ($lo !== null && !in_array($lo, $rights2, true))',
            'replace' => 'if (!in_array($lo, $rights2, true))',
            'rationale' => 'Without a posted organization the default applies and is not refused.',
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
            'search' => 'SELECT 1 FROM vouchers WHERE voucher_id = $id $rightssql;',
            'replace' => 'SELECT 1 FROM vouchers WHERE voucher_id = $id;',
            'rationale' => 'The visibility check uses the organization scope of the user.',
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
            'id' => 'finalize-bgf-check-removed',
            'region' => $finalize,
            'search' => "if (!has_right(\$rights, 'bgf'))",
            'replace' => 'if (false)',
            'rationale' => 'Fail-open: users without bgf finalize vouchers.',
        ),
        array(
            'id' => 'finalize-bgf-check-closed',
            'region' => $finalize,
            'search' => "if (!has_right(\$rights, 'bgf'))",
            'replace' => 'if (true)',
            'rationale' => 'bgf users finalize vouchers.',
        ),
        array(
            'id' => 'finalize-unscoped',
            'region' => $finalize,
            'search' => 'UPDATE vouchers SET receipt_received = true WHERE voucher_id = $id $rightssql;',
            'replace' => 'UPDATE vouchers SET receipt_received = true WHERE voucher_id = $id;',
            'rationale' => 'Wrong organization: finalization marks rows of other organizations.',
            'equivalent' => array('reason' => 'Only users with the bgf right finalize since the bgf '
                . 'check, and their rights SQL is empty.'),
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
