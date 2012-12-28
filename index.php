<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require("constants.php");
require("new.php");
require("open.php");
require("closed.php");
require("edit.php");
require("transfer.php");
require("deleted.php");
require("donations.php");
require("impressum.php");
require("report.php");
$dbconn = pg_connect("dbname=accounting")
  or die('Verbindungsaufbau fehlgeschlagen: ' . pg_last_error());

require("functions.php");

$page = "index";
if (isset($_GET["action"]))
{
  if (in_array($_GET["action"],array("new","open","closed","transfer","edit","donations","deleted","impressum")))
    $page = $_GET["action"];
  else
    $page = "report";

}

acc_header($page);

if ($page == "open")
{
  page_open();
}
else if ($page == "edit")
{
  if (isset($_POST["speichern"]) && $_POST["speichern"] == "Speichern")
    page_edit_save();
  else
    page_edit();
}
else if ($page == "new")
{
  if (isset($_POST["speichern"]) && $_POST["speichern"] == "Speichern")
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
else if ($page == "donations")
{
  page_donations();
}
else if ($page == "deleted")
{
  page_deleted();
}
else if ($page == "impressum")
{
  page_impressum();
}
else
{
  page_report();
}

acc_footer();

pg_close($dbconn);
?>

