<?php

/*
 * format_amount() (src/format.php): the amounts of the reports and listings,
 * byte for byte the former sprintf("%1.2f", $cents / 100.0) idiom of the
 * pages, followed by the unit; and the listing amount in the CSV export.
 */

final class FormatTest extends FunctionsTestCase
{
    public static function amounts()
    {
        return [
            'zero' => [0, '0.00'],
            'one cent' => [1, '0.01'],
            'minus one cent' => [-1, '-0.01'],
            'five cents' => [5, '0.05'],
            'whole euros' => [1200, '12.00'],
            'euros and cents' => [1005, '10.05'],
            'database text' => ['1234', '12.34'],
            'negative database text' => ['-123456', '-1234.56'],
            'negative zero text' => ['-0', '0.00'],
            'negative zero' => [-0.0, '0.00'],
            'SUM of no rows' => [null, '0.00'],
            'false' => [false, '0.00'],
            'half a cent rounds' => [0.5, '0.01'],
            'largest vouchers.amount' => [2147483647, '21474836.47'],
            'smallest vouchers.amount' => [-2147483648, '-21474836.48'],
            'bigint SUM' => ['922337203685477580', '9223372036854776.00'],
            'PHP_INT_MAX' => [PHP_INT_MAX, '92233720368547760.00'],
            'PHP_INT_MIN' => [PHP_INT_MIN, '-92233720368547760.00'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('amounts')]
    public function testFormatAmountIsTheFormerIdiom($cents, $expected)
    {
        self::assertSame($expected, format_amount($cents));
        self::assertSame(sprintf("%1.2f", $cents / 100.0), format_amount($cents));
    }

    public function testFormatAmountAppendsTheUnit()
    {
        self::assertSame('12.34€', format_amount(1234, '€'));
        self::assertSame('-0.05&nbsp;€', format_amount(-5, '&nbsp;€'));
        self::assertSame('0.00 €', format_amount(null, ' €'));
    }

    public function testNonNumericAmountFailsAsBefore()
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('Unsupported operand types: string / float');
        format_amount('abc');
    }

    public function testListingAmountInHtmlAndCsv()
    {
        self::assertSame("<td>-12.34€</td>\n", tag('td', format_amount(-1234, '€')));
        $GLOBALS['make_csv'] = true;
        self::assertSame("-12,34€\t", tag('td', format_amount(-1234, '€')));
        self::assertSame("0,00€\t", tag('td', format_amount(null, '€')));
    }
}
