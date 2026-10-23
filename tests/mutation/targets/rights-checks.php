<?php
/*
 * Organization scope of voucher writes: the rights that voucher creation and
 * revision pass to get_voucher() (default organization), and the rights SQL
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
            'id' => 'ack-finalized-check-removed',
            'region' => $ack,
            'search' => 'WHERE NOT deleted AND receipt_received AND voucher_id = $id',
            'replace' => 'WHERE NOT deleted AND false AND voucher_id = $id',
            'rationale' => 'Fail-open: a finalized voucher can be approved.',
        ),
        array(
            'id' => 'ack-receipt-check-unscoped',
            'region' => $ack,
            'search' => 'WHERE (ack1 IS NULL OR file != 0) AND voucher_id = $id $rightssql;',
            'replace' => 'WHERE (ack1 IS NULL OR file != 0) AND voucher_id = $id;',
            'rationale' => 'A user of another organization is refused before any update ("Hier fehlt '
                . 'noch der Beleg"), instead of getting the approval redirect.',
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
            'id' => 'finalize-unscoped',
            'region' => $finalize,
            'search' => 'UPDATE vouchers SET receipt_received = true WHERE voucher_id = $id $rightssql;',
            'replace' => 'UPDATE vouchers SET receipt_received = true WHERE voucher_id = $id;',
            'rationale' => 'Wrong organization: finalization marks rows of other organizations.',
        ),
        array(
            'id' => 'drop-acks-reset-removed',
            'region' => $dropAcks,
            'search' => 'vouchers_reset_ack($id, $rights);',
            'replace' => 'true;',
            'rationale' => 'The approval reset clears ack1 and ack2.',
        ),
    ),
);
