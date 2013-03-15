<?php
if (empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) || $_SERVER['HTTP_X_FORWARDED_PROTO'] != 'https')
{
  header("Location: https://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"]);
  return;
}
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
  if (isset($_COOKIE["pp_pgacc_login"]) && preg_match('/^-?\d+$/', $_COOKIE["pp_pgacc_login"]) == 1 && isset($_COOKIE["pp_pgacc_id"]) && preg_match('/^-?\d+$/', $_COOKIE["pp_pgacc_id"]) == 1)
  {
    $data = pg_query("SELECT * FROM users WHERE cookie = {$_COOKIE["pp_pgacc_login"]} AND id = {$_COOKIE["pp_pgacc_id"]} AND login > now() - '24 hours'::interval");
    if (!$data)
      return $get == 'id'?0:'';
    while ($line = pg_fetch_array($data, null, PGSQL_ASSOC))
    {
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
require("closed.php");
require("transactions.php");
require("import.php");
require("edit.php");
require("transfer.php");
require("deleted.php");
require("donations.php");
require("spendings.php");
require("login.php");
require("accounts.php");
require("impressum.php");
require("report.php");
require("recover.php");
$dbconn = pg_connect("dbname=accounting")
  or die('Verbindungsaufbau fehlgeschlagen: ' . pg_last_error());

require("functions.php");

$page = "index";
if (isset($_GET["action"]))
{
  if (in_array($_GET["action"],array("new","open","transactions","spendings","closed","transfer","edit","donations","deleted","impressum","recover","import","accounts","login","logout")))
    $page = $_GET["action"];
  else
    $page = "report";

}

acc_header($page);
$year = 2013;
if (isset($_GET["year"]) && preg_match('/^\d\d\d\d$/', $_GET["year"]) == 1)
{
  $year = intval($_GET["year"]);
}

if ($page == "open")
{
  $rights = checklogin('rights');
  page_open($rights);
}
else if ($page == "edit")
{
  $rights = checklogin('rights');
  if (isset($_POST["speichern"]) && $_POST["speichern"] == "Speichern")
    page_edit_save($rights);
  else if (isset($_POST["ack"]))
    page_edit_ack($rights);
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
  page_transactions($rights);
}
else if ($page == "closed")
{
  $rights = checklogin('rights');
  page_closed($rights);
}
else if ($page == "spendings")
{
  page_spendings($rights);
}
else if ($page == "transfer")
{
  page_transfer();
}
else if ($page == "donations")
{
  page_donations($year);
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
else
{
  page_report($year);
}

acc_footer();

pg_close($dbconn);
?>

