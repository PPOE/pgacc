<?php

/*
 * Output encoding of the listing sink that no page calls today
 * (kdonations_page_listing_line(), disabled for legal reasons), so that it
 * stays encoded if re-enabled.
 * Synthetic type and organization rows are inserted in a transaction that
 * is rolled back.
 */
require_once dirname(dirname(__DIR__)) . '/kdonations.php';

final class UnusedOutputTest extends FunctionsTestCase
{
    private function withSyntheticRows($callback)
    {
        pg_query($GLOBALS['dbconn'], 'BEGIN');
        pg_query($GLOBALS['dbconn'], "INSERT INTO type (id, name, used, income, realtype) VALUES (95, '<s>type</s> & \"Ö\"', 0, -1, 95)");
        pg_query($GLOBALS['dbconn'], "INSERT INTO lo (id, name) VALUES (95, '<s>lo</s> ''x''')");
        ob_start();
        call_user_func($callback);
        $output = ob_get_clean();
        pg_query($GLOBALS['dbconn'], 'ROLLBACK');
        return $output;
    }

    private function kdonationsLine()
    {
        kdonations_page_listing_line(['date' => '2024-01-02 00:00:00', 'type' => 95, 'orga' => 95,
            'comment' => '<s>comment</s> BG/123456789 12', 'amount' => 1234, 'name' => '<s>donor</s> & Ö']);
    }

    public function testKdonationsListingLineEncodesHtml()
    {
        $GLOBALS['make_csv'] = false;
        self::assertSame("<tr><td>2024-01-02</td>\n<td>&lt;s&gt;type&lt;/s&gt; &amp; &quot;Ö&quot;</td>\n"
            . "<td>&lt;s&gt;lo&lt;/s&gt; &#039;x&#039;</td>\n<td>&lt;s&gt;comment&lt;/s&gt;  <i>Kontodaten</i> </td>\n"
            . "<td>12.34€</td>\n<td>&lt;s&gt;donor&lt;/s&gt; &amp; Ö</td>\n</tr>", $this->withSyntheticRows([$this, 'kdonationsLine']));
    }

    public function testKdonationsListingLineKeepsCsvRaw()
    {
        $GLOBALS['make_csv'] = true;
        self::assertSame(
            "2024-01-02\t<s>type</s> & \"Ö\"\t<s>lo</s> 'x'\t<s>comment</s>  <i>Kontodaten</i> \t12,34€\t<s>donor</s> & Ö\t\n",
            $this->withSyntheticRows([$this, 'kdonationsLine']),
        );
    }
}
