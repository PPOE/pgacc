<?php

use PHPUnit\Framework\TestCase;

final class FunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = array();
        $GLOBALS['make_csv'] = false;
        $GLOBALS['functions_test_rights'] = '';
    }

    public function testHexAndAsciiConversion(): void
    {
        self::assertSame("A\0Z", hex2ascii('41 00 5a'));
        self::assertSame('', hex2ascii(''));
        self::assertSame('41005A', ascii2hex("A\0Z"));
        self::assertSame('', ascii2hex(''));
    }

    public function testCsvDownloadLinkOutputAndSuppression(): void
    {
        $_SERVER['REQUEST_URI'] = '/index.php?action=all';
        ob_start();
        csv_download_link();
        $withoutCount = ob_get_clean();
        self::assertStringContainsString('/index.php?action=all&format=csv', $withoutCount);
        self::assertStringNotContainsString('Umsätze gefunden', $withoutCount);

        ob_start();
        csv_download_link(3);
        $withCount = ob_get_clean();
        self::assertStringContainsString('3 Umsätze gefunden', $withCount);

        $GLOBALS['make_csv'] = true;
        ob_start();
        $result = csv_download_link(3);
        self::assertSame('', ob_get_clean());
        self::assertNull($result);
    }

    /**
     * @dataProvider filterProvider
     */
    public function testGetfilterBuildsExpectedConditions(array $get, string $expected): void
    {
        $_GET = $get;
        self::assertSame($expected, getfilter());
    }

    public static function filterProvider(): array
    {
        return array(
            'empty' => array(array(), ''),
            'numeric ids and member flag' => array(
                array('filter_id' => '12', 'filter_bid' => '34', 'filter_member' => '0', 'filter_member_id' => '56'),
                ' AND voucher_id = 12 AND id = 34 AND member  AND member_id = 56'
            ),
            'date year' => array(array('filter_date' => '2024'), " AND date >= '2024-01-01' AND date <= '2024-12-31' "),
            'date month' => array(array('filter_date' => '2024-02'), " AND date >= '2024-02-01' AND date < TIMESTAMP '2024-02-01' + INTERVAL '1 month' "),
            'date day' => array(array('filter_date' => '2024-02-29'), " AND date = '2024-02-29' "),
            'numeric and text type' => array(
                array('filter_type' => '8'),
                ' AND type = 8'
            ),
            'type name' => array(array('filter_type' => 'Spende'), " AND lower(type.name) LIKE lower('%Spende%')"),
            'organization number and name' => array(
                array('filter_lo' => '4'),
                " AND orga = 4 AND lower(lo.name) LIKE lower('%4%')"
            ),
            'organization name' => array(array('filter_lo' => 'Wien'), " AND lower(lo.name) LIKE lower('%Wien%')"),
            'contra account' => array(array('filter_gk' => '@bank 1'), " AND contra_account LIKE '@bank 1%'"),
            'amount operator' => array(array('filter_amount' => '>= 123'), ' AND amount >= 12300'),
            'amount with decimals' => array(array('filter_amount' => '< -12,50'), ' AND amount < -1250'),
            'account filters' => array(
                array('filter_k' => 'AT 1', 'filter_vk' => 'AT 2'),
                " AND account LIKE 'AT 1%' AND vaccount LIKE 'AT 2%'"
            ),
            'comment text and comment gf' => array(
                array('filter_text' => 'Piraten', 'filter_comment' => 'Geprüft'),
                " AND lower(comment) LIKE lower('%Piraten%') AND lower(commentgf) LIKE lower('%Geprüft%')"
            ),
            'committed and refund and attachment' => array(
                array('filter_comm' => '1', 'filter_refund' => '1', 'filter_bel' => '1'),
                ' AND committed  AND refund  AND file != 0 '
            ),
            'acknowledgements' => array(
                array('filter_ack' => 'Peter', 'filter_ack_old' => 'Old'),
                " AND (ack1 LIKE '%Peter%' OR ack2 LIKE '%Peter%')  AND (ack1_old LIKE '%Peter%' OR ack2_old LIKE '%Peter%') "
            ),
            'voucher name' => array(array('filter_name' => 'Anna'), " AND lower(vouchers.name) LIKE lower('%Anna%')"),
            'invalid values are ignored' => array(
                array('filter_id' => '1 OR 1=1', 'filter_date' => 'yesterday', 'filter_name' => 'x%'),
                ''
            ),
        );
    }

    public function testGetfilterSuppressesMalformedLegacyNumericRegex(): void
    {
        $_GET = array('filter_amount' => '150');
        self::assertSame('', @getfilter());
    }

    /**
     * @dataProvider tagProvider
     */
    public function testTagRendersHtmlAndCsv(array $args, string $expectedHtml, string $expectedCsv): void
    {
        [$tagName, $text] = $args;
        $GLOBALS['make_csv'] = false;
        self::assertSame($expectedHtml, tag($tagName, $text));
        $GLOBALS['make_csv'] = true;
        self::assertSame($expectedCsv, tag($tagName, $text));
    }

    public static function tagProvider(): array
    {
        return array(
            'row and newline flattening' => array(array('tr', "line 1\nline 2"), "<tr>line 1\nline 2</tr>\n", "line 1 / line 2\n"),
            'cell and euro decimal' => array(array('td', '12.50€'), "<td>12.50€</td>\n", "12,50€\t"),
            'other tag' => array(array('th', 'Header'), "<th>Header</th>\n", 'Header'),
            'negative euro' => array(array('td', '-12.5€'), "<td>-12.5€</td>\n", "-12,5€\t"),
        );
    }

    /**
     * @dataProvider headerPageProvider
     */
    public function testAccHeaderRendersEachPageTitle(string $page, string $title): void
    {
        ob_start();
        acc_header(null, $page, 2024);
        $html = ob_get_clean();
        self::assertStringContainsString('<title>' . $title . '</title>', $html);
    }

    public static function headerPageProvider(): array
    {
        return array(
            'default' => array('index', 'Rechenschaftsbericht - Piratenpartei Österreichs'),
            'donations' => array('donations', 'Spenden - Piratenpartei Österreichs'),
            'goods donations' => array('kdonations', 'Sachspenden - Piratenpartei Österreichs'),
            'spendings' => array('spendings', 'Ausgaben - Piratenpartei Österreichs'),
            'campaign costs' => array('wk', 'Wahlkampfkosten - Piratenpartei Österreichs'),
            'transactions' => array('transactions', 'Kontobewegungen - Piratenpartei Österreichs'),
        );
    }

    public function testAccHeaderHidesAdminLinksWithoutRightsAndShowsDeletedLinkToBgf(): void
    {
        ob_start();
        acc_header(null, 'index', 2024);
        $publicHtml = ob_get_clean();
        self::assertStringNotContainsString('action=import', $publicHtml);
        self::assertStringNotContainsString('action=deleted', $publicHtml);

        $GLOBALS['functions_test_rights'] = 'bgf';
        ob_start();
        acc_header(null, 'index', 2024);
        $adminHtml = ob_get_clean();
        self::assertStringContainsString('action=deleted', $adminHtml);
        self::assertStringContainsString('action=accounts', $adminHtml);
        self::assertStringContainsString('action=open&filter_date=2024', $adminHtml);
    }

    public function testBlockHelpersSuppressCsvAndRenderHtml(): void
    {
        ob_start();
        block_start('test');
        block_end();
        $html = ob_get_clean();
        self::assertStringContainsString('class="wiki use_termstest"', $html);
        self::assertStringContainsString('</div>', $html);

        $GLOBALS['make_csv'] = true;
        ob_start();
        block_start('test');
        block_end();
        self::assertSame('', ob_get_clean());
    }

    public function testAccFooterShowsLoginOrLogoutLink(): void
    {
        ob_start();
        acc_footer();
        $anonymous = ob_get_clean();
        self::assertStringContainsString('action=login', $anonymous);
        self::assertStringNotContainsString('action=logout', $anonymous);

        $GLOBALS['functions_test_rights'] = 'root';
        ob_start();
        acc_footer();
        $authenticated = ob_get_clean();
        self::assertStringContainsString('action=logout', $authenticated);
        self::assertStringNotContainsString('action=login', $authenticated);
    }

    /**
     * @dataProvider formatDateProvider
     */
    public function testFormatDate(string $input, string $expected): void
    {
        self::assertSame($expected, format_date($input));
    }

    public static function formatDateProvider(): array
    {
        return array(
            'day month year' => array('1.2.2024', '2024-2-1'),
            'timestamp' => array('2024-02-03 12:34:56', '2024-02-03'),
            'null' => array('', ''),
            'unrecognized' => array('2024/02/03', '2024/02/03'),
        );
    }

    /**
     * @dataProvider rightsProvider
     */
    public function testRights2orgasql(string $rights, string $expected): void
    {
        self::assertSame($expected, @rights2orgasql($rights));
    }

    public static function rightsProvider(): array
    {
        return array(
            'bgf' => array('bgf', ''),
            'capitalized bgf' => array('Rbgf', ''),
            'organization ids' => array('4,5', " AND orga IN ('4','5') "),
            'no organization ids' => array('reviewer', ' AND false '),
        );
    }

    /**
     * @dataProvider accountProvider
     */
    public function testKonto2lo(string $account, $expected): void
    {
        self::assertSame($expected, konto2lo($account));
    }

    public static function accountProvider(): array
    {
        return array(
            array('50110117270', 1), array('50110117300', 2), array('50110117318', 3),
            array('50110117326', 4), array('50110117350', 5), array('50110117369', 6),
            array('50110117393', 10), array('10110123642', 9), array('50110110437', 10),
            array('unknown-account', 'unknown-account'),
        );
    }

    /**
     * @dataProvider sortProvider
     */
    public function testGetsort(string $sort, string $expected): void
    {
        $_GET['sort'] = $sort;
        self::assertSame($expected, getsort());
    }

    public static function sortProvider(): array
    {
        $default = 'date ASC, voucher_id DESC, vouchers.id ASC';
        return array(
            'missing sort' => array('', $default),
            'unknown sort' => array('unknown', $default),
            'ida' => array('ida', 'voucher_id ASC, vouchers.id ASC'),
            'idd' => array('idd', 'voucher_id DESC, vouchers.id ASC'),
            'bida' => array('bida', 'vouchers.id ASC, voucher_id ASC'),
            'bidd' => array('bidd', 'vouchers.id DESC, voucher_id ASC'),
            'dated' => array('dated', 'date DESC, voucher_id DESC, vouchers.id ASC'),
            'datea' => array('datea', $default),
            'typea' => array('typea', 'type ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'typed' => array('typed', 'type DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'loa' => array('loa', 'orga ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'lod' => array('lod', 'orga DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'membera' => array('membera', 'member_id ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'memberd' => array('memberd', 'member_id DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'gka' => array('gka', 'contra_account DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'gkd' => array('gkd', 'contra_account ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'ka' => array('ka', 'account ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'kd' => array('kd', 'account DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'ama' => array('ama', 'amount ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'amd' => array('amd', 'amount DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'commenta' => array('commenta', 'commentgf ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'commentd' => array('commentd', 'commentgf DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'texta' => array('texta', 'comment ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'textd' => array('textd', 'comment DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'comma' => array('comma', 'committed DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'commd' => array('commd', 'committed ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'acka' => array('acka', 'ack1 ASC, ack2 ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'ackd' => array('ackd', 'ack1 DESC, ack2 DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'refunda' => array('refunda', 'refund ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'refundd' => array('refundd', 'refund DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'bela' => array('bela', 'file ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'beld' => array('beld', 'file DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'namea' => array('namea', 'name ASC, date ASC, voucher_id DESC, vouchers.id ASC'),
            'named' => array('named', 'name DESC, date ASC, voucher_id DESC, vouchers.id ASC'),
        );
    }

    /**
     * @dataProvider oppositeSortProvider
     */
    public function testGetoppsort(string $current, string $requested, string $expected): void
    {
        $_GET['sort'] = $current;
        self::assertSame($expected, getoppsort($requested));
    }

    public static function oppositeSortProvider(): array
    {
        return array(
            'ascending to descending' => array('namea', 'namea', 'named'),
            'descending to ascending' => array('named', 'named', 'namea'),
            'different current sort' => array('datea', 'named', 'named'),
            'missing current sort' => array('', 'namea', 'namea'),
            'invalid direction' => array('namex', 'namex', 'namex'),
        );
    }

    public function testPercentOfBookingsUsesSyntheticDatabaseRows(): void
    {
        $text = percent_of_bookings();
        self::assertStringContainsString('2024: 7 Buchungszeilen, 4 (57.14 %) abgeschlossen, 2 (28.57 %) bearbeitet, 1 (14.29 %) unbearbeitet.', $text);
        self::assertStringNotContainsString('2023: ', $text);
    }

    public function testEyesWithAndWithoutNoeyesFlag(): void
    {
        self::assertSame(' NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL) ', eyes());
        $_GET['noeyes'] = '1';
        self::assertSame(' NOT deleted ', eyes());
    }

    public function testWhitelistKeepsOnlyPresentWhitelistedKeys(): void
    {
        self::assertSame(
            array('name' => 'Alice', 'zero' => 0),
            whitelist(array('name' => 'Alice', 'ignored' => 'x', 'zero' => 0), array('name' => true, 'zero' => true))
        );
    }

    public function testVouchersResetAckMovesCurrentAcknowledgementsToOldFields(): void
    {
        $voucherId = 987654321;
        $connection = pg_connect('host=' . getenv('PGHOST') . ' port=' . getenv('PGPORT') . ' dbname=' . getenv('PGDATABASE') . ' user=' . getenv('PGUSER') . ' password=' . getenv('PGPASSWORD'));
        self::assertNotFalse($connection);
        pg_query($connection, 'BEGIN');
        try {
            pg_query_params($connection, 'INSERT INTO vouchers (voucher_id, ack1, ack2, deleted) VALUES ($1, $2, $3, false)', array($voucherId, 'reviewer-one', 'reviewer-two'));
            vouchers_reset_ack($voucherId, '');
            $result = pg_query_params($connection, 'SELECT ack1, ack2, ack1_old, ack2_old FROM vouchers WHERE voucher_id = $1', array($voucherId));
            self::assertSame(
                array('ack1' => null, 'ack2' => null, 'ack1_old' => 'reviewer-one', 'ack2_old' => 'reviewer-two'),
                pg_fetch_assoc($result)
            );
        } finally {
            pg_query($connection, 'ROLLBACK');
            pg_close($connection);
        }
    }
}
