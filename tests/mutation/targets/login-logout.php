<?php
/*
 * Login and logout handling: login() in index.php (password check, session
 * cookies, redirect target), the session check of checklogin(), the logout
 * and login_redirect() blocks that index.php answers before acc_header(),
 * login_redirect() and page_login() in login.php, and the page_new_buchung()
 * signature in new.php (compiled on every request). The helpers in
 * functions.php are covered by LoginSessionTest and Infection. This is page code that only the HTTP tests reach. See
 * tests/mutation/README.md for the format.
 */
$login = array('start' => 'function login($user, $pass)', 'end' => 'require("constants.php");');
$logout = array('start' => 'if ($page == "logout" && request_is_post())', 'end' => 'if ($page == "login" && login_redirect())');
$loginBlock = array('start' => 'if ($page == "login" && login_redirect())', 'end' => '$year = date(\'Y\');');
$loginRedirect = array('start' => 'function login_redirect()', 'end' => 'function page_login()');
$checklogin = array('start' => 'function checklogin(', 'end' => '$page = "index";');
$pageLogin = array('start' => 'function page_login()', 'end' => '<h1>Login erforderlich:</h1>');

// The early-answer blocks of index.php and the start of the page, used to
// move one block back after acc_header() (the ordering before the fix).
$logoutBlock = "if (\$page == \"logout\" && request_is_post())\n{\n"
    . "  // Ends the session on the server too: its cookies stop working even if\n"
    . "  // a copy was kept. Wrong or missing cookies end nothing.\n"
    . "  \$logoutId = checklogin('id', false);\n  if (\$logoutId > 0)\n"
    . "    pg_query(\$dbconn, \"UPDATE users SET cookie = NULL WHERE id = \$logoutId\");\n"
    . "  header(login_cookie_header(\"pp_pgacc_login\", \"deleted\", 0), false);\n"
    . "  header(login_cookie_header(\"pp_pgacc_id\", \"deleted\", 0), false);\n  header(\"Location: index.php\");\n"
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
            'search' => "if (\$line['success'] == 't' &&",
            'replace' => "if (\$line['success'] != 't' &&",
            'rationale' => 'Only a matching password hash may log in.',
        ),
        array(
            'id' => 'login-password-check-removed',
            'region' => $login,
            'search' => "if (\$line['success'] == 't' &&",
            'replace' => 'if (true &&',
            'rationale' => 'Fail-open: any password for an existing user logs in.',
        ),
        array(
            'id' => 'login-without-session',
            'region' => $login,
            'search' => '($token = login_start_session($dbconn, $line[\'id\'])) !== false',
            'replace' => '($token = login_start_session($dbconn, $line[\'id\'])) === false',
            'rationale' => 'A login needs a stored session; it fails only without a valid key, CSPRNG or stored session.',
        ),
        array(
            'id' => 'login-session-of-another-user',
            'region' => $login,
            'search' => 'login_start_session($dbconn, $line[\'id\'])',
            'replace' => 'login_start_session($dbconn, 1)',
            'rationale' => 'The session is started for the user who logged in.',
        ),
        array(
            'id' => 'login-cookie-renamed',
            'region' => $login,
            'search' => 'header(login_cookie_header("pp_pgacc_login", $token,',
            'replace' => 'header(login_cookie_header("pp_pgacc_session", $token,',
            'rationale' => 'checklogin() reads the pp_pgacc_login cookie.',
        ),
        array(
            'id' => 'login-cookie-value',
            'region' => $login,
            'search' => 'login_cookie_header("pp_pgacc_login", $token, 86400)',
            'replace' => 'login_cookie_header("pp_pgacc_login", $token . "0", 86400)',
            'rationale' => 'The login cookie is the token of the stored session.',
        ),
        array(
            'id' => 'login-cookie-expires-immediately',
            'region' => $login,
            'search' => 'login_cookie_header("pp_pgacc_login", $token, 86400)',
            'replace' => 'login_cookie_header("pp_pgacc_login", $token, -86400)',
            'rationale' => 'A browser discards an already expired cookie, so the session would be lost.',
        ),
        array(
            'id' => 'login-cookie-expiry',
            'region' => $login,
            'search' => 'login_cookie_header("pp_pgacc_login", $token, 86400)',
            'replace' => 'login_cookie_header("pp_pgacc_login", $token, 3600)',
            'rationale' => 'The login cookie lasts 24 hours, not one.',
        ),
        array(
            'id' => 'login-id-cookie-removed',
            'region' => $login,
            'search' => 'header(login_cookie_header("pp_pgacc_id", $id, 86400), false);',
            'replace' => 'true;',
            'rationale' => 'checklogin() also needs the user id cookie.',
        ),
        array(
            'id' => 'login-id-cookie-renamed',
            'region' => $login,
            'search' => 'login_cookie_header("pp_pgacc_id", $id,',
            'replace' => 'login_cookie_header("pp_pgacc_uid", $id,',
            'rationale' => 'checklogin() reads the pp_pgacc_id cookie.',
        ),
        array(
            'id' => 'login-id-cookie-value',
            'region' => $login,
            'search' => 'login_cookie_header("pp_pgacc_id", $id, 86400)',
            'replace' => 'login_cookie_header("pp_pgacc_id", 1, 86400)',
            'rationale' => 'The id cookie is the logged-in user, not always user 1.',
        ),
        array(
            'id' => 'login-id-cookie-without-flags',
            'region' => $login,
            'search' => 'header(login_cookie_header("pp_pgacc_id", $id, 86400), false);',
            'replace' => 'setcookie("pp_pgacc_id", $id, time()+86400);',
            'rationale' => 'The cookies are secure, HttpOnly and SameSite=Lax (setcookie() of PHP 5.3 cannot send SameSite).',
        ),
        array(
            'id' => 'login-id-cookie-replaces-login-cookie',
            'region' => $login,
            'search' => 'header(login_cookie_header("pp_pgacc_id", $id, 86400), false);',
            'replace' => 'header(login_cookie_header("pp_pgacc_id", $id, 86400), true);',
            'rationale' => 'Both Set-Cookie headers are sent; the second must not replace the first.',
        ),
        array(
            'id' => 'login-target-ignored',
            'region' => $login,
            'search' => 'header("Location: " . login_target($_POST[\'target\']));',
            'replace' => 'header("Location: index.php");',
            'rationale' => 'A login with a target returns to that page.',
        ),
        array(
            'id' => 'login-target-not-decoded',
            'region' => $login,
            'search' => 'header("Location: " . login_target($_POST[\'target\']));',
            'replace' => 'header("Location: " . $_POST[\'target\']);',
            'rationale' => 'The posted target is hex-encoded and must be decoded.',
        ),
        array(
            'id' => 'login-target-unchecked',
            'region' => $login,
            'search' => 'header("Location: " . login_target($_POST[\'target\']));',
            'replace' => 'header("Location: " . hex2ascii($_POST[\'target\']));',
            'rationale' => 'Only targets within the application are redirected to (open redirect, header injection).',
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
            'search' => "\$id = \$line['id'];",
            'replace' => "\$id = \$line['id'] + \$pgacc_undefined;",
            'rationale' => 'Same response, but a new (hidden) undefined-variable notice: the diagnostics '
                . 'check in the HTTP tests must kill it.',
        ),

        // The session check of checklogin() in index.php.
        array(
            'id' => 'checklogin-token-not-checked',
            'region' => $checklogin,
            'search' => "      if (!\$tokenMatches || !\$line || strval(\$line['cookie']) === '')\n        continue;",
            'replace' => "      if (false)\n        continue;",
            'rationale' => 'Fail-open: any pp_pgacc_login value would authenticate as the pp_pgacc_id user.',
        ),
        array(
            'id' => 'checklogin-logged-out-accepted',
            'region' => $checklogin,
            'search' => " || strval(\$line['cookie']) === ''",
            'replace' => '',
            'rationale' => 'A logged-out session (users.cookie NULL) has no token, not even the one of an empty nonce.',
        ),
        array(
            'id' => 'checklogin-implicit-connection',
            'region' => $checklogin,
            'search' => "\$data = pg_query(\$dbconn, \"SELECT * FROM users",
            'replace' => "\$data = pg_query(\"SELECT * FROM users",
            'rationale' => 'The session check passes its connection: PHP 8.5 deprecates the implicit one (displayed before the logout headers).',
        ),
        array(
            'id' => 'checklogin-token-check-inverted',
            'region' => $checklogin,
            'search' => 'if (!$tokenMatches ||',
            'replace' => 'if ($tokenMatches ||',
            'rationale' => 'Only the session token of the stored nonce and login time authenticates.',
        ),
        array(
            'id' => 'checklogin-missing-row-read',
            'region' => $checklogin,
            'search' => ' || !$line || ',
            'replace' => ' || ',
            'rationale' => 'Even a matching dummy token must not read fields from a missing row.',
        ),
        array(
            'id' => 'checklogin-stops-after-first-row',
            'region' => $checklogin,
            'search' => '} while ($line && ($line = pg_fetch_array($data, null, PGSQL_ASSOC)));',
            'replace' => '} while (false);',
            'rationale' => 'The schema does not guarantee unique IDs: a nonmatching first row must not hide a later matching session.',
        ),
        array(
            'id' => 'checklogin-nonce-is-token',
            'region' => $checklogin,
            'search' => 'login_token_equals($token, ',
            'replace' => 'login_token_equals($line[\'cookie\'], ',
            'rationale' => 'The stored nonce itself (the token format before the fix) must not authenticate.',
        ),
        array(
            'id' => 'checklogin-login-time-not-bound',
            'region' => $checklogin,
            'search' => "login_session_token(\$line['id'], \$line['cookie'], \$line['login'])",
            'replace' => "login_session_token(\$line['id'], \$line['cookie'], \$line['name'])",
            'rationale' => 'The token is bound to the login time of the session.',
        ),
        array(
            'id' => 'checklogin-session-expiry',
            'region' => $checklogin,
            'search' => "login > now() - '24 hours'::interval",
            'replace' => "login > now() - '48 hours'::interval",
            'rationale' => 'A session ends 24 hours after the login.',
        ),

        // The logout block of index.php, answered before acc_header().
        array(
            'id' => 'logout-session-kept',
            'region' => $logout,
            'search' => 'pg_query($dbconn, "UPDATE users SET cookie = NULL WHERE id = $logoutId");',
            'replace' => 'true;',
            'rationale' => 'Logout ends the session on the server: a kept copy of the cookies must stop working.',
        ),
        array(
            'id' => 'logout-implicit-connection',
            'region' => $logout,
            'search' => 'pg_query($dbconn, "UPDATE users SET cookie = NULL',
            'replace' => 'pg_query("UPDATE users SET cookie = NULL',
            'rationale' => 'Logout passes its connection: PHP 8.5 deprecates the implicit one (displayed before the logout headers).',
        ),
        array(
            'id' => 'logout-trusts-id-cookie',
            'region' => $logout,
            'search' => "\$logoutId = checklogin('id', false);",
            'replace' => "\$logoutId = isset(\$_COOKIE['pp_pgacc_id']) ? intval(\$_COOKIE['pp_pgacc_id']) : 0;",
            'rationale' => 'Only the session of valid cookies is ended, not that of any user id sent in a cookie.',
            'equivalent' => array('reason' => 'Since the form-token check, a logout POST reaches this block only with '
                . 'a valid form token, i.e. after checklogin() accepted the cookies: the id cookie is then the id of '
                . 'that session (intval() of the same digits). The guard stays as defence in depth.'),
        ),
        array(
            'id' => 'logout-without-session',
            'region' => $logout,
            'search' => 'if ($logoutId > 0)',
            'replace' => 'if (true)',
            'rationale' => 'Without a valid session there is nothing to end (the query would fail without an id).',
            'equivalent' => array('reason' => 'Since the form-token check, a logout POST without a valid session gets '
                . 'the token error before this block, so $logoutId is always positive here. The guard stays as '
                . 'defence in depth.'),
        ),
        array(
            'id' => 'logout-login-cookie-kept',
            'region' => $logout,
            'search' => 'header(login_cookie_header("pp_pgacc_login", "deleted", 0), false);',
            'replace' => 'true;',
            'rationale' => 'Logout clears the session cookie.',
        ),
        array(
            'id' => 'logout-login-cookie-renamed',
            'region' => $logout,
            'search' => 'login_cookie_header("pp_pgacc_login", "deleted",',
            'replace' => 'login_cookie_header("pp_pgacc_logon", "deleted",',
            'rationale' => 'Logout clears the pp_pgacc_login cookie, not another one.',
        ),
        array(
            'id' => 'logout-id-cookie-kept',
            'region' => $logout,
            'search' => 'header(login_cookie_header("pp_pgacc_id", "deleted", 0), false);',
            'replace' => 'true;',
            'rationale' => 'Logout clears the user id cookie.',
        ),
        array(
            'id' => 'logout-id-cookie-not-cleared',
            'region' => $logout,
            'search' => 'login_cookie_header("pp_pgacc_id", "deleted", 0)',
            'replace' => 'login_cookie_header("pp_pgacc_id", "0", 0)',
            'rationale' => 'Logout clears the id cookie ("deleted"), not sets it to a value.',
        ),
        array(
            'id' => 'logout-id-cookie-kept-alive',
            'region' => $logout,
            'search' => 'login_cookie_header("pp_pgacc_id", "deleted", 0)',
            'replace' => 'login_cookie_header("pp_pgacc_id", "deleted", 86400)',
            'rationale' => 'Logout deletes the cookie (Max-Age=0 and an expiry in the past).',
        ),
        array(
            'id' => 'logout-id-cookie-replaces-login-cookie',
            'region' => $logout,
            'search' => 'header(login_cookie_header("pp_pgacc_id", "deleted", 0), false);',
            'replace' => 'header(login_cookie_header("pp_pgacc_id", "deleted", 0), true);',
            'rationale' => 'Both cookies are cleared; the second Set-Cookie header must not replace the first.',
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
            'search' => 'header("Location: " . login_target($_POST[\'target\']));',
            'replace' => 'header("Location: " . $_POST[\'target\']);',
            'rationale' => 'The posted target is hex-encoded and must be decoded.',
        ),
        array(
            'id' => 'logged-in-target-unchecked',
            'file' => 'login.php',
            'region' => $loginRedirect,
            'search' => 'header("Location: " . login_target($_POST[\'target\']));',
            'replace' => 'header("Location: " . hex2ascii($_POST[\'target\']));',
            'rationale' => 'Only targets within the application are redirected to (open redirect, header injection).',
        ),
        array(
            'id' => 'logged-in-target-ignored',
            'file' => 'login.php',
            'region' => $loginRedirect,
            'search' => 'header("Location: " . login_target($_POST[\'target\']));',
            'replace' => 'header("Location: index.php");',
            'rationale' => 'An already logged-in user with a target is redirected to that page.',
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
        array(
            'id' => 'page-login-target-unchecked',
            'file' => 'login.php',
            'search' => "login_form_target(\$_GET['target'])",
            'replace' => "\$_GET['target']",
            'rationale' => 'The form keeps only allowed hex targets (reflected markup, open redirect).',
        ),
        array(
            'id' => 'page-login-target-dropped',
            'file' => 'login.php',
            'search' => "login_form_target(\$_GET['target'])",
            'replace' => "''",
            'rationale' => 'The form carries the target of the login redirect, so the login returns to that page.',
        ),

        // page_new_buchung() in new.php, compiled on every request.
        array(
            'id' => 'page-new-buchung-default-restored',
            'file' => 'new.php',
            'search' => 'function page_new_buchung($part, $part_to_remove,$rights,$add)',
            'replace' => 'function page_new_buchung($part = 0, $part_to_remove,$rights,$add)',
            'rationale' => 'The optional-before-required default is deprecated since PHP 8.0 and reported '
                . 'on every request that compiles new.php.',
        ),
    ),
);
