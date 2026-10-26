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

    #[PHPUnit\Framework\Attributes\DataProvider('csvDownloadLinkUriProvider')]
    public function testCsvDownloadLinkSeparator($uri, $expected)
    {
        $_SERVER['REQUEST_URI'] = $uri;
        ob_start();
        csv_download_link();
        self::assertSame('<br style="clear: both;" /><center><a href="' . $expected . '">Als CSV Datei herunterladen</a></center>', ob_get_clean());
    }

    public static function csvDownloadLinkUriProvider()
    {
        return [
            'no query string' => ['/index.php', '/index.php?format=csv'],
            'root' => ['/', '/?format=csv'],
            'empty' => ['', '?format=csv'],
            'trailing question mark' => ['/index.php?', '/index.php?&amp;format=csv'],
            'trailing ampersand' => ['/index.php?action=all&', '/index.php?action=all&amp;&amp;format=csv'],
            'already csv' => ['/index.php?action=all&format=csv', '/index.php?action=all&amp;format=csv&amp;format=csv'],
            'later format wins' => ['/index.php?action=all&format=csv&format=html', '/index.php?action=all&amp;format=csv&amp;format=html&amp;format=csv'],
            'question mark in a value' => ['/index.php?action=all&filter_text=test?', '/index.php?action=all&amp;filter_text=test?&amp;format=csv'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('filterProvider')]
    public function testGetfilterBuildsExpectedConditions(array $get, $expected)
    {
        $_GET = $get;
        self::assertSame($expected, getfilter());
    }

    public static function filterProvider()
    {
        return [
            'empty' => [[], ''],
            'numeric ids and member flag' => [
                ['filter_id' => '12', 'filter_bid' => '34', 'filter_member' => '0', 'filter_member_id' => '56'],
                ' AND voucher_id = 12 AND id = 34 AND member  AND member_id = 56',
            ],
            'date year' => [['filter_date' => '2024'], " AND date >= '2024-01-01' AND date <= '2024-12-31' "],
            'date month' => [['filter_date' => '2024-02'], " AND date >= '2024-02-01' AND date < TIMESTAMP '2024-02-01' + INTERVAL '1 month' "],
            'date day' => [['filter_date' => '2024-02-29'], " AND date = '2024-02-29' "],
            'numeric and text type' => [
                ['filter_type' => '8'],
                ' AND type = 8',
            ],
            'type name' => [['filter_type' => 'Spende'], " AND lower(type.name) LIKE lower('%Spende%')"],
            'organization number and name' => [
                ['filter_lo' => '4'],
                " AND orga = 4 AND lower(lo.name) LIKE lower('%4%')",
            ],
            'organization name' => [['filter_lo' => 'Wien'], " AND lower(lo.name) LIKE lower('%Wien%')"],
            'contra account' => [['filter_gk' => '@bank 1'], " AND contra_account LIKE '@bank 1%'"],
            'amount operator' => [['filter_amount' => '>= 123'], ' AND amount >= 12300'],
            'amount with decimals' => [['filter_amount' => '< -12,50'], ' AND amount < -1250'],
            'account filters' => [
                ['filter_k' => 'AT 1', 'filter_vk' => 'AT 2'],
                " AND account LIKE 'AT 1%' AND vaccount LIKE 'AT 2%'",
            ],
            'comment text and comment gf' => [
                ['filter_text' => 'Piraten', 'filter_comment' => 'Geprüft'],
                " AND lower(comment) LIKE lower('%Piraten%') AND lower(commentgf) LIKE lower('%Geprüft%')",
            ],
            'committed and refund and attachment' => [
                ['filter_comm' => '1', 'filter_refund' => '1', 'filter_bel' => '1'],
                ' AND committed  AND refund  AND file != 0 ',
            ],
            'acknowledgements' => [
                ['filter_ack' => 'Peter', 'filter_ack_old' => 'Old'],
                " AND (ack1 LIKE '%Peter%' OR ack2 LIKE '%Peter%')  AND (ack1_old LIKE '%Old%' OR ack2_old LIKE '%Old%') ",
            ],
            'old acknowledgements alone' => [
                ['filter_ack_old' => 'Old'],
                " AND (ack1_old LIKE '%Old%' OR ack2_old LIKE '%Old%') ",
            ],
            'voucher name' => [['filter_name' => 'Anna'], " AND lower(vouchers.name) LIKE lower('%Anna%')"],
            'invalid values are ignored' => [
                ['filter_id' => '1 OR 1=1', 'filter_date' => 'yesterday', 'filter_name' => 'x%'],
                '',
            ],
            'array values are ignored' => [
                [
                    'filter_id' => ['1'],
                    'filter_bid' => ['1'],
                    'filter_date' => ['1'],
                    'filter_type' => ['1'],
                    'filter_lo' => ['1'],
                    'filter_member_id' => ['1'],
                    'filter_gk' => ['1'],
                    'filter_amount' => ['1'],
                    'filter_k' => ['1'],
                    'filter_vk' => ['1'],
                    'filter_text' => ['1'],
                    'filter_comment' => ['1'],
                    'filter_ack' => ['1'],
                    'filter_ack_old' => ['1'],
                    'filter_name' => ['1'],
                ],
                '',
            ],
        ];
    }

    public function testGetfilterSuppressesMalformedLegacyNumericRegex()
    {
        $_GET = ['filter_amount' => '150'];
        self::assertSame('', @getfilter());
    }

    #[PHPUnit\Framework\Attributes\DataProvider('tagProvider')]
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
        return [
            'row and newline flattening' => [['tr', "line 1\nline 2"], "<tr>line 1\nline 2</tr>\n", "line 1 / line 2\n"],
            'cell and euro decimal' => [['td', '12.50€'], "<td>12.50€</td>\n", "12,50€\t"],
            'other tag' => [['th', 'Header'], "<th>Header</th>\n", 'Header'],
            'negative euro' => [['td', '-12.5€'], "<td>-12.5€</td>\n", "-12,5€\t"],
            // CSV cells that a spreadsheet would read as a formula get a
            // leading single quote; HTML and amounts are unchanged.
            'formula with =' => [['td', '=HYPERLINK("x")'], "<td>=HYPERLINK(\"x\")</td>\n", "'=HYPERLINK(\"x\")\t"],
            'formula with +' => [['td', '+A1'], "<td>+A1</td>\n", "'+A1\t"],
            'formula with -' => [['td', '-2+3'], "<td>-2+3</td>\n", "'-2+3\t"],
            'formula with @' => [['td', '@SUM(A1)'], "<td>@SUM(A1)</td>\n", "'@SUM(A1)\t"],
            // Tabs and line breaks inside a cell would split the
            // tab-separated cell or row.
            'tab in a cell' => [['td', "one\ttwo"], "<td>one\ttwo</td>\n", "one two\t"],
            'carriage return in a cell' => [['td', "one\rtwo"], "<td>one\rtwo</td>\n", "one / two\t"],
            'carriage return in other tags' => [['th', "one\rtwo"], "<th>one\rtwo</th>\n", 'one / two'],
            'windows line break in a cell' => [['td', "one\r\ntwo"], "<td>one\r\ntwo</td>\n", "one / two\t"],
            'leading tab' => [['td', "\t=1"], "<td>\t=1</td>\n", "' =1\t"],
            'formula after spaces' => [['td', '  @SUM(A1)'], "<td>  @SUM(A1)</td>\n", "'  @SUM(A1)\t"],
            'number after a space' => [['td', ' -5'], "<td> -5</td>\n", "' -5\t"],
            'formula after a vertical tab' => [['td', "\x0B=1"], "<td>\x0B=1</td>\n", "'\x0B=1\t"],
            'formula after a form feed' => [['td', "\f@x"], "<td>\f@x</td>\n", "'\f@x\t"],
            'text after a space' => [['td', ' x=1'], "<td> x=1</td>\n", " x=1\t"],
            // A leading double quote would start a quoted field that the
            // spreadsheet unquotes (and evaluates) or merges with the next cells.
            'leading double quote' => [['td', '"=1"'], "<td>\"=1\"</td>\n", "'\"=1\"\t"],
            'double quote after a space' => [['td', ' "x'], "<td> \"x</td>\n", "' \"x\t"],
            'double quote inside' => [['td', 'a"b'], "<td>a\"b</td>\n", "a\"b\t"],
            // Amounts have a decimal point; other characters are no amount.
            'formula with euro sign' => [['td', '-1+2€'], "<td>-1+2€</td>\n", "'-1+2€\t"],
            'euro amount without decimals' => [['td', '-12€'], "<td>-12€</td>\n", "-12€\t"],
            'text after an amount' => [['td', '-12.50€x'], "<td>-12.50€x</td>\n", "'-12.50€x\t"],
            'tabs of a row are kept' => [['tr', "a\tb\t"], "<tr>a\tb\t</tr>\n", "a\tb\t\n"],
            'formula character inside' => [['td', 'a=b+c-d@e'], "<td>a=b+c-d@e</td>\n", "a=b+c-d@e\t"],
            'formula character in other tags' => [['tr', '=x'], "<tr>=x</tr>\n", "=x\n"],
            'negative euro amount is no formula' => [['td', '-50.00€'], "<td>-50.00€</td>\n", "-50,00€\t"],
            'negative number without euro' => [['td', '-5'], "<td>-5</td>\n", "-5\t"],
            'negative decimal without euro' => [['td', '-5,25'], "<td>-5,25</td>\n", "-5,25\t"],
            'negative integer value' => [['td', -1000], "<td>-1000</td>\n", "-1000\t"],
            'number with a formula after it' => [['td', "-5\n=1"], "<td>-5\n=1</td>\n", "'-5 / =1\t"],
            'number with a trailing letter' => [['td', '-5x'], "<td>-5x</td>\n", "'-5x\t"],
            'letter before a number' => [['td', '-x5'], "<td>-x5</td>\n", "'-x5\t"],
            'plus number' => [['td', '+5'], "<td>+5</td>\n", "'+5\t"],
        ];
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

    #[PHPUnit\Framework\Attributes\DataProvider('nullTextTagProvider')]
    public function testTagRendersNullTextLikeEmptyTextWithoutDiagnostics($tagName, $expectedHtml, $expectedCsv)
    {
        // Listings pass nullable columns (for example vouchers.name or
        // vouchers.contra_account) to tag(), also in CSV mode.
        $render = function () use ($tagName) {
            return tag($tagName, null);
        };
        $GLOBALS['make_csv'] = false;
        self::assertSame(
            ['return' => $expectedHtml, 'output' => '', 'diagnostics' => []],
            self::callAndCaptureDiagnostics($render),
        );
        $GLOBALS['make_csv'] = true;
        self::assertSame(
            ['return' => $expectedCsv, 'output' => '', 'diagnostics' => []],
            self::callAndCaptureDiagnostics($render),
        );
        self::assertSame($expectedCsv, tag($tagName, ''));
    }

    public static function nullTextTagProvider()
    {
        return [
            'cell' => ['td', "<td></td>\n", "\t"],
            'row' => ['tr', "<tr></tr>\n", "\n"],
            'other tag' => ['b', "<b></b>\n", ''],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('headerPageProvider')]
    public function testAccHeaderRendersEachPageTitle($page, $title)
    {
        ob_start();
        acc_header(null, $page, 2024);
        $html = ob_get_clean();
        self::assertStringContainsString('<title>' . $title . '</title>', $html);
    }

    public static function headerPageProvider()
    {
        return [
            'default' => ['index', 'Rechenschaftsbericht - Piratenpartei Österreichs'],
            'donations' => ['donations', 'Spenden - Piratenpartei Österreichs'],
            'goods donations' => ['kdonations', 'Sachspenden - Piratenpartei Österreichs'],
            'spendings' => ['spendings', 'Ausgaben - Piratenpartei Österreichs'],
            'campaign costs' => ['wk', 'Wahlkampfkosten - Piratenpartei Österreichs'],
            'transactions' => ['transactions', 'Kontobewegungen - Piratenpartei Österreichs'],
        ];
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
        foreach (['bgf' => true, 'Rbgf' => true, 'root' => true, '2,xbgf,xroot' => false, 'BGF' => false] as $rights => $shown) {
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
        $writeLinks = ['<br /><a href="index.php?action=new">Erfassen</a>', 'action=import"', 'action=import2"'];
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
                $bgfHtml,
            ),
            $readOnlyHtml,
        );
    }

    #[PHPUnit\Framework\Attributes\DataProvider('organizationAllowedProvider')]
    public function testOrganizationAllowed($lo, $rights, $expected)
    {
        $result = self::callAndCaptureDiagnostics(function () use ($lo, $rights) {
            return organization_allowed($lo, $rights);
        });
        self::assertSame(['return' => $expected, 'output' => '', 'diagnostics' => []], $result);
    }

    public static function organizationAllowedProvider()
    {
        return [
            'bgf any organization' => ['7', 'bgf', true],
            'root alone is no organization' => [7, 'root', false],
            'root with an organization' => [3, 'root,3', true],
            'root with another organization' => [4, 'root,3', false],
            'own organization' => ['2', '1,2', true],
            'own organization as integer' => [2, '2', true],
            'foreign organization' => ['3', '1,2', false],
            'padded organization' => ['01', '1', true],
            'padded right' => ['3', '03', true],
            'padded both' => [3, '1,003', true],
            'other number' => ['13', '1,3', false],
            'zero' => ['0', '00', true],
            'spaced right' => ['2', '1, 2', true],
            'spaced bgf' => ['7', '2, bgf', true],
            'spaced root' => ['7', ' root', false],
            'spaced read-only bgf' => ['7', ' Rbgf', false],
            'non-numeric organization' => ['2x', '2x', false],
            'empty token is not organization 0' => ['0', '1,', false],
            'empty organization' => ['', '0', false],
            'read-only bgf is not bgf' => ['7', 'Rbgf', false],
            'substring of root' => ['7', 'xroot', false],
            'no rights' => ['10', '', false],
            'null rights' => ['10', null, false],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('writeRightsProvider')]
    public function testWriteRights($rights, $expected)
    {
        $result = self::callAndCaptureDiagnostics(function () use ($rights) {
            return write_rights($rights);
        });
        self::assertSame(['return' => $expected, 'output' => '', 'diagnostics' => []], $result);
    }

    public static function writeRightsProvider()
    {
        return [
            'read-only bgf' => ['Rbgf', false],
            'read-only bgf with empty tokens' => ['Rbgf,', false],
            'read-only bgf with an organization' => ['Rbgf,2', '2'],
            'organization before read-only bgf' => ['1,Rbgf,2', '1,2'],
            'read-only bgf with bgf' => ['bgf,Rbgf', 'bgf'],
            'bgf unchanged' => ['bgf', 'bgf'],
            'admin unchanged' => ['root,bgf', 'root,bgf'],
            'organizations unchanged' => ['1,,2', '1,,2'],
            'empty rights unchanged' => ['', ''],
            'null rights unchanged' => [null, null],
            'substring is not read-only bgf' => ['xRbgf', 'xRbgf'],
            'spaced read-only bgf' => ['Rbgf, 2', '2'],
            'spaced read-only bgf alone' => [' Rbgf ', false],
        ];
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
        $diagnostics = [];
        set_error_handler(function ($type, $message, $file, $line) use (&$diagnostics) {
            $diagnostics[] = $type . ' ' . basename($file) . ':' . $line . ' ' . $message;
            return true;
        });
        ob_start();
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
        return ['return' => $return, 'output' => $output, 'diagnostics' => $diagnostics];
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

        // Same output and the same diagnostics.
        self::assertSame($emptyRights, $nullRights);
        self::assertStringContainsString('<title>Rechenschaftsbericht - Piratenpartei Österreichs</title>', $nullRights['output']);
        self::assertStringNotContainsString('action=new', $nullRights['output']);
        self::assertStringNotContainsString('action=accounts', $nullRights['output']);
    }

    public function testAccFooterTreatsNullRightsAsAnonymousWithoutDiagnostics()
    {
        $GLOBALS['functions_test_rights'] = null;
        $footer = self::callAndCaptureDiagnostics('acc_footer');
        self::assertSame([], $footer['diagnostics']);
        self::assertNull($footer['return']);
        self::assertStringContainsString('<a href="index.php?action=login">Login</a>', $footer['output']);
        self::assertStringNotContainsString('action=logout', $footer['output']);

        $GLOBALS['functions_test_rights'] = '';
        self::assertSame($footer, self::callAndCaptureDiagnostics('acc_footer'));
    }

    #[PHPUnit\Framework\Attributes\DataProvider('formatDateProvider')]
    public function testFormatDate($input, $expected)
    {
        self::assertSame($expected, format_date($input));
    }

    public static function formatDateProvider()
    {
        return [
            'day month year' => ['1.2.2024', '2024-2-1'],
            'timestamp' => ['2024-02-03 12:34:56', '2024-02-03'],
            'null' => ['', ''],
            'unrecognized' => ['2024/02/03', '2024/02/03'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('rightsProvider')]
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
        self::assertSame(['return' => ' AND false ', 'output' => '', 'diagnostics' => []], $result);
    }

    public static function rightsProvider()
    {
        return [
            'bgf' => ['bgf', ''],
            'capitalized bgf' => ['Rbgf', ''],
            'organization ids' => ['4,5', " AND orga IN ('4','5') "],
            'no organization ids' => ['reviewer', ' AND false '],
            'single organization' => ['4', " AND orga IN ('4') "],
            'empty rights deny access' => ['', ' AND false '],
            'empty tokens deny access' => [',', ' AND false '],
            'root is not an exemption in this helper' => ['root', ' AND false '],
            'tokens are trimmed' => ['1, 2', " AND orga IN ('1','2') "],
            'trimmed bgf' => ['2, bgf', ''],
            'trimmed read-only bgf' => [' Rbgf', ''],
            'root with an organization' => ['root,3', " AND orga IN ('3') "],
            'mixed role and organization' => ['reviewer,4', " AND orga IN ('4') "],
            'mixed valid and invalid IDs' => ['reviewer,4,5x,7', " AND orga IN ('4','7') "],
            'empty tokens do not add organizations' => ['reviewer,,4,', " AND orga IN ('4') "],
            'bgf token among other rights' => ['reviewer,bgf,4', ''],
            'Rbgf token among other rights' => ['reviewer,Rbgf,4', ''],
            'bgf substring does not grant access' => ['notbgf', ' AND false '],
            'Rbgf substring does not grant access' => ['notRbgf', ' AND false '],
            'bgf matching is case sensitive' => ['BGF', ' AND false '],
            'Rbgf matching is case sensitive' => ['rbgf', ' AND false '],
            'numeric suffix is not an ID' => ['role4', ' AND false '],
            'numeric prefix is not an ID' => ['4role', ' AND false '],
            'SQL-like token does not grant access' => ['4 OR 1=1', ' AND false '],
            'negative ID is rejected' => ['-4', ' AND false '],
            'decimal ID is rejected' => ['4.5', ' AND false '],
            'zero ID stays restricted' => ['0', " AND orga IN ('0') "],
            'leading zero ID stays restricted' => ['04', " AND orga IN ('04') "],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('hasRightProvider')]
    public function testHasRight($rights, $right, $expected)
    {
        $result = self::callAndCaptureDiagnostics(function () use ($rights, $right) {
            return has_right($rights, $right);
        });
        self::assertSame(['return' => $expected, 'output' => '', 'diagnostics' => []], $result);
    }

    public static function hasRightProvider()
    {
        return [
            'bgf' => ['bgf', 'bgf', true],
            'bgf among other rights' => ['root,bgf', 'bgf', true],
            'root among other rights' => ['root,bgf', 'root', true],
            'read-only bgf is not bgf' => ['Rbgf', 'bgf', false],
            'Rbgf' => ['Rbgf,2', 'Rbgf', true],
            'substring is not a right' => ['notbgf', 'bgf', false],
            'case sensitive' => ['BGF', 'bgf', false],
            'organization id' => ['1,2', '2', true],
            'numeric comparison is not used' => ['02', '2', false],
            'tokens are trimmed' => ['bgf, root', 'root', true],
            'trimmed tokens stay exact' => [' Rbgf ', 'bgf', false],
            'trimmed read-only bgf' => ['2, Rbgf', 'Rbgf', true],
            'empty rights' => ['', 'bgf', false],
            'null rights' => [null, 'bgf', false],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('accountProvider')]
    public function testKonto2lo($account, $expected)
    {
        self::assertSame($expected, konto2lo($account));
    }

    public static function accountProvider()
    {
        return [
            ['50110117270', 1], ['50110117300', 2], ['50110117318', 3],
            ['50110117326', 4], ['50110117350', 5], ['50110117369', 6],
            ['50110117393', 10], ['10110123642', 9], ['50110110437', 10],
            ['unknown-account', 'unknown-account'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('sortProvider')]
    public function testGetsort($sort, $expected)
    {
        $_GET['sort'] = $sort;
        self::assertSame($expected, getsort());
    }

    public static function sortProvider()
    {
        $default = 'date ASC, voucher_id DESC, vouchers.id ASC';
        return [
            'missing sort' => ['', $default],
            'unknown sort' => ['unknown', $default],
            'ida' => ['ida', 'voucher_id ASC, vouchers.id ASC'],
            'idd' => ['idd', 'voucher_id DESC, vouchers.id ASC'],
            'bida' => ['bida', 'vouchers.id ASC, voucher_id ASC'],
            'bidd' => ['bidd', 'vouchers.id DESC, voucher_id ASC'],
            'dated' => ['dated', 'date DESC, voucher_id DESC, vouchers.id ASC'],
            'datea' => ['datea', $default],
            'typea' => ['typea', 'type ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'typed' => ['typed', 'type DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'loa' => ['loa', 'orga ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'lod' => ['lod', 'orga DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'membera' => ['membera', 'member_id ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'memberd' => ['memberd', 'member_id DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'gka' => ['gka', 'contra_account DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'gkd' => ['gkd', 'contra_account ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'ka' => ['ka', 'account ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'kd' => ['kd', 'account DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'ama' => ['ama', 'amount ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'amd' => ['amd', 'amount DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'commenta' => ['commenta', 'commentgf ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'commentd' => ['commentd', 'commentgf DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'texta' => ['texta', 'comment ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'textd' => ['textd', 'comment DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'comma' => ['comma', 'committed DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'commd' => ['commd', 'committed ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'acka' => ['acka', 'ack1 ASC, ack2 ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'ackd' => ['ackd', 'ack1 DESC, ack2 DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'refunda' => ['refunda', 'refund ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'refundd' => ['refundd', 'refund DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'bela' => ['bela', 'file ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'beld' => ['beld', 'file DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'namea' => ['namea', 'name ASC, date ASC, voucher_id DESC, vouchers.id ASC'],
            'named' => ['named', 'name DESC, date ASC, voucher_id DESC, vouchers.id ASC'],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('oppositeSortProvider')]
    public function testGetoppsort($current, $requested, $expected)
    {
        $_GET['sort'] = $current;
        self::assertSame($expected, getoppsort($requested));
    }

    public static function oppositeSortProvider()
    {
        return [
            'ascending to descending' => ['namea', 'namea', 'named'],
            'descending to ascending' => ['named', 'named', 'namea'],
            'different current sort' => ['datea', 'named', 'named'],
            'missing current sort' => ['', 'namea', 'namea'],
            'invalid direction' => ['namex', 'namex', 'namex'],
        ];
    }

    public function testGetoppsortOfNullWithAnEmptySortIsDiagnosticFree()
    {
        $_GET['sort'] = '';
        $result = self::callAndCaptureDiagnostics(function () {
            return getoppsort(null);
        });
        self::assertNull($result['return']);
        self::assertSame([], $result['diagnostics']);
    }

    public function testPercentOfBookingsUsesSyntheticDatabaseRows()
    {
        $text = percent_of_bookings();
        self::assertStringStartsWith(
            '<div class="progress"><table><caption>Bearbeitungsstand der Buchungszeilen je Jahr</caption>'
            . '<colgroup><col class="year" /><col span="4" /><col class="bar" /></colgroup>'
            . '<thead><tr><th scope="col">Jahr</th><th scope="col">Buchungs&shy;zeilen</th>'
            . '<th scope="col"><span class="open" aria-hidden="true"></span>unbe&shy;arbeitet</th>'
            . '<th scope="col"><span class="edited" aria-hidden="true"></span>bear&shy;beitet</th>'
            . '<th scope="col"><span class="closed" aria-hidden="true"></span>abge&shy;schlossen</th>'
            . '<th class="bar" aria-hidden="true"></th></tr></thead><tbody><tr>',
            $text,
        );
        // Only two years of synthetic bookings: no older years to hide.
        self::assertStringEndsWith('</tr></tbody></table></div>', $text);
        self::assertStringNotContainsString('<details', $text);
        // Workflow order: unbearbeitet, bearbeitet, abgeschlossen; the bar
        // repeats the rounded percentages as segment widths.
        self::assertStringContainsString(
            '<tr><th scope="row">2024</th><td>7</td><td>1 <span class="percent">(14.29 %)</span></td>'
            . '<td>2 <span class="percent">(28.57 %)</span></td><td>4 <span class="percent">(57.14 %)</span></td>'
            . '<td class="bar"><span aria-hidden="true" title="2024: 14.29 % unbearbeitet, 28.57 % bearbeitet, 57.14 % abgeschlossen">'
            . '<span class="open" style="width: 14.29%"></span><span class="edited" style="width: 28.57%"></span>'
            . '<span class="closed" style="width: 57.14%"></span></span></td></tr>',
            $text,
        );
        self::assertStringNotContainsString('>2023<', $text);
    }

    public function testPercentOfBookingsHidesOlderYearsInATableWithTheSameColumns()
    {
        $year = intval(date('Y'));
        $text = self::percentOfBookingsForYears([2012, 2013, $year - 2, $year - 1, $year]);
        $older = substr($text, strpos($text, '<details'));
        $head = '<colgroup><col class="year" /><col span="4" /><col class="bar" /></colgroup>'
            . '<thead><tr><th scope="col">Jahr</th><th scope="col">Buchungs&shy;zeilen</th>'
            . '<th scope="col"><span class="open" aria-hidden="true"></span>unbe&shy;arbeitet</th>'
            . '<th scope="col"><span class="edited" aria-hidden="true"></span>bear&shy;beitet</th>'
            . '<th scope="col"><span class="closed" aria-hidden="true"></span>abge&shy;schlossen</th>'
            . '<th class="bar" aria-hidden="true"></th></tr></thead><tbody>';
        self::assertStringStartsWith(
            '<details class="more"><summary>Weitere Jahre anzeigen (2012&ndash;' . ($year - 2) . ')</summary>'
            . '<table class="older"><caption>Bearbeitungsstand der Buchungszeilen, weitere Jahre</caption>' . $head
            . '<tr><th scope="row">' . ($year - 2) . '</th><td>1</td><td>1 <span class="percent">(100 %)</span></td>',
            $older,
        );
        self::assertStringContainsString('</caption>' . $head . '<tr><th scope="row">' . $year . '</th>', $text);
        self::assertSame(1, substr_count($text, '<details'));
    }

    #[PHPUnit\Framework\Attributes\DataProvider('progressYearsProvider')]
    public function testPercentOfBookingsShowsTheTwoMostRecentYears(array $offsets, array $shown, array $older, $summary)
    {
        $year = intval(date('Y'));
        $relative = function (array $offsets) use ($year) {
            return array_map(function ($offset) use ($year) {
                return abs($offset) < 100 ? $year + $offset : $offset;
            }, $offsets);
        };
        $text = self::percentOfBookingsForYears($relative($offsets));
        $details = strpos($text, '<details');
        $visible = $details === false ? $text : substr($text, 0, $details);
        $hidden = $details === false ? '' : substr($text, $details);
        self::assertSame($relative($shown), self::progressRowYears($visible));
        self::assertSame($relative($older), self::progressRowYears($hidden));
        if ($summary === null) {
            self::assertFalse($details);
        } else {
            $summary = preg_replace_callback('/\{(-?\d+)\}/', function ($match) use ($year) {
                return strval($year + intval($match[1]));
            }, $summary);
            self::assertStringContainsString('<summary>Weitere Jahre anzeigen (' . $summary . ')</summary>', $hidden);
        }
    }

    // Years: offsets from the current year (0, -1, ...) or absolute years.
    public static function progressYearsProvider()
    {
        return [
            'current and previous year' => [[2012, 2013, -2, -1, 0], [0, -1], [-2, 2013, 2012], '2012&ndash;{-2}'],
            'next year is shown too' => [[2012, -1, 0, 1], [1, 0, -1], [2012], '2012'],
            'previous year missing' => [[2012, -3, -2, 0], [0, -2], [-3, 2012], '2012&ndash;{-3}'],
            'current year missing' => [[2012, -3, -2, -1], [-1, -2], [-3, 2012], '2012&ndash;{-3}'],
            'both missing' => [[2012, 2013, 2014], [2014, 2013], [2012], '2012'],
            'two years only' => [[2012, 0], [0, 2012], [], null],
            'one year only' => [[2013], [2013], [], null],
        ];
    }

    // percent_of_bookings() with one unacknowledged booking in each year.
    private static function percentOfBookingsForYears(array $years)
    {
        $connection = pg_connect(pgacc_test_connection_string());
        self::assertNotFalse($connection);
        pg_query($connection, 'BEGIN');
        try {
            pg_query($connection, 'UPDATE vouchers SET deleted = true');
            foreach ($years as $year) {
                // An explicit id: the rollback does not reset the id sequence,
                // and later workflow tests expect its values.
                pg_query_params($connection, 'INSERT INTO vouchers (id, voucher_id, date, deleted) VALUES ($1, $1, $2, false)', [987654000 + $year, "$year-06-15"]);
            }
            return percent_of_bookings();
        } finally {
            // The shared default link stays open for later tests.
            pg_query($connection, 'ROLLBACK');
        }
    }

    private static function progressRowYears($html)
    {
        preg_match_all('#<tr><th scope="row">(\d+)</th>#', $html, $matches);
        return array_map('intval', $matches[1]);
    }

    public function testPercentOfBookingsWithoutBookingsIsEmpty()
    {
        $connection = pg_connect(pgacc_test_connection_string());
        self::assertNotFalse($connection);
        pg_query($connection, 'BEGIN');
        try {
            pg_query($connection, 'UPDATE vouchers SET deleted = true');
            $text = percent_of_bookings();
        } finally {
            // The shared default link stays open for later tests.
            pg_query($connection, 'ROLLBACK');
        }
        self::assertSame('', $text);
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
            ['name' => 'Alice', 'zero' => 0],
            whitelist(['name' => 'Alice', 'ignored' => 'x', 'zero' => 0], ['name' => true, 'zero' => true]),
        );
    }

    public function testVouchersResetAckMovesCurrentAcknowledgementsToOldFields()
    {
        $voucherId = 987654321;
        $connection = pg_connect(pgacc_test_connection_string());
        self::assertNotFalse($connection);
        pg_query($connection, 'BEGIN');
        try {
            pg_query_params($connection, 'INSERT INTO vouchers (voucher_id, ack1, ack2, deleted) VALUES ($1, $2, $3, false)', [$voucherId, 'reviewer-one', 'reviewer-two']);
            vouchers_reset_ack($voucherId, '');
            $result = pg_query_params($connection, 'SELECT ack1, ack2, ack1_old, ack2_old FROM vouchers WHERE voucher_id = $1', [$voucherId]);
            self::assertSame(
                ['ack1' => null, 'ack2' => null, 'ack1_old' => 'reviewer-one', 'ack2_old' => 'reviewer-two'],
                pg_fetch_assoc($result),
            );
        } catch (Exception $exception) {
            pg_query($connection, 'ROLLBACK');
            throw $exception;
        } catch (Throwable $exception) {
            pg_query($connection, 'ROLLBACK');
            throw $exception;
        }
        // pg_connect() returned the shared default link; keep it open for
        // helpers in later tests, whatever the execution order. Both exits
        // roll back explicitly.
        pg_query($connection, 'ROLLBACK');
    }
}
