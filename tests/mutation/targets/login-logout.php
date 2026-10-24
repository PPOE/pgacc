<?php
/*
 * Login and logout handling: login() in index.php (password check, session
 * cookies, redirect), the logout and login_redirect() blocks that index.php
 * answers before acc_header(), login_redirect() and page_login() in
 * login.php, and the page_new_buchung() signature in new.php (compiled on
 * every request). This is page code that only the HTTP tests reach. See
 * tests/mutation/README.md for the format.
 */
$login = array('start' => 'function login($user, $pass)', 'end' => 'require("constants.php");');
$logout = array('start' => 'if ($page == "logout")', 'end' => 'if ($page == "login" && login_redirect())');
$loginBlock = array('start' => 'if ($page == "login" && login_redirect())', 'end' => '$year = date(\'Y\');');
$loginRedirect = array('start' => 'function login_redirect()', 'end' => 'function page_login()');
$pageLogin = array('start' => 'function page_login()', 'end' => '<h1>Login erforderlich:</h1>');

// The early-answer blocks of index.php and the start of the page, used to
// move one block back after acc_header() (the ordering before the fix).
$logoutBlock = "if (\$page == \"logout\")\n{\n  setcookie(\"pp_pgacc_login\", \"\", time() - 3600);\n"
    . "  setcookie(\"pp_pgacc_id\", \"\", time() - 3600);\n  header(\"Location: index.php\");\n"
    . "  pg_close(\$dbconn);\n  return;\n}\n";
$loginRedirectBlock = "if (\$page == \"login\" && login_redirect())\n{\n  pg_close(\$dbconn);\n  return;\n}\n";
$pageStart = "\n\$year = date('Y');\n\nif (!\$make_csv)\n  acc_header(\$dbconn,\$page,\$year);\n";

return array(
    'description' => 'Login and logout: index.php login(), early logout/login blocks, login.php, '
        . 'new.php page_new_buchung() signature (HTTP login/logout regressions)',
    'file' => 'index.php',
    'tests' => array(
        // Valid/rejected login, login with target, already logged in, cookie
        // attributes and database state, logout, empty redirect bodies, and
        // the same against an output_buffering=0 server.
        array('type' => 'http', 'script' => 'tests/http-login.php'),
    ),
    'timeout' => 60,
    'mutations' => array(
        // login() in index.php.
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
            'rationale' => 'The session token and login time must be stored, or the cookies cannot authenticate.',
        ),
        array(
            'id' => 'login-cookie-renamed',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_login", $rand,',
            'replace' => 'setcookie("pp_pgacc_session", $rand,',
            'rationale' => 'checklogin() reads the pp_pgacc_login cookie.',
        ),
        array(
            'id' => 'login-cookie-value',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_login", $rand, time()+86400);',
            'replace' => 'setcookie("pp_pgacc_login", $rand + 1, time()+86400);',
            'rationale' => 'The login cookie must carry the token stored in users.cookie.',
        ),
        array(
            'id' => 'login-cookie-expires-immediately',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_login", $rand, time()+86400);',
            'replace' => 'setcookie("pp_pgacc_login", $rand, time()-86400);',
            'rationale' => 'A browser discards an already expired cookie, so the session would be lost.',
        ),
        array(
            'id' => 'login-cookie-expiry',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_login", $rand, time()+86400);',
            'replace' => 'setcookie("pp_pgacc_login", $rand, time()+3600);',
            'rationale' => 'The login cookie lasts 24 hours, not one.',
        ),
        array(
            'id' => 'login-id-cookie-removed',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_id", $id, time()+86400);',
            'replace' => 'true;',
            'rationale' => 'checklogin() also needs the user id cookie.',
        ),
        array(
            'id' => 'login-id-cookie-renamed',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_id", $id,',
            'replace' => 'setcookie("pp_pgacc_uid", $id,',
            'rationale' => 'checklogin() reads the pp_pgacc_id cookie.',
        ),
        array(
            'id' => 'login-id-cookie-value',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_id", $id, time()+86400);',
            'replace' => 'setcookie("pp_pgacc_id", 1, time()+86400);',
            'rationale' => 'The id cookie is the logged-in user, not always user 1.',
        ),
        array(
            'id' => 'login-id-cookie-path',
            'region' => $login,
            'search' => 'setcookie("pp_pgacc_id", $id, time()+86400);',
            'replace' => 'setcookie("pp_pgacc_id", $id, time()+86400, "/");',
            'rationale' => 'The cookies keep their attributes (no path), so existing browsers keep matching them.',
        ),
        array(
            'id' => 'login-target-ignored',
            'region' => $login,
            'search' => 'header("Location: " . hex2ascii($_POST[\'target\']));',
            'replace' => 'header("Location: index.php");',
            'rationale' => 'A login with a target returns to that page.',
        ),
        array(
            'id' => 'login-target-not-decoded',
            'region' => $login,
            'search' => 'header("Location: " . hex2ascii($_POST[\'target\']));',
            'replace' => 'header("Location: " . $_POST[\'target\']);',
            'rationale' => 'The posted target is hex-encoded and must be decoded.',
        ),
        array(
            'id' => 'login-default-redirect-removed',
            'region' => $login,
            'search' => "      else\n        header(\"Location: index.php\");",
            'replace' => "      else\n        true;",
            'rationale' => 'A successful login redirects (302) to index.php.',
        ),
        array(
            'id' => 'login-default-redirect-target-changed',
            'region' => $login,
            'search' => "      else\n        header(\"Location: index.php\");",
            'replace' => "      else\n        header(\"Location: index.php?action=all\");",
            'rationale' => 'A successful login without target redirects to index.php itself.',
        ),
        array(
            'id' => 'login-username-not-escaped',
            'region' => $login,
            'search' => '$user = pg_escape_string($user);',
            'replace' => '$user = $user;',
            'rationale' => 'The user name is interpolated into SQL and must be escaped.',
        ),
        array(
            'id' => 'login-password-not-escaped',
            'region' => $login,
            'search' => '$pass = pg_escape_string($pass);',
            'replace' => '$pass = $pass;',
            'rationale' => 'The password is interpolated into SQL and must be escaped.',
        ),
        array(
            'id' => 'login-raises-hidden-notice',
            'region' => $login,
            'search' => '$rand = mt_rand();',
            'replace' => '$rand = mt_rand() + $pgacc_undefined;',
            'rationale' => 'Same response, but a new (hidden) undefined-variable notice: the diagnostics '
                . 'check in the HTTP tests must kill it.',
        ),

        // The logout block of index.php, answered before acc_header().
        array(
            'id' => 'logout-login-cookie-kept',
            'region' => $logout,
            'search' => 'setcookie("pp_pgacc_login", "", time() - 3600);',
            'replace' => 'true;',
            'rationale' => 'Logout clears the session cookie.',
        ),
        array(
            'id' => 'logout-login-cookie-renamed',
            'region' => $logout,
            'search' => 'setcookie("pp_pgacc_login", "",',
            'replace' => 'setcookie("pp_pgacc_logon", "",',
            'rationale' => 'Logout clears the pp_pgacc_login cookie, not another one.',
        ),
        array(
            'id' => 'logout-id-cookie-kept',
            'region' => $logout,
            'search' => 'setcookie("pp_pgacc_id", "", time() - 3600);',
            'replace' => 'true;',
            'rationale' => 'Logout clears the user id cookie.',
        ),
        array(
            'id' => 'logout-id-cookie-not-cleared',
            'region' => $logout,
            'search' => 'setcookie("pp_pgacc_id", "", time() - 3600);',
            'replace' => 'setcookie("pp_pgacc_id", "0", time() - 3600);',
            'rationale' => 'Logout clears the id cookie (empty value), not sets it to a value.',
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
            'id' => 'logout-continues',
            'region' => $logout,
            'search' => "  pg_close(\$dbconn);\n  return;",
            'replace' => "  true;\n  true;",
            'rationale' => 'Logout ends the request: the redirect body stays empty instead of rendering the default page.',
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
        array(
            'id' => 'logout-after-output',
            'search' => $logoutBlock . $loginRedirectBlock . $pageStart,
            'replace' => $loginRedirectBlock . $pageStart . $logoutBlock,
            'rationale' => 'The original ordering: logout after acc_header() has output the page. The cookies '
                . 'and redirect then depend on output buffering (fails with output_buffering=0).',
        ),

        // The login_redirect() block of index.php.
        array(
            'id' => 'login-continues',
            'region' => $loginBlock,
            'search' => "  pg_close(\$dbconn);\n  return;",
            'replace' => "  pg_close(\$dbconn);\n  true;",
            'rationale' => 'A completed login redirect ends the request (empty body, no page on a closed connection).',
        ),
        array(
            'id' => 'login-after-output',
            'search' => $loginRedirectBlock . $pageStart,
            'replace' => $pageStart . $loginRedirectBlock,
            'rationale' => 'The original ordering: login after acc_header() has output the page. The cookies '
                . 'and redirect then depend on output buffering (fails with output_buffering=0).',
        ),

        // login_redirect() in login.php.
        array(
            'id' => 'logged-in-guard-removed',
            'file' => 'login.php',
            'region' => $loginRedirect,
            'search' => "if (checklogin('id',false) > 0)",
            'replace' => 'if (false)',
            'rationale' => 'An already logged-in user is redirected to the posted target; posted credentials are '
                . 'not used for a new login.',
        ),
        array(
            'id' => 'logged-in-target-check-removed',
            'file' => 'login.php',
            'region' => $loginRedirect,
            'search' => "if (isset(\$_POST['target']))",
            'replace' => 'if (true)',
            'rationale' => 'An already logged-in user without a posted target gets the success page, not a redirect.',
        ),
        array(
            'id' => 'logged-in-target-not-decoded',
            'file' => 'login.php',
            'region' => $loginRedirect,
            'search' => 'header("Location: " . hex2ascii($_POST[\'target\']));',
            'replace' => 'header("Location: " . $_POST[\'target\']);',
            'rationale' => 'The posted target is hex-encoded and must be decoded.',
        ),
        array(
            'id' => 'logged-in-target-continues',
            'file' => 'login.php',
            'region' => $loginRedirect,
            'search' => "      return true;",
            'replace' => "      return false;",
            'rationale' => 'The target redirect of a logged-in user ends the request (empty body).',
        ),
        array(
            'id' => 'login-submit-check-negated',
            'file' => 'login.php',
            'region' => $loginRedirect,
            'search' => 'if ($submit == "Anmeldung")',
            'replace' => 'if ($submit != "Anmeldung")',
            'rationale' => 'Credentials are processed only for the form submission.',
        ),
        array(
            'id' => 'login-success-continues',
            'file' => 'login.php',
            'region' => $loginRedirect,
            'search' => 'return login($name, $pass) > 0;',
            'replace' => 'return login($name, $pass) < 0;',
            'rationale' => 'A successful login ends the request (empty redirect body).',
        ),
        array(
            'id' => 'rejected-login-ends-request',
            'file' => 'login.php',
            'region' => $loginRedirect,
            'search' => 'return login($name, $pass) > 0;',
            'replace' => 'return login($name, $pass) >= 0;',
            'rationale' => 'A rejected login renders the login form again.',
        ),

        // page_login() in login.php.
        array(
            'id' => 'page-login-guard-removed',
            'file' => 'login.php',
            'region' => $pageLogin,
            'search' => "if (checklogin('id',false) > 0)",
            'replace' => 'if (false)',
            'rationale' => 'An already logged-in user sees the success message, not the login form.',
        ),

        // page_new_buchung() in new.php, compiled on every request.
        array(
            'id' => 'page-new-buchung-default-restored',
            'file' => 'new.php',
            'search' => 'function page_new_buchung($part, $part_to_remove,$rights,$add)',
            'replace' => 'function page_new_buchung($part = 0, $part_to_remove,$rights,$add)',
            'rationale' => 'The optional-before-required default is deprecated since PHP 8.0 and reported '
                . 'on every request that compiles new.php.',
            'equivalent' => array(
                'php' => array('7.4'),
                'reason' => 'Before PHP 8.0 a default before required parameters is silently allowed and '
                    . 'never used (the only caller passes all four arguments), so nothing is observable. '
                    . 'PHP 8.0 and later report the deprecation, which the diagnostics check kills.',
            ),
        ),
    ),
);
