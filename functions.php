<?php
function tag($tag, $text)
{
  return "<$tag>$text</$tag>\n";
}
function acc_header($page = "index")
{
echo '
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>Buchungssystem Piratenpartei Österreichs</title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<link rel="stylesheet" type="text/css" media="screen" href="gregor.css" />
<link rel="stylesheet" type="text/css" media="screen" href="style.css" />
</head>
<body>
<div id="content">
<div class="wiki motd">Die Daten werden derzeit noch eingearbeitet und sind daher noch nicht vollständig. Die Buchungen werden in einem 4-Augen-Prinzip bestätigt. Derzeit ist dies allerdings noch deaktiviert. Beträge können sich daher noch geringfügig ändern falls eine Buchung nicht korrekt durchgeführt wurde.</div>
';
$rights = checklogin('rights',false);
echo '
<div class="page">
<div class="main" id="default">
<div class="slot_default" id="slot_default"><div class="ui_tabs"><div class="ui_tabs_links">
<a href="index.php"'.($page == "index"?' class="selected"':'').'>Rechenschaftsbericht</a>';
if (strlen($rights) > 0)
echo '<a href="index.php?action=new"'.($page == "new"?' class="selected"':'').'>Buchung erfassen</a>';
if (strlen($rights) > 0)
  echo '<a href="index.php?action=import"'.($page == "import"?' class="selected"':'').'>Buchungsimport</a>';
if (strlen($rights) > 0)
  echo '<a href="index.php?action=open"'.($page == "open"?' class="selected"':'').'>Offene Buchungen</a>';
if (strlen($rights) > 0)
  echo '<a href="index.php?action=closed"'.($page == "closed"?' class="selected"':'').'>Abgeschlossene Buchungen</a>';
if (strpos($rights,'bsm') !== false || strpos($rights,'root') !== false)
  echo '<a href="index.php?action=deleted"'.($page == "deleted"?' class="selected"':'').'>Alte Revisionen</a>';
if (strlen($rights) > 0)
  echo '<a href="index.php?action=accounts"'.($page == "accounts"?' class="selected"':'').'>Benutzerverwaltung</a>';
echo '<a href="index.php?action=donations"'.($page == "donations"?' class="selected"':'').'>Spendentransparenz</a>
<a href="index.php?action=spendings"'.($page == "spendings"?' class="selected"':'').'>Ausgabentransparenz</a>
<a href="index.php?action=transactions"'.($page == "transactions"?' class="selected"':'').'>Transaktionen</a>
</div><br />
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
  if (!in_array('bgf',$rights_r) && !in_array('bsm',$rights_r) && !in_array('root',$rights_r))
  {
    $rights_r = explode(",", $rights);
    foreach ($rights_r as $right)
    {
      if (preg_match('/^\d+$/', $right) == 1)
        $rights2[] = "'".$right."'";
    }
    if (count($rights2) > 0)
      return " AND account IN (" . implode(",",$rights2) . ") ";
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
  $sort = "voucher_id DESC,id ASC";
  if (isset($_GET["sort"]))
  { 
    switch ($_GET["sort"])
    {
      case 'ida':
        $sort = "voucher_id ASC, id ASC";
        break;
      case 'idd':
        $sort = "voucher_id DESC, id ASC";
        break;
      case 'bida':
        $sort = "id ASC, voucher_id ASC";
        break;          
      case 'bidd':
        $sort = "id DESC, voucher_id ASC";
        break;          
      case 'dated':
        $sort = "date DESC, voucher_id DESC, id ASC";
        break;          
      case 'datea':
        $sort = "date ASC, voucher_id DESC, id ASC";
        break;          
      case 'typea':
        $sort = "type ASC, voucher_id DESC, id ASC";
        break;          
      case 'typed':
        $sort = "type DESC, voucher_id DESC, id ASC";
        break;          
      case 'loa':
        $sort = "orga ASC, voucher_id DESC, id ASC";
        break;          
      case 'lod':
        $sort = "orga DESC, voucher_id DESC, id ASC";
        break;          
      case 'membera':
        $sort = "member ASC, voucher_id DESC, id ASC";
        break;          
      case 'memberd':
        $sort = "member DESC, voucher_id DESC, id ASC";
        break;
      case 'gka':
        $sort = "contra_account DESC, voucher_id DESC, id ASC";
        break;
      case 'gkd':
        $sort = "contra_account ASC, voucher_id DESC, id ASC";
        break;
      case 'ka':
        $sort = "account ASC, voucher_id DESC, id ASC";
        break;
      case 'kd':
        $sort = "account DESC, voucher_id DESC, id ASC";
        break;
      case 'ama':
        $sort = "amount ASC, voucher_id DESC, id ASC";
        break;
      case 'amd':
        $sort = "amount DESC, voucher_id DESC, id ASC";
        break;
      case 'texta':
        $sort = "text ASC, voucher_id DESC, id ASC";
        break;
      case 'textd':
        $sort = "text DESC, voucher_id DESC, id ASC";
        break;
      case 'comma':
        $sort = "committed DESC, voucher_id DESC, id ASC";
        break;
      case 'commd':
        $sort = "committed ASC, voucher_id DESC, id ASC";
        break;
      case 'acka':
        $sort = "length(ack1) ASC, length(ack2) ASC, voucher_id DESC, id ASC";
        break;
      case 'ackd':
        $sort = "length(ack1) DESC, length(ack2) DESC, voucher_id DESC, id ASC";
        break;
      case 'bela':
        $sort = "receipt_received ASC, voucher_id DESC, id ASC";
        break;
      case 'beld':
        $sort = "receipt_received DESC, voucher_id DESC, id ASC";
        break;
      case 'namea':
        $sort = "name ASC, voucher_id DESC, id ASC";
        break;
      case 'named':
        $sort = "name DESC, voucher_id DESC, id ASC";
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
