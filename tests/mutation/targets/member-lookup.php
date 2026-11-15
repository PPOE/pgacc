<?php

/*
 * Member lookup of the edit page: the exact bgf right and the finalized
 * check (edit.php page_edit()), the one member list (datalist) for the
 * number fields, the escaped member texts, the suggestions with their limit
 * and UTF-8 guard and the hints of member_lookup_html() (new.php,
 * getusers.php), the note on a number that the member list does not have
 * (page_edit_save()), and the link to the next open booking (edit.php
 * edit_next_open_link()). Tested with tests/http-member-lookup.php against a
 * synthetic member list of the membership stub, and MemberLookupTest.
 * See tests/mutation/README.md for the format.
 */
$lookup = '$lookup = has_right($rights, \'bgf\') && !$received;';
$suggestion = "'m.id = ANY(\$1::integer[]) OR (length(trim(m.name)) >= 5'";
$unknown = "if (\$members_error === null && has_right(\$rights, 'bgf') && posted_unknown_member(\$parts, \$rights)) {";
$typeHint = "if (\$voucher['member'] == 'true' && \$voucher['dir'] == 'in' && \$voucher['type'] != 1) {";
return [
    'description' => 'Member lookup of the edit page (bgf only): member list, suggestions, notes, next open booking',
    'file' => 'new.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'MemberLookupTest', 'file' => 'tests/unit/MemberLookupTest.php'],
        ['type' => 'http', 'script' => 'tests/http-member-lookup.php'],
    ],
    'timeout' => 120,
    'mutations' => [
        [
            'id' => 'lookup-on-non-member-rows',
            'search' => "(\$lookup && \$voucher['member'] == 'true' && member_datalist_needed(true) ? ' list=\"'",
            'replace' => "(\$lookup && member_datalist_needed(true) ? ' list=\"'",
            'rationale' => 'A page without members\' rows fetches the member list.',
        ],
        [
            'id' => 'list-attribute-without-member-list',
            'search' => "member_datalist_needed(true) ? ' list=\"'",
            'replace' => "(member_datalist_needed(true) || true) ? ' list=\"'",
            'rationale' => 'Without the member list the number field points to a list that is not there.',
        ],
        [
            'id' => 'suggestion-limit-removed',
            'search' => "array_map('member_option_text', array_slice(\$rows, 0, MEMBER_LOOKUP_LIMIT))",
            'replace' => "array_map('member_option_text', \$rows)",
            'rationale' => 'More than 20 suggestions are listed.',
        ],
        [
            'id' => 'type-hint-for-fee',
            'search' => $typeHint,
            'replace' => str_replace(" && \$voucher['type'] != 1", '', $typeHint),
            'rationale' => 'A membership fee row gets the hint to choose the membership fee.',
        ],
        [
            'id' => 'type-hint-for-outgoing',
            'search' => $typeHint,
            'replace' => str_replace(" && \$voucher['dir'] == 'in'", '', $typeHint),
            'rationale' => 'An outgoing row gets the income type hint.',
        ],
        [
            'id' => 'unknown-number-not-noted',
            'search' => "if (\$voucher['member'] == 'true' && intval(\$voucher['mitgliedsnummer']) > 0 && !isset(member_list()[intval(\$voucher['mitgliedsnummer'])])) {",
            'replace' => 'if (false) {',
            'rationale' => 'A number that the member list does not have is saved without a note.',
        ],
        [
            'id' => 'unknown-number-note-without-bgf',
            'file' => 'edit.php',
            'search' => $unknown,
            'replace' => str_replace(" && has_right(\$rights, 'bgf')", '', $unknown),
            'rationale' => 'Organization users get the member lookup note.',
        ],
        [
            'id' => 'lookup-for-every-writer',
            'file' => 'edit.php',
            'search' => $lookup,
            'replace' => '$lookup = write_rights($rights) !== false && !$received;',
            'rationale' => 'Organization users get the list of all members.',
        ],
        [
            'id' => 'lookup-on-finalized-voucher',
            'file' => 'edit.php',
            'search' => $lookup,
            'replace' => '$lookup = has_right($rights, \'bgf\');',
            'rationale' => 'A finalized voucher offers the lookup.',
        ],
        [
            'id' => 'datalist-without-lookup',
            'file' => 'edit.php',
            'search' => '    if (member_datalist_needed()) {',
            'replace' => '    if (true) {',
            'rationale' => 'Users without the exact bgf right get the list of all members.',
        ],
        [
            'id' => 'next-open-without-bgf',
            'file' => 'edit.php',
            'search' => 'if ($hint == 5 && has_right($rights, \'bgf\')) {',
            'replace' => 'if ($hint == 5) {',
            'rationale' => 'Users without the exact bgf right get the link.',
        ],
        [
            'id' => 'next-open-finalized',
            'file' => 'edit.php',
            'search' => '" AND NOT EXISTS (SELECT 1 FROM vouchers f WHERE NOT f.deleted AND f.receipt_received AND f.voucher_id = v.voucher_id)"',
            'replace' => '" AND true"',
            'rationale' => 'A finalized voucher, which cannot be changed, is the next open booking.',
        ],
        [
            'id' => 'member-name-unescaped',
            'file' => 'getusers.php',
            'search' => "' – ' . html_escape(\$row['name'])",
            'replace' => "' – ' . \$row['name']",
            'rationale' => 'A member name from the membership system reaches the page as markup.',
        ],
        [
            'id' => 'organization-name-unescaped',
            'file' => 'getusers.php',
            'search' => "' (' . html_escape(\$row['lo']) . ')'",
            'replace' => "' (' . \$row['lo'] . ')'",
            'rationale' => 'An organization name reaches the page as markup.',
        ],
        [
            'id' => 'unpaid-marker-inverted',
            'file' => 'getusers.php',
            'search' => "(\$row['paid'] == 0 ? ' (nicht bezahlt)' : '')",
            'replace' => "(\$row['paid'] == 1 ? ' (nicht bezahlt)' : '')",
            'rationale' => 'Paying members are marked as not paid.',
        ],
        [
            'id' => 'member-list-limited',
            'file' => 'getusers.php',
            'search' => "member_lookup_rows('true', [], null)",
            'replace' => "member_lookup_rows('true', [], MEMBER_LOOKUP_LIMIT)",
            'rationale' => 'The member list of the number fields misses members.',
        ],
        [
            'id' => 'suggestion-invalid-utf8',
            'file' => 'getusers.php',
            'search' => "if (!mb_check_encoding(\$name . \$comment, 'UTF-8')) {",
            'replace' => 'if (false) {',
            'rationale' => 'Invalid UTF-8 in a posted name or text reaches the database, which refuses it.',
        ],
        [
            'id' => 'suggestion-short-names',
            'file' => 'getusers.php',
            'search' => $suggestion,
            'replace' => str_replace('>= 5', '>= 4', $suggestion),
            'rationale' => 'Names shorter than 5 characters are suggested whenever they are part of a word.',
        ],
        [
            'id' => 'suggestion-numbers-dropped',
            'file' => 'getusers.php',
            'search' => $suggestion,
            'replace' => str_replace('m.id = ANY($1::integer[]) OR', 'cardinality($1::integer[]) < 0 OR', $suggestion),
            'rationale' => 'Member numbers in the booking text are not suggested.',
        ],
    ],
];
