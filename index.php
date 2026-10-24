<?php
if (empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) || $_SERVER['HTTP_X_FORWARDED_PROTO'] != 'https')
{
  header("Location: https://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"]);
  return;
}
global $realtype_req;
$realtype_req = "";//" AND realtype = id ";
global $user_id;
$user_id = 0;
global $user_prefs_hide;
$user_prefs_hide = array();
function login($user, $pass)
{
  $user = pg_escape_string($user);
  $pass = pg_escape_string($pass);
  $data = pg_query("SELECT id,hash = crypt('$pass',hash) AS success,rights FROM users WHERE name = '$user'") or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  if (!$data)
    return 0;
  while ($line = pg_fetch_array($data, null, PGSQL_ASSOC))
  {
    // Fails closed without a valid $key or a CSPRNG (functions.php).
    if ($line['success'] == 't' && ($nonce = login_new_nonce()) !== false)
    {
      $id = $line['id'];
      $session = pg_query("UPDATE users SET cookie = $nonce, login = now() WHERE id = $id RETURNING login");
      setcookie("pp_pgacc_login", login_session_token($id, $nonce, pg_fetch_result($session, 0, 0)), time()+86400);
      setcookie("pp_pgacc_id", $id, time()+86400);
      pg_free_result($data);
      if (isset($_POST['target']))
        header("Location: " . hex2ascii($_POST['target']));
      else
        header("Location: index.php");
      return $id;
    }
  }
  pg_free_result($data);
  return 0;
}
require("constants.php");
require("getusers.php");
require("new.php");
require("open.php");
require("mb.php");
require("all.php");
require("search.php");
require("closed.php");
require("transactions.php");
require("import.php");
require("import2.php");
require("edit.php");
require("deleted.php");
require("donations.php");
require("kdonations.php");
require("spendings.php");
require("login.php");
require("accounts.php");
require("impressum.php");
require("report.php");
require("vreport.php");
require("tinyreport.php");
require("wk.php");
require("recover.php");
require("file.php");
require("statistics.php");
require("vendor/autoload.php");
$dbconn = pg_connect("dbname=accounting")
  or die('Verbindungsaufbau fehlgeschlagen: ' . pg_last_error());

require("functions.php");

function checklogin($get = 'name', $redir = true)
{
  global $user_id;
  global $user_prefs_hide;
  if (isset($_COOKIE["pp_pgacc_login"]) && isset($_COOKIE["pp_pgacc_id"]) && preg_match('/^-?\d+$/', $_COOKIE["pp_pgacc_id"]) == 1)
  {
    $data = pg_query("SELECT * FROM users WHERE id = {$_COOKIE["pp_pgacc_id"]} AND login > now() - '24 hours'::interval");
    if (!$data)
      return $get == 'id'?0:'';
    while ($line = pg_fetch_array($data, null, PGSQL_ASSOC))
    {
      // The session token of this login (functions.php), compared in constant time.
      if (!login_token_equals(login_session_token($line['id'], $line['cookie'], $line['login']), $_COOKIE["pp_pgacc_login"]))
        continue;
      $user_id = $line['id'];
      $hide_cols = explode(",",$line['hide']);
      foreach ($hide_cols as $col)
      {
        if (intval($col) > 0)
          $user_prefs_hide[$col] = 1;
      }
      if ($get == 'name')
      {
        pg_free_result($data);
        return $line['name'];
      }
      else if ($get == 'id')
      {
        pg_free_result($data);
        return $line['id'];
      }
      else
      {
        pg_free_result($data);
        return $line['rights'];
      } 
    }
    pg_free_result($data);
  }
  if ($redir)
    header("Location: index.php?action=login&target=" . ascii2hex($_SERVER["REQUEST_URI"]));
}

$page = "index";
if (isset($_GET["action"]))
{
  if (in_array($_GET["action"],array("new","search","all","mb","open","transactions","spendings","closed","transfer","edit","kdonations","donations","deleted","impressum","recover","import","import2","accounts","login","logout","file","merge","statistics","wk","vreport","tinyreport")))
    $page = $_GET["action"];
  else
    $page = "report";

}

global $print_empty;
$print_empty = false;
if (isset($_GET["print_empty"]))
{
  $print_empty = true;
}

// Before any output (the CSV byte order mark or acc_header()): protected
// pages send visitors who are not logged in the login redirect (checklogin()
// then returns nothing), and the member search sends its redirect.
if (in_array($page,array("all","mb","open","merge","edit","new","closed","deleted","recover","accounts","import","import2")) && checklogin('id') === null)
{
  pg_close($dbconn);
  return;
}
if ($page == "search")
{
  $rights = checklogin('rights',false);
  page_search($rights);
  pg_close($dbconn);
  return;
}

global $make_csv;
$make_csv = false;
if (isset($_GET["format"]) && $_GET["format"] == 'csv' && in_array($_GET["action"],array("search","all","open","transactions","spendings","closed","deleted")))
{
  header('Content-Encoding: UTF-8');
  header('Content-type: text/csv; charset=UTF-8');
  header("Content-Disposition: attachment; filename=$page.csv");
  echo "\xEF\xBB\xBF"; // UTF-8 BOM
  $make_csv = true;
}

if ($page == "file")
{
  $rights = checklogin('rights');
  $success = download_file($rights);
  if ($success)
  {
    pg_close($dbconn);
    return;
  }
}
if (isset($_GET["hide"]))
{
  global $user_id;
  global $user_prefs_hide;
  $id = checklogin('id');
  $col = intval($_GET["hide"]);
  if ($col > 0)
    $user_prefs_hide[$col] = 1;
  elseif ($col < 0)
    $user_prefs_hide[-$col] = 0;    
  $cols_array = array();
  foreach ($user_prefs_hide as $hide_col => $state)
  {
    if (intval($hide_col) > 0 && $state == 1)
    {
      $cols_array[] = $hide_col;
    }
  }
  $cols = implode(',',$cols_array);
  $data = pg_query("UPDATE users SET hide = '$cols' WHERE id = $id") or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  pg_free_result($data);
}

// relocate() (edit.php) answers edit and merge requests with a redirect
// instead of the page: buffer the page so that it can be discarded.
if ($page == "edit" || $page == "merge")
  ob_start();

// Login and logout send cookies and redirects: answer them before
// acc_header() starts the page.
if ($page == "logout")
{
  // Ends the session on the server too: its cookies stop working even if
  // a copy was kept. Wrong or missing cookies end nothing.
  $logoutId = checklogin('id', false);
  if ($logoutId > 0)
    pg_query("UPDATE users SET cookie = NULL WHERE id = $logoutId");
  setcookie("pp_pgacc_login", "", time() - 3600);
  setcookie("pp_pgacc_id", "", time() - 3600);
  header("Location: index.php");
  pg_close($dbconn);
  return;
}
if ($page == "login" && login_redirect())
{
  pg_close($dbconn);
  return;
}

$year = date('Y');

if (!$make_csv)
  acc_header($dbconn,$page,$year);

if (isset($_GET["year"]) && preg_match('/^\d\d\d\d$/', $_GET["year"]) == 1)
{
  $year = intval($_GET["year"]);
}
else if (isset($_GET["year"]) && preg_match('/^\d\d\d\d-\d$/', $_GET["year"]) == 1)
{
  $year = $_GET["year"];
} 

if ($page == "all")
{
  $rights = checklogin('rights');
  page_all($rights);
}
else if ($page == "mb")
{
  $rights = checklogin('rights');
  page_mb($rights);
}
else if ($page == "open")
{
  $rights = checklogin('rights');
  page_open($rights);
}
else if ($page == "merge")
{
  $rights = checklogin('rights');
  page_edit_merge($rights);
}
else if ($page == "edit")
{
  $rights = checklogin('rights');
  if (isset($_POST["fileupload"]) && $_POST["fileupload"] == "PDF Hochladen (OHNE SPEICHERN)")
    page_new_file($rights);
  else if (isset($_POST["speichern"]) && $_POST["speichern"] == "Speichern")
    page_edit_save($rights);
  else if (isset($_POST["ack"]))
    page_edit_ack($rights);
  else if (isset($_POST["belegfehler"]))
    page_edit_drop_acks($rights);
  else
    page_edit($rights);
}
else if ($page == "new")
{
  $rights = checklogin('rights');
  if (isset($_POST["speichern"]) && $_POST["speichern"] == "Speichern")
    page_new_save($rights);
  else if (isset($_POST["ack"]))
    page_new_ack($rights);
  else
    page_new($rights);
}
else if ($page == "transactions")
{
  page_transactions();
}
else if ($page == "closed")
{
  $rights = checklogin('rights');
  page_closed($rights);
}
else if ($page == "spendings")
{
  page_spendings();
}
else if ($page == "transfer")
{
  page_transfer();
}
else if ($page == "kdonations")
{
  page_kdonations(intval($_GET["year"]));
}
else if ($page == "donations")
{
  page_donations(intval($_GET["year"]));
}
else if ($page == "deleted")
{
  $rights = checklogin('rights');
  page_deleted($rights);
}
else if ($page == "impressum")
{
  page_impressum();
}
else if ($page == "recover")
{
  $rights = checklogin('rights');
  page_recover($rights);
}
else if ($page == "accounts")
{
  $rights = checklogin('rights');
  page_accounts($rights);
}
else if ($page == "login")
{
  page_login();
}
else if ($page == "import")
{
  $rights = checklogin('rights');
  if (write_rights($rights) === false)
    echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
  else
    page_import($rights);
}
else if ($page == "import2")
{
  $rights = checklogin('rights');
  if (write_rights($rights) === false)
    echo '<div class="slot_error" id="slot_error">FEHLER: Keine Zugriffsrechte.</div><br /><br /><br />';
  else
    page_import2(write_rights($rights));
}
else if ($page == "statistics")
{
  page_statistics();
}
else if ($page == "wk")
{
  page_wk();
}
else if ($page == "tinyreport")
{
  $rights = checklogin('rights',false);
  page_tinyreport($rights);
}
else if ($page == "vreport")
{
  $rights = checklogin('rights',false);
  page_vreport($rights);
}
else
{
  page_report($year);
}

if (!$make_csv)
  acc_footer();

pg_close($dbconn);
?>

