<?php

/*
 * The entry points public/index.php and public/mbapi.php change to the
 * release root first and fail closed when they cannot (500, a logged
 * reason, nothing else runs); tests/http-docroot.php forbids it with
 * open_basedir. See tests/mutation/README.md for the format.
 */
return [
    'description' => 'Entry points: fail closed without the release root',
    'file' => 'public/index.php',
    'tests' => [
        ['type' => 'http', 'script' => 'tests/http-docroot.php'],
    ],
    'mutations' => [
        ['id' => 'index-chdir-failure-ignored',
            'search' => 'if (!chdir(dirname(__DIR__))) {', 'replace' => 'if (!chdir(dirname(__DIR__)) && false) {',
            'rationale' => 'index.php runs on in the document root and tries to load the code from there.'],
        ['id' => 'index-chdir-failure-200',
            'search' => '    http_response_code(500);', 'replace' => '    http_response_code(200);',
            'rationale' => 'The failure must not look like a successful empty page.'],
        ['id' => 'mbapi-chdir-failure-ignored', 'file' => 'public/mbapi.php',
            'search' => 'if (!chdir(dirname(__DIR__))) {', 'replace' => 'if (!chdir(dirname(__DIR__)) && false) {',
            'rationale' => 'mbapi.php runs on in the document root and tries to load the code from there.'],
        ['id' => 'mbapi-chdir-failure-200', 'file' => 'public/mbapi.php',
            'search' => '    http_response_code(500);', 'replace' => '    http_response_code(200);',
            'rationale' => 'The failure must not look like a successful empty export.'],
    ],
];
