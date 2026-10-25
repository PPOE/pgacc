<?php

// The released version, shown on the Impressum. Each release pull request sets
// it to the new version together with the dated CHANGELOG.md section;
// scripts/release.sh fails unless it equals the tagged version (README.md,
// "Releases").
const PGACC_VERSION = '2.0.0';

function page_impressum()
{
    global $dbconn;
    $pg_version_array = pg_version($dbconn);
    // Only the major versions of PHP and PostgreSQL ("18", not
    // "18.6 (Debian 18.6-1.pgdg12+2)").
    $pg_version = intval($pg_version_array['server']);
    echo '
      <div class="main" id="default">
        <div class="slot_default" id="slot_default"><br /><div>
<span style="font-weight: bold;">Diensteanbieter:</span></div><br />
Das Impressum der Piratenpartei Österreichs findet sich unter <a href="https://piratenpartei.at/rechtliches/impressum/">piratenpartei.at/rechtliches/impressum</a>.<br />
<br /><br />
<div><span style="font-weight: bold;">Dieser Dienst ist mit folgender Software realisiert worden:</span></div><br />
<div><table class="ui_list"><thead class="ui_list_head"><tr><th class="">Software</th><th class="">Version</th><th class="">Lizenz</th></tr></thead><tbody class="ui_list_body"><tr class="ui_list_row ui_list_odd"><td class=""><a href="https://github.com/PPOE/pgacc">pgacc</a></td><td class=""><div>' . PGACC_VERSION . '</div></td><td class=""><a href="https://github.com/PPOE/pgacc/blob/master/LICENSE">siehe LICENSE</a></td></tr><tr class="ui_list_row ui_list_odd"><td class=""><a href="http://www.php.net">PHP</a></td><td class=""><div>' . PHP_MAJOR_VERSION . '</div></td><td class=""><a href="http://www.php.net/license/3_01.txt">PHP License</a></td></tr><tr class="ui_list_row ui_list_odd"><td class=""><a href="http://www.postgresql.org/">PostgreSQL</a></td><td class=""><div>' . $pg_version . '</div></td><td class=""><a href="http://www.postgresql.org/about/licence">BSD</a></td></tr></tbody></table></div><br />
<br /><br />
      </div>
      <br style="clear: both;" />
    </div>
';
}
?>
