<?php

/*
 * Router of the test servers: php -S ... -t public tests/runtime/router.php.
 *
 * The built-in server serves every file below its document root. This router
 * applies the allow list of the nginx site (deploy/ansible/templates/
 * nginx-site.conf.j2), in the same order, so the tests see what production
 * serves. tests/web-server/matrix.php is the shared request matrix:
 * tests/http-docroot.php runs it against this router, tests/web-server/run.sh
 * against real nginx with the rendered template. Keep both in step.
 *
 * 1. The path is normalised as nginx does: percent-encoding decoded (an
 *    encoded "/" or "." counts like a plain one), repeated slashes merged,
 *    "." and ".." segments resolved; ".." above the document root, a NUL
 *    byte or a request target that is not a path answer 400.
 * 2. Dot files and backup copies: 403.
 * 3. The short page addresses (/donations, ...): index.php?action=<name>,
 *    the request's query string appended.
 * 4. "/", index.php and mbapi.php run; style.css, js/*.js and icons/*.png are
 *    served by the built-in server (return false); anything else 404.
 * 5. A served file whose path resolves through a symbolic link (nginx:
 *    disable_symlinks on from=$document_root) answers 403.
 *
 * Intentional differences from nginx: the error pages are plain text, and the
 * built-in server sends its own headers for static files (Content-Type,
 * no Cache-Control: no-cache). REQUEST_URI stays the requested target, as
 * with nginx.
 *
 * The built-in server of PHP 8.5 runs auto_prepend_file (diagnostics,
 * coverage) before the router, and again for a script the router leaves to
 * it (return false). So the router runs the entry points itself, in global
 * scope; only $pgacc_router_* variables, removed again before they run.
 */

function pgacc_router_answer($status)
{
    http_response_code($status);
    echo [400 => "400 Bad Request\n", 403 => "403 Forbidden\n", 404 => "404 Not Found\n"][$status];
    return true;
}

// The normalised path (nginx's ngx_http_parse_complex_uri), or null for a
// target nginx rejects with 400.
function pgacc_router_normalise($target)
{
    $path = rawurldecode(explode('?', $target, 2)[0]);
    if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
        return null;
    }
    $segments = [];
    $parts = explode('/', substr($path, 1));
    foreach ($parts as $index => $part) {
        if ($part === '..') {
            if (count($segments) === 0) {
                return null;
            }
            array_pop($segments);
        } elseif ($part !== '.' && ($part !== '' || $index === count($parts) - 1)) {
            $segments[] = $part;
        }
        // "/a/.." and "/a/." end in a slash.
        if (($part === '..' || $part === '.') && $index === count($parts) - 1) {
            $segments[] = '';
        }
    }
    return '/' . implode('/', $segments);
}

// True if $path below the document root is a file reached without a
// symbolic link (nginx with disable_symlinks on refuses any other).
function pgacc_router_plain_file($path)
{
    $root = realpath($_SERVER['DOCUMENT_ROOT']);
    $file = realpath($_SERVER['DOCUMENT_ROOT'] . $path);
    return $root !== false && $file === $root . $path;
}

$pgacc_router_path = pgacc_router_normalise($_SERVER['REQUEST_URI']);

if ($pgacc_router_path === null) {
    unset($pgacc_router_path);
    return pgacc_router_answer(400);
}

if (preg_match('#(^|/)\.#', $pgacc_router_path) === 1
    || preg_match('#(~|\.(bak|old|orig|save|swp|swo|tmp|deleteme|dist|sql|tar|tgz|zip|gz|7z|rar)|_backup|\.php[^/]+|(^|/)\#[^/]*\#)$#i', $pgacc_router_path) === 1) {
    unset($pgacc_router_path);
    return pgacc_router_answer(403);
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
    if (!pgacc_router_plain_file($pgacc_router_path)) {
        unset($pgacc_router_path, $pgacc_router_match);
        return pgacc_router_answer(403);
    }
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $pgacc_router_path;
    $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . $pgacc_router_path;
    unset($_SERVER['PATH_INFO'], $_SERVER['PATH_TRANSLATED']);
    unset($pgacc_router_path, $pgacc_router_match);
    return require $_SERVER['SCRIPT_FILENAME'];
}

if (preg_match('#^/(style\.css|js/[A-Za-z0-9._-]+\.js|icons/[A-Za-z0-9._-]+\.png)$#', $pgacc_router_path) === 1) {
    // The built-in server resolves the target the same way (dot segments,
    // slashes, encoding) and serves the file, or answers 404.
    $pgacc_router_status = !file_exists($_SERVER['DOCUMENT_ROOT'] . $pgacc_router_path)
        ? 404 : (pgacc_router_plain_file($pgacc_router_path) ? 0 : 403);
    unset($pgacc_router_path, $pgacc_router_match);
    if ($pgacc_router_status !== 0) {
        return pgacc_router_answer($pgacc_router_status);
    }
    unset($pgacc_router_status);
    return false;
}

unset($pgacc_router_path, $pgacc_router_match);
return pgacc_router_answer(404);
