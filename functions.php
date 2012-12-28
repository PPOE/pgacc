<?php
function tag($tag, $text)
{
  echo "<$tag>$text</$tag>";
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
<div class="wiki motd">Dies ist nur eine Demo-Version die keine realen Daten enthält.</div>
';
echo '
<div class="page">
<div class="main" id="default">
<div class="slot_default" id="slot_default"><div class="ui_tabs"><div class="ui_tabs_links">
<a href="index.php"'.($page == "index"?' class="selected"':'').'>Rechenschaftsbericht</a>
<a href="index.php?action=new"'.($page == "new"?' class="selected"':'').'>Buchung erfassen</a>
<a href="index.php?action=open"'.($page == "open"?' class="selected"':'').'>Offene Buchungen</a>
<a href="index.php?action=closed"'.($page == "closed"?' class="selected"':'').'>Abgeschlossene Buchungen</a>
<a href="index.php?action=transfer"'.($page == "transfer"?' class="selected"':'').'>Offene Kontotransfers</a>
<a href="index.php?action=deleted"'.($page == "deleted"?' class="selected"':'').'>Gelöschte Buchungen</a>
<a href="index.php?action=donations"'.($page == "donations"?' class="selected"':'').'>Spendentransparenz</a>
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
echo '
</div></div></div></div>
    <div class="footer" id="footer">
      <div class="slot_footer" id="slot_footer"><a href="index.php?action=impressum">Impressum</a></div>
    </div>
    </div>
  </body>
</html>
';
}

?>
