<?php

/*
 * Formatting and parsing helpers for the pages (index.php loads this file
 * after src/db.php): amounts in cents and dates. The file only defines
 * functions, so a direct request prints nothing (the web server denies src/
 * anyway).
 */

// An amount in cents, parsed exactly as text (no float rounding) after
// trimming spaces, tabs, CR and LF: an optional sign, the euros, then one of
// the $decimal separators (one or more characters of ",.") and the decimals:
// exactly two with $two_decimals, otherwise none, one or two (no separator
// without decimals). The characters of $thousands ('' for none) other than
// the decimal separator may only separate groups of exactly three digits of
// the euros: with $decimal and $thousands both ",." the last separator is the
// decimal one ("25,00", "25.00", "1.234,56" and "1,234.56"). Null for any
// other value and for amounts outside the integer column vouchers.amount
// (-2147483648 to 2147483647 cents, compared as text, independent of the
// integer size).
function amount_cents($value, $decimal, $thousands, $two_decimals)
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value, " \t\r\n");
    $parts = null;
    $group = '';
    foreach (str_split($decimal) as $separator) {
        $group = str_replace($separator, '', $thousands);
        $euros = $group === '' ? '\d+' : '\d{1,3}(?:[' . preg_quote($group, '/') . ']\d{3})+|\d+';
        $pattern = '/^([+-]?)(' . $euros . ')(?:' . preg_quote($separator, '/') . ($two_decimals ? '(\d\d))' : '(\d\d?))?') . '$/D';
        if (preg_match($pattern, $value, $parts) == 1) {
            break;
        }
        $parts = null;
    }
    if ($parts === null) {
        return null;
    }
    $digits = ltrim(str_replace(str_split($group), '', $parts[2]) . str_pad($parts[3] ?? '', 2, '0'), '0');
    $limit = $parts[1] == '-' ? '2147483648' : '2147483647';
    if (strlen($digits) > strlen($limit) || (strlen($digits) == strlen($limit) && strcmp($digits, $limit) > 0)) {
        return null;
    }
    return intval(($parts[1] == '-' ? '-' : '') . $digits);
}
// A date d.m.20yy (day and month with or without a leading zero) as 20yy-m-d,
// a timestamp "YYYY-MM-DD h..." as its date; any other value unchanged.
function format_date($date)
{
    if ($date != null && preg_match('/^((\d|(0|1|2)\d|3(0|1))\.(\d|0\d|1(0|1|2))\.20\d\d)$/', $date) == 1) {
        $tmp = explode(".", $date);
        $date = $tmp[2] . '-' . $tmp[1] . '-' . $tmp[0];
    } elseif ($date != null && preg_match('/^\d\d\d\d-\d\d-\d\d \d.*$/', $date) == 1) {
        $tmp = explode(" ", $date);
        $date = $tmp[0];
    }
    return $date;
}
// An amount in cents as euros with two decimals ("1234" as "12.34", null as
// "0.00"), followed by $unit: "€" in the listings (tag() writes the decimal
// point as a comma in the CSV export), "&nbsp;€" in the reports.
function format_amount($cents, $unit = '')
{
    return sprintf("%1.2f", $cents / 100.0) . $unit;
}
