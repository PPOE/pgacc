<?php

/*
 * Member lookup of the edit page: the exact bgf right and the finalized
 * check (edit.php page_edit()), the pick check of get_voucher() (bgf, in the
 * member list), the escaped output and hit limit of member_lookup_html()
 * (new.php), the search validation, the ILIKE escaping and the suggestions
 * (getusers.php), and the link to the next open booking (edit.php
 * edit_next_open_link()). Tested with tests/http-member-lookup.php against a
 * synthetic member list of the membership stub, and MemberLookupTest.
 * See tests/mutation/README.md for the format.
 */
$pickCheck = '$pick > 0 && has_right($rights, \'bgf\') && members_loaded(20) === null && isset(member_list()[$pick])';
$lookup = '$lookup = has_right($rights, \'bgf\') && !$received;';
$likeCall = "return member_lookup_rows(implode(' AND ', \$conditions), array_map('member_like_pattern', \$value));";
$suggestion = "'m.id = ANY(\$1::integer[]) OR (length(trim(m.name)) >= 5'";
return [
    'description' => 'Member lookup of the edit page (bgf only): pick check, search, escaping, next open booking',
    'file' => 'new.php',
    'tests' => [
        ['type' => 'phpunit', 'filter' => 'MemberLookupTest', 'file' => 'tests/unit/MemberLookupTest.php'],
        ['type' => 'http', 'script' => 'tests/http-member-lookup.php'],
    ],
    'timeout' => 120,
    'mutations' => [
        [
            'id' => 'pick-without-bgf',
            'search' => $pickCheck,
            'replace' => str_replace(" && has_right(\$rights, 'bgf')", '', $pickCheck),
            'rationale' => 'Fail-open: users without the exact bgf right set a member by a posted pick.',
        ],
        [
            'id' => 'pick-not-in-list',
            'search' => $pickCheck,
            'replace' => str_replace(' && isset(member_list()[$pick])', '', $pickCheck),
            'rationale' => 'A picked number that the member list does not have is saved.',
        ],
        [
            'id' => 'member-name-unescaped',
            'search' => "' – ' . html_escape(\$row['name'])",
            'replace' => "' – ' . \$row['name']",
            'rationale' => 'A member name from the membership system reaches the page as markup.',
        ],
        [
            'id' => 'organization-name-unescaped',
            'search' => "' (' . html_escape(\$row['lo']) . ')'",
            'replace' => "' (' . \$row['lo'] . ')'",
            'rationale' => 'An organization name reaches the page as markup.',
        ],
        [
            'id' => 'search-term-unescaped',
            'search' => "'\" value=\"' . html_escape(\$query) . '\" onkeydown=",
            'replace' => "'\" value=\"' . \$query . '\" onkeydown=",
            'rationale' => 'The reflected search term reaches the page as markup.',
        ],
        [
            'id' => 'hit-limit-removed',
            'search' => 'foreach (array_slice($rows, 0, MEMBER_LOOKUP_LIMIT) as $row) {',
            'replace' => 'foreach ($rows as $row) {',
            'rationale' => 'More than 20 hits are listed.',
        ],
        [
            'id' => 'unpaid-marker-inverted',
            'search' => "(\$row['paid'] == 0 ? ' (nicht bezahlt)' : '')",
            'replace' => "(\$row['paid'] == 1 ? ' (nicht bezahlt)' : '')",
            'rationale' => 'Paying members are marked as not paid.',
        ],
        [
            'id' => 'lookup-for-every-writer',
            'file' => 'edit.php',
            'search' => $lookup,
            'replace' => '$lookup = write_rights($rights) !== false && !$received;',
            'rationale' => 'Organization users get the search over all members.',
        ],
        [
            'id' => 'lookup-on-finalized-voucher',
            'file' => 'edit.php',
            'search' => $lookup,
            'replace' => '$lookup = has_right($rights, \'bgf\');',
            'rationale' => 'A finalized voucher offers the lookup.',
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
            'id' => 'number-digits-unlimited',
            'file' => 'getusers.php',
            'search' => 'if (strlen($q) > 9) {',
            'replace' => 'if (strlen($q) > 10) {',
            'rationale' => 'A number of 10 digits (beyond integer) reaches the query.',
        ],
        [
            'id' => 'like-unescaped',
            'file' => 'getusers.php',
            'search' => $likeCall,
            'replace' => str_replace("'member_like_pattern'", "fn(\$word) => \"%\$word%\"", $likeCall),
            'rationale' => '"%" and "_" in a search act as wildcards.',
        ],
        [
            'id' => 'like-escape-clause-removed',
            'file' => 'getusers.php',
            'search' => "\$conditions[] = 'm.name ILIKE \$' . (\$i + 1) . \" ESCAPE '!'\";",
            'replace' => "\$conditions[] = 'm.name ILIKE \$' . (\$i + 1);",
            'rationale' => 'The patterns are read with the default escape character: "!" no longer escapes.',
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
