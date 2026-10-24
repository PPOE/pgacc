<?php
/*
 * Asserts, under php-cgi, the php.ini values confirmed on production
 * (/etc/php5/cgi/php.ini there). The image keeps Debian squeeze's stock CGI
 * php.ini, which already has exactly these values; this check fails the build
 * if that ever stops being true. Run by tests/php53/runtime.Dockerfile.
 *
 * Must stay compatible with PHP 5.3.
 */

$expected = array(
    'output_buffering' => '4096',
    'short_open_tag' => '1',
    'display_errors' => '',
    'error_reporting' => '22527',
    'register_globals' => '',
    'magic_quotes_gpc' => '',
    'date.timezone' => '',
    'default_charset' => '',
);
$failed = false;
foreach ($expected as $name => $value) {
    $actual = ini_get($name);
    printf("php-cgi ini %s = %s\n", $name, var_export($actual, true));
    if ($actual !== $value) {
        fwrite(STDERR, "php-cgi ini $name is " . var_export($actual, true)
            . ', expected ' . var_export($value, true) . "\n");
        $failed = true;
    }
}
foreach (array('pgsql', 'mcrypt', 'mbstring', 'xml') as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "php-cgi extension $extension is not loaded\n");
        $failed = true;
    }
}
// Production: i686 (the server runs under "linux32"), with the Suhosin patch.
$suhosin = defined('SUHOSIN_PATCH_VERSION') ? constant('SUHOSIN_PATCH_VERSION') : null;
if (php_sapi_name() !== 'cgi-fcgi' || PHP_VERSION !== '5.3.3-7+squeeze19'
    || php_uname('m') !== 'i686' || PHP_INT_SIZE !== 4 || $suhosin === null) {
    fwrite(STDERR, 'unexpected runtime: ' . php_sapi_name() . ' ' . PHP_VERSION
        . ' ' . php_uname('m') . ' PHP_INT_SIZE=' . PHP_INT_SIZE
        . ' Suhosin patch ' . var_export($suhosin, true) . "\n");
    $failed = true;
}
printf("php-cgi %s %s on %s, PHP_INT_SIZE=%d, Suhosin patch %s\n",
    php_sapi_name(), PHP_VERSION, php_uname('m'), PHP_INT_SIZE, $suhosin);
exit($failed ? 1 : 0);
