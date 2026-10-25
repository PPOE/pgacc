<?php

/*
 * Tests of the reference tooling itself: allowlist validation and
 * classification, normalization, statement-to-table detection, dump deltas,
 * value export and the line diff. Runs before every recording and
 * comparison (tests/reference/in-container.sh); also runnable on its own:
 *   php tests/reference/selftest.php
 * Must stay compatible with PHP 5.6.
 */
require __DIR__ . '/lib.php';

$GLOBALS['selftest_failures'] = 0;
$GLOBALS['selftest_count'] = 0;

function check($label, $actual, $expected)
{
    $GLOBALS['selftest_count']++;
    if ($actual !== $expected) {
        $GLOBALS['selftest_failures']++;
        fwrite(STDERR, "FAIL $label\n  expected " . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
    }
}

// ---------------------------------------------------------------- allowlist

$entries = array(
    array('id' => 'A', 'php' => array('7.4'), 'files' => 'http/import-*.txt', 'lines' => '/^[-+]status: (200|500)$/', 'reason' => 'r', 'category' => 'accepted'),
    array('id' => 'B', 'php' => array('7.4', '8.0'), 'files' => 'helpers/*.txt', 'lines' => '/^\+.* THROWS TypeError$/', 'reason' => 'r', 'category' => 'accepted'),
    array('id' => 'C', 'php' => array('8.5'), 'files' => '*', 'lines' => '/./', 'reason' => 'r', 'category' => 'todo'),
);
check('valid allowlist', ref_allowlist_problems($entries), array());
check('committed allowlist is valid', ref_allowlist_problems(require __DIR__ . '/allowlist.php'), array());
check('allowlist problems', ref_allowlist_problems(array(
    array('id' => 'X', 'php' => array('7.4'), 'files' => '*', 'lines' => '/a/'),
    array('id' => 'X', 'php' => '7.4', 'files' => '*', 'lines' => '/a/', 'reason' => 'r', 'category' => 'accepted'),
    array('id' => 'Y', 'php' => array('7.4'), 'files' => '*', 'lines' => '/(/', 'reason' => 'r', 'category' => 'accepted'),
    array('id' => 'Z', 'php' => array(), 'files' => '', 'lines' => '/a/', 'reason' => 'r', 'category' => 'accepted'),
    array('id' => 'W', 'php' => array('7.4'), 'files' => '*', 'lines' => '/a/', 'reason' => 'r', 'category' => 'fixme'),
    array('id' => 'V', 'php' => array('7.4'), 'pg' => '18', 'files' => '*', 'lines' => '/a/', 'reason' => 'r', 'category' => 'todo'),
    array('id' => 'U', 'php' => array('7.4'), 'pg' => array(), 'files' => '*', 'lines' => '/a/', 'reason' => 'r', 'category' => 'todo'),
)), array(
    'entry 0: missing reason',
    'entry 0: missing category',
    'entry 1: duplicate id X',
    'entry 1: php must be a list of versions',
    'entry 2: invalid lines pattern',
    'entry 3: missing php',
    'entry 3: missing files',
    "entry 4: category must be 'accepted' or 'todo'",
    'entry 5: pg must be a non-empty list of PostgreSQL versions',
    'entry 6: pg must be a non-empty list of PostgreSQL versions',
));
check('transform entry problems', ref_allowlist_problems(array(
    array('id' => 'T1', 'php' => array('8.5'), 'files' => '*', 'transform' => 'crlf-to-lf', 'reason' => 'r', 'category' => 'accepted'),
    array('id' => 'T2', 'php' => array('8.5'), 'files' => '*', 'transform' => 'trim', 'reason' => 'r', 'category' => 'accepted'),
    array('id' => 'T3', 'php' => array('8.5'), 'files' => '*', 'transform' => 'crlf-to-lf', 'lines' => '/a/', 'reason' => 'r', 'category' => 'accepted'),
)), array(
    "entry 1: transform must be 'crlf-to-lf'",
    'entry 2: transform and lines exclude each other',
));
$transformEntries = array(
    array('id' => 'T', 'php' => array('8.5'), 'files' => array('http/*', 'helpers/misc.txt'), 'transform' => 'crlf-to-lf', 'reason' => 'r', 'category' => 'accepted'),
    array('id' => 'L', 'php' => array('8.5'), 'files' => '*', 'lines' => '/^[-+]x$/', 'reason' => 'r', 'category' => 'accepted'),
);
$html = "# step: s\r\nstatus: 200\nheader: Content-type: text/html\n--- body (11 bytes) ---\n<p>\r\n</p>\r\n";
$used = array();
check(
    'crlf-to-lf converts a text/html body and its length',
    ref_transform_reference('8.5', 'http/a.txt', $html, $transformEntries, $used),
    "# step: s\r\nstatus: 200\nheader: Content-type: text/html\n--- body (9 bytes) ---\n<p>\n</p>\n"
);
check('crlf-to-lf counts the converted line endings', $used, array('T' => 2));
$csv = "status: 200\nheader: Content-type: text/csv; charset=UTF-8\n--- body (6 bytes) ---\na\r\nb\r\n";
check(
    'crlf-to-lf converts only the final CRLF of another text body',
    ref_crlf_to_lf($csv, 'http/a.txt'),
    "status: 200\nheader: Content-type: text/csv; charset=UTF-8\n--- body (5 bytes) ---\na\r\nb\n"
);
$binary = "status: 200\nheader: Content-type: application/pdf\n--- body (binary, 4 bytes, sha256 x) ---\nYQ0K\r\n";
check('crlf-to-lf never converts a binary body', ref_crlf_to_lf($binary, 'http/a.txt'), $binary);
check('crlf-to-lf converts other recordings throughout', ref_crlf_to_lf("a\r\nb\r\n", 'sql/a.sql'), "a\nb\n");
$used = array();
check('crlf-to-lf only on its files', ref_transform_reference('8.5', 'sql/a.sql', "a\r\n", $transformEntries, $used), "a\r\n");
check('crlf-to-lf only on its PHP versions', ref_transform_reference('7.4', 'http/a.txt', $html, $transformEntries, $used), $html);
check('crlf-to-lf without CRLF is not used', array(ref_transform_reference('8.5', 'helpers/misc.txt', "a\n", $transformEntries, $used), $used), array("a\n", array()));
check(
    'transform entries accept no lines',
    ref_classify_changes('8.5', 'http/a.txt', array('-y', '+x'), $transformEntries),
    array(array('L' => 1), array('-y'))
);
check(
    'crlf-to-lf in exported strings',
    ref_crlf_to_lf('a string(7) "\r\n<p>\r\n" b string(3) "\\\\r\n"', 'helpers/misc.txt'),
    'a string(5) "\n<p>\n" b string(3) "\\\\r\n"'
);
check('a list of globs matches any of them', array(ref_files_match(array('a/*', 'b'), 'b'), ref_files_match(array('a/*'), 'b')), array(true, false));
$pgEntries = array(
    array('id' => 'P', 'php' => array('7.4'), 'pg' => array('18'), 'files' => '*', 'lines' => '/a/', 'reason' => 'r', 'category' => 'todo'),
    array('id' => 'Q', 'php' => array('7.4'), 'files' => '*', 'lines' => '/b/', 'reason' => 'r', 'category' => 'todo'),
);
check('pg entries on their server', ref_entries_for_pg($pgEntries, '18'), $pgEntries);
check('pg entries on another server', ref_entries_for_pg($pgEntries, '8.4'), array($pgEntries[1]));
check(
    'category summary',
    ref_category_summary($entries, array('A' => 3, 'C' => 2)),
    array('accepted' => array('A' => 3), 'todo' => array('C' => 2))
);

check(
    'all lines accepted',
    ref_classify_changes('7.4', 'http/import-bawag.txt', array('-status: 200', '+status: 500'), $entries),
    array(array('A' => 2), array())
);
check(
    'unmatched line remains',
    ref_classify_changes('7.4', 'http/import-bawag.txt', array('-status: 200', '+body changed'), $entries),
    array(array('A' => 1), array('+body changed'))
);
check(
    'other PHP version is not accepted',
    ref_classify_changes('8.0', 'http/import-bawag.txt', array('-status: 200'), $entries),
    array(array(), array('-status: 200'))
);
check(
    'glob must match the file',
    ref_classify_changes('7.4', 'http/report.txt', array('-status: 200'), $entries),
    array(array(), array('-status: 200'))
);
check(
    'sign is part of the line',
    ref_classify_changes('7.4', 'helpers/hex.txt', array('-x THROWS TypeError', '+x THROWS TypeError'), $entries),
    array(array('B' => 1), array('-x THROWS TypeError'))
);
check(
    'first matching entry wins',
    ref_classify_changes('8.5', 'helpers/hex.txt', array('+y'), $entries),
    array(array('C' => 1), array())
);
check('stale entries', ref_stale_entries('7.4', $entries, array('A' => 3)), array('B'));
check('entries of other versions are not stale', ref_stale_entries('5.3', $entries, array()), array());

// ---------------------------------------------------------------- normalization

check(
    'login cookie',
    ref_normalize_set_cookie('pp_pgacc_login=1804289383; expires=Tue, 16-Jun-2026 10:00:07 GMT; Max-Age=86400'),
    'pp_pgacc_login={{cookie}}; expires=Tue, 16-Jun-2026 {{time}} GMT; Max-Age=86400'
);
check(
    'session token cookie',
    ref_normalize_set_cookie('pp_pgacc_login=' . str_repeat('0a', 32) . '; expires=Tue, 16 Jun 2026 10:00:07 GMT'),
    'pp_pgacc_login={{cookie}}; expires=Tue, 16 Jun 2026 {{time}} GMT'
);
check(
    'other cookie values are kept',
    ref_normalize_set_cookie('pp_pgacc_login=' . str_repeat('0a', 31) . '; secure'),
    'pp_pgacc_login=' . str_repeat('0a', 31) . '; secure'
);
check(
    'id cookie keeps its value',
    ref_normalize_set_cookie('pp_pgacc_id=3; expires=Tue, 16 Jun 2026 10:00:07 GMT'),
    'pp_pgacc_id=3; expires=Tue, 16 Jun 2026 {{time}} GMT'
);
check(
    'deleted cookie',
    ref_normalize_set_cookie('pp_pgacc_login=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0'),
    'pp_pgacc_login=deleted; expires=Thu, 01-Jan-1970 {{time}} GMT; Max-Age=0'
);
check('relevant headers', ref_relevant_headers(array('Host: x', 'Date: now', 'Connection: close', 'X-Powered-By: PHP/5.6.40',
    'Content-type: text/html; charset=UTF-8', 'Location: index.php', 'odd')), array(
        'Content-type: text/html; charset=UTF-8', 'Location: index.php', 'unparsed: odd'));
check(
    'consistent captcha',
    ref_normalize_body('Was ergibt die Addition 12 + 40?</label> <input value="15" name="captcha" type="hidden">'),
    'Was ergibt die Addition {{captcha-a}} + {{captcha-b}}?</label> <input value="{{captcha-c=a+b-37}}" name="captcha" type="hidden">'
);
check(
    'inconsistent captcha',
    ref_normalize_body('Was ergibt die Addition 12 + 40? <input value="16" name="captcha" type="hidden">'),
    'Was ergibt die Addition {{captcha-a}} + {{captcha-b}}? <input value="{{captcha-c=INCONSISTENT}}" name="captcha" type="hidden">'
);
check(
    'captcha out of range',
    ref_normalize_body('Was ergibt die Addition 0 + 40? value="3" name="captcha"'),
    'Was ergibt die Addition {{captcha-a}} + {{captcha-b}}? value="{{captcha-c=INCONSISTENT}}" name="captcha"'
);
check('transport headers', ref_relevant_headers(array('Server: lighttpd/1.4.28', 'Content-Length: 2',
    'Content-type: text/html', 'Content-Length: 672', 'Content-Length:'), 2), array(
        'Content-type: text/html', 'Content-Length: 672', 'Content-Length: '));
$captchaBody = 'Was ergibt die Addition 12 + 40? value="15" name="captcha"';
$step = array('id' => 's', 'method' => 'GET', 'path' => '/', 'user' => 'anon', 'form' => array(), 'files' => array());
check(
    'web server Content-Length of a normalized body',
    ref_http_snapshot($step, array('status' => 200,
        'headers' => array('Server: lighttpd/1.4.28', 'Content-Length: ' . strlen($captchaBody), 'Content-Length: 3'),
        'body' => $captchaBody)),
    "# step: s\n# request: GET / as anon\nstatus: 200\nheader: Content-Length: 3\n--- body ("
    . strlen(ref_normalize_body($captchaBody)) . " bytes) ---\n" . ref_normalize_body($captchaBody)
);
check(
    'impressum versions',
    ref_normalize_body('<a href="http://www.php.net">PHP</a></td><td class=""><div>5.6.40</div></td>'
    . '<a href="http://www.postgresql.org/">PostgreSQL</a></td><td class=""><div>17.11 (Debian)</div>'),
    '<a href="http://www.php.net">PHP</a></td><td class=""><div>{{php-version}}</div></td>'
    . '<a href="http://www.postgresql.org/">PostgreSQL</a></td><td class=""><div>{{pg-version}}</div>'
);
check('body without captcha', ref_normalize_body('value="5" name="other"'), 'value="5" name="other"');
check(
    'form tokens',
    ref_normalize_body('<input type="hidden" name="csrf_token" value="' . str_repeat('a1', 32)
    . '" /><input type="hidden" name="csrf_token" value="' . str_repeat('0', 64) . '" /><input name="csrf_token" value="" />'),
    '<input type="hidden" name="csrf_token" value="{{csrf-token}}" /><input type="hidden" name="csrf_token" value="{{csrf-token}}" />'
    . '<input name="csrf_token" value="" />'
);
check('text body', ref_body_block("a\nb"), "--- body (3 bytes) ---\na\nb");
check('binary body', ref_body_block("%PDF\0"), '--- body (binary, 5 bytes, sha256 ' . hash('sha256', "%PDF\0") . ") ---\nJVBERgA=\n");
check('latin1 body is binary', strpos(ref_body_block("\xFC"), 'binary') !== false, true);
check(
    'sql cookie',
    ref_normalize_sql('UPDATE users SET cookie = 1804289383, login = now() WHERE id = 1; SELECT 1 WHERE cookie = -5'),
    'UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 1; SELECT 1 WHERE cookie = {{cookie}}'
);
check('written tables', ref_written_tables(array(
    "SELECT * FROM vouchers WHERE comment = 'UPDATE x'",
    'INSERT INTO vouchers (id) VALUES (1)',
    "UPDATE users SET hide = '' WHERE id = 1",
    'DROP TABLE IF EXISTS ppmembers;',
    'CREATE TABLE ppmembers (id integer)',
    "SELECT nextval('voucher_number') AS num;",
    'delete from import',
)), array('(sequences)', 'import', 'ppmembers', 'users', 'vouchers', 'x'));
check('cell normalization', array(
    ref_normalize_cell('users', 'cookie', '123'),
    ref_normalize_cell('users', 'login', REF_FIXTURE_LOGIN),
    ref_normalize_cell('users', 'login', '2026-10-23 17:00:00.123'),
    ref_normalize_cell('users', 'hash', '$1$abcdefgh$0123456789abcdefghijkl'),
    ref_normalize_cell('users', 'hash', 'plain'),
    ref_normalize_cell('vouchers', 'save_date', '2026-10-23 17:00:00'),
    ref_normalize_cell('vouchers', 'date', '2024-01-01 00:00:00'),
    ref_normalize_cell('users', 'login', null),
), array('{{cookie}}', REF_FIXTURE_LOGIN, '{{now}}', '{{md5-crypt}}', 'plain', '{{save_date}}', '2024-01-01 00:00:00', null));
check('cell escaping', ref_escape_cell("a\tb\nc\\"), 'a\\tb\\nc\\\\');
check('null cell', ref_escape_cell(null), '\\N');
check('dump delta', ref_dump_delta(array('h', 'a', 'b', 'b'), array('h', 'b', 'c')), array('- a', '- b', '+ c'));
check('dump delta unchanged', ref_dump_delta(array('h', 'a'), array('h', 'a')), array('(unchanged)'));
check('dump delta created', ref_dump_delta(null, array('h', 'a')), array('(created) h', '+ a'));
check('dump delta dropped', ref_dump_delta(array('h', 'a'), null), array('(dropped) h', '- a'));
check('dump delta columns', ref_dump_delta(array('h', 'a'), array('i', 'a')), array('(columns changed) h -> i'));

check(
    'export scalars',
    array(ref_export(null), ref_export(true), ref_export(-3), ref_export('a"b' . "\n\xFC")),
    array('null', 'true', 'int(-3)', 'string(5) "a\\"b\\n\\374"')
);
check(
    'export floats',
    array(ref_export(0.1), ref_export(0.1 + 0.2), ref_export(floatval('-0')), ref_export(0.0), ref_export(1e100), ref_export(-INF), ref_export(NAN)),
    array('float(0.10000000000000001)', 'float(0.30000000000000004)', 'float(-0)', 'float(0)', 'float(1.0E+100)', 'float(-INF)', 'float(NAN)')
);
check('export array', ref_export(array('a' => 1, 2 => array())), 'array(string(1) "a" => int(1), int(2) => array())');

$dir = sys_get_temp_dir() . '/pgacc-reference-selftest-' . getmypid();
ref_write("$dir/a.txt", "one\ntwo\nthree\n");
ref_write("$dir/b.txt", "one\n2\nthree\nfour\n");
check('changed lines', ref_changed_lines("$dir/a.txt", "$dir/b.txt"), array('-two', '+2', '+four'));
check('missing file is empty', ref_changed_lines('/dev/null', "$dir/a.txt"), array('+one', '+two', '+three'));
check('identical files', ref_changed_lines("$dir/a.txt", "$dir/a.txt"), array());
check('file list', ref_list_files($dir), array('a.txt', 'b.txt'));
unlink("$dir/a.txt");
unlink("$dir/b.txt");
rmdir($dir);

if ($GLOBALS['selftest_failures'] > 0) {
    fwrite(STDERR, "Reference tooling self-test: {$GLOBALS['selftest_failures']} of {$GLOBALS['selftest_count']} checks failed\n");
    exit(1);
}
echo "Reference tooling self-test: {$GLOBALS['selftest_count']} checks passed\n";
