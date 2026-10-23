<?php
/*
 * Helper golden master: runs the pure and near-pure helpers of functions.php
 * (and the few helpers defined in page files) plus probes of value-handling
 * idioms copied from the application over a generated, deterministic input
 * corpus, and records every result with ref_export(). The corpus targets PHP
 * 7/8 semantic changes: string-number comparison, numeric strings with
 * whitespace, float-to-string, round(), sort order, 64-bit limits, UTF-8 and
 * ISO-8859 bytes, null and array arguments.
 *
 * Output: helpers/<group>.txt (compared) and diagnostics/helpers-<group>.txt
 * (informational). Exceptions and errors thrown by a call are recorded as
 * "THROWS <class>". No database, no network.
 *
 * Usage: php tests/reference/helpers.php <output directory>
 * Must stay compatible with PHP 5.6 (catch (Throwable) parses there and
 * simply never matches).
 */
require __DIR__ . '/lib.php';

if (!isset($argv[1])) {
    ref_fail('usage: helpers.php <output directory>');
}
$GLOBALS['helpers_out'] = rtrim($argv[1], '/');
$GLOBALS['make_csv'] = false;
$_GET = array();
$_POST = array();
$_SERVER['REQUEST_URI'] = '/index.php?action=all';

function checklogin($get = 'name', $redir = true)
{
    return '';
}

$root = dirname(dirname(__DIR__));
require $root . '/functions.php';
require $root . '/donations.php';
require $root . '/import.php';
require $root . '/new.php';

// ------------------------------------------------------------------ corpus

function corpus_scalars()
{
    return array(
        null, true, false, 0, 1, -1, 7, 42, 100, 2000, -1000, 2147483647, 2147483648, -2147483649,
        PHP_INT_MAX, -PHP_INT_MAX - 1, 0.0, floatval('-0'), 0.1, 0.1 + 0.2, 0.7, 1.005, 2.5, -2.5, 3.5, 1e3, 1e15,
        1e16, 1.5e300, 12.345, 100 / 3, -0.01,
        '', '0', '00', '0.0', '1', '-1', '+1', '1e3', '1E3', '1e1000', ' 12', '12 ', ' 12 ', "12\n", '12abc',
        'abc', '0x1A', '0b11', '012', '1.0', '1,5', '-0', '.5', '5.', 'NAN', 'INF', '1.005', '2.5',
        '9223372036854775807', '9223372036854775808', '-9223372036854775809', '2147483648', '4294967296',
        'ä', 'Österreich', 'üöÄÖÜß', "\xFC", "\xE4\xF6", '€', "\xA4", "a\nb", "tab\tx", "quote'", 'dq"', '<b>x</b>',
        '&amp;', 'BG/123456789', 'AT606000050110117300', '50110117300', '31.12.2024', '1.1.2024',
        '2024-01-01 12:00:00', 'root,bgf', '1,2', 'Rbgf', ' 1,2 ', '1e3,2', 'null', 'true',
    );
}

function corpus_strings()
{
    $strings = array();
    foreach (corpus_scalars() as $value) {
        if (is_string($value)) {
            $strings[] = $value;
        }
    }
    return $strings;
}

// A smaller set for the cross products.
function corpus_compare()
{
    return array(null, true, false, 0, 1, -1, 0.0, 1.5, 100, PHP_INT_MAX, '', '0', '1', '-1', '1.0', '1e0',
        ' 1', '1 ', 'a', 'abc', '1abc', 'null', '00', '0.0', '9223372036854775808', 'ä', array(), array(0));
}

// ------------------------------------------------------------------ recording

$GLOBALS['helpers_lines'] = array();
$GLOBALS['helpers_diagnostics'] = array();
$GLOBALS['helpers_label'] = '';

function helpers_error_handler($type, $message)
{
    $GLOBALS['helpers_diagnostics'][] = $GLOBALS['helpers_label'] . ': ' . $type . ' ' . $message;
    return true;
}

function export_args($args)
{
    $parts = array();
    foreach ($args as $arg) {
        $parts[] = ref_export($arg);
    }
    return implode(', ', $parts);
}

// Calls $callable and records its result, output and thrown errors.
function probe($label, $callable, $args = array())
{
    $GLOBALS['helpers_label'] = $label;
    set_error_handler('helpers_error_handler');
    ob_start();
    try {
        $result = 'returns ' . ref_export(call_user_func_array($callable, $args));
    } catch (Exception $exception) {
        $result = 'THROWS ' . get_class($exception);
    } catch (Throwable $throwable) {
        $result = 'THROWS ' . get_class($throwable);
    }
    $output = ob_get_clean();
    restore_error_handler();
    if ($output !== '') {
        $result .= ' output ' . ref_export($output);
    }
    $GLOBALS['helpers_lines'][] = $label . ' ' . $result;
}

function call($function, $args, $context = '')
{
    probe(($context !== '' ? "[$context] " : '') . $function . '(' . export_args($args) . ')', $function, $args);
}

function finish_group($group)
{
    ref_write($GLOBALS['helpers_out'] . "/helpers/$group.txt", implode("\n", $GLOBALS['helpers_lines']) . "\n");
    if (count($GLOBALS['helpers_diagnostics']) > 0) {
        ref_write($GLOBALS['helpers_out'] . "/diagnostics/helpers-$group.txt", implode("\n", $GLOBALS['helpers_diagnostics']) . "\n");
    }
    $GLOBALS['helpers_lines'] = array();
    $GLOBALS['helpers_diagnostics'] = array();
}

function with_get($get, $function, $args = array())
{
    $_GET = $get;
    call($function, $args, 'GET ' . ref_export($get));
    $_GET = array();
}

// ------------------------------------------------------------------ functions.php

$hex = array_merge(corpus_scalars(), array('41', '4142', '414', '41 42', '41 00 5a', 'zz', '4G', 'C3BC', 'c3bc', 'E282AC',
    '0x41', '-41', '4 1', '  ', 'FF', '00', array('41')));
foreach ($hex as $value) {
    call('hex2ascii', array($value));
}
foreach (array_merge(corpus_scalars(), array("A\0Z", array('a'))) as $value) {
    call('ascii2hex', array($value));
}
foreach (corpus_strings() as $value) {
    probe('hex2ascii(ascii2hex(' . ref_export($value) . '))', 'hex2ascii', array(ascii2hex($value)));
}
finish_group('hex');

$texts = array_merge(corpus_scalars(), array('12.50€', '-3.00€', '1.234.56€', '0€', '12.5€', 'abc€', '12€', '-0.5€',
    "line1\nline2", '1.5 €'));
foreach (array(false, true) as $csv) {
    $GLOBALS['make_csv'] = $csv;
    foreach (array('td', 'tr', 'b') as $tagName) {
        foreach ($texts as $text) {
            call('tag', array($tagName, $text), $csv ? 'csv' : 'html');
        }
    }
}
$GLOBALS['make_csv'] = false;
finish_group('tag');

$dates = corpus_scalars();
foreach (array('1', '01', '9', '10', '29', '31', '32', '0', '001') as $day) {
    foreach (array('1', '01', '10', '12', '13', '0') as $month) {
        foreach (array('2000', '2024', '2099', '1999', '24') as $year) {
            $dates[] = "$day.$month.$year";
        }
    }
}
$dates = array_merge($dates, array('2024-01-01 1', '2024-01-01 10:00:00', '2024-1-1 10:00', '2024-01-01', '2024-01-01T10:00',
    ' 1.1.2024', '1.1.2024 ', '1.1.2024.1', '01.01.20245', '31.12.2024 23:59'));
foreach ($dates as $date) {
    call('format_date', array($date));
}
finish_group('format_date');

$rightsParts = array('', '1', '2', '10', 'bgf', 'Rbgf', 'root', ' 1', '1 ', '-1', '01', '1.0', 'abc', '1e1', "1'");
$rights = corpus_scalars();
foreach ($rightsParts as $a) {
    foreach ($rightsParts as $b) {
        $rights[] = "$a,$b";
    }
}
$rights[] = array('1');
foreach ($rights as $value) {
    call('rights2orgasql', array($value));
}
finish_group('rights2orgasql');

$kontos = array_merge(corpus_scalars(), array('50110117270', '50110117300', '50110117318', '50110117326', '50110117350',
    '50110117369', '50110117393', '10110123642', '50110110437', 50110117270, 50110110437, 50110117300.0, ' 50110117300',
    '50110117300 ', '050110117300', '5.0110117300e10', '50110117300.0', 'AT606000050110117300'));
foreach ($kontos as $konto) {
    call('konto2lo', array($konto));
}
finish_group('konto2lo');

$filterNames = array('filter_id', 'filter_bid', 'filter_date', 'filter_type', 'filter_lo', 'filter_member', 'filter_member_id',
    'filter_gk', 'filter_amount', 'filter_k', 'filter_vk', 'filter_text', 'filter_comment', 'filter_comm', 'filter_ack',
    'filter_ack_old', 'filter_refund', 'filter_bel', 'filter_name');
$filterValues = array_merge(corpus_strings(), array('2024', '2024-04', '2024-04-01', '>1000', '<=-50', '=120', '>=12.5',
    '<-50,00', '-50,00', '120', '> 100', '>=-1.', '<1,5', 'expense', 'a b', '@x', 'a%', "o'r", array('1')));
foreach ($filterNames as $name) {
    foreach ($filterValues as $value) {
        with_get(array($name => $value), 'getfilter');
    }
}
with_get(array('filter_ack' => 'x', 'filter_ack_old' => 'y'), 'getfilter');
with_get(array('filter_type' => 'Spende', 'filter_lo' => '2', 'filter_date' => '2024-02', 'filter_amount' => '>0'), 'getfilter');
finish_group('getfilter');

$sorts = array('ida', 'idd', 'bida', 'bidd', 'dated', 'datea', 'typea', 'typed', 'loa', 'lod', 'membera', 'memberd', 'gka',
    'gkd', 'ka', 'kd', 'ama', 'amd', 'commenta', 'commentd', 'texta', 'textd', 'comma', 'commd', 'acka', 'ackd', 'refunda',
    'refundd', 'bela', 'beld', 'namea', 'named');
foreach (array_merge($sorts, corpus_strings(), array('IDA', 'ida ', array('ida'))) as $sort) {
    with_get(array('sort' => $sort), 'getsort');
}
with_get(array(), 'getsort');
foreach (array_merge(array('ida', 'amd', 'x', 'a', 'd', '', 'ad'), array(0, null)) as $sortn) {
    foreach (array('ida', 'amd', 'x', 'a', 'd', '', 0, array('ida')) as $current) {
        with_get(array('sort' => $current), 'getoppsort', array($sortn));
    }
    with_get(array(), 'getoppsort', array($sortn));
}
finish_group('getsort');

with_get(array(), 'eyes');
with_get(array('noeyes' => ''), 'eyes');
foreach (array(
    array(array(), array()),
    array(array('a' => 1, 'b' => 2), array('a' => true)),
    array(array('a' => 1, 0 => 2, '1' => 3), array(0 => 1, 'a' => 0)),
    array(array('x' => null), array('x' => null)),
    array(array('x' => 1), array('x' => false)),
    array(array(1, 2, 3), array(2 => 'yes')),
) as $args) {
    call('whitelist', $args);
}
foreach (array('/index.php?action=all', '/index.php?action=all&filter_text=ä', '') as $uri) {
    $_SERVER['REQUEST_URI'] = $uri;
    foreach (array(false, true) as $csv) {
        $GLOBALS['make_csv'] = $csv;
        foreach (array(array(), array(-1), array(0), array(5), array('3'), array(null), array(2.5), array('x')) as $args) {
            call('csv_download_link', $args, ($csv ? 'csv' : 'html') . ' ' . $uri);
        }
    }
}
foreach (array(false, true) as $csv) {
    $GLOBALS['make_csv'] = $csv;
    foreach (array(array(), array(''), array(' x'), array(null)) as $args) {
        call('block_start', $args, $csv ? 'csv' : 'html');
    }
    call('block_end', array(), $csv ? 'csv' : 'html');
}
$GLOBALS['make_csv'] = false;
$_SERVER['REQUEST_URI'] = '/index.php?action=all';
finish_group('misc');

// ------------------------------------------------------------------ page-file helpers

foreach (corpus_scalars() as $year) {
    call('date_condition', array($year));
    call('date_condition', array($year, 0));
}
foreach (array_merge(corpus_strings(), array('Jörg', 'a b')) as $text) {
    call('umlaute', array($text));
}
foreach (corpus_scalars() as $data) {
    call('convif', array(false, $data));
    call('convif', array(true, $data));
}
finish_group('page-helpers');

$GLOBALS['helpers_post_regexes'] = array(false, '/^\d+$/', '/^(in|out|wk|bel)$/', '/^-?\d+((\.|,)\d\d?)?$/');
foreach (array_merge(corpus_strings(), array(array('1'))) as $value) {
    foreach ($GLOBALS['helpers_post_regexes'] as $regex) {
        $_POST = array('amount0' => $value);
        call('get_param', array(0, 'amount', 'default', $regex), 'POST ' . ref_export($_POST));
        call('get_param_bool', array(0, 'amount', 'false', $regex, 'true'), 'POST ' . ref_export($_POST));
    }
}
$_POST = array();
call('get_param', array(0, 'amount', null, false), 'POST array()');
call('get_param', array('', 'amount', 1, false), 'POST array()');
finish_group('get_param');

// ------------------------------------------------------------------ idiom probes

// new.php:46: round(str_replace(",", ".", <validated amount>) * 100.0), then
// interpolated into the INSERT statement.
function idiom_new_amount($amount)
{
    $value = round(str_replace(',', '.', $amount) * 100.0);
    return array($value, "$value");
}
// import.php:118 and friends: intval(str_replace(array('.', ','), ...)).
function idiom_import_amount($amount)
{
    return intval(str_replace(array('.', ','), array('', ''), $amount));
}
// report.php, tinyreport.php, ...: sprintf("%1.2f", $line['sum'] / 100.0).
function idiom_money($sum)
{
    return sprintf('%1.2f', $sum / 100.0);
}
// functions.php percent_of_bookings(): round($b * 100 / (1.0 * $c), 2) in a string.
function idiom_percent($b, $c)
{
    $p = round($b * 100 / (1.0 * $c), 2);
    return "$p %";
}
function idiom_string($value)
{
    return array((string) $value, "$value", strval($value));
}
function idiom_numbers($value)
{
    return array(intval($value), floatval($value), is_numeric($value));
}
function idiom_loose_equal($a, $b)
{
    return $a == $b;
}
function idiom_less($a, $b)
{
    return $a < $b;
}
function idiom_sort($values, $flags)
{
    sort($values, $flags);
    return $values;
}
function idiom_in_array($needle)
{
    return array(in_array($needle, array(0, '1', 'a', null)), array_search($needle, array('x', 0, '0', '')));
}
function idiom_switch($value)
{
    // The switch in konto2lo()/getusers.php compares loosely, like ==.
    switch ($value) {
        case '0':
            return 'case "0"';
        case 'a':
            return 'case "a"';
        case 38:
            return 'case 38';
        default:
            return 'default';
    }
}

foreach (array('0', '1', '-1', '12,34', '-12,34', '12.3', '0.07', '1.005', '1,005', '2.675', '100.5', '-0,99', '99999999999.99',
    '12345678901234.56', '1e3', 'abc', '') as $amount) {
    probe('new.php:46 amount ' . ref_export($amount), 'idiom_new_amount', array($amount));
}
foreach (array_merge(corpus_strings(), array('+50,00', '-1.234,56', '+1.000.000,00', '-0,01', '25.00', '-0.78')) as $amount) {
    probe('import.php:118 amount ' . ref_export($amount), 'idiom_import_amount', array($amount));
}
foreach (array_merge(corpus_scalars(), array('-2147483647', '123456', '-5', '5', '50005', '-123456', '9999')) as $sum) {
    probe('sprintf money ' . ref_export($sum), 'idiom_money', array($sum));
}
for ($c = 1; $c <= 7; $c++) {
    for ($b = 0; $b <= $c; $b++) {
        probe("percent_of_bookings $b/$c", 'idiom_percent', array($b, $c));
    }
}
foreach (corpus_scalars() as $value) {
    probe('string conversion ' . ref_export($value), 'idiom_string', array($value));
    probe('number conversion ' . ref_export($value), 'idiom_numbers', array($value));
    probe('in_array/array_search ' . ref_export($value), 'idiom_in_array', array($value));
    probe('switch ' . ref_export($value), 'idiom_switch', array($value));
}
foreach (array(0.1 + 0.2, 1 / 3, 2.5, 3.5, -2.5, 1.955, 5.045, 5.055, 1234.5, 1e15 + 0.3, 0.285, 1.45) as $float) {
    foreach (array(0, 1, 2) as $precision) {
        probe('round(' . ref_export($float) . ", $precision)", 'round', array($float, $precision));
    }
}
finish_group('idioms-values');

$compare = corpus_compare();
foreach ($compare as $a) {
    foreach ($compare as $b) {
        probe(ref_export($a) . ' == ' . ref_export($b), 'idiom_loose_equal', array($a, $b));
        probe(ref_export($a) . ' < ' . ref_export($b), 'idiom_less', array($a, $b));
    }
}
finish_group('idioms-compare');

$sortSets = array(
    array('10', '9', '2', '1', 'a', 'B', '', '0', ' 1', '1e1', 'abc', '-1'),
    array(10, '9', 'a', 0, 'b', '', null, false, true, 1.5, '1.5', ' 2'),
    array('Österreich', 'Zürich', 'Aachen', 'äpfel', 'Apfel', 'zebra'),
);
foreach ($sortSets as $index => $set) {
    foreach (array('SORT_REGULAR' => SORT_REGULAR, 'SORT_STRING' => SORT_STRING, 'SORT_NUMERIC' => SORT_NUMERIC) as $name => $flag) {
        probe("sort set $index $name", 'idiom_sort', array($set, $flag));
    }
}
finish_group('idioms-sort');

echo "Helper golden master written to {$GLOBALS['helpers_out']}/helpers\n";
