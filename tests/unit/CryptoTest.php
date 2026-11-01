<?php

/*
 * Contract tests for the mcrypt replacement (Rijndael-256 CBC with zero
 * padding) in functions.php. The expected bytes in
 * tests/fixtures/mcrypt/vectors.json were produced by the real mcrypt
 * extension (see tests/fixtures/mcrypt/generate-vectors.php), so existing
 * receipt files and the membership-system exchange stay readable.
 */

final class CryptoTest extends FunctionsTestCase
{
    private static function vectors($group)
    {
        $vectors = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/mcrypt/vectors.json'), true);
        $cases = [];
        foreach ($vectors[$group] as $vector) {
            $cases[$vector['name']] = [$vector];
        }
        return $cases;
    }

    public static function receiptProvider()
    {
        return self::vectors('receipts');
    }

    public static function receiptDecryptProvider()
    {
        return self::vectors('receipt_decrypts');
    }

    public static function getusersProvider()
    {
        return self::vectors('getusers');
    }

    #[PHPUnit\Framework\Attributes\DataProvider('receiptProvider')]
    public function testReceiptEncryptionMatchesMcrypt(array $vector)
    {
        $plain = hex2bin($vector['plain']);
        self::assertSame($vector['cipher'], bin2hex(receipt_encrypt($vector['id'], $vector['key'], $plain)));
        self::assertSame($vector['decrypted'], bin2hex(receipt_decrypt($vector['id'], $vector['key'], hex2bin($vector['cipher']))));
    }

    #[PHPUnit\Framework\Attributes\DataProvider('receiptDecryptProvider')]
    public function testReceiptDecryptionMatchesMcrypt(array $vector)
    {
        self::assertSame($vector['decrypted'], bin2hex(receipt_decrypt($vector['id'], $vector['key'], hex2bin($vector['cipher']))));
    }

    public function testReceiptCallSitesPassAnUndefinedKey()
    {
        // new.php, import2.php and file.php pass an undefined $key (null); see
        // the mcrypt item in tests/COMPATIBILITY_PLAN.md. That must stay the
        // empty-key derivation, or existing receipt files become unreadable.
        $vectors = self::vectors('receipts');
        $vector = $vectors['pdf document'][0];
        self::assertSame('', $vector['key']);
        self::assertSame($vector['cipher'], bin2hex(receipt_encrypt($vector['id'], null, hex2bin($vector['plain']))));
        self::assertSame($vector['decrypted'], bin2hex(receipt_decrypt($vector['id'], null, hex2bin($vector['cipher']))));
    }

    public function testMissingReceiptFileDecryptsLikeMcrypt()
    {
        // file.php passes false from file_get_contents() for a missing file;
        // mcrypt treated it as an empty string and decrypted one zero block.
        $vectors = self::vectors('receipt_decrypts');
        $vector = $vectors['empty ciphertext'][0];
        self::assertSame($vector['decrypted'], bin2hex(receipt_decrypt($vector['id'], $vector['key'], false)));
    }

    #[PHPUnit\Framework\Attributes\DataProvider('getusersProvider')]
    public function testMembershipImportMatchesMcrypt(array $vector)
    {
        // getusers.php: base64 ciphertext in, zero padding stripped with rtrim().
        self::assertSame(
            $vector['decrypted'],
            bin2hex(rtrim(membership_decrypt($vector['datakey'], base64_decode($vector['sData'])), "\0")),
        );
        $padded = membership_decrypt($vector['datakey'], base64_decode($vector['sData']));
        self::assertSame(0, strlen($padded) % 32);
        self::assertSame(hex2bin($vector['plain']), substr($padded, 0, strlen($vector['plain']) / 2));
    }

    public function testBlockSizeIs256Bits()
    {
        // Rijndael with a 256-bit block, not AES (128-bit block): the IV is a
        // whole 32-byte md5 hex string, and one byte of data becomes 32 bytes.
        $key = str_repeat('k', 32);
        $iv = str_repeat('i', 32);
        self::assertSame(32, strlen(rijndael256_encrypt($key, 'x', $iv)));
        self::assertSame(64, strlen(rijndael256_encrypt($key, str_repeat('x', 33), $iv)));
        self::assertSame(
            'x' . str_repeat("\0", 31),
            rijndael256_decrypt($key, rijndael256_encrypt($key, 'x', $iv), $iv),
        );
        self::assertNotSame(
            rijndael256_encrypt($key, 'x', $iv),
            rijndael256_encrypt($key, 'x', str_repeat('j', 32)),
        );
    }
}
