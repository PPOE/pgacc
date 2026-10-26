<?php

// year_nav() in functions.php: the year selector of the report and the
// donations page.
final class YearNavTest extends FunctionsTestCase
{
    public static function url($year)
    {
        return $year === null ? 'all?x=1&y=2' : "page?year=$year&x=1";
    }

    private static function nav($selected, $allowAll = false)
    {
        return year_nav($selected, [self::class, 'url'], $allowAll);
    }

    // The list items from the current year down to 2012, $selected marked.
    private static function items($selected)
    {
        $html = '';
        for ($i = intval(date('Y')); $i >= 2012; $i--) {
            $current = $i === $selected ? ' aria-current="page"' : '';
            $html .= "<li><a href=\"page?year=$i&amp;x=1\"$current>$i</a></li>";
        }
        return $html;
    }

    private static function prev($year)
    {
        return "<a class=\"year_nav_step year_nav_prev\" href=\"page?year=$year&amp;x=1\" aria-label=\"Vorheriges Jahr: $year\" title=\"Vorheriges Jahr: $year\">&lsaquo;</a>";
    }

    private static function next($year)
    {
        return "<a class=\"year_nav_step year_nav_next\" href=\"page?year=$year&amp;x=1\" aria-label=\"Nächstes Jahr: $year\" title=\"Nächstes Jahr: $year\">&rsaquo;</a>";
    }

    private const PREV_DISABLED = '<span class="year_nav_step year_nav_prev" role="link" aria-disabled="true" aria-label="Vorheriges Jahr">&lsaquo;</span>';
    private const NEXT_DISABLED = '<span class="year_nav_step year_nav_next" role="link" aria-disabled="true" aria-label="Nächstes Jahr">&rsaquo;</span>';

    private static function expected($label, $prev, $items, $next)
    {
        return '<nav class="year_nav" aria-label="Jahr">' . $prev
            . '<details class="year_nav_menu"><summary aria-label="Jahr wählen: ' . $label . '">' . $label . '</summary><ul>'
            . $items . '</ul></details>' . $next . '</nav>';
    }

    public function testMiddleYearLinksBothNeighbours()
    {
        self::assertSame(
            self::expected('2015', self::prev(2014), self::items(2015), self::next(2016)),
            self::nav(2015),
        );
        // The report passes the year of date('Y') as a string.
        self::assertSame(self::nav(2015), self::nav('2015'));
    }

    public function testFirstYearHasNoPreviousLink()
    {
        self::assertSame(
            self::expected('2012', self::PREV_DISABLED, self::items(2012), self::next(2013)),
            self::nav(2012),
        );
    }

    public function testCurrentYearHasNoNextLink()
    {
        $year = intval(date('Y'));
        self::assertSame(
            self::expected("$year", self::prev($year - 1), self::items($year), self::NEXT_DISABLED),
            self::nav($year),
        );
        self::assertSame(self::nav($year), self::nav(date('Y')));
    }

    public function testYearsOutsideTheRangeLinkBackIntoIt()
    {
        $year = intval(date('Y'));
        // No year is marked; the steps lead to the nearest year of the list.
        self::assertSame(
            self::expected('2011', self::PREV_DISABLED, self::items(null), self::next(2012)),
            self::nav(2011),
        );
        self::assertSame(
            self::expected('1999', self::PREV_DISABLED, self::items(null), self::next(2012)),
            self::nav(1999),
        );
        $future = $year + 3;
        self::assertSame(
            self::expected("$future", self::prev($year), self::items(null), self::NEXT_DISABLED),
            self::nav($future),
        );
        self::assertSame(
            self::expected('2012', self::PREV_DISABLED, self::items(2012), self::next(2013)),
            self::nav(2012),
        );
    }

    public function testValuesAreEscaped()
    {
        $html = year_nav(2015, fn($y) => "x?a=\"<$y>&'");
        self::assertStringContainsString('<li><a href="x?a=&quot;&lt;2012&gt;&amp;&#039;">2012</a></li>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('"<', $html);
    }

    public function testSelectedIsReadAsAnInteger()
    {
        // Never printed as given: a value with markup is its integer.
        self::assertSame(self::nav(2015), self::nav(' 2015'));
        self::assertSame(self::nav(0), self::nav('<b>"'));
        self::assertStringContainsString('<summary aria-label="Jahr wählen: 0">0</summary>', self::nav('<b>"'));
        // Without $allowAll, null is no year either.
        self::assertSame(self::nav(0), self::nav(null));
    }

    public function testAllYears()
    {
        $all = '<li><a href="all?x=1&amp;y=2">Alle Jahre</a></li>';
        $allCurrent = '<li><a href="all?x=1&amp;y=2" aria-current="page">Alle Jahre</a></li>';
        // Without $allowAll there is no "Alle Jahre" entry.
        self::assertStringNotContainsString('Alle Jahre', self::nav(2015));
        self::assertSame(
            self::expected('2015', self::prev(2014), $all . self::items(2015), self::next(2016)),
            self::nav(2015, true),
        );
        self::assertSame(
            self::expected('Alle Jahre', self::PREV_DISABLED, $allCurrent . self::items(null), self::NEXT_DISABLED),
            self::nav(null, true),
        );
    }
}
