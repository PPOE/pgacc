<?php

/*
 * amount_cents() (functions.php): exact amount parsing of the voucher form
 * and of every bank import format (Paypal and Sparkasse in either separator
 * convention), limited to the integer column
 * vouchers.amount; and the year range of donations.php date_condition().
 */

require_once dirname(dirname(__DIR__)) . '/donations.php';

final class AmountParsingTest extends FunctionsTestCase
{
    // The options of the call sites: decimal separators, thousands separator,
    // exactly two decimals.
    public const VOUCHER = [',.', '', false];      // new.php get_voucher(), Handkassa import
    public const BAWAG = [',', '.', true];         // BAWAG and Sparkasse imports
    public const BOTH = [',.', ',.', true];        // Paypal and Sparkasse imports: either convention

    public static function amounts()
    {
        return [
            'voucher: whole euros' => [self::VOUCHER, '12', 1200],
            'voucher: zero' => [self::VOUCHER, '0', 0],
            'voucher: zero with decimals' => [self::VOUCHER, '0,00', 0],
            'voucher: default value' => [self::VOUCHER, '0.00', 0],
            'voucher: comma, two decimals' => [self::VOUCHER, '12,34', 1234],
            'voucher: dot, two decimals' => [self::VOUCHER, '12.34', 1234],
            'voucher: one decimal' => [self::VOUCHER, '12,5', 1250],
            'voucher: one cent' => [self::VOUCHER, '0,01', 1],
            'voucher: negative' => [self::VOUCHER, '-12,50', -1250],
            'voucher: plus sign' => [self::VOUCHER, '+5', 500],
            'voucher: negative zero' => [self::VOUCHER, '-0', 0],
            'voucher: leading zeros' => [self::VOUCHER, '007,10', 710],
            'voucher: float-unsafe decimals' => [self::VOUCHER, '0.29', 29],
            'voucher: float-unsafe decimals 2' => [self::VOUCHER, '1.15', 115],
            'voucher: leading space' => [self::VOUCHER, ' 12,50', 1250],
            'voucher: trailing newline' => [self::VOUCHER, "12\n", 1200],
            'voucher: tab and CR' => [self::VOUCHER, "\t12,50\r\n", 1250],
            'voucher: three decimals, comma' => [self::VOUCHER, '1,005', null],
            'voucher: three decimals, dot' => [self::VOUCHER, '1.005', null],
            'voucher: thousands separator' => [self::VOUCHER, '1.234,56', null],
            'voucher: empty' => [self::VOUCHER, '', null],
            'voucher: only spaces' => [self::VOUCHER, '  ', null],
            'voucher: text' => [self::VOUCHER, 'abc', null],
            'voucher: exponent' => [self::VOUCHER, '1e3', null],
            'voucher: exponent with decimals' => [self::VOUCHER, '1e3,2', null],
            'voucher: huge exponent' => [self::VOUCHER, '1e1000', null],
            'voucher: separator only' => [self::VOUCHER, '5,', null],
            'voucher: decimals only' => [self::VOUCHER, ',50', null],
            'voucher: double sign' => [self::VOUCHER, '--5', null],
            'voucher: inner space' => [self::VOUCHER, '1 234', null],
            'voucher: other whitespace' => [self::VOUCHER, "\x0B12", null],
            'voucher: largest' => [self::VOUCHER, '21474836,47', 2147483647],
            'voucher: smallest' => [self::VOUCHER, '-21474836,48', -2147483648],
            'voucher: above the column' => [self::VOUCHER, '21474836,48', null],
            'voucher: below the column' => [self::VOUCHER, '-21474836,49', null],
            'voucher: above, same length, first digit' => [self::VOUCHER, '31474836,47', null],
            'voucher: below the limit, same length' => [self::VOUCHER, '11474836,47', 1147483647],
            'voucher: leading zeros at the limit' => [self::VOUCHER, '0021474836,47', 2147483647],
            'voucher: one digit longer' => [self::VOUCHER, '100000000', null],
            'voucher: beyond 64 bits' => [self::VOUCHER, '9223372036854775808', null],
            'voucher: many digits' => [self::VOUCHER, '99999999999999999999,99', null],
            'voucher: array' => [self::VOUCHER, ['1'], null],
            'voucher: null' => [self::VOUCHER, null, null],
            'bawag: positive' => [self::BAWAG, '+50,00', 5000],
            'bawag: zero' => [self::BAWAG, '+0,00', 0],
            'bawag: negative with thousands' => [self::BAWAG, '-1.234,56', -123456],
            'bawag: millions' => [self::BAWAG, '+1.000.000,00', 100000000],
            'bawag: one cent' => [self::BAWAG, '-0,01', -1],
            'bawag: no sign' => [self::BAWAG, '12,34', 1234],
            'bawag: without group separator' => [self::BAWAG, '1234,56', 123456],
            'bawag: largest' => [self::BAWAG, '+21.474.836,47', 2147483647],
            'bawag: smallest' => [self::BAWAG, '-21.474.836,48', -2147483648],
            'bawag: above the column' => [self::BAWAG, '+21.474.836,48', null],
            'bawag: below the column' => [self::BAWAG, '-21.474.836,49', null],
            'bawag: dot decimals' => [self::BAWAG, '25.00', null],
            'bawag: exponent' => [self::BAWAG, '1e3', null],
            'bawag: exponent with decimals' => [self::BAWAG, '1e3,2', null],
            'bawag: huge exponent' => [self::BAWAG, '1e1000', null],
            'bawag: beyond 64 bits' => [self::BAWAG, '9223372036854775808', null],
            'bawag: beyond 64 bits with cents' => [self::BAWAG, '92233720368547758,08', null],
            'bawag: whole number' => [self::BAWAG, '100', null],
            'bawag: one decimal' => [self::BAWAG, '1,5', null],
            'bawag: three decimals' => [self::BAWAG, '1,005', null],
            'bawag: short group' => [self::BAWAG, '1.23,45', null],
            'bawag: long group' => [self::BAWAG, '1.2345,67', null],
            'bawag: long first group' => [self::BAWAG, '1234.567,89', null],
            'bawag: separator as decimal' => [self::BAWAG, '1.234.56', null],
            'bawag: empty' => [self::BAWAG, '', null],
            'bawag: text' => [self::BAWAG, 'abc', null],
            'bawag: double sign' => [self::BAWAG, '+-5,00', null],
            'bawag: surrounding space' => [self::BAWAG, ' 5,00 ', 500],
            'both: dot decimals' => [self::BOTH, '25.00', 2500],
            'both: comma decimals' => [self::BOTH, '25,00', 2500],
            'both: zero' => [self::BOTH, '0.00', 0],
            'both: zero, comma' => [self::BOTH, '+0,00', 0],
            'both: negative' => [self::BOTH, '-0.78', -78],
            'both: negative, comma' => [self::BOTH, '-0,78', -78],
            'both: comma thousands' => [self::BOTH, '1,234.56', 123456],
            'both: dot thousands' => [self::BOTH, '-1.234,56', -123456],
            'both: millions, dots' => [self::BOTH, '1.000.000,00', 100000000],
            'both: millions, commas' => [self::BOTH, '1,000,000.00', 100000000],
            'both: largest' => [self::BOTH, '21,474,836.47', 2147483647],
            'both: above the column' => [self::BOTH, '21.474.836,48', null],
            'both: same separator twice' => [self::BOTH, '1.234.56', null],
            'both: same separator twice, comma' => [self::BOTH, '1,234,56', null],
            'both: mixed groups' => [self::BOTH, '1,234.567,89', null],
            'both: mixed thousands' => [self::BOTH, '1.234,567.89', null],
            'both: short group' => [self::BOTH, '1.23,45', null],
            'both: one decimal' => [self::BOTH, '25,0', null],
            'both: three decimals' => [self::BOTH, '25.000', null],
            'both: whole number' => [self::BOTH, '25', null],
            'both: exponent' => [self::BOTH, '1e3', null],
        ];
    }

    /**
     * @dataProvider amounts
     */
    public function testAmountCents(array $options, $value, $expected)
    {
        $this->assertSame($expected, amount_cents($value, $options[0], $options[1], $options[2]));
    }

    public static function years()
    {
        return [
            'year' => [2024, 1, " AND date >= '2024-01-01' AND date < '2025-01-01'"],
            'until year' => [2024, 0, "AND date < '2025-01-01'"],
            'year text' => ['2024', 1, " AND date >= '2024-01-01' AND date < '2025-01-01'"],
            'first year' => [1, 1, " AND date >= '0001-01-01' AND date < '0002-01-01'"],
            'two-digit year' => [13, 0, "AND date < '0014-01-01'"],
            'last year' => [9999, 0, "AND date < '10000-01-01'"],
            'year zero' => [0, 1, ' AND false'],
            'negative' => [-1, 0, ' AND false'],
            'five digits' => [10000, 1, ' AND false'],
            'largest int' => [PHP_INT_MAX, 1, ' AND false'],
            'largest int, until' => [PHP_INT_MAX, 0, ' AND false'],
            'huge float' => [1.5E+300, 1, ' AND false'],
            'fraction' => [2.5, 1, ' AND false'],
            'text' => ['abc', 0, ' AND false'],
            'text after the year' => ['2024x', 1, ' AND false'],
            'suffix' => ['2024-1', 1, ' AND false'],
            'text before the year' => ['x2024', 1, ' AND false'],
            'null' => [null, 1, ' AND false'],
        ];
    }

    /**
     * @dataProvider years
     */
    public function testDateConditionYearRange($year, $begin, $expected)
    {
        $this->assertSame($expected, date_condition($year, $begin));
    }
}
