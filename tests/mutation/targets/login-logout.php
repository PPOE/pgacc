<?php
/*
 * Login and logout handling in index.php: login() (session cookies, password
 * check, redirect) and the "logout" branch of the page dispatcher. This is
 * top-level page code that only the HTTP tests reach. See
 * tests/mutation/README.md for the format.
 */
$login = array('start' => 'function login($user, $pass)', 'end' => 'require("constants.php");');
$logout = array('start' => 'else if ($page == "logout")', 'end' => 'else if ($page == "import")');

return array(
    'description' => 'index.php login() and logout branch (HTTP login/logout regressions)',
    'file' => 'index.php',
    'tests' => array(
        // Valid/rejected login, login with target, cookies authenticate,
        // logout, and the output_buffering=0 known failures.
        array('type' => 'http', 'script' => 'tests/http-login.php'),
    ),
    'timeout' => 60,
    'mutations' => array(
        array(
            'id' => 'login-password-check-inverted',
            'region' => $login,
            'search' => "if (\$line['success'] == 't')",
            'replace' => "if (\$line['success'] != 't')",
            'rationale' => 'Only a matching password hash may log in.',
        ),
        array(
            'id' => 'login-password-check-removed',
            'region' => $login,
            'search' => "if (\$line['success'] == 't')",
            'replace' => 'if (true)',
            'rationale' => 'Fail-open: any password for an existing user logs in.',
        ),
        array(
            'id' => 'login-session-not-stored',
            'region' => $login,
            'search' => 'pg_query("UPDATE users SET cookie = $rand, login = now() WHERE id = $id");',
            'replace' => 'true;',
            'rationale' => 'The session token must be stored, or the cookies cannot authenticate.',
        ),
        array(
            'id' => 'login-cookie-renamed',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_login", $rand,',
            'replace' => 'setcookie("pp_pgacc_session", $rand,',
            'rationale' => 'checklogin() reads the pp_pgacc_login cookie.',
        ),
        array(
            'id' => 'login-id-cookie-removed',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_id", $id, time()+86400);',
            'replace' => 'true;',
            'rationale' => 'checklogin() also needs the user id cookie.',
        ),
        array(
            'id' => 'login-cookie-expires-immediately',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_login", $rand, time()+86400);',
            'replace' => 'setcookie("pp_pgacc_login", $rand, time()-86400);',
            'rationale' => 'A browser discards an already expired cookie, so the session would be lost.',
        ),
        array(
            'id' => 'login-target-ignored',
            'region' => $login,
            'search' => 'header("Location: " . hex2ascii($_POST[\'target\']));',
            'replace' => 'header("Location: index.php");',
            'rationale' => 'A login with a target returns to that page.',
        ),
        array(
            'id' => 'login-default-redirect-removed',
            'region' => $login,
            'search' => "      else\n        header(\"Location: index.php\");",
            'replace' => "      else\n        true;",
            'rationale' => 'A successful login redirects (302) to index.php.',
        ),
        array(
            'id' => 'login-username-not-escaped',
            'region' => $login,
            'search' => '$user = pg_escape_string($user);',
            'replace' => '$user = $user;',
            'rationale' => 'The user name is interpolated into SQL and must be escaped.',
        ),
        array(
            'id' => 'login-raises-hidden-notice',
            'region' => $login,
            'search' => '$rand = mt_rand();',
            'replace' => '$rand = mt_rand() + $pgacc_undefined;',
            'rationale' => 'Same response, but a new (hidden) undefined-variable notice: the diagnostics '
                . 'check in the HTTP tests must kill it.',
        ),
        array(
            'id' => 'logout-login-cookie-kept',
            'region' => $logout,
            'search' => 'setcookie("pp_pgacc_login", "", time() - 3600);',
            'replace' => 'true;',
            'rationale' => 'Logout clears the session cookie.',
        ),
        array(
            'id' => 'logout-id-cookie-kept',
            'region' => $logout,
            'search' => 'setcookie("pp_pgacc_id", "", time() - 3600);',
            'replace' => 'true;',
            'rationale' => 'Logout clears the user id cookie.',
        ),
        array(
            'id' => 'logout-redirect-removed',
            'region' => $logout,
            'search' => 'header("Location: index.php");',
            'replace' => 'true;',
            'rationale' => 'Logout redirects (302) to index.php.',
        ),
        array(
            'id' => 'logout-redirect-target-changed',
            'region' => $logout,
            'search' => 'header("Location: index.php");',
            'replace' => 'header("Location: index.php?action=login");',
            'rationale' => 'Logout redirects to index.php itself.',
        ),
        array(
            'id' => 'logout-cookie-expiry-in-future',
            'region' => $logout,
            'search' => 'setcookie("pp_pgacc_login", "", time() - 3600);',
            'replace' => 'setcookie("pp_pgacc_login", "", time() + 3600);',
            'rationale' => 'Cookie deletion must not depend on the expiry argument.',
            'equivalent' => array(
                'reason' => 'setcookie() with an empty value always sends "deleted" with an expiry in 1970, '
                    . 'ignoring the expiry argument (PHP 5.6-8.5), so the response is byte-identical.',
            ),
        ),
    ),
);
