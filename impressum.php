<?php

// The released version, shown on the Impressum and appended to the stylesheet
// and script URLs (?v=), so that browsers load them again after an upgrade
// (functions.php acc_header(), vreport.php). Each release pull request sets
// it to the new version together with the dated CHANGELOG.md section;
// scripts/release.sh fails unless it equals the tagged version (README.md,
// "Releases").
const PGACC_VERSION = '2.2.0';

// Third-party software shipped outside Composer (public/js/README.md,
// public/icons/, and Tailwind CSS in public/style.css, built from css/app.css).
const IMPRESSUM_BUNDLED = [
    ['Chart.js', 'https://www.chartjs.org', 'MIT', 'https://spdx.org/licenses/MIT.html'],
    ['Tailwind CSS', 'https://tailwindcss.com', 'MIT', 'https://spdx.org/licenses/MIT.html'],
    ['Silk Icons von Mark James', 'http://www.famfamfam.com/lab/icons/silk/', 'CC BY 2.5', 'https://creativecommons.org/licenses/by/2.5/'],
];

// The runtime Composer packages of composer.lock (not the development ones):
// name, link (homepage, else the source repository) and licenses.
function impressum_composer_packages($lockFile)
{
    $lock = is_readable($lockFile) ? json_decode((string) file_get_contents($lockFile), true) : null;
    $rows = [];
    foreach ($lock['packages'] ?? [] as $package) {
        $url = $package['homepage'] ?? preg_replace('/\.git$/', '', $package['source']['url'] ?? '');
        $licenses = $package['license'] ?? [];
        $rows[] = [$package['name'], $url, implode(', ', $licenses), count($licenses) === 1 ? 'https://spdx.org/licenses/' . $licenses[0] . '.html' : ''];
    }
    return $rows;
}

function impressum_row($name, $url, $version, $license, $licenseUrl)
{
    $link = function ($text, $href) {
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return $href === '' ? $text : '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $text . '</a>';
    };
    return '<tr class="ui_list_row ui_list_odd"><td class="">' . $link($name, $url) . '</td><td class=""><div>' . $version . '</div></td><td class="">' . $link($license, $licenseUrl) . '</td></tr>';
}

function page_impressum()
{
    global $dbconn;
    $pg_version_array = pg_version($dbconn);
    // Only the major versions of PHP and PostgreSQL ("18", not
    // "18.6 (Debian 18.6-1.pgdg12+2)"); none for third-party libraries.
    $pg_version = intval($pg_version_array['server']);
    $rows = impressum_row('pgacc', 'https://github.com/PPOE/pgacc', PGACC_VERSION, 'LICENSE', 'https://github.com/PPOE/pgacc/blob/master/LICENSE')
        . impressum_row('PHP', 'http://www.php.net', (string) PHP_MAJOR_VERSION, 'PHP License', 'http://www.php.net/license/3_01.txt')
        . impressum_row('PostgreSQL', 'http://www.postgresql.org/', (string) $pg_version, 'PostgreSQL License', 'http://www.postgresql.org/about/licence');
    foreach (array_merge(impressum_composer_packages(__DIR__ . '/composer.lock'), IMPRESSUM_BUNDLED) as $row) {
        $rows .= impressum_row($row[0], $row[1], '–', $row[2], $row[3]);
    }
    echo '
      <div class="main" id="default">
        <div class="slot_default" id="slot_default"><br /><div>
<span style="font-weight: bold;">Diensteanbieter:</span></div><br />
Piratenpartei Österreichs<br />
Impressum: <a href="https://piratenpartei.at/rechtliches/impressum/">piratenpartei.at/rechtliches/impressum</a><br />
<br /><br />
<div><span style="font-weight: bold;">Dieser Dienst ist mit folgender Software realisiert worden:</span></div><br />
<div><table class="ui_list impressum_list"><thead class="ui_list_head"><tr><th class="">Software</th><th class="">Version</th><th class="">Lizenz</th></tr></thead><tbody class="ui_list_body">' . $rows . '</tbody></table></div><br />
<br /><br />
      </div>
      <br style="clear: both;" />
    </div>
';
}
