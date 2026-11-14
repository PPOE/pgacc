<?php

/*
 * The pure helpers of the member lookup of the edit page (getusers.php):
 * search words, the LIKE pattern, member numbers in a booking text and the
 * kind of a search. The database part is covered by tests/http-member-lookup.php.
 */

require_once dirname(dirname(__DIR__)) . '/getusers.php';

final class MemberLookupTest extends FunctionsTestCase
{
    public function testSearchTermsSplitOnWhitespaceAndAreLimited()
    {
        self::assertSame(['Anna', 'Muster'], member_search_terms("  Anna \t Muster\n"));
        self::assertSame(['a', 'b', 'c', 'd', 'e'], member_search_terms('a b c d e f g'));
        self::assertSame([str_repeat('ä', 50)], member_search_terms(str_repeat('ä', 60)));
        self::assertSame([], member_search_terms('   '));
        self::assertSame([], member_search_terms("\xff\xfe"));
    }

    public function testLikePatternEscapesWildcardsAndTheEscapeCharacter()
    {
        self::assertSame('%Anna%', member_like_pattern('Anna'));
        self::assertSame('%a!%b!_c!!d%', member_like_pattern('a%b_c!d'));
        self::assertSame('%back\\slash%', member_like_pattern('back\\slash'));
    }

    public function testTextNumbersAreThreeToSixDigitsEachOnce()
    {
        self::assertSame([123, 9001, 123456], member_text_numbers('MB 12 und 123, Nr.9001/123456; 1234567 x123 123'));
        self::assertSame([], member_text_numbers('ohne Nummer 12'));
        self::assertSame([], member_text_numbers(''));
    }

    public function testSearchQueryKinds()
    {
        self::assertSame(['id', 9001], member_search_query(' 9001 '));
        self::assertSame(['id', 123456789], member_search_query('123456789'));
        self::assertSame(['error', 'Mitgliedsnummer zu lang (höchstens 9 Ziffern).'], member_search_query('1234567890'));
        self::assertSame(['name', ['Anna', 'Muster']], member_search_query('Anna Muster'));
        self::assertSame(['name', ['Äöü']], member_search_query('Äöü'));
        self::assertSame(['name', ['a', 'b', 'c']], member_search_query('a b c'));
        self::assertSame(['error', 'Suchbegriff zu kurz (mindestens 3 Zeichen).'], member_search_query('ab'));
        self::assertSame(['error', 'Suchbegriff zu kurz (mindestens 3 Zeichen).'], member_search_query(' a b '));
        self::assertSame(['error', 'Suchbegriff zu kurz (mindestens 3 Zeichen).'], member_search_query(''));
        self::assertSame(['name', ['9001x']], member_search_query('9001x'));
    }
}
