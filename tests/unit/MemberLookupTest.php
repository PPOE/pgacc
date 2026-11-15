<?php

/*
 * The pure helpers of the member lookup of the edit page (getusers.php):
 * member numbers in a booking text and the text of a member in the member
 * list and the suggestions. The database part is covered by
 * tests/http-member-lookup.php.
 */

require_once dirname(dirname(__DIR__)) . '/getusers.php';

final class MemberLookupTest extends FunctionsTestCase
{
    public function testTextNumbersAreThreeToSixDigitsEachOnce()
    {
        self::assertSame([123, 9001, 123456], member_text_numbers('MB 12 und 123, Nr.9001/123456; 1234567 x123 123'));
        self::assertSame([], member_text_numbers('ohne Nummer 12'));
        self::assertSame([], member_text_numbers(''));
    }

    public function testOptionTextHasOrganizationUnpaidMarkerAndIsEscaped()
    {
        self::assertSame('7801 – Mira Lookupova (Synthetic Unit)', member_option_text(['id' => '7801', 'name' => 'Mira Lookupova', 'lo' => 'Synthetic Unit', 'paid' => '1']));
        self::assertSame('7802 – Otto Lookupova (nicht bezahlt)', member_option_text(['id' => '7802', 'name' => 'Otto Lookupova', 'lo' => '', 'paid' => '0']));
        self::assertSame('7803 – Eve &lt;b&gt;&quot;T&quot; (A &amp; &lt;i&gt;)', member_option_text(['id' => '7803', 'name' => 'Eve <b>"T"', 'lo' => 'A & <i>', 'paid' => '1']));
    }
}
