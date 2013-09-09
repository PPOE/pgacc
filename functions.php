<?php
function getfilter()
{
  $filter = "";
  if (isset($_GET['filter_id']) && preg_match('/^\d+$/',$_GET['filter_id']) == 1)
    $filter .= " AND voucher_id = " . $_GET['filter_id'];
  if (isset($_GET['filter_bid']) && preg_match('/^\d+$/',$_GET['filter_bid']) == 1)
    $filter .= " AND id = " . $_GET['filter_bid'];
  if (isset($_GET['filter_date']))
  {
    if (preg_match('/^\d\d\d\d$/',$_GET['filter_date']) == 1)
    {
      $filter .= " AND date >= '" . $_GET['filter_date'] . "-01-01' AND date <= '" . $_GET['filter_date'] . "-12-31' ";
    }
    else if (preg_match('/^\d\d\d\d-\d\d$/',$_GET['filter_date']) == 1)
    {
      $filter .= " AND date >= '" . $_GET['filter_date'] . "-01' AND date <= '" . $_GET['filter_date'] . "-30' ";
    }
    else if (preg_match('/^\d\d\d\d-\d\d-\d\d$/',$_GET['filter_date']) == 1)
    {
      $filter .= " AND date = '" . $_GET['filter_date'] . "' ";
    }
  }
  if (isset($_GET['filter_type']) && preg_match('/^\d+$/',$_GET['filter_type']) == 1)
    $filter .= " AND type = " . $_GET['filter_type'];
  else if (isset($_GET['filter_type']) && preg_match('/^[äöüÄÖÜa-z0-9,. ]+$/i',$_GET['filter_type']) == 1)
    $filter .= " AND lower(type.name) LIKE lower('%" . $_GET['filter_type'] . "%')";
  if (isset($_GET['filter_lo']) && preg_match('/^\d+$/',$_GET['filter_lo']) == 1)
    $filter .= " AND orga = " . $_GET['filter_lo'];
  if (isset($_GET['filter_lo']) && preg_match('/^[äöüÄÖÜa-z0-9,. ]+$/i',$_GET['filter_lo']) == 1)
    $filter .= " AND lower(lo.name) LIKE lower('%" . $_GET['filter_lo'] . "%')";
  if (isset($_GET['filter_member']))
    $filter .= " AND member ";
  if (isset($_GET['filter_member_id']) && preg_match('/^\d+$/',$_GET['filter_member_id']) == 1)
    $filter .= " AND member_id = " . $_GET['filter_member_id'];
  if (isset($_GET['filter_gk']) && preg_match('/^[äöüÄÖÜa-z0-9,. ]+$/i',$_GET['filter_gk']) == 1)
    $filter .= " AND contra_account LIKE '" . $_GET['filter_gk'] . "%'";
  if (isset($_GET['filter_amount']) && preg_match('/^(>|>=|=|<|<=) *-?\d+?$/',$_GET['filter_amount']) == 1)
    $filter .= " AND amount " . $_GET['filter_amount'] . "00";
  else if (isset($_GET['filter_amount']) && preg_match('/^(>|>=|=|<|<=) *-?\d+(\.(\d\d)?)?$/',$_GET['filter_amount']) == 1)
    $filter .= " AND amount " . $_GET['filter_amount'];
  if (isset($_GET['filter_k']) && preg_match('/^[äöüÄÖÜa-z0-9,. ]+$/i',$_GET['filter_k']) == 1)
    $filter .= " AND account LIKE '" . $_GET['filter_k']."%'";
  if (isset($_GET['filter_text']) && preg_match('/^[äöüÄÖÜa-z0-9,. ]+$/i',$_GET['filter_text']) == 1)
    $filter .= " AND lower(comment) LIKE lower('%" . $_GET['filter_text'] . "%')";
  if (isset($_GET['filter_comm']))
    $filter .= " AND committed ";
  if (isset($_GET['filter_ack']) && preg_match('/^[äöüÄÖÜa-z0-9 ]+$/i',$_GET['filter_ack']) == 1)
    $filter .= " AND (ack1 LIKE '%" . $_GET['filter_ack'] . "%' OR ack2 LIKE '%" . $_GET['filter_ack'] . "%') ";
  if (isset($_GET['filter_bel']))
    $filter .= " AND file != 0 ";
  if (isset($_GET['filter_name']) && preg_match('/^[äöüÄÖÜa-z0-9 ]+$/i',$_GET['filter_name']) == 1)
    $filter .= " AND lower(vouchers.name) LIKE lower('%" . $_GET['filter_name'] . "%')";
  return $filter;
}
function tag($tag, $text)
{
  return "<$tag>$text</$tag>\n";
}
function acc_header($dbconn,$page = "index")
{
echo '
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>Buchungssystem Piratenpartei Österreichs</title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<link rel="stylesheet" type="text/css" media="screen" href="gregor.css" />
<link rel="stylesheet" type="text/css" media="screen" href="style.css?" />
</head>
<body>
<div id="content">
<div class="wiki motd">Die Daten werden sukzessive eingearbeitet und in einem 4-Augen-Prinzip bestätigt. Die Angaben im Rechenschaftsbericht können also derzeit noch geringfügig variieren. '.percent_of_bookings($dbconn).'</div>
';
$rights = checklogin('rights',false);
echo '
<div class="page ' . $page . '">
<div class="main" id="default">
<div class="slot_default" id="slot_default"><div class="ui_tabs"><div class="ui_tabs_links">
<a href="index.php"'.($page == "index"?' class="selected"':'').'>Rechenschaftsbericht</a>';
echo '<a href="index.php?action=donations"'.($page == "donations"?' class="selected"':'').'>Spendentransparenz</a>
<a href="index.php?action=spendings"'.($page == "spendings"?' class="selected"':'').'>Ausgabentransparenz</a>
<a href="index.php?action=transactions"'.($page == "transactions"?' class="selected"':'').'>Transaktionen</a>
';
if (strlen($rights) > 0)
{
  echo '<br /><a href="index.php?action=new"'.($page == "new"?' class="selected"':'').'>Buchung erfassen</a>';
  echo '<a href="index.php?action=import"'.($page == "import"?' class="selected"':'').'>Buchungsimport</a>';
  echo '<a href="index.php?action=open"'.($page == "open"?' class="selected"':'').'>Offene Buchungen</a>';
  echo '<a href="index.php?action=closed"'.($page == "closed"?' class="selected"':'').'>Abgeschlossene Buchungen</a>';
  echo '<a href="index.php?action=all"'.($page == "all"?' class="selected"':'').'>Alle Buchungen</a>';
  if (strpos($rights,'bsm') !== false || strpos($rights,'root') !== false)
    echo '<a href="index.php?action=deleted"'.($page == "deleted"?' class="selected"':'').'>Alte Revisionen</a>';
  if (strpos($rights,'root') !== false)
    echo '<a href="index.php?action=accounts"'.($page == "accounts"?' class="selected"':'').'>Benutzerverwaltung</a>';
}
echo '</div><br />
';
}

function block_start($p = "")
{
echo '
<br style="clear: both;" />
<div class="wiki use_terms'.$p.'">
';
}
function block_end()
{
echo '
</div>
';
}

function acc_footer()
{
$rights = checklogin('rights',false);
echo '
</div></div>
<div class="slot_default" id="slot_default"><div class="ui_tabs">
<div class="ui_tabs"><div class="ui_tabs_links">
      <a href="index.php?action=impressum">Impressum</a>
';
if (strlen($rights) == 0)
  echo '<a href="index.php?action=login">Login</a>';
if (strlen($rights) != 0)
  echo '<a href="index.php?action=logout">Logout</a>';
echo '      </div>
    </div>
    </div>
    </div>
    </div>
    </div>
    </div>
  </body>
</html>
';
}
function format_date($date)
{
  if ($date != null && preg_match('/^((\d|(0|1|2)\d|3(0|1))\.(\d|0\d|1(0|1|2))\.20\d\d)$/', $date) == 1)
  {
    $tmp = explode(".",$date);
    $date = $tmp[2].'-'.$tmp[1].'-'.$tmp[0];
  }
  else if ($date != null && preg_match('/^\d\d\d\d-\d\d-\d\d \d.*$/', $date) == 1)
  {
    $tmp = explode(" ",$date);
    $date = $tmp[0];
  }
  return $date;
}
function rights2orgasql($rights)
{
  $rights_r = explode(",", $rights);
  if (!in_array('bgf',$rights_r) && !in_array('bsm',$rights_r))
  {
/*    $rights_r = explode(",", $rights);
    foreach ($rights_r as $right)
    {
      if (preg_match('/^\d+$/', $right) == 1)
        $rights2[] = "'".$right."'";
    }
    if (count($rights2) > 0)*/
      return " AND NOT receipt_received ";//AND account IN (" . implode(",",$rights2) . ") ";
  }
  return "";
}
function konto2lo($konto)
{
  switch ($konto)
  {
    case '50110117270':
      return 1;
    case '50110117300':
      return 2;
    case '50110117318':
      return 3;
    case '50110117326':
      return 4;
    case '50110117350':
      return 5;
    case '50110117369':
      return 6;
    case '50110117393':
      return 10;
    case '10110123642':
      return 9;
    case '50110110437':
      return 10;
    default:
      return $konto;
  }
}
function getsort()
{
  $sort = "date ASC, voucher_id DESC, vouchers.id ASC";
  if (isset($_GET["sort"]))
  { 
    switch ($_GET["sort"])
    {
      case 'ida':
        $sort = "voucher_id ASC, vouchers.id ASC";
        break;
      case 'idd':
        $sort = "voucher_id DESC, vouchers.id ASC";
        break;
      case 'bida':
        $sort = "vouchers.id ASC, voucher_id ASC";
        break;          
      case 'bidd':
        $sort = "vouchers.id DESC, voucher_id ASC";
        break;          
      case 'dated':
        $sort = "date DESC, voucher_id DESC, vouchers.id ASC";
        break;          
      case 'datea':
        $sort = "date ASC, voucher_id DESC, vouchers.id ASC";
        break;          
      case 'typea':
        $sort = "type ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;          
      case 'typed':
        $sort = "type DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;          
      case 'loa':
        $sort = "orga ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;          
      case 'lod':
        $sort = "orga DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;          
      case 'membera':
        $sort = "member_id ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;          
      case 'memberd':
        $sort = "member_id DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'gka':
        $sort = "contra_account DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'gkd':
        $sort = "contra_account ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'ka':
        $sort = "account ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'kd':
        $sort = "account DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'ama':
        $sort = "amount ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'amd':
        $sort = "amount DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'texta':
        $sort = "comment ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'textd':
        $sort = "comment DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'comma':
        $sort = "committed DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'commd':
        $sort = "committed ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'acka':
        $sort = "ack1 ASC, ack2 ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'ackd':
        $sort = "ack1 DESC, ack2 DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'bela':
        $sort = "file ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'beld':
        $sort = "file DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'namea':
        $sort = "name ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'named':
        $sort = "name DESC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      default: 
    }
  }
  return $sort;
}
function getoppsort($sortn)
{
  if (isset($_GET["sort"]))
  {
    if ($_GET["sort"] == $sortn)
    {
      $sortdir = substr($sortn,-1,1);
      switch ($sortdir)
      {
        case 'a':
          return substr($sortn,0,-1) . 'd';
        case 'd':
          return substr($sortn,0,-1) . 'a';
      }
    }
  }
  return $sortn;
}
function percent_of_bookings()
{
  $text = "";
  $query = "SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date < '2013-01-01' AND NOT deleted;";
  $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    $c = intval($line['c']);
    $b = intval($line['b']) - intval($line['a']);
    $bp = round($b * 100 / (1.0 * $c),2);
    $a = intval($line['a']);
    $ap = round($a * 100 / (1.0 * $c),2);
    $text = "<br /><i>$c Buchungszeilen, davon sind $b ($bp %) in Bearbeitung und $a ($ap %) abgeschlossen (2012).</i>";
  }
  pg_free_result($result);
  $query = "SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2013-01-01' AND NOT deleted;";
  $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
    $c = intval($line['c']);
    $b = intval($line['b']) - intval($line['a']);
    $bp = round($b * 100 / (1.0 * $c),2);
    $a = intval($line['a']);
    $ap = round($a * 100 / (1.0 * $c),2);
    $text .= "<br /><i>$c Buchungszeilen, davon sind $b ($bp %) in Bearbeitung und $a ($ap %) abgeschlossen (2013).</i>";
  }
  pg_free_result($result);
  return $text;
}

function eyes()
{
  return " NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL) ";
}

/**
 * Return a subset of the parameter array with only keys of the whitelist.
 * Only checks keys, not values.
 *
 * @author meisterluk
 * @author Peter Grassberger <petertheone@gmail.com>
 *
 * @param $array array to select from
 * @param $whitelist array of keys to select
 * @return array a subset of array
 */
function whitelist($array, $whitelist) {
    $new_array = array();
    foreach ($array as $key => $value) {
        if (isset($whitelist[$key]))
            $new_array[$key] = $value;
    }
    return $new_array;
}

?>
