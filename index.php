<?php
if (empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) || $_SERVER['HTTP_X_FORWARDED_PROTO'] != 'https')
{
  header("Location: https://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"]);
  return;
}
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
    if ($line['success'] == 't')
    {
      $id = $line['id'];
      $rand = mt_rand();
      pg_query("UPDATE users SET cookie = $rand, login = now() WHERE id = $id");
      setcookie("pp_pgacc_login", $rand, time()+86400);
      setcookie("pp_pgacc_id", $id, time()+86400);
      pg_free_result($data);
      header("Location: index.php");
      return $id;
    }
  }
  pg_free_result($data);
  return 0;
}
function checklogin($get = 'name', $redir = true)
{
  global $user_id;
  global $user_prefs_hide;
  if (isset($_COOKIE["pp_pgacc_login"]) && preg_match('/^-?\d+$/', $_COOKIE["pp_pgacc_login"]) == 1 && isset($_COOKIE["pp_pgacc_id"]) && preg_match('/^-?\d+$/', $_COOKIE["pp_pgacc_id"]) == 1)
  {
    $data = pg_query("SELECT * FROM users WHERE cookie = {$_COOKIE["pp_pgacc_login"]} AND id = {$_COOKIE["pp_pgacc_id"]} AND login > now() - '24 hours'::interval");
    if (!$data)
      return $get == 'id'?0:'';
    while ($line = pg_fetch_array($data, null, PGSQL_ASSOC))
    {
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
    header("Location: index.php?action=login");
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
require("edit.php");
require("transfer.php");
require("deleted.php");
require("donations.php");
require("kdonations.php");
require("spendings.php");
require("login.php");
require("accounts.php");
require("impressum.php");
require("report.php");
require("wk.php");
require("recover.php");
require("file.php");
require("statistics.php");
$dbconn = pg_connect("dbname=accounting")
  or die('Verbindungsaufbau fehlgeschlagen: ' . pg_last_error());

require("functions.php");

$page = "index";
if (isset($_GET["action"]))
{
  if (in_array($_GET["action"],array("new","search","all","mb","open","transactions","spendings","closed","transfer","edit","kdonations","donations","deleted","impressum","recover","import","accounts","login","logout","file","merge","statistics","wk")))
    $page = $_GET["action"];
  else
    $page = "report";

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

if (!$make_csv)
  acc_header($dbconn,$page);
$year = 2012;
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
else if ($page == "search")
{
  $rights = checklogin('rights');
  page_search($rights);
}
else if ($page == "merge")
{
  $rights = checklogin('rights');
  page_edit_merge($rights,intval($_POST["bid1"]),intval($_POST["bid2"]));
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
  else if (isset($_POST["beleg"]))
    page_edit_finalize($rights);
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
else if ($page == "logout")
{
  setcookie("pp_pgacc_login", "", time() - 3600);
  setcookie("pp_pgacc_id", "", time() - 3600);
  header("Location: index.php");
}
else if ($page == "import")
{
  $rights = checklogin('rights');
  page_import($rights);
}
else if ($page == "statistics")
{
  page_statistics();
}
else if ($page == "wk")
{
  page_wk();
}
else
{
  page_report($year);
}

if (!$make_csv)
  acc_footer();

pg_close($dbconn);
?>

