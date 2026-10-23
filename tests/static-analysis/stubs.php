<?php
// Declarations for PHPStan only; never executed or deployed.
//
// The application still calls the mcrypt extension, removed from PHP core in
// 7.2 and not installed in the analysis image. These signatures follow the
// PHP 5.6 manual so call sites are still type-checked; the removal itself is
// reported by PHPCompatibility.

const MCRYPT_RIJNDAEL_256 = 'rijndael-256';
const MCRYPT_MODE_CBC = 'cbc';

/**
 * @param string $cipher
 * @param string $key
 * @param string $data
 * @param string $mode
 * @param string $iv
 * @return string|false
 */
function mcrypt_encrypt($cipher, $key, $data, $mode, $iv = null)
{
}

/**
 * @param string $cipher
 * @param string $key
 * @param string $data
 * @param string $mode
 * @param string $iv
 * @return string|false
 */
function mcrypt_decrypt($cipher, $key, $data, $mode, $iv = null)
{
}
