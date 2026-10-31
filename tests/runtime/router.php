<?php

/*
 * Router of the test servers: php -S ... -t public tests/runtime/router.php.
 *
 * The built-in server serves every file below its document root. This router
 * applies the allow list of the nginx site (deploy/ansible/templates/
 * nginx-site.conf.j2), in the same order, so the tests see what production
 * serves: dot files and backup copies 403; the short page addresses
 * (/donations, ...) rewritten to index.php?action=<name> with the query
 * string appended; "/", index.php and mbapi.php run; style.css, js/*.js and
 * icons/*.png served by the built-in server (return false); anything else
 * 404. tests/http-docroot.php checks it. Keep both in step.
 *
 * The built-in server of PHP 8.5 runs auto_prepend_file (diagnostics,
 * coverage) before the router, and again for a script the router leaves to
 * it (return false). So the router runs the entry points itself, in global
 * scope; only $pgacc_router_* variables, removed again before they run.
 */

$pgacc_router_path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (preg_match('#(^|/)\.#', $pgacc_router_path) === 1
    || preg_match('#(~|\.(bak|old|orig|save|swp|swo|tmp|deleteme|dist|sql|tar|tgz|zip|gz|7z|rar)|_backup|\.php[^/]+|(^|/)\#[^/]*\#)$#i', $pgacc_router_path) === 1) {
    unset($pgacc_router_path);
    http_response_code(403);
    echo "403 Forbidden\n";
    return true;
}

if (preg_match('#^/(vreport|tinyreport|donations|kdonations|spendings|wk|transactions)$#', $pgacc_router_path, $pgacc_router_match) === 1) {
    // nginx: rewrite ^/(.+)$ /index.php?action=$1 last; (the request's query
    // string is appended, REQUEST_URI stays the requested address).
    $_SERVER['QUERY_STRING'] = 'action=' . $pgacc_router_match[1]
        . (($_SERVER['QUERY_STRING'] ?? '') === '' ? '' : '&' . $_SERVER['QUERY_STRING']);
    parse_str($_SERVER['QUERY_STRING'], $_GET);
    $_REQUEST = array_merge($_GET, $_POST);
    $pgacc_router_path = '/index.php';
} elseif ($pgacc_router_path === '/') {
    $pgacc_router_path = '/index.php';
}

if ($pgacc_router_path === '/index.php' || $pgacc_router_path === '/mbapi.php') {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $pgacc_router_path;
    $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . $pgacc_router_path;
    unset($pgacc_router_path, $pgacc_router_match);
    return require $_SERVER['SCRIPT_FILENAME'];
}

if (preg_match('#^/(style\.css|js/[A-Za-z0-9._-]+\.js|icons/[A-Za-z0-9._-]+\.png)$#', $pgacc_router_path) === 1) {
    unset($pgacc_router_path, $pgacc_router_match);
    return false;
}

unset($pgacc_router_path, $pgacc_router_match);
http_response_code(404);
echo "404 Not Found\n";
return true;
