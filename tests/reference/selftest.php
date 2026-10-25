<?php

/*
 * Tests of the reference tooling itself: allowlist validation and
 * classification, normalization, statement-to-table detection, dump deltas,
 * value export and the line diff. Runs before every recording and
 * comparison (tests/reference/in-container.sh); also runnable on its own:
 *   php tests/reference/selftest.php
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

$entries = [
    ['id' => 'A', 'php' => ['7.4'], 'files' => 'http/import-*.txt', 'lines' => '/^[-+]status: (200|500)$/', 'reason' => 'r', 'category' => 'accepted'],
    ['id' => 'B', 'php' => ['7.4', '8.0'], 'files' => 'helpers/*.txt', 'lines' => '/^\+.* THROWS TypeError$/', 'reason' => 'r', 'category' => 'accepted'],
    ['id' => 'C', 'php' => ['8.5'], 'files' => '*', 'lines' => '/./', 'reason' => 'r', 'category' => 'todo'],
];
check('valid allowlist', ref_allowlist_problems($entries), []);
check('committed allowlist is valid', ref_allowlist_problems(require __DIR__ . '/allowlist.php'), []);
check('allowlist problems', ref_allowlist_problems([
    ['id' => 'X', 'php' => ['7.4'], 'files' => '*', 'lines' => '/a/'],
    ['id' => 'X', 'php' => '7.4', 'files' => '*', 'lines' => '/a/', 'reason' => 'r', 'category' => 'accepted'],
    ['id' => 'Y', 'php' => ['7.4'], 'files' => '*', 'lines' => '/(/', 'reason' => 'r', 'category' => 'accepted'],
    ['id' => 'Z', 'php' => [], 'files' => '', 'lines' => '/a/', 'reason' => 'r', 'category' => 'accepted'],
    ['id' => 'W', 'php' => ['7.4'], 'files' => '*', 'lines' => '/a/', 'reason' => 'r', 'category' => 'fixme'],
    ['id' => 'V', 'php' => ['7.4'], 'pg' => ['18'], 'files' => '*', 'lines' => '/a/', 'reason' => 'r', 'category' => 'todo'],
    ['id' => 'U', 'php' => ['8.5'], 'files' => '*', 'transform' => 'crlf-to-lf', 'reason' => 'r', 'category' => 'todo'],
]), [
    'entry 0: missing reason',
    'entry 0: missing category',
    'entry 1: duplicate id X',
    'entry 1: php must be a list of versions',
    'entry 2: invalid lines pattern',
    'entry 3: missing php',
    'entry 3: missing files',
    "entry 4: category must be 'accepted' or 'todo'",
    'entry 5: unknown field pg',
    'entry 6: unknown field transform',
    'entry 6: missing lines',
]);
check('a list of globs matches any of them', [ref_files_match(['a/*', 'b'], 'b'), ref_files_match(['a/*'], 'b')], [true, false]);
check(
    'category summary',
    ref_category_summary($entries, ['A' => 3, 'C' => 2]),
    ['accepted' => ['A' => 3], 'todo' => ['C' => 2]],
);

check(
    'all lines accepted',
    ref_classify_changes('7.4', 'http/import-bawag.txt', ['-status: 200', '+status: 500'], $entries),
    [['A' => 2], []],
);
check(
    'unmatched line remains',
    ref_classify_changes('7.4', 'http/import-bawag.txt', ['-status: 200', '+body changed'], $entries),
    [['A' => 1], ['+body changed']],
);
check(
    'other PHP version is not accepted',
    ref_classify_changes('8.0', 'http/import-bawag.txt', ['-status: 200'], $entries),
    [[], ['-status: 200']],
);
check(
    'glob must match the file',
    ref_classify_changes('7.4', 'http/report.txt', ['-status: 200'], $entries),
    [[], ['-status: 200']],
);
check(
    'sign is part of the line',
    ref_classify_changes('7.4', 'helpers/hex.txt', ['-x THROWS TypeError', '+x THROWS TypeError'], $entries),
    [['B' => 1], ['-x THROWS TypeError']],
);
check(
    'first matching entry wins',
    ref_classify_changes('8.5', 'helpers/hex.txt', ['+y'], $entries),
    [['C' => 1], []],
);
check('stale entries', ref_stale_entries('7.4', $entries, ['A' => 3]), ['B']);
check('entries of other versions are not stale', ref_stale_entries('5.3', $entries, []), []);

// ---------------------------------------------------------------- normalization

check(
    'login cookie',
    ref_normalize_set_cookie('pp_pgacc_login=1804289383; expires=Tue, 16-Jun-2026 10:00:07 GMT; Max-Age=86400'),
    'pp_pgacc_login={{cookie}}; expires=Tue, 16-Jun-2026 {{time}} GMT; Max-Age=86400',
);
check(
    'session token cookie',
    ref_normalize_set_cookie('pp_pgacc_login=' . str_repeat('0a', 32) . '; expires=Tue, 16 Jun 2026 10:00:07 GMT'),
    'pp_pgacc_login={{cookie}}; expires=Tue, 16 Jun 2026 {{time}} GMT',
);
check(
    'other cookie values are kept',
    ref_normalize_set_cookie('pp_pgacc_login=' . str_repeat('0a', 31) . '; secure'),
    'pp_pgacc_login=' . str_repeat('0a', 31) . '; secure',
);
check(
    'id cookie keeps its value',
    ref_normalize_set_cookie('pp_pgacc_id=3; expires=Tue, 16 Jun 2026 10:00:07 GMT'),
    'pp_pgacc_id=3; expires=Tue, 16 Jun 2026 {{time}} GMT',
);
check(
    'deleted cookie',
    ref_normalize_set_cookie('pp_pgacc_login=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0'),
    'pp_pgacc_login=deleted; expires=Thu, 01-Jan-1970 {{time}} GMT; Max-Age=0',
);
check('relevant headers', ref_relevant_headers(['Host: x', 'Date: now', 'Connection: close', 'X-Powered-By: PHP/5.6.40',
    'Content-type: text/html; charset=UTF-8', 'Location: index.php', 'odd']), [
        'Content-type: text/html; charset=UTF-8', 'Location: index.php', 'unparsed: odd']);
check(
    'consistent captcha',
    ref_normalize_body('Was ergibt die Addition 12 + 40?</label> <input value="15" name="captcha" type="hidden">'),
    'Was ergibt die Addition {{captcha-a}} + {{captcha-b}}?</label> <input value="{{captcha-c=a+b-37}}" name="captcha" type="hidden">',
);
check(
    'inconsistent captcha',
    ref_normalize_body('Was ergibt die Addition 12 + 40? <input value="16" name="captcha" type="hidden">'),
    'Was ergibt die Addition {{captcha-a}} + {{captcha-b}}? <input value="{{captcha-c=INCONSISTENT}}" name="captcha" type="hidden">',
);
check(
    'captcha out of range',
    ref_normalize_body('Was ergibt die Addition 0 + 40? value="3" name="captcha"'),
    'Was ergibt die Addition {{captcha-a}} + {{captcha-b}}? value="{{captcha-c=INCONSISTENT}}" name="captcha"',
);
check('transport headers', ref_relevant_headers(['Server: lighttpd/1.4.28', 'Content-Length: 2',
    'Content-type: text/html', 'Content-Length: 672', 'Content-Length:'], 2), [
        'Content-type: text/html', 'Content-Length: 672', 'Content-Length: ']);
$captchaBody = 'Was ergibt die Addition 12 + 40? value="15" name="captcha"';
$step = ['id' => 's', 'method' => 'GET', 'path' => '/', 'user' => 'anon', 'form' => [], 'files' => []];
check(
    'web server Content-Length of a normalized body',
    ref_http_snapshot($step, ['status' => 200,
        'headers' => ['Server: lighttpd/1.4.28', 'Content-Length: ' . strlen($captchaBody), 'Content-Length: 3'],
        'body' => $captchaBody]),
    "# step: s\n# request: GET / as anon\nstatus: 200\nheader: Content-Length: 3\n--- body ("
    . strlen(ref_normalize_body($captchaBody)) . " bytes) ---\n" . ref_normalize_body($captchaBody),
);
check(
    'impressum versions',
    ref_normalize_body('<a href="http://www.php.net">PHP</a></td><td class=""><div>5.6.40</div></td>'
    . '<a href="http://www.postgresql.org/">PostgreSQL</a></td><td class=""><div>17.11 (Debian)</div>'),
    '<a href="http://www.php.net">PHP</a></td><td class=""><div>{{php-version}}</div></td>'
    . '<a href="http://www.postgresql.org/">PostgreSQL</a></td><td class=""><div>{{pg-version}}</div>',
);
check('body without captcha', ref_normalize_body('value="5" name="other"'), 'value="5" name="other"');
check(
    'form tokens',
    ref_normalize_body('<input type="hidden" name="csrf_token" value="' . str_repeat('a1', 32)
    . '" /><input type="hidden" name="csrf_token" value="' . str_repeat('0', 64) . '" /><input name="csrf_token" value="" />'),
    '<input type="hidden" name="csrf_token" value="{{csrf-token}}" /><input type="hidden" name="csrf_token" value="{{csrf-token}}" />'
    . '<input name="csrf_token" value="" />',
);
check('text body', ref_body_block("a\nb"), "--- body (3 bytes) ---\na\nb");
check('binary body', ref_body_block("%PDF\0"), '--- body (binary, 5 bytes, sha256 ' . hash('sha256', "%PDF\0") . ") ---\nJVBERgA=\n");
check('latin1 body is binary', strpos(ref_body_block("\xFC"), 'binary') !== false, true);
check(
    'sql cookie',
    ref_normalize_sql('UPDATE users SET cookie = 1804289383, login = now() WHERE id = 1; SELECT 1 WHERE cookie = -5'),
    'UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 1; SELECT 1 WHERE cookie = {{cookie}}',
);
check('written tables', ref_written_tables([
    "SELECT * FROM vouchers WHERE comment = 'UPDATE x'",
    'INSERT INTO vouchers (id) VALUES (1)',
    "UPDATE users SET hide = '' WHERE id = 1",
    'DROP TABLE IF EXISTS ppmembers;',
    'CREATE TABLE ppmembers (id integer)',
    "SELECT nextval('voucher_number') AS num;",
    'delete from import',
]), ['(sequences)', 'import', 'ppmembers', 'users', 'vouchers', 'x']);
check('cell normalization', [
    ref_normalize_cell('users', 'cookie', '123'),
    ref_normalize_cell('users', 'login', REF_FIXTURE_LOGIN),
    ref_normalize_cell('users', 'login', '2026-10-23 17:00:00.123'),
    ref_normalize_cell('users', 'hash', '$1$abcdefgh$0123456789abcdefghijkl'),
    ref_normalize_cell('users', 'hash', 'plain'),
    ref_normalize_cell('vouchers', 'save_date', '2026-10-23 17:00:00'),
    ref_normalize_cell('vouchers', 'date', '2024-01-01 00:00:00'),
    ref_normalize_cell('users', 'login', null),
], ['{{cookie}}', REF_FIXTURE_LOGIN, '{{now}}', '{{md5-crypt}}', 'plain', '{{save_date}}', '2024-01-01 00:00:00', null]);
check('cell escaping', ref_escape_cell("a\tb\nc\\"), 'a\\tb\\nc\\\\');
check('null cell', ref_escape_cell(null), '\\N');
check('dump delta', ref_dump_delta(['h', 'a', 'b', 'b'], ['h', 'b', 'c']), ['- a', '- b', '+ c']);
check('dump delta unchanged', ref_dump_delta(['h', 'a'], ['h', 'a']), ['(unchanged)']);
check('dump delta created', ref_dump_delta(null, ['h', 'a']), ['(created) h', '+ a']);
check('dump delta dropped', ref_dump_delta(['h', 'a'], null), ['(dropped) h', '- a']);
check('dump delta columns', ref_dump_delta(['h', 'a'], ['i', 'a']), ['(columns changed) h -> i']);

check(
    'export scalars',
    [ref_export(null), ref_export(true), ref_export(-3), ref_export('a"b' . "\n\xFC")],
    ['null', 'true', 'int(-3)', 'string(5) "a\\"b\\n\\374"'],
);
check(
    'export floats',
    [ref_export(0.1), ref_export(0.1 + 0.2), ref_export(floatval('-0')), ref_export(0.0), ref_export(1e100), ref_export(-INF), ref_export(NAN)],
    ['float(0.10000000000000001)', 'float(0.30000000000000004)', 'float(-0)', 'float(0)', 'float(1.0E+100)', 'float(-INF)', 'float(NAN)'],
);
check('export array', ref_export(['a' => 1, 2 => []]), 'array(string(1) "a" => int(1), int(2) => array())');

$dir = sys_get_temp_dir() . '/pgacc-reference-selftest-' . getmypid();
ref_write("$dir/a.txt", "one\ntwo\nthree\n");
ref_write("$dir/b.txt", "one\n2\nthree\nfour\n");
check('changed lines', ref_changed_lines("$dir/a.txt", "$dir/b.txt"), ['-two', '+2', '+four']);
check('missing file is empty', ref_changed_lines('/dev/null', "$dir/a.txt"), ['+one', '+two', '+three']);
check('identical files', ref_changed_lines("$dir/a.txt", "$dir/a.txt"), []);
check('file list', ref_list_files($dir), ['a.txt', 'b.txt']);
unlink("$dir/a.txt");
unlink("$dir/b.txt");
rmdir($dir);

if ($GLOBALS['selftest_failures'] > 0) {
    fwrite(STDERR, "Reference tooling self-test: {$GLOBALS['selftest_failures']} of {$GLOBALS['selftest_count']} checks failed\n");
    exit(1);
}
echo "Reference tooling self-test: {$GLOBALS['selftest_count']} checks passed\n";
