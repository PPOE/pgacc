<?php

/*
 * The request matrix of the document root public/ and its allow list, shared
 * by every server that must apply it:
 *
 * - tests/http-docroot.php: PHP's built-in server with tests/runtime/router.php
 *   (the application service and a release with planted files);
 * - tests/web-server/run.sh: real nginx with the rendered site template
 *   deploy/ansible/templates/nginx-site.conf.j2, and Apache with
 *   public/.htaccess and the virtual host of UPGRADING.md.
 *
 * Request targets are sent as they are (no client-side normalisation).
 *
 * 'static': target => file below public/ that must be served byte for byte.
 * 'entry': target => [entry point, QUERY_STRING it must run with].
 * 'refused': target => status (400, 403 or 404); the answer must not show
 *     any file content.
 * 'planted': files web_server_plant() puts into a release (release root and
 *     public/), 'planted_public' files it puts into public/ only, and
 *     'planted_refused', the requests for them that only answer like this
 *     when they exist (a symbolic link is refused with 403).
 * 'entry_link': only for the real servers (tests/web-server/run.sh; the
 *     router of tests/http-docroot.php links the entry points itself): the
 *     entry point public/index.php replaced by a symbolic link to
 *     entry-sentinel.php in the release root must be refused with the
 *     status given per server, and the sentinel must not run.
 *
 * Where a server intentionally answers differently, the harness of that
 * server lists the target with its reason (WEB_SERVER_DIFFERENCES in
 * tests/web-server/check.php).
 */

return [
    'static' => [
        '/style.css?v=1' => 'style.css',
        '/js/vreport-chart.js?v=1' => 'js/vreport-chart.js',
        '/js/chart.umd.min.js' => 'js/chart.umd.min.js',
        '/icons/add.png' => 'icons/add.png',
        // Dot segments and repeated slashes are resolved before the allow
        // list applies, encoded characters are decoded (as nginx does).
        '/icons/../style.css' => 'style.css',
        '/./style.css' => 'style.css',
        '//style.css' => 'style.css',
        '/js//vreport-chart.js' => 'js/vreport-chart.js',
        '/js/%2e%2e/style.css' => 'style.css',
        '/%73tyle.css' => 'style.css',
        '/js%2fvreport-chart.js' => 'js/vreport-chart.js',
    ],
    'entry' => [
        '/' => ['index.php', ''],
        '/index.php' => ['index.php', ''],
        '/index.php?action=login' => ['index.php', 'action=login'],
        // The short page addresses: index.php?action=<name>, the query
        // string appended.
        '/donations?year=2024' => ['index.php', 'action=donations&year=2024'],
        '/vreport' => ['index.php', 'action=vreport'],
        '/js/../index.php' => ['index.php', ''],
        '//index.php' => ['index.php', ''],
        '/%69ndex.php' => ['index.php', ''],
    ],
    'refused' => [
        // Code, configuration and development files of the release root.
        '/functions.php' => 404,
        '/file.php' => 404,
        '/edit.php' => 404,
        '/getusers.php' => 404,
        '/impressum.php' => 404,
        '/constants.php' => 404,
        '/sync.php' => 404,
        '/check.php' => 404,
        // The former membership export (public/mbapi.php, removed).
        '/mbapi.php' => 404,
        '/migrate-receipt-key.php' => 404,
        '/src/db.php' => 404,
        '/src/sync-api.php' => 404,
        // The document root of the internal sync listener.
        '/internal/mbapi.php' => 404,
        '/internal/' => 404,
        '/vendor/autoload.php' => 404,
        '/vendor/' => 404,
        '/composer.json' => 404,
        '/composer.lock' => 404,
        '/tests/run.sh' => 404,
        '/tests/runtime/test-constants.php' => 404,
        '/files/' => 404,
        '/files' => 404,
        '/README.md' => 404,
        '/public/index.php' => 404,
        // public/ files and addresses outside the allow list.
        '/js/README.md' => 404,
        '/icons/readme.html' => 404,
        '/icons/' => 404,
        '/js/' => 404,
        '/style.css/' => 404,
        '/donations/' => 404,
        // No PATH_INFO: only the exact entry points run.
        '/index.php/x' => 404,
        '/index.php/' => 404,
        '/mbapi.php/x' => 404,
        '/index.php/../constants.php' => 404,
        '/index.php/..%2fconstants.php' => 404,
        // Case variants: the allow list is case-sensitive.
        '/INDEX.PHP' => 404,
        '/Index.php' => 404,
        '/MBAPI.PHP' => 404,
        '/STYLE.CSS' => 404,
        '/Donations' => 404,
        '/ICONS/add.png' => 404,
        // Dot files and backup, editor and dump copies (also in
        // capitals and encoded): 403.
        '/.htaccess' => 403,
        '/.git/config' => 403,
        '/js/.hidden.js' => 403,
        '/index.php~' => 403,
        '/index.php.bak' => 403,
        '/constants.php.bak' => 403,
        '/functions.php~' => 403,
        '/constants.php.save' => 403,
        '/functions.php.orig' => 403,
        '/dump.sql' => 403,
        '/pgacc.tar.gz' => 403,
        '/config.php_backup' => 403,
        '/CONSTANTS.PHP.BAK' => 403,
        '/js/../constants.php.bak' => 403,
        '/%63onstants.php.bak' => 403,
        '/icons/x.png.bak' => 403,
        // Above the document root: rejected as a bad request.
        '/../constants.php' => 400,
        '/%2e%2e/constants.php' => 400,
        '/js/../../constants.php' => 400,
        '/js/..%2f..%2fconstants.php' => 400,
    ],
    'planted' => [
        'constants.php.bak', 'functions.php~', 'constants.php.save', 'functions.php.orig', 'dump.sql',
        'pgacc.tar.gz', 'notes.txt', 'config.php_backup',
    ],
    'planted_public' => [
        'evil.php', 'js/evil.php', 'icons/planted.svg', 'icons/planted.gif', 'icons/planted.ico', 'js/planted.json',
        'planted.js', 'planted.png', 'icons/planted.js', 'icons/planted.css', 'js/planted.css', 'js/sub/planted.js',
    ],
    'planted_refused' => [
        // Not on the allow list: public/notes.txt and the 'planted_public'
        // files (PHP scripts, other file types, .js, .css and .png files
        // outside their directory or in a subdirectory).
        '/notes.txt' => 404,
        '/evil.php' => 404,
        '/js/evil.php' => 404,
        '/icons/planted.svg' => 404,
        '/icons/planted.gif' => 404,
        '/icons/planted.ico' => 404,
        '/js/planted.json' => 404,
        '/planted.js' => 404,
        '/planted.png' => 404,
        '/icons/planted.js' => 404,
        '/icons/planted.css' => 404,
        '/js/planted.css' => 404,
        '/js/sub/planted.js' => 404,
        // public/js/planted-link.js: a symbolic link to constants.php.bak in
        // the release root.
        '/js/planted-link.js' => 403,
    ],
    'entry_link' => [
        // nginx: try_files with disable_symlinks reports the refused link
        // as missing (404); Apache: public/.htaccess (-l) answers 403.
        '/index.php' => ['nginx' => 404, 'apache' => 403],
    ],
];
