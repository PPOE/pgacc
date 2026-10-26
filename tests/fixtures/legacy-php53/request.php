<?php
/*
 * One request against the original pgacc code (commit 6458a4d, the last one
 * with mcrypt; generate.sh extracts it to /app). Like index.php, it includes
 * the configuration (fixture-constants.php, a configured $key in global
 * scope), then the page files, and calls the page function. Run with php-cgi. RX_MODE=upload or
 * download.
 */
require '/fixture/fixture-constants.php';
require '/app/new.php';
require '/app/edit.php';
require '/app/file.php';
require '/app/functions.php';
$dbconn = pg_connect(getenv('RX_DATABASE'));
if (getenv('RX_MODE') === 'upload') {
    // The receipt form of the edit page: POST parts/bid0 and file0, GET id.
    $_POST = array('parts' => '1', 'bid0' => getenv('RX_BID'));
    $source = getenv('RX_SOURCE');
    $_FILES = array('file0' => array('name' => basename($source), 'tmp_name' => $source,
        'size' => filesize($source), 'type' => 'application/octet-stream', 'error' => 0));
    ob_start();
    page_new_file('bgf');   // ends with a redirect and exit
    echo "the upload did not redirect\n";
    exit(1);
}
// index.php?action=file&id=<row>
download_file('bgf');
