<?php

final class FunctionsTest extends FunctionsTestCase
{
    public function testHexAndAsciiConversion()
    {
        self::assertSame("A\0Z", hex2ascii('41 00 5a'));
        self::assertSame('', hex2ascii(''));
        self::assertSame('41005A', ascii2hex("A\0Z"));
        self::assertSame('', ascii2hex(''));
    }

    public function testCsvDownloadLinkOutputAndSuppression()
    {
        $_SERVER['REQUEST_URI'] = '/index.php?action=all';
        ob_start();
        csv_download_link();
        $withoutCount = ob_get_clean();
        self::assertStringContainsString('/index.php?action=all&amp;format=csv', $withoutCount);
        self::assertStringNotContainsString('Umsätze gefunden', $withoutCount);

        ob_start();
        csv_download_link(3);
        $withCount = ob_get_clean();
        self::assertStringContainsString('3 Umsätze gefunden', $withCount);

        // The request URI is reflected in an attribute and HTML-encoded.
        $_SERVER['REQUEST_URI'] = '/index.php?action=all&filter_date=2024&x="><s>\'&amp;';
        ob_start();
        csv_download_link();
        self::assertSame('<br style="clear: both;" /><center><a href="/index.php?action=all&amp;filter_date=2024&amp;x=&quot;&gt;&lt;s&gt;&#039;&amp;amp;&amp;format=csv">Als CSV Datei herunterladen</a></center>', ob_get_clean());

        $GLOBALS['make_csv'] = true;
        ob_start();
        $result = csv_download_link(3);
        self::assertSame('', ob_get_clean());
        self::assertNull($result);
    }

    /**
     * @dataProvider csvDownloadLinkUriProvider
     */
    public function testCsvDownloadLinkSeparator($uri, $expected)
    {
        $_SERVER['REQUEST_URI'] = $uri;
        ob_start();
        csv_download_link();
        self::assertSame('<br style="clear: both;" /><center><a href="' . $expected . '">Als CSV Datei herunterladen</a></center>', ob_get_clean());
    }

    public static function csvDownloadLinkUriProvider()
    {
        return array(
            'no query string' => array('/index.php', '/index.php?format=csv'),
            'root' => array('/', '/?format=csv'),
            'empty' => array('', '?format=csv'),
            'trailing question mark' => array('/index.php?', '/index.php?format=csv'),
            'trailing ampersand' => array('/index.php?action=all&', '/index.php?action=all&amp;format=csv'),
            'already csv' => array('/index.php?action=all&format=csv', '/index.php?action=all&amp;format=csv'),
            'csv first' => array('/index.php?format=csv&action=all', '/index.php?format=csv&amp;action=all'),
            'other format' => array('/index.php?action=all&format=csvx', '/index.php?action=all&amp;format=csvx&amp;format=csv'),
            'format in path' => array('/x&format=csv/index.php', '/x&amp;format=csv/index.php?format=csv'),
            'format in path with query' => array('/x&format=csv&/index.php?action=all', '/x&amp;format=csv&amp;/index.php?action=all&amp;format=csv'),
        );
    }

    /**
     * @dataProvider filterProvider
     */
    public function testGetfilterBuildsExpectedConditions(array $get, $expected)
    {
        $_GET = $get;
        self::assertSame($expected, getfilter());
    }

    public static function filterProvider()
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
                " AND (ack1 LIKE '%Peter%' OR ack2 LIKE '%Peter%')  AND (ack1_old LIKE '%Old%' OR ack2_old LIKE '%Old%') "
            ),
            'old acknowledgements alone' => array(
                array('filter_ack_old' => 'Old'),
                " AND (ack1_old LIKE '%Old%' OR ack2_old LIKE '%Old%') "
            ),
            'voucher name' => array(array('filter_name' => 'Anna'), " AND lower(vouchers.name) LIKE lower('%Anna%')"),
            'invalid values are ignored' => array(
                array('filter_id' => '1 OR 1=1', 'filter_date' => 'yesterday', 'filter_name' => 'x%'),
                ''
            ),
        );
    }

    public function testGetfilterSuppressesMalformedLegacyNumericRegex()
    {
        $_GET = array('filter_amount' => '150');
        self::assertSame('', @getfilter());
    }

    /**
     * @dataProvider tagProvider
     */
    public function testTagRendersHtmlAndCsv(array $args, $expectedHtml, $expectedCsv)
    {
        list($tagName, $text) = $args;
        $GLOBALS['make_csv'] = false;
        self::assertSame($expectedHtml, tag($tagName, $text));
        $GLOBALS['make_csv'] = true;
        self::assertSame($expectedCsv, tag($tagName, $text));
    }

    public static function tagProvider()
    {
        return array(
            'row and newline flattening' => array(array('tr', "line 1\nline 2"), "<tr>line 1\nline 2</tr>\n", "line 1 / line 2\n"),
            'cell and euro decimal' => array(array('td', '12.50€'), "<td>12.50€</td>\n", "12,50€\t"),
            'other tag' => array(array('th', 'Header'), "<th>Header</th>\n", 'Header'),
            'negative euro' => array(array('td', '-12.5€'), "<td>-12.5€</td>\n", "-12,5€\t"),
            // CSV cells that a spreadsheet would read as a formula get a
            // leading single quote; HTML and amounts are unchanged.
            'formula with =' => array(array('td', '=HYPERLINK("x")'), "<td>=HYPERLINK(\"x\")</td>\n", "'=HYPERLINK(\"x\")\t"),
            'formula with +' => array(array('td', '+A1'), "<td>+A1</td>\n", "'+A1\t"),
            'formula with -' => array(array('td', '-2+3'), "<td>-2+3</td>\n", "'-2+3\t"),
            'formula with @' => array(array('td', '@SUM(A1)'), "<td>@SUM(A1)</td>\n", "'@SUM(A1)\t"),
            // Tabs and line breaks inside a cell would split the
            // tab-separated cell or row.
            'tab in a cell' => array(array('td', "one\ttwo"), "<td>one\ttwo</td>\n", "one two\t"),
            'carriage return in a cell' => array(array('td', "one\rtwo"), "<td>one\rtwo</td>\n", "one / two\t"),
            'carriage return in other tags' => array(array('th', "one\rtwo"), "<th>one\rtwo</th>\n", 'one / two'),
            'windows line break in a cell' => array(array('td', "one\r\ntwo"), "<td>one\r\ntwo</td>\n", "one / two\t"),
            'leading tab' => array(array('td', "\t=1"), "<td>\t=1</td>\n", "' =1\t"),
            'formula after spaces' => array(array('td', '  @SUM(A1)'), "<td>  @SUM(A1)</td>\n", "'  @SUM(A1)\t"),
            'number after a space' => array(array('td', ' -5'), "<td> -5</td>\n", "' -5\t"),
            'formula after a vertical tab' => array(array('td', "\x0B=1"), "<td>\x0B=1</td>\n", "'\x0B=1\t"),
            'formula after a form feed' => array(array('td', "\f@x"), "<td>\f@x</td>\n", "'\f@x\t"),
            'text after a space' => array(array('td', ' x=1'), "<td> x=1</td>\n", " x=1\t"),
            // A leading double quote would start a quoted field that the
            // spreadsheet unquotes (and evaluates) or merges with the next cells.
            'leading double quote' => array(array('td', '"=1"'), "<td>\"=1\"</td>\n", "'\"=1\"\t"),
            'double quote after a space' => array(array('td', ' "x'), "<td> \"x</td>\n", "' \"x\t"),
            'double quote inside' => array(array('td', 'a"b'), "<td>a\"b</td>\n", "a\"b\t"),
            // Amounts have a decimal point; other characters are no amount.
            'formula with euro sign' => array(array('td', '-1+2€'), "<td>-1+2€</td>\n", "'-1+2€\t"),
            'euro amount without decimals' => array(array('td', '-12€'), "<td>-12€</td>\n", "-12€\t"),
            'text after an amount' => array(array('td', '-12.50€x'), "<td>-12.50€x</td>\n", "'-12.50€x\t"),
            'tabs of a row are kept' => array(array('tr', "a\tb\t"), "<tr>a\tb\t</tr>\n", "a\tb\t\n"),
            'formula character inside' => array(array('td', 'a=b+c-d@e'), "<td>a=b+c-d@e</td>\n", "a=b+c-d@e\t"),
            'formula character in other tags' => array(array('tr', '=x'), "<tr>=x</tr>\n", "=x\n"),
            'negative euro amount is no formula' => array(array('td', '-50.00€'), "<td>-50.00€</td>\n", "-50,00€\t"),
            'negative number without euro' => array(array('td', '-5'), "<td>-5</td>\n", "-5\t"),
            'negative decimal without euro' => array(array('td', '-5,25'), "<td>-5,25</td>\n", "-5,25\t"),
            'negative integer value' => array(array('td', -1000), "<td>-1000</td>\n", "-1000\t"),
            'number with a formula after it' => array(array('td', "-5\n=1"), "<td>-5\n=1</td>\n", "'-5 / =1\t"),
            'number with a trailing letter' => array(array('td', '-5x'), "<td>-5x</td>\n", "'-5x\t"),
            'letter before a number' => array(array('td', '-x5'), "<td>-x5</td>\n", "'-x5\t"),
            'plus number' => array(array('td', '+5'), "<td>+5</td>\n", "'+5\t"),
        );
    }

    public function testHtmlEscapeEncodesSpecialCharactersOnly()
    {
        self::assertSame('&lt;a href=&quot;x&quot;&gt;Tom &amp; Jerry&#039;s&lt;/a&gt;', html_escape('<a href="x">Tom & Jerry\'s</a>'));
        // Umlauts, the euro sign and other UTF-8 text pass unchanged.
        self::assertSame('Österreich: Grüße, Maß, 12 € (ÄÖÜäöüß)', html_escape('Österreich: Grüße, Maß, 12 € (ÄÖÜäöüß)'));
        self::assertSame('Österreich: Grüße, Maß &amp; 12 €', html_escape('Österreich: Grüße, Maß & 12 €'));
        // Byte-transparent: invalid UTF-8 is kept, not dropped (htmlspecialchars()
        // with UTF-8 returns '' for it on PHP 5.3 and with ENT_QUOTES alone on 8).
        self::assertSame("\xC3(&lt;\xFF", html_escape("\xC3(<\xFF"));
        // Entities in the data are shown as written.
        self::assertSame('&amp;amp; &amp;#039;', html_escape('&amp; &#039;'));
        self::assertSame('', html_escape(null));
        self::assertSame('', html_escape(''));
        self::assertSame('42', html_escape(42));
        self::assertSame('0', html_escape('0'));
    }

    public function testListingTextEscapesHtmlButNotCsv()
    {
        $GLOBALS['make_csv'] = false;
        self::assertSame('&lt;b&gt;Jörg &quot;Q&quot; O&#039;Brien &amp; Co&lt;/b&gt;', listing_text('<b>Jörg "Q" O\'Brien & Co</b>'));
        self::assertSame('', listing_text(null));
        $GLOBALS['make_csv'] = true;
        self::assertSame('<b>Jörg "Q" O\'Brien & Co</b>', listing_text('<b>Jörg "Q" O\'Brien & Co</b>'));
        self::assertNull(listing_text(null));
    }

    /**
     * @dataProvider nullTextTagProvider
     */
    public function testTagRendersNullTextLikeEmptyTextWithoutDiagnostics($tagName, $expectedHtml, $expectedCsv)
    {
        // Listings pass nullable columns (for example vouchers.name or
        // vouchers.contra_account) to tag(), also in CSV mode.
        $render = function () use ($tagName) {
            return tag($tagName, null);
        };
        $GLOBALS['make_csv'] = false;
        self::assertSame(
            array('return' => $expectedHtml, 'output' => '', 'diagnostics' => array()),
            self::callAndCaptureDiagnostics($render)
        );
        $GLOBALS['make_csv'] = true;
        self::assertSame(
            array('return' => $expectedCsv, 'output' => '', 'diagnostics' => array()),
            self::callAndCaptureDiagnostics($render)
        );
        self::assertSame($expectedCsv, tag($tagName, ''));
    }

    public static function nullTextTagProvider()
    {
        return array(
            'cell' => array('td', "<td></td>\n", "\t"),
            'row' => array('tr', "<tr></tr>\n", "\n"),
            'other tag' => array('b', "<b></b>\n", ''),
        );
    }

    /**
     * @dataProvider headerPageProvider
     */
    public function testAccHeaderRendersEachPageTitle($page, $title)
    {
        ob_start();
        acc_header(null, $page, 2024);
        $html = ob_get_clean();
        self::assertStringContainsString('<title>' . $title . '</title>', $html);
    }

    public static function headerPageProvider()
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

    public function testAccHeaderHidesAdminLinksWithoutRightsAndShowsDeletedLinkToBgf()
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

    public function testAccHeaderShowsDeletedLinkForExactRolesOnly()
    {
        foreach (array('bgf' => true, 'Rbgf' => true, 'root' => true, '2,xbgf,xroot' => false, 'BGF' => false) as $rights => $shown) {
            $GLOBALS['functions_test_rights'] = $rights;
            ob_start();
            acc_header(null, 'index', 2024);
            $html = ob_get_clean();
            self::assertSame($shown, strpos($html, 'action=deleted') !== false, $rights);
        }
    }

    public function testAccHeaderOffersNoWritePagesToReadOnlyRights()
    {
        $GLOBALS['functions_test_rights'] = 'bgf';
        ob_start();
        acc_header(null, 'index', 2024);
        $bgfHtml = ob_get_clean();
        $GLOBALS['functions_test_rights'] = 'Rbgf';
        ob_start();
        acc_header(null, 'index', 2024);
        $readOnlyHtml = ob_get_clean();
        $GLOBALS['functions_test_rights'] = 'Rbgf,2';
        ob_start();
        acc_header(null, 'index', 2024);
        $readOnlyTwoHtml = ob_get_clean();
        $writeLinks = array('<br /><a href="index.php?action=new">Erfassen</a>', 'action=import"', 'action=import2"');
        foreach ($writeLinks as $link) {
            self::assertStringContainsString($link, $bgfHtml);
            self::assertStringNotContainsString($link, $readOnlyHtml);
            self::assertStringContainsString($link, $readOnlyTwoHtml);
        }
        // Apart from the write links, read-only bgf gets the menu of bgf.
        self::assertSame(
            str_replace(
                '<a href="index.php?action=new">Erfassen</a><a href="index.php?action=import">Import</a>'
                    . '<a href="index.php?action=import2">Belege Import</a>',
                '',
                $bgfHtml
            ),
            $readOnlyHtml
        );
    }

    /**
     * @dataProvider organizationAllowedProvider
     */
    public function testOrganizationAllowed($lo, $rights, $expected)
    {
        $result = self::callAndCaptureDiagnostics(function () use ($lo, $rights) {
            return organization_allowed($lo, $rights);
        });
        self::assertSame(array('return' => $expected, 'output' => '', 'diagnostics' => array()), $result);
    }

    public static function organizationAllowedProvider()
    {
        return array(
            'bgf any organization' => array('7', 'bgf', true),
            'root alone is no organization' => array(7, 'root', false),
            'root with an organization' => array(3, 'root,3', true),
            'root with another organization' => array(4, 'root,3', false),
            'own organization' => array('2', '1,2', true),
            'own organization as integer' => array(2, '2', true),
            'foreign organization' => array('3', '1,2', false),
            'padded organization' => array('01', '1', true),
            'padded right' => array('3', '03', true),
            'padded both' => array(3, '1,003', true),
            'other number' => array('13', '1,3', false),
            'zero' => array('0', '00', true),
            'spaced right' => array('2', '1, 2', true),
            'spaced bgf' => array('7', '2, bgf', true),
            'spaced root' => array('7', ' root', false),
            'spaced read-only bgf' => array('7', ' Rbgf', false),
            'non-numeric organization' => array('2x', '2x', false),
            'empty token is not organization 0' => array('0', '1,', false),
            'empty organization' => array('', '0', false),
            'read-only bgf is not bgf' => array('7', 'Rbgf', false),
            'substring of root' => array('7', 'xroot', false),
            'no rights' => array('10', '', false),
            'null rights' => array('10', null, false),
        );
    }

    /**
     * @dataProvider writeRightsProvider
     */
    public function testWriteRights($rights, $expected)
    {
        $result = self::callAndCaptureDiagnostics(function () use ($rights) {
            return write_rights($rights);
        });
        self::assertSame(array('return' => $expected, 'output' => '', 'diagnostics' => array()), $result);
    }

    public static function writeRightsProvider()
    {
        return array(
            'read-only bgf' => array('Rbgf', false),
            'read-only bgf with empty tokens' => array('Rbgf,', false),
            'read-only bgf with an organization' => array('Rbgf,2', '2'),
            'organization before read-only bgf' => array('1,Rbgf,2', '1,2'),
            'read-only bgf with bgf' => array('bgf,Rbgf', 'bgf'),
            'bgf unchanged' => array('bgf', 'bgf'),
            'admin unchanged' => array('root,bgf', 'root,bgf'),
            'organizations unchanged' => array('1,,2', '1,,2'),
            'empty rights unchanged' => array('', ''),
            'null rights unchanged' => array(null, null),
            'substring is not read-only bgf' => array('xRbgf', 'xRbgf'),
            'spaced read-only bgf' => array('Rbgf, 2', '2'),
            'spaced read-only bgf alone' => array(' Rbgf ', false),
        );
    }

    public function testBlockHelpersSuppressCsvAndRenderHtml()
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

    public function testAccFooterShowsLoginLinkOrLogoutForm()
    {
        ob_start();
        acc_footer();
        $anonymous = ob_get_clean();
        self::assertStringContainsString('action=login', $anonymous);
        self::assertStringNotContainsString('action=logout', $anonymous);

        $GLOBALS['functions_test_rights'] = 'root';
        $GLOBALS['csrf_token'] = str_repeat('ab', 32);
        ob_start();
        acc_footer();
        $authenticated = ob_get_clean();
        unset($GLOBALS['csrf_token']);
        // Logout is a POST form with the form token of the session.
        self::assertStringContainsString('<form class="inline" action="index.php?action=logout" method="post">'
            . '<input type="hidden" name="csrf_token" value="' . str_repeat('ab', 32) . '" />'
            . '<button class="link" type="submit">Logout</button></form>', $authenticated);
        self::assertStringNotContainsString('action=login', $authenticated);
    }

    /**
     * Runs $callback and returns its return value, its output and every PHP
     * diagnostic it raised (including deprecations, which PHPUnit 9 only
     * prints), so tests can assert that a call is diagnostic-free.
     */
    private static function callAndCaptureDiagnostics($callback)
    {
        $diagnostics = array();
        set_error_handler(function ($type, $message, $file, $line) use (&$diagnostics) {
            $diagnostics[] = $type . ' ' . basename($file) . ':' . $line . ' ' . $message;
            return true;
        });
        ob_start();
        // No "finally": the PHP 5.3 lane runs these tests (PHPUnit 4.8).
        // Throwable (PHP 7+) is only resolved when something is thrown.
        try {
            $return = call_user_func($callback);
            $output = ob_get_contents();
        } catch (Exception $exception) {
            ob_end_clean();
            restore_error_handler();
            throw $exception;
        } catch (Throwable $exception) {
            ob_end_clean();
            restore_error_handler();
            throw $exception;
        }
        ob_end_clean();
        restore_error_handler();
        return array('return' => $return, 'output' => $output, 'diagnostics' => $diagnostics);
    }

    // The real checklogin('rights', false) returns null, not '', for visitors
    // without a valid login cookie (index.php), so anonymous pages pass null.
    public function testAccHeaderTreatsNullRightsLikeEmptyRights()
    {
        $render = function () {
            acc_header(null, 'index', 2024);
        };
        $GLOBALS['functions_test_rights'] = '';
        $emptyRights = self::callAndCaptureDiagnostics($render);
        $GLOBALS['functions_test_rights'] = null;
        $nullRights = self::callAndCaptureDiagnostics($render);

        // Same output and the same diagnostics (on PHP 8.5 the implicit
        // PostgreSQL connection of percent_of_bookings() is reported for both).
        self::assertSame($emptyRights, $nullRights);
        self::assertStringContainsString('<title>Rechenschaftsbericht - Piratenpartei Österreichs</title>', $nullRights['output']);
        self::assertStringNotContainsString('action=new', $nullRights['output']);
        self::assertStringNotContainsString('action=accounts', $nullRights['output']);
    }

    public function testAccFooterTreatsNullRightsAsAnonymousWithoutDiagnostics()
    {
        $GLOBALS['functions_test_rights'] = null;
        $footer = self::callAndCaptureDiagnostics('acc_footer');
        self::assertSame(array(), $footer['diagnostics']);
        self::assertNull($footer['return']);
        self::assertStringContainsString('<a href="index.php?action=login">Login</a>', $footer['output']);
        self::assertStringNotContainsString('action=logout', $footer['output']);

        $GLOBALS['functions_test_rights'] = '';
        self::assertSame($footer, self::callAndCaptureDiagnostics('acc_footer'));
    }

    /**
     * @dataProvider formatDateProvider
     */
    public function testFormatDate($input, $expected)
    {
        self::assertSame($expected, format_date($input));
    }

    public static function formatDateProvider()
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
    public function testRights2orgasql($rights, $expected)
    {
        self::assertSame($expected, rights2orgasql($rights));
    }

    public function testRights2orgasqlDeniesNullRightsWithoutDiagnostics()
    {
        // Listing pages such as page_all() receive checklogin('rights'),
        // which is null for anonymous requests (after the login redirect header).
        $result = self::callAndCaptureDiagnostics(function () {
            return rights2orgasql(null);
        });
        self::assertSame(array('return' => ' AND false ', 'output' => '', 'diagnostics' => array()), $result);
    }

    public static function rightsProvider()
    {
        return array(
            'bgf' => array('bgf', ''),
            'capitalized bgf' => array('Rbgf', ''),
            'organization ids' => array('4,5', " AND orga IN ('4','5') "),
            'no organization ids' => array('reviewer', ' AND false '),
            'single organization' => array('4', " AND orga IN ('4') "),
            'empty rights deny access' => array('', ' AND false '),
            'empty tokens deny access' => array(',', ' AND false '),
            'root is not an exemption in this helper' => array('root', ' AND false '),
            'tokens are trimmed' => array('1, 2', " AND orga IN ('1','2') "),
            'trimmed bgf' => array('2, bgf', ''),
            'trimmed read-only bgf' => array(' Rbgf', ''),
            'root with an organization' => array('root,3', " AND orga IN ('3') "),
            'mixed role and organization' => array('reviewer,4', " AND orga IN ('4') "),
            'mixed valid and invalid IDs' => array('reviewer,4,5x,7', " AND orga IN ('4','7') "),
            'empty tokens do not add organizations' => array('reviewer,,4,', " AND orga IN ('4') "),
            'bgf token among other rights' => array('reviewer,bgf,4', ''),
            'Rbgf token among other rights' => array('reviewer,Rbgf,4', ''),
            'bgf substring does not grant access' => array('notbgf', ' AND false '),
            'Rbgf substring does not grant access' => array('notRbgf', ' AND false '),
            'bgf matching is case sensitive' => array('BGF', ' AND false '),
            'Rbgf matching is case sensitive' => array('rbgf', ' AND false '),
            'numeric suffix is not an ID' => array('role4', ' AND false '),
            'numeric prefix is not an ID' => array('4role', ' AND false '),
            'SQL-like token does not grant access' => array('4 OR 1=1', ' AND false '),
            'negative ID is rejected' => array('-4', ' AND false '),
            'decimal ID is rejected' => array('4.5', ' AND false '),
            'zero ID stays restricted' => array('0', " AND orga IN ('0') "),
            'leading zero ID stays restricted' => array('04', " AND orga IN ('04') "),
        );
    }

    /**
     * @dataProvider hasRightProvider
     */
    public function testHasRight($rights, $right, $expected)
    {
        $result = self::callAndCaptureDiagnostics(function () use ($rights, $right) {
            return has_right($rights, $right);
        });
        self::assertSame(array('return' => $expected, 'output' => '', 'diagnostics' => array()), $result);
    }

    public static function hasRightProvider()
    {
        return array(
            'bgf' => array('bgf', 'bgf', true),
            'bgf among other rights' => array('root,bgf', 'bgf', true),
            'root among other rights' => array('root,bgf', 'root', true),
            'read-only bgf is not bgf' => array('Rbgf', 'bgf', false),
            'Rbgf' => array('Rbgf,2', 'Rbgf', true),
            'substring is not a right' => array('notbgf', 'bgf', false),
            'case sensitive' => array('BGF', 'bgf', false),
            'organization id' => array('1,2', '2', true),
            'numeric comparison is not used' => array('02', '2', false),
            'tokens are trimmed' => array('bgf, root', 'root', true),
            'trimmed tokens stay exact' => array(' Rbgf ', 'bgf', false),
            'trimmed read-only bgf' => array('2, Rbgf', 'Rbgf', true),
            'empty rights' => array('', 'bgf', false),
            'null rights' => array(null, 'bgf', false),
        );
    }

    /**
     * @dataProvider accountProvider
     */
    public function testKonto2lo($account, $expected)
    {
        self::assertSame($expected, konto2lo($account));
    }

    public static function accountProvider()
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
    public function testGetsort($sort, $expected)
    {
        $_GET['sort'] = $sort;
        self::assertSame($expected, getsort());
    }

    public static function sortProvider()
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
    public function testGetoppsort($current, $requested, $expected)
    {
        $_GET['sort'] = $current;
        self::assertSame($expected, getoppsort($requested));
    }

    public static function oppositeSortProvider()
    {
        return array(
            'ascending to descending' => array('namea', 'namea', 'named'),
            'descending to ascending' => array('named', 'named', 'namea'),
            'different current sort' => array('datea', 'named', 'named'),
            'missing current sort' => array('', 'namea', 'namea'),
            'invalid direction' => array('namex', 'namex', 'namex'),
        );
    }

    public function testPercentOfBookingsUsesSyntheticDatabaseRows()
    {
        $text = percent_of_bookings();
        self::assertStringContainsString('2024: 7 Buchungszeilen, 4 (57.14 %) abgeschlossen, 2 (28.57 %) bearbeitet, 1 (14.29 %) unbearbeitet.', $text);
        self::assertStringNotContainsString('2023: ', $text);
    }

    public function testEyesWithAndWithoutNoeyesFlag()
    {
        self::assertSame(' NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL) ', eyes());
        $_GET['noeyes'] = '1';
        self::assertSame(' NOT deleted ', eyes());
    }

    public function testWhitelistKeepsOnlyPresentWhitelistedKeys()
    {
        self::assertSame(
            array('name' => 'Alice', 'zero' => 0),
            whitelist(array('name' => 'Alice', 'ignored' => 'x', 'zero' => 0), array('name' => true, 'zero' => true))
        );
    }

    public function testVouchersResetAckMovesCurrentAcknowledgementsToOldFields()
    {
        $voucherId = 987654321;
        $connection = pg_connect(pgacc_test_connection_string());
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
        } catch (Exception $exception) {
            pg_query($connection, 'ROLLBACK');
            throw $exception;
        } catch (Throwable $exception) {
            pg_query($connection, 'ROLLBACK');
            throw $exception;
        }
        // pg_connect() returned the shared default link; keep it open for
        // helpers in later tests, whatever the execution order. No "finally"
        // (PHP 5.3 lane), so both exits roll back explicitly.
        pg_query($connection, 'ROLLBACK');
    }
}
