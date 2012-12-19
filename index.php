<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require("constants.php");
require("new.php");
require("closed.php");
require("transfer.php");
require("open.php");
require("report.php");
$dbconn = pg_connect("dbname=accounting")
  or die('Verbindungsaufbau fehlgeschlagen: ' . pg_last_error());

require("functions.php");

$page = "index";
if (isset($_GET["action"]))
{
  if (in_array($_GET["action"],array("new","open","closed","transfer")))
    $page = $_GET["action"];
  else
    $page = "report";

}

acc_header($page);

if ($page == "open")
{
  page_open();
}
else if ($page == "new")
{
  if (isset($_POST["speichern"]) && $_POST["speichern"] == "speichern")
    page_new_save();
  else
    page_new();
}
else if ($page == "closed")
{
  page_closed();
}
else if ($page == "transfer")
{
  page_transfer();
}
else
{
  page_report();
}

acc_footer();

pg_close($dbconn);
?>

