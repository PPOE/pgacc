<?php
function page_impressum()
{
$pg_version_array = pg_version();
$pg_version = $pg_version_array['server'];
echo '
      <div class="main" id="default">
        <div class="slot_default" id="slot_default"><br /><div>
<span style="font-weight: bold;">Diensteanbieter:</span></div><br />
Piratenpartei Österreichs<br />
Schadinagasse 3<br />
1170 Wien<br />
<br /><br />
<div><span style="font-weight: bold;">Dieser Dienst ist mit folgender Software realisiert worden:</span></div><br />
<div><table class="ui_list"><thead class="ui_list_head"><tr><th class="">Software</th><th class="">Version</th><th class="">Lizenz</th></tr></thead><tbody class="ui_list_body"><tr class="ui_list_row ui_list_odd"><td class=""><a href="https://github.com/PPOE/pgacc">pgacc</a></td><td class=""><div>0.0.1</div></td><td class=""><a href="#">N/A</a></td></tr><tr class="ui_list_row ui_list_odd"><td class=""><a href="http://www.php.net">PHP</a></td><td class=""><div>'.phpversion().'</div></td><td class=""><a href="http://www.php.net/license/3_01.txt">PHP License</a></td></tr><tr class="ui_list_row ui_list_odd"><td class=""><a href="http://www.postgresql.org/">PostgreSQL</a></td><td class=""><div>'.$pg_version.'</div></td><td class=""><a href="http://www.postgresql.org/about/licence">BSD</a></td></tr></tbody></table></div><br />
<br /><br />
      </div>
      <br style="clear: both;" />
    </div>
';
}
?>
