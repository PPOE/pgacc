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
    const VOUCHER = array(',.', '', false);      // new.php get_voucher(), Handkassa import
    const BAWAG = array(',', '.', true);         // BAWAG and Sparkasse imports
    const BOTH = array(',.', ',.', true);        // Paypal and Sparkasse imports: either convention

    public static function amounts()
    {
        return array(
            'voucher: whole euros' => array(self::VOUCHER, '12', 1200),
            'voucher: zero' => array(self::VOUCHER, '0', 0),
            'voucher: zero with decimals' => array(self::VOUCHER, '0,00', 0),
            'voucher: default value' => array(self::VOUCHER, '0.00', 0),
            'voucher: comma, two decimals' => array(self::VOUCHER, '12,34', 1234),
            'voucher: dot, two decimals' => array(self::VOUCHER, '12.34', 1234),
            'voucher: one decimal' => array(self::VOUCHER, '12,5', 1250),
            'voucher: one cent' => array(self::VOUCHER, '0,01', 1),
            'voucher: negative' => array(self::VOUCHER, '-12,50', -1250),
            'voucher: plus sign' => array(self::VOUCHER, '+5', 500),
            'voucher: negative zero' => array(self::VOUCHER, '-0', 0),
            'voucher: leading zeros' => array(self::VOUCHER, '007,10', 710),
            'voucher: float-unsafe decimals' => array(self::VOUCHER, '0.29', 29),
            'voucher: float-unsafe decimals 2' => array(self::VOUCHER, '1.15', 115),
            'voucher: leading space' => array(self::VOUCHER, ' 12,50', 1250),
            'voucher: trailing newline' => array(self::VOUCHER, "12\n", 1200),
            'voucher: tab and CR' => array(self::VOUCHER, "\t12,50\r\n", 1250),
            'voucher: three decimals, comma' => array(self::VOUCHER, '1,005', null),
            'voucher: three decimals, dot' => array(self::VOUCHER, '1.005', null),
            'voucher: thousands separator' => array(self::VOUCHER, '1.234,56', null),
            'voucher: empty' => array(self::VOUCHER, '', null),
            'voucher: only spaces' => array(self::VOUCHER, '  ', null),
            'voucher: text' => array(self::VOUCHER, 'abc', null),
            'voucher: exponent' => array(self::VOUCHER, '1e3', null),
            'voucher: exponent with decimals' => array(self::VOUCHER, '1e3,2', null),
            'voucher: huge exponent' => array(self::VOUCHER, '1e1000', null),
            'voucher: separator only' => array(self::VOUCHER, '5,', null),
            'voucher: decimals only' => array(self::VOUCHER, ',50', null),
            'voucher: double sign' => array(self::VOUCHER, '--5', null),
            'voucher: inner space' => array(self::VOUCHER, '1 234', null),
            'voucher: other whitespace' => array(self::VOUCHER, "\x0B12", null),
            'voucher: largest' => array(self::VOUCHER, '21474836,47', 2147483647),
            'voucher: smallest' => array(self::VOUCHER, '-21474836,48', -2147483648),
            'voucher: above the column' => array(self::VOUCHER, '21474836,48', null),
            'voucher: below the column' => array(self::VOUCHER, '-21474836,49', null),
            'voucher: above, same length, first digit' => array(self::VOUCHER, '31474836,47', null),
            'voucher: below the limit, same length' => array(self::VOUCHER, '11474836,47', 1147483647),
            'voucher: leading zeros at the limit' => array(self::VOUCHER, '0021474836,47', 2147483647),
            'voucher: one digit longer' => array(self::VOUCHER, '100000000', null),
            'voucher: beyond 64 bits' => array(self::VOUCHER, '9223372036854775808', null),
            'voucher: many digits' => array(self::VOUCHER, '99999999999999999999,99', null),
            'voucher: array' => array(self::VOUCHER, array('1'), null),
            'voucher: null' => array(self::VOUCHER, null, null),
            'bawag: positive' => array(self::BAWAG, '+50,00', 5000),
            'bawag: zero' => array(self::BAWAG, '+0,00', 0),
            'bawag: negative with thousands' => array(self::BAWAG, '-1.234,56', -123456),
            'bawag: millions' => array(self::BAWAG, '+1.000.000,00', 100000000),
            'bawag: one cent' => array(self::BAWAG, '-0,01', -1),
            'bawag: no sign' => array(self::BAWAG, '12,34', 1234),
            'bawag: without group separator' => array(self::BAWAG, '1234,56', 123456),
            'bawag: largest' => array(self::BAWAG, '+21.474.836,47', 2147483647),
            'bawag: smallest' => array(self::BAWAG, '-21.474.836,48', -2147483648),
            'bawag: above the column' => array(self::BAWAG, '+21.474.836,48', null),
            'bawag: below the column' => array(self::BAWAG, '-21.474.836,49', null),
            'bawag: dot decimals' => array(self::BAWAG, '25.00', null),
            'bawag: exponent' => array(self::BAWAG, '1e3', null),
            'bawag: exponent with decimals' => array(self::BAWAG, '1e3,2', null),
            'bawag: huge exponent' => array(self::BAWAG, '1e1000', null),
            'bawag: beyond 64 bits' => array(self::BAWAG, '9223372036854775808', null),
            'bawag: beyond 64 bits with cents' => array(self::BAWAG, '92233720368547758,08', null),
            'bawag: whole number' => array(self::BAWAG, '100', null),
            'bawag: one decimal' => array(self::BAWAG, '1,5', null),
            'bawag: three decimals' => array(self::BAWAG, '1,005', null),
            'bawag: short group' => array(self::BAWAG, '1.23,45', null),
            'bawag: long group' => array(self::BAWAG, '1.2345,67', null),
            'bawag: long first group' => array(self::BAWAG, '1234.567,89', null),
            'bawag: separator as decimal' => array(self::BAWAG, '1.234.56', null),
            'bawag: empty' => array(self::BAWAG, '', null),
            'bawag: text' => array(self::BAWAG, 'abc', null),
            'bawag: double sign' => array(self::BAWAG, '+-5,00', null),
            'bawag: surrounding space' => array(self::BAWAG, ' 5,00 ', 500),
            'both: dot decimals' => array(self::BOTH, '25.00', 2500),
            'both: comma decimals' => array(self::BOTH, '25,00', 2500),
            'both: zero' => array(self::BOTH, '0.00', 0),
            'both: zero, comma' => array(self::BOTH, '+0,00', 0),
            'both: negative' => array(self::BOTH, '-0.78', -78),
            'both: negative, comma' => array(self::BOTH, '-0,78', -78),
            'both: comma thousands' => array(self::BOTH, '1,234.56', 123456),
            'both: dot thousands' => array(self::BOTH, '-1.234,56', -123456),
            'both: millions, dots' => array(self::BOTH, '1.000.000,00', 100000000),
            'both: millions, commas' => array(self::BOTH, '1,000,000.00', 100000000),
            'both: largest' => array(self::BOTH, '21,474,836.47', 2147483647),
            'both: above the column' => array(self::BOTH, '21.474.836,48', null),
            'both: same separator twice' => array(self::BOTH, '1.234.56', null),
            'both: same separator twice, comma' => array(self::BOTH, '1,234,56', null),
            'both: mixed groups' => array(self::BOTH, '1,234.567,89', null),
            'both: mixed thousands' => array(self::BOTH, '1.234,567.89', null),
            'both: short group' => array(self::BOTH, '1.23,45', null),
            'both: one decimal' => array(self::BOTH, '25,0', null),
            'both: three decimals' => array(self::BOTH, '25.000', null),
            'both: whole number' => array(self::BOTH, '25', null),
            'both: exponent' => array(self::BOTH, '1e3', null),
        );
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
        return array(
            'year' => array(2024, 1, " AND date >= '2024-01-01' AND date < '2025-01-01'"),
            'until year' => array(2024, 0, "AND date < '2025-01-01'"),
            'year text' => array('2024', 1, " AND date >= '2024-01-01' AND date < '2025-01-01'"),
            'first year' => array(1, 1, " AND date >= '0001-01-01' AND date < '0002-01-01'"),
            'two-digit year' => array(13, 0, "AND date < '0014-01-01'"),
            'last year' => array(9999, 0, "AND date < '10000-01-01'"),
            'year zero' => array(0, 1, ' AND false'),
            'negative' => array(-1, 0, ' AND false'),
            'five digits' => array(10000, 1, ' AND false'),
            'largest int' => array(PHP_INT_MAX, 1, ' AND false'),
            'largest int, until' => array(PHP_INT_MAX, 0, ' AND false'),
            'huge float' => array(1.5E+300, 1, ' AND false'),
            'fraction' => array(2.5, 1, ' AND false'),
            'text' => array('abc', 0, ' AND false'),
            'text after the year' => array('2024x', 1, ' AND false'),
            'suffix' => array('2024-1', 1, ' AND false'),
            'text before the year' => array('x2024', 1, ' AND false'),
            'null' => array(null, 1, ' AND false'),
        );
    }

    /**
     * @dataProvider years
     */
    public function testDateConditionYearRange($year, $begin, $expected)
    {
        $this->assertSame($expected, date_condition($year, $begin));
    }
}
