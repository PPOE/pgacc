<?php

/*
 * Form tokens (CSRF) and POST-only writes: the central check in index.php,
 * the token that checklogin() derives for the session, the POST-only column
 * preferences and logout, merge and recovery (edit.php, recover.php), the
 * merge that moves no row, and the token fields of the forms that write.
 * The helpers in functions.php (csrf_session_token(), csrf_field(),
 * csrf_request_valid(), request_is_post()) are covered by LoginSessionTest
 * and Infection. See tests/mutation/README.md for the format.
 */
$csrfBlock = array('start' => '$csrf_exempt = in_array(', 'end' => 'if ($page == "search")');
$checklogin = array('start' => 'function checklogin(', 'end' => '$page = "index";');
$hide = array('start' => 'if (isset($_POST["hide"])', 'end' => '// relocate() (edit.php) answers');
$merge = array('start' => 'function page_edit_merge($rights)', 'end' => 'function page_edit_drop_acks($rights)');

return array(
    'description' => 'Form tokens and POST-only writes: index.php check, checklogin() token, column preferences, '
        . 'logout, merge, recovery and the token fields of the forms (HTTP CSRF and listing tests)',
    'file' => 'index.php',
    'tests' => array(
        // Missing, wrong and other-user tokens per write action, GET on the
        // converted actions, the token fields, valid tokens, re-login.
        array('type' => 'http', 'script' => 'tests/http-csrf.php'),
        // The column buttons of the listings and their POST.
        array('type' => 'http', 'script' => 'tests/http-listing-prefs.php'),
    ),
    'timeout' => 120,
    'mutations' => array(
        array(
            'id' => 'csrf-check-removed',
            'region' => $csrfBlock,
            'search' => 'if (request_is_post() && !$csrf_exempt)',
            'replace' => 'if (false && !$csrf_exempt)',
            'rationale' => 'Fail-open: POST requests without a valid form token write.',
        ),
        array(
            'id' => 'csrf-token-ignored',
            'region' => $csrfBlock,
            'search' => 'if (!csrf_request_valid())',
            'replace' => 'if (false)',
            'rationale' => 'Fail-open: any form token, or none, is accepted.',
        ),
        array(
            'id' => 'csrf-get-checked-instead',
            'region' => $csrfBlock,
            'search' => 'if (request_is_post() && !$csrf_exempt)',
            'replace' => 'if (!request_is_post() && !$csrf_exempt)',
            'rationale' => 'The token is checked on POST requests (GET pages need none).',
        ),
        array(
            'id' => 'csrf-exempt-merge',
            'region' => $csrfBlock,
            'search' => 'array("login","donations","kdonations")',
            'replace' => 'array("login","donations","kdonations","merge")',
            'rationale' => 'Only the login form and the public captcha forms are exempt.',
        ),
        array(
            'id' => 'csrf-exempt-nothing',
            'region' => $csrfBlock,
            'search' => 'array("login","donations","kdonations")',
            'replace' => 'array()',
            'rationale' => 'The login form and the public captcha forms have no session and need no token.',
        ),
        array(
            'id' => 'csrf-session-not-checked',
            'region' => $csrfBlock,
            'search' => "  checklogin('id', false);\n  if (!csrf_request_valid())",
            'replace' => "  true;\n  if (!csrf_request_valid())",
            'rationale' => 'Pages without the login check (logout) need the session of the request for its token.',
        ),
        array(
            'id' => 'csrf-error-continues',
            'region' => $csrfBlock,
            'search' => "    pg_close(\$dbconn);\n    return;\n  }\n}",
            'replace' => "    true;\n    true;\n  }\n}",
            'rationale' => 'A refused request ends after the token error: nothing is written.',
        ),
        array(
            'id' => 'csrf-error-message-missing',
            'region' => $csrfBlock,
            'search' => "echo '<div class=\"slot_error\" id=\"slot_error\">FEHLER: Ungültiges Formular-Token. Bitte die Seite neu laden.</div><br /><br /><br />';",
            'replace' => 'true;',
            'rationale' => 'A refused request says why.',
        ),
        array(
            'id' => 'csrf-token-not-derived',
            'region' => $checklogin,
            'search' => "\$csrf_token = csrf_session_token(\$line['id'], \$line['cookie'], \$line['login']);",
            'replace' => '$csrf_token = false;',
            'rationale' => 'An accepted session has a form token; without it no form works.',
        ),
        array(
            'id' => 'csrf-token-from-id-only',
            'region' => $checklogin,
            'search' => "\$csrf_token = csrf_session_token(\$line['id'], \$line['cookie'], \$line['login']);",
            'replace' => "\$csrf_token = csrf_session_token(\$line['id'], 0, '');",
            'rationale' => 'The token depends on the login (nonce and time): a new login replaces it.',
        ),
        array(
            'id' => 'hide-on-get',
            'region' => $hide,
            'search' => "if (isset(\$_POST[\"hide\"]) && !\$csrf_exempt)\n{\n  global \$user_id;\n  global \$user_prefs_hide;\n"
                . "  \$id = checklogin('id');\n  \$col = intval(\$_POST[\"hide\"]);",
            'replace' => "if (isset(\$_REQUEST[\"hide\"]) && !\$csrf_exempt)\n{\n  global \$user_id;\n  global \$user_prefs_hide;\n"
                . "  \$id = checklogin('id');\n  \$col = intval(\$_REQUEST[\"hide\"]);",
            'rationale' => 'The old GET link (?hide=N) stores no column preference.',
        ),
        array(
            'id' => 'hide-on-exempt-page',
            'region' => $hide,
            'search' => 'if (isset($_POST["hide"]) && !$csrf_exempt)',
            'replace' => 'if (isset($_POST["hide"]))',
            'rationale' => 'Column preferences are stored only on pages whose POST requests are token-checked.',
        ),
        array(
            'id' => 'logout-on-get',
            'search' => 'if ($page == "logout" && request_is_post())',
            'replace' => 'if ($page == "logout")',
            'rationale' => 'A GET request (old link) ends no session.',
        ),
        array(
            'id' => 'merge-on-get',
            'file' => 'edit.php',
            'region' => $merge,
            'search' => "if (!request_is_post())\n{",
            'replace' => "if (false)\n{",
            'rationale' => 'The old GET link merges nothing and points to the button.',
        ),
        array(
            'id' => 'merge-no-row-redirects',
            'file' => 'edit.php',
            'region' => $merge,
            'search' => 'if ($merged == 0)',
            'replace' => 'if (false)',
            'rationale' => 'A merge that moved no row reports "Buchungen nicht gefunden" instead of success.',
        ),
        array(
            'id' => 'merge-form-without-token',
            'file' => 'edit.php',
            'search' => "method=\"post\" style=\"display: none\">' . csrf_field() . '",
            'replace' => "method=\"post\" style=\"display: none\">' . '",
            'rationale' => 'The merge form of the edit page carries the form token.',
        ),
        array(
            'id' => 'merge-form-not-root-only',
            'file' => 'edit.php',
            'search' => "if (has_right(\$rights, 'root'))\n  echo '<form id=\"mergeform\"",
            'replace' => "if (true)\n  echo '<form id=\"mergeform\"",
            'rationale' => 'Only root gets the merge form.',
        ),
        array(
            'id' => 'recover-on-get',
            'file' => 'recover.php',
            'search' => 'if (request_is_post() && isset($_POST["doit"]))',
            'replace' => 'if (isset($_REQUEST["doit"]))',
            'rationale' => 'The old GET link (doit=1) only shows the confirmation form.',
        ),
        array(
            'id' => 'recover-form-without-token',
            'file' => 'recover.php',
            'search' => "method=\"post\">\n'.csrf_field().'\n",
            'replace' => "method=\"post\">\n'.''.'\n",
            'rationale' => 'The recovery form carries the form token.',
        ),
        array(
            'id' => 'voucher-form-without-token',
            'file' => 'new.php',
            'search' => "method=\"post\">\n'.csrf_field().'\n",
            'replace' => "method=\"post\">\n'.''.'\n",
            'rationale' => 'The voucher form (new and edit) carries the form token.',
        ),
        array(
            'id' => 'accounts-form-without-token',
            'file' => 'accounts.php',
            'search' => "method=\"POST\">'.csrf_field().'<table>",
            'replace' => "method=\"POST\">'.''.'<table>",
            'rationale' => 'The user list (account and password form) carries the form token.',
        ),
        array(
            'id' => 'column-form-without-token',
            'file' => 'closed.php',
            'search' => "method=\"post\">'.csrf_field().'<button",
            'replace' => "method=\"post\">'.'<button",
            'rationale' => 'The column buttons of the listings carry the form token.',
        ),
        array(
            'id' => 'logout-form-without-token',
            'file' => 'functions.php',
            'search' => "method=\"post\">' . csrf_field() . '<button class=\"link\" type=\"submit\">Logout",
            'replace' => "method=\"post\">' . '' . '<button class=\"link\" type=\"submit\">Logout",
            'rationale' => 'The Logout button carries the form token.',
        ),
    ),
);
