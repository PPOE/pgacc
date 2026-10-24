<?php
function hex2ascii($hex){
        $ascii='';
        $hex=str_replace(" ", "", $hex);
        for($i=0; $i<strlen($hex); $i=$i+2) {
                $ascii.=chr(hexdec(substr($hex, $i, 2)));
                }
                return($ascii);
}
 
function ascii2hex($ascii) {
        $hex = '';
        for ($i = 0; $i < strlen($ascii); $i++) {
                $byte = strtoupper(dechex(ord($ascii[$i])));
                $byte = str_repeat('0', 2 - strlen($byte)).$byte;
                $hex.=$byte." ";
                }
                $hex=str_replace(" ", "", $hex);
                return $hex;
}

function csv_download_link($i = -1)
{
  global $make_csv;
  if ($make_csv)
    return;
  $link = $_SERVER['REQUEST_URI'] . "&format=csv";
  echo "<br style=\"clear: both;\" /><center>";
  if ($i >= 0)
    echo "$i Umsätze gefunden<br /><br />";
  echo "<a href=\"$link\">Als CSV Datei herunterladen</a></center>";
}
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
      $filter .= " AND date >= '" . $_GET['filter_date'] . "-01' AND date < TIMESTAMP '" . $_GET['filter_date'] . "-01' + INTERVAL '1 month' ";
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
  if (isset($_GET['filter_gk']) && preg_match('/^[@äöüÄÖÜa-z0-9,. ]+$/i',$_GET['filter_gk']) == 1)
    $filter .= " AND contra_account LIKE '" . $_GET['filter_gk'] . "%'";
  if (isset($_GET['filter_amount']) && preg_match('/^(>|>=|=|<|<=) *-?\d+?$/',$_GET['filter_amount']) == 1)
    $filter .= " AND amount " . $_GET['filter_amount'] . "00";
  else if (isset($_GET['filter_amount']) && preg_match('/^(>|>=|=|<|<=) *-?\d+((\.|,)(\d\d)?)?$/',$_GET['filter_amount']) == 1)
    $filter .= " AND amount " . str_replace(array(".",","),array("",""),$_GET['filter_amount']);
  else if (isset($_GET['filter_amount']) && preg_match('/^*-?\d+((\.|,)(\d\d)?)?$/',$_GET['filter_amount']) == 1)
    $filter .= " AND amount = " . str_replace(array(".",","),array("",""),$_GET['filter_amount']);
  if (isset($_GET['filter_k']) && preg_match('/^[@äöüÄÖÜa-z0-9,. ]+$/i',$_GET['filter_k']) == 1)
    $filter .= " AND account LIKE '" . $_GET['filter_k']."%'";
  if (isset($_GET['filter_vk']) && preg_match('/^[@äöüÄÖÜa-z0-9,. ]+$/i',$_GET['filter_vk']) == 1)
    $filter .= " AND vaccount LIKE '" . $_GET['filter_vk']."%'";
  if (isset($_GET['filter_text']) && preg_match('/^[@äöüÄÖÜa-z0-9,. ]+$/i',$_GET['filter_text']) == 1)
    $filter .= " AND lower(comment) LIKE lower('%" . $_GET['filter_text'] . "%')";
  if (isset($_GET['filter_comment']) && preg_match('/^[äöüÄÖÜa-z0-9,. ]+$/i',$_GET['filter_comment']) == 1)
    $filter .= " AND lower(commentgf) LIKE lower('%" . $_GET['filter_comment'] . "%')";
  if (isset($_GET['filter_comm']))
    $filter .= " AND committed ";
  if (isset($_GET['filter_ack']) && preg_match('/^[äöüÄÖÜa-z0-9 ]+$/i',$_GET['filter_ack']) == 1)
    $filter .= " AND (ack1 LIKE '%" . $_GET['filter_ack'] . "%' OR ack2 LIKE '%" . $_GET['filter_ack'] . "%') ";
  if (isset($_GET['filter_ack_old']) && preg_match('/^[äöüÄÖÜa-z0-9 ]+$/i',$_GET['filter_ack_old']) == 1)
    $filter .= " AND (ack1_old LIKE '%" . pg_escape_string(isset($_GET['filter_ack']) ? $_GET['filter_ack'] : '') . "%' OR ack2_old LIKE '%" . pg_escape_string(isset($_GET['filter_ack']) ? $_GET['filter_ack'] : '') . "%') ";
  if (isset($_GET['filter_refund']))
    $filter .= " AND refund ";
  if (isset($_GET['filter_bel']))
    $filter .= " AND file != 0 ";
  if (isset($_GET['filter_name']) && preg_match('/^[äöüÄÖÜa-z0-9 ]+$/i',$_GET['filter_name']) == 1)
    $filter .= " AND lower(vouchers.name) LIKE lower('%" . $_GET['filter_name'] . "%')";
  return $filter;
}
function tag($tag, $text)
{
  global $make_csv;
  if ($make_csv)
  {
    $text = str_replace(array("\n"),array(' / '),$text === null ? '' : $text);
    if (preg_match('/^-?\d+(.\d+)?€$/',$text) == 1)
    {
      $text = str_replace(array("."),array(","),$text);
    }
    if ($tag == 'tr')
      return "$text\n";
    else if ($tag == 'td')
      return "$text\t";
    else
      return "$text";
  }
  return "<$tag>$text</$tag>\n";
}
function acc_header($dbconn,$page = "index",$year = 2015)
{
echo '
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
';
switch ($page)
{
case "donations":
  echo '<title>Spenden - Piratenpartei Österreichs</title>
  <meta name="description" content="Aktuelle Spendenlisten der Piratenpartei Österreichs" />
  <meta name="keywords" content="spenden, spendenliste" />';
  break;
case "kdonations":
  echo '<title>Sachspenden - Piratenpartei Österreichs</title>
  <meta name="description" content="Liste aller Sachspenden welche die Piratenpartei Österreichs erhalten hat" />
  <meta name="keywords" content="sachspenden, spenden, spendenliste, sachspendenliste" />';
  break;
case "spendings":
  echo '<title>Ausgaben - Piratenpartei Österreichs</title>
  <meta name="description" content="Liste aller Ausgaben welche die Piratenpartei Österreichs getätigt hat" />
  <meta name="keywords" content="ausgaben, ausgabenliste" />';
  break;
case "wk":
  echo '<title>Wahlkampfkosten - Piratenpartei Österreichs</title>
  <meta name="description" content="Wahlkampfabrechnungen der Piratenpartei Österreichs" />
  <meta name="keywords" content="wahlkampf, wahlkampfkosten, wahlkampfabrechnung" />';
  break;
case "transactions":
  echo '<title>Kontobewegungen - Piratenpartei Österreichs</title>
  <meta name="description" content="Liste aller Kontobewegungen der Piratenpartei Österreichs" />
  <meta name="keywords" content="konto, einsicht, kontenbewegungen, transaktionen" />';
  break;
default:
  echo '<title>Rechenschaftsbericht - Piratenpartei Österreichs</title>
  <meta name="description" content="Der vorläufige Rechenschaftsbericht der Piratenpartei Österreichs" />
  <meta name="keywords" content="einnahmen, ausgaben, rechenschaftsbericht, spenden, spendenliste, sachspenden, wahlkampf, wahlkampfabrechnung, piratenpartei, österreichs, österreich" />';
  break;
}
echo '
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<link rel="stylesheet" type="text/css" media="screen" href="gregor.css" />
<link rel="stylesheet" type="text/css" media="screen" href="style.css" />
</head>
<body>
<div id="content">
<div class="wiki motd">Die Daten werden sukzessive eingearbeitet und in einem 4-Augen-Prinzip bestätigt. Die Angaben im Rechenschaftsbericht können also derzeit noch geringfügig variieren.' .percent_of_bookings($dbconn).'</div>
';
$rights = checklogin('rights',false);
echo '
<div class="page ' . $page . '">
<div class="main" id="default">
<div class="slot_default" id="slot_default"><div class="ui_tabs"><div class="ui_tabs_links">
<a href="/"'.($page == "index"?' class="selected"':'').'>Rechenschaftsbericht</a>';
echo '<a href="donations"'.($page == "donations"?' class="selected"':'').'>Spenden</a>
<a href="kdonations"'.($page == "kdonations"?' class="selected"':'').'>Sachspenden</a>
<a href="spendings"'.($page == "spendings"?' class="selected"':'').'>Ausgaben</a>
<a href="wk"'.($page == "wk"?' class="selected"':'').'>Wahlkampf</a>
<a href="transactions"'.($page == "transactions"?' class="selected"':'').'>Kontobeweg.</a>
<a href="vreport"'.($page == "vreport"?' class="selected"':'').'>Virt. Konten</a>
';/*<a href="index.php?action=statistics"'.($page == "statistics"?' class="selected"':'').'>Statistiken</a>
';*/
if (strlen($rights === null ? '' : $rights) > 0)
{
  echo '<br />';
  if (write_rights($rights) !== false)
  {
    echo '<a href="index.php?action=new"'.($page == "new"?' class="selected"':'').'>Erfassen</a>';
    echo '<a href="index.php?action=import"'.($page == "import"?' class="selected"':'').'>Import</a>';
    echo '<a href="index.php?action=import2"'.($page == "import2"?' class="selected"':'').'>Belege Import</a>';
  }
  echo '<a href="index.php?action=open&filter_date='.$year.'"'.($page == "open"?' class="selected"':'').'>Offen</a>';
  echo '<a href="index.php?action=closed&filter_date='.$year.'"'.($page == "closed"?' class="selected"':'').'>Abgeschlossen</a>';
  echo '<a href="index.php?action=all&filter_date='.$year.'"'.($page == "all"?' class="selected"':'').'>Alles</a>';
  echo '<a href="index.php?action=mb"'.($page == "mb"?' class="selected"':'').'>Mitgliedsbeiträge</a>';
  echo '<a href="index.php?action=tinyreport"'.($page == "tinyreport"?' class="selected"':'').'>Konten</a>';
  //echo '<a href="index.php?action=vreport"'.($page == "vreport"?' class="selected"':'').'>Virt. Konten</a>';
  if (strpos($rights,'bgf') !== false || strpos($rights,'root') !== false)
    echo '<a href="index.php?action=deleted&filter_date='.$year.'"'.($page == "deleted"?' class="selected"':'').'>Alte Rev.</a>';
//  if (strpos($rights,'root') !== false)
    echo '<a href="index.php?action=accounts"'.($page == "accounts"?' class="selected"':'').'>Benutzer</a>';
}
echo '</div><br />
';
}

function block_start($p = "")
{
  global $make_csv;
  if ($make_csv)
    return;
echo '
<br style="clear: both;" />
<div class="wiki use_terms'.$p.'">
';
}
function block_end()
{
  global $make_csv;
  if ($make_csv)
    return;
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
if (strlen($rights === null ? '' : $rights) == 0)
  echo '<a href="index.php?action=login">Login</a>';
if (strlen($rights === null ? '' : $rights) != 0)
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
// Whether the comma-separated rights contain exactly this right ("bgf" is
// not contained in "Rbgf").
function has_right($rights, $right)
{
  return in_array($right, explode(",", $rights === null ? '' : $rights), true);
}
// Rights for changing data. The read-only right "Rbgf" (see accounts.php)
// does not count: false for a user who has no other right, otherwise the
// other rights. Rights without "Rbgf" are returned unchanged.
function write_rights($rights)
{
  $tokens = explode(",", $rights === null ? '' : $rights);
  if (!in_array('Rbgf', $tokens, true))
    return $rights;
  $rights_w = array();
  foreach ($tokens as $right)
  {
    if ($right !== 'Rbgf' && $right !== '')
      $rights_w[] = $right;
  }
  if (count($rights_w) == 0)
    return false;
  return implode(",", $rights_w);
}
function rights2orgasql($rights)
{
  $rights_r = explode(",", $rights === null ? '' : $rights);
  if (!in_array('bgf',$rights_r) && !in_array('Rbgf',$rights_r))
  {
    $rights_r = explode(",", $rights === null ? '' : $rights);
    $rights2 = array();
    foreach ($rights_r as $right)
    {
      if (preg_match('/^\d+$/', $right) == 1)
        $rights2[] = "'".$right."'";
    }
    if (count($rights2) > 0)
      return " AND orga IN (" . implode(",",$rights2) . ") ";
    return " AND false ";
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
      case 'commenta':
        $sort = "commentgf ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'commentd':
        $sort = "commentgf DESC, date ASC, voucher_id DESC, vouchers.id ASC";
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
      case 'refunda':
        $sort = "refund ASC, date ASC, voucher_id DESC, vouchers.id ASC";
        break;
      case 'refundd':
        $sort = "refund DESC, date ASC, voucher_id DESC, vouchers.id ASC";
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
  $startYear = 2012;
  $endYear = date('Y') + 1;
  for ($currentYear = $startYear; $currentYear < $endYear + 1; $currentYear++) {
    $query = "SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '" . $currentYear . "-01-01' AND date < '" . ($currentYear + 1) . "-01-01' AND NOT deleted;";
    $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
    while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
      $c = intval($line['c']);
      if ($c <= 0) {
        continue;
      }
      $b = intval($line['b']) - intval($line['a']);
      $bp = round($b * 100 / (1.0 * $c),2);
      $a = intval($line['a']);
      $ap = round($a * 100 / (1.0 * $c),2);
      $d = $c - $b - $a;
      $dp = round($d * 100 / (1.0 * $c), 2);
      $text .= "<br /><i>$currentYear: $c Buchungszeilen, $a ($ap %) abgeschlossen, $b ($bp %) bearbeitet, $d ($dp %) unbearbeitet.</i>";
    }
    pg_free_result($result);
  }
  return $text;
}

function eyes()
{
  if (isset($_GET["noeyes"]))
    return " NOT deleted ";
  else
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

function vouchers_reset_ack($id, $rightssql) {
  $query = "
    UPDATE vouchers
    SET
      ack1_old = ack1,
      ack2_old = ack2,
      ack1 = NULL,
      ack2 = NULL
    WHERE
      NOT deleted AND
      (ack1 IS NOT NULL OR ack2 IS NOT NULL) AND
      voucher_id = $id $rightssql;
  ";
  $result = pg_query($query) or die('Abfrage fehlgeschlagen: ' . pg_last_error());
  while ($line = pg_fetch_array($result, null, PGSQL_ASSOC)) {
  }
  pg_free_result($result);
}

/*
 * Replacement for the removed mcrypt extension, byte-compatible with
 * mcrypt_encrypt()/mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $key, $data,
 * MCRYPT_MODE_CBC, $iv): Rijndael with a 256-bit block (not AES), CBC mode,
 * data zero-padded to whole 32-byte blocks (at least one), padding kept on
 * decryption. Keys and IVs are 32-byte md5 hex strings. phpseclib uses the
 * mcrypt extension where it is loaded and its own implementation elsewhere;
 * both give the same bytes.
 * Contract vectors: tests/fixtures/mcrypt/vectors.json.
 */
function rijndael256_cbc($key, $iv)
{
  $cipher = new \phpseclib\Crypt\Rijndael(\phpseclib\Crypt\Base::MODE_CBC);
  $cipher->setBlockLength(256);
  $cipher->setKey($key);
  $cipher->setIV($iv);
  $cipher->disablePadding();
  return $cipher;
}

function rijndael256_zero_pad($data)
{
  $blocks = max(1, intval(ceil(strlen($data) / 32)));
  return str_pad($data, $blocks * 32, "\0");
}

function rijndael256_encrypt($key, $data, $iv)
{
  return rijndael256_cbc($key, $iv)->encrypt(rijndael256_zero_pad($data));
}

function rijndael256_decrypt($key, $data, $iv)
{
  return rijndael256_cbc($key, $iv)->decrypt(rijndael256_zero_pad($data));
}

/*
 * Receipt files, keyed by the voucher ID: key and IV, shared by encryption
 * and decryption. See the mcrypt and receipt key items in
 * tests/COMPATIBILITY_PLAN.md before changing the key derivation.
 */
function receipt_cipher_params($id, $key)
{
  return array(md5($id . $key . $id), md5($key . $id));
}

function receipt_encrypt($id, $key, $data)
{
  list($cipherKey, $iv) = receipt_cipher_params($id, $key);
  return rijndael256_encrypt($cipherKey, $data, $iv);
}

function receipt_decrypt($id, $key, $data)
{
  list($cipherKey, $iv) = receipt_cipher_params($id, $key);
  return rijndael256_decrypt($cipherKey, $data, $iv);
}

/*
 * Receipt storage in files/. New receipts are <n>.v2.aes: keyed on the file
 * number <n> and the configured $key of constants.php, AES-256-CBC with a
 * random IV, authenticated with HMAC-SHA256 (see receipt_v2_encrypt()).
 * Legacy receipts are <n>.aes: keyed on the voucher ID with an empty key part
 * (receipt_encrypt(); the callers never read the configured key).
 * migrate-receipt-key.php re-encrypts legacy files. See the receipt key item
 * in tests/COMPATIBILITY_PLAN.md.
 */

// At least 16 characters and not the CHANGE-ME placeholder of the README.
function receipt_key_is_valid($key)
{
  return is_string($key) && strlen($key) >= 16 && strpos($key, 'CHANGE-ME') === false;
}

// The configured key, or '' without a valid one (receipts then fail closed).
function receipt_configured_key()
{
  // constants.php is required in global scope (index.php).
  if (isset($GLOBALS['key']) && receipt_key_is_valid($GLOBALS['key']))
    return $GLOBALS['key'];
  return '';
}

// Identifies a key in the migration's output without revealing it.
function receipt_key_fingerprint($key)
{
  return substr(hash_hmac('sha256', 'pgacc-key-fingerprint', $key), 0, 8);
}

function receipt_v2_path($directory, $number)
{
  return $directory . '/' . $number . '.v2.aes';
}

function receipt_legacy_path($directory, $number)
{
  return $directory . '/' . $number . '.aes';
}

// PDF and JPEG by their magic bytes; anything else is sent as a download.
function receipt_content_type($plain)
{
  if (substr($plain, 0, 4) === '%PDF')
    return 'application/pdf';
  if (substr($plain, 0, 3) === "\xFF\xD8\xFF")
    return 'image/jpeg';
  return 'application/octet-stream';
}

function receipt_has_known_type($plain)
{
  return receipt_content_type($plain) !== 'application/octet-stream';
}

// Encryption and MAC keys of one <n>.v2.aes file.
function receipt_v2_keys($key, $number)
{
  return array(
    hash_hmac('sha256', 'pgacc-receipt-v2-enc|' . $number, $key, true),
    hash_hmac('sha256', 'pgacc-receipt-v2-mac|' . $number, $key, true),
  );
}

function receipt_v2_cipher($encryptionKey, $iv)
{
  $cipher = new \phpseclib\Crypt\AES(\phpseclib\Crypt\Base::MODE_CBC);
  $cipher->setKey($encryptionKey);
  $cipher->setIV($iv);
  return $cipher;
}

// Byte strings compared in constant time (hash_equals() needs PHP 5.6).
function receipt_equals($known, $given)
{
  if (strlen($known) !== strlen($given))
    return false;
  $difference = 0;
  for ($i = 0; $i < strlen($known); $i++)
    $difference |= ord($known[$i]) ^ ord($given[$i]);
  return $difference === 0;
}

/*
 * The bytes of files/<number>.v2.aes: "PGR2", a 16-byte IV (random unless
 * given, for test vectors), the AES-256-CBC ciphertext with PKCS#7 padding,
 * and the HMAC-SHA256 of everything before it (encrypt-then-MAC).
 */
function receipt_v2_encrypt($key, $number, $data, $iv = null)
{
  if ($iv === null)
    $iv = \phpseclib\Crypt\Random::string(16);
  list($encryptionKey, $macKey) = receipt_v2_keys($key, $number);
  $ciphertext = receipt_v2_cipher($encryptionKey, $iv)->encrypt($data);
  $mac = hash_init('sha256', HASH_HMAC, $macKey);
  hash_update($mac, 'PGR2' . $iv);
  hash_update($mac, $ciphertext);
  return 'PGR2' . $iv . $ciphertext . hash_final($mac, true);
}

// The plain text of <number>.v2.aes bytes, or false: the MAC is checked first,
// so a wrong key, another file number or a modified file is rejected.
function receipt_v2_decrypt($key, $number, $file)
{
  if (!is_string($file) || strlen($file) < 68 || substr($file, 0, 4) !== 'PGR2')
    return false;
  list($encryptionKey, $macKey) = receipt_v2_keys($key, $number);
  $ciphertext = substr($file, 20, -32);
  $mac = hash_init('sha256', HASH_HMAC, $macKey);
  hash_update($mac, substr($file, 0, 20));
  hash_update($mac, $ciphertext);
  if (!receipt_equals(hash_final($mac, true), substr($file, -32)))
    return false;
  return receipt_v2_cipher($encryptionKey, substr($file, 4, 16))->decrypt($ciphertext);
}

// Writes a temporary file next to $path and renames it over $path, so readers
// never see a partly written file. Only the temporary file is ever removed.
function receipt_write_file($path, $bytes)
{
  $temp = $path . '.tmp-' . getmypid() . '-' . str_replace('.', '', uniqid('', true));
  if (@file_put_contents($temp, $bytes) === strlen($bytes) && @rename($temp, $path))
    return true;
  @unlink($temp);
  return false;
}

// Stores a new receipt as <number>.v2.aes. Fails closed: without a valid
// configured key nothing is written.
function receipt_store($directory, $number, $data)
{
  $receiptKey = receipt_configured_key();
  if ($receiptKey === '')
  {
    error_log('pgacc: receipt not stored: no valid receipt key ($key) configured');
    return false;
  }
  if (!receipt_write_file(receipt_v2_path($directory, $number), receipt_v2_encrypt($receiptKey, $number, $data)))
  {
    error_log("pgacc: receipt $number.v2.aes could not be written");
    return false;
  }
  return true;
}

// The plain text of files/<number>.v2.aes ($path), or false (logged; neither
// the key nor its fingerprint goes to the web server's log).
function receipt_read_v2($path, $number)
{
  $receiptKey = receipt_configured_key();
  if ($receiptKey === '')
  {
    error_log("pgacc: receipt $number.v2.aes not decrypted: no valid receipt key (\$key) configured");
    return false;
  }
  $plain = receipt_v2_decrypt($receiptKey, $number, @file_get_contents($path));
  if ($plain === false)
    error_log("pgacc: receipt $number.v2.aes could not be decrypted (wrong key or modified file)");
  return $plain;
}

// Membership-system exchange (getusers.php, mbapi.php): key and IV.
function membership_cipher_params($datakey)
{
  return array(md5($datakey), md5(md5($datakey)));
}

function membership_encrypt($datakey, $data)
{
  list($cipherKey, $iv) = membership_cipher_params($datakey);
  return rijndael256_encrypt($cipherKey, $data, $iv);
}

function membership_decrypt($datakey, $data)
{
  list($cipherKey, $iv) = membership_cipher_params($datakey);
  return rijndael256_decrypt($cipherKey, $data, $iv);
}

?>
