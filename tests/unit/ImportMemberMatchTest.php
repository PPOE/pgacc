<?php

/*
 * The member lookup of the bank import (import.php): a line without a name is
 * matched against the member list (getusers(), here a synthetic list served
 * by the membership stub over verified HTTPS, MembershipStub, so no
 * membership system is contacted). The import runs inside a transaction that is rolled back.
 */

require_once dirname(dirname(__DIR__)) . '/getusers.php';
require_once dirname(dirname(__DIR__)) . '/import.php';
require_once __DIR__ . '/AppExitGuard.php';
require_once __DIR__ . '/MembershipStub.php';

final class ImportMemberMatchTest extends FunctionsTestCase
{
    /**
     * Imports one Handkassa line without a name against a member list with the
     * given name and returns the imported voucher's name and member id.
     */
    private static function importAgainstMember($memberName, $text)
    {
        $key = 'synthetic-import-member-key';
        // Padding members have spaces in their names; the import compares the
        // line without spaces, so they never match.
        $plain = "1\t7101\t$memberName\t38\t1\n";
        for ($i = 2; $i < 40; $i++) {
            $plain .= "$i\t" . (7100 + $i) . "\tPadding Member $i\t40\t0\n";
        }
        MembershipStub::serve(MembershipStub::encrypt($key, $plain));
        $csv = "Datum;Betrag;Konto;Text;Kommentar;Fremdkonto;Zweckwidmung;Kostenrückerstattung;Name;Mitgliedsnummer;Lo;Adresse;PLZ;Stadt\r\n"
            . "05.04.2024;5,00;Handkassa;$text;;;Nein;Nein;;0;1;;;\r\n";
        $path = tempnam(sys_get_temp_dir(), 'pgacc-import');
        file_put_contents($path, $csv);
        $_FILES = ['uploadcsv' => [
            'name' => ['members.csv'],
            'type' => ['text/csv'],
            'tmp_name' => [$path],
            'error' => [0],
            'size' => [strlen($csv)],
        ]];
        $datakey = isset($GLOBALS['datakey']) ? $GLOBALS['datakey'] : null;
        $GLOBALS['datakey'] = $key;
        $connection = pg_connect(pgacc_test_connection_string());
        pg_query($connection, 'BEGIN');
        $diagnostics = [];
        set_error_handler(function ($type, $message) use (&$diagnostics) {
            $diagnostics[] = $message;
            return true;
        });
        ob_start();
        try {
            AppExitGuard::enter('page_import()');
            page_import('bgf');
            AppExitGuard::leave();
            $result = pg_query_params($connection, 'SELECT name, member_id FROM vouchers WHERE comment = $1', [str_replace('|', "\n", $text)]);
            $row = pg_fetch_assoc($result);
            pg_free_result($result);
        } finally {
            $output = ob_get_clean();
            restore_error_handler();
            MembershipStub::reset();
            pg_query($connection, 'ROLLBACK');
            $GLOBALS['datakey'] = $datakey;
            $_FILES = [];
            unlink($path);
        }
        self::assertSame([], $diagnostics);
        self::assertStringContainsString('wurde erfolgreich importiert', $output);
        return $row;
    }

    // An upload error reports the file with its name encoded. Browsers send no
    // type with an upload error, so only a constructed upload reaches it.
    public function testUploadErrorShowsTheEncodedFileName()
    {
        $key = 'synthetic-import-member-key';
        $plain = '';
        for ($i = 1; $i < 40; $i++) {
            $plain .= "$i\t" . (7100 + $i) . "\tPadding Member $i\t40\t0\n";
        }
        MembershipStub::serve(MembershipStub::encrypt($key, $plain));
        $_FILES = ['uploadcsv' => [
            'name' => ['<i>a&b</i>.csv'],
            'type' => ['text/csv'],
            'tmp_name' => [''],
            'error' => [UPLOAD_ERR_PARTIAL],
            'size' => [100],
        ]];
        $datakey = isset($GLOBALS['datakey']) ? $GLOBALS['datakey'] : null;
        $GLOBALS['datakey'] = $key;
        $connection = pg_connect(pgacc_test_connection_string());
        pg_query($connection, 'BEGIN');
        ob_start();
        try {
            AppExitGuard::enter('page_import()');
            page_import('bgf');
            AppExitGuard::leave();
        } finally {
            $output = ob_get_clean();
            MembershipStub::reset();
            pg_query($connection, 'ROLLBACK');
            $GLOBALS['datakey'] = $datakey;
            $_FILES = [];
        }
        self::assertStringContainsString('Datei 1 (&lt;i&gt;a&amp;b&lt;/i&gt;.csv) wurde nicht erfolgreich hochgeladen', $output);
        self::assertStringNotContainsString('<i>a&b</i>', $output);
    }

    public function testQuotedMemberNameMatches()
    {
        self::assertSame(
            ['name' => "D'Arc", 'member_id' => '7101'],
            self::importAgainstMember("D'Arc", "Spende von D'Arc"),
        );
    }

    #[PHPUnit\Framework\Attributes\DataProvider('wildcardMemberProvider')]
    public function testMemberNamesAreNoPatterns($memberName, $text)
    {
        self::assertSame(
            ['name' => '', 'member_id' => '0'],
            self::importAgainstMember($memberName, $text),
        );
    }

    public static function wildcardMemberProvider()
    {
        return [
            'percent sign' => ['%', 'Spende Synthetic'],
            'underscore' => ['A_B', 'Spende AxB'],
            'backslash' => ['A\\\\', 'Spende Ab'],
            'empty name' => ['', 'Spende Synthetic'],
        ];
    }

    /**
     * A missing CSV field (null) converts to an empty string without a
     * deprecation; other fields are converted from ISO-8859-15.
     */
    public function testConvifOfNullIsEmptyAndDiagnosticFree()
    {
        $diagnostics = [];
        set_error_handler(function ($type, $message) use (&$diagnostics) {
            $diagnostics[] = "$type $message";
            return true;
        });
        try {
            $converted = convif(true, null);
            $umlaut = convif(true, "\xE4");
        } finally {
            restore_error_handler();
        }
        self::assertSame('', $converted);
        self::assertSame("\xC3\xA4", $umlaut);
        self::assertSame([], $diagnostics);
    }
}
