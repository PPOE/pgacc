<?php

/*
 * The software table of the Impressum (impressum.php): the released pgacc
 * version, only the major versions of PHP and PostgreSQL, and the third-party
 * rows (runtime Composer packages from composer.lock, bundled software). Page code that
 * only the HTTP tests reach. See tests/mutation/README.md for the format.
 */
return array(
    'description' => 'impressum.php software versions',
    'file' => 'impressum.php',
    'tests' => array(
        array('type' => 'http', 'script' => 'tests/http-page-views.php'),
    ),
    'mutations' => array(
        array(
            'id' => 'pg-full-version',
            'search' => "\$pg_version = intval(\$pg_version_array['server']);",
            'replace' => "\$pg_version = \$pg_version_array['server'];",
            'rationale' => 'The PostgreSQL cell shows the full server version with the distribution suffix.',
        ),
        array(
            'id' => 'pg-version-float',
            'search' => "\$pg_version = intval(\$pg_version_array['server']);",
            'replace' => "\$pg_version = floatval(\$pg_version_array['server']);",
            'rationale' => 'The PostgreSQL cell shows the minor version ("18.6").',
        ),
        array(
            'id' => 'php-full-version',
            'search' => '(string) PHP_MAJOR_VERSION,',
            'replace' => 'PHP_VERSION,',
            'rationale' => 'The PHP cell shows the exact version.',
        ),
        array(
            'id' => 'pgacc-version-major',
            'search' => "PGACC_VERSION, 'LICENSE'",
            'replace' => "(string) intval(PGACC_VERSION), 'LICENSE'",
            'rationale' => 'The pgacc cell shows the released version, not only its major.',
        ),
        array(
            'id' => 'composer-dev-packages',
            'search' => "\$lock['packages'] ?? []",
            'replace' => "\$lock['packages-dev'] ?? []",
            'rationale' => 'The table lists the development packages instead of the shipped runtime packages.',
        ),
        array(
            'id' => 'composer-lock-ignored',
            'search' => 'is_readable($lockFile) ?',
            'replace' => 'false ?',
            'rationale' => 'The Composer packages are missing from the table.',
        ),
        array(
            'id' => 'composer-homepage-ignored',
            'search' => "\$url = \$package['homepage'] ?? preg_replace(",
            'replace' => "\$url = preg_replace(",
            'rationale' => 'A package links to its source repository although it has a homepage.',
        ),
        array(
            'id' => 'bundled-rows-dropped',
            'search' => "array_merge(impressum_composer_packages(__DIR__ . '/composer.lock'), IMPRESSUM_BUNDLED)",
            'replace' => "impressum_composer_packages(__DIR__ . '/composer.lock')",
            'rationale' => 'Chart.js and the Silk icons (credit required by CC BY 2.5) are missing.',
        ),
        array(
            'id' => 'third-party-version-shown',
            'search' => "\$row[1], '–', \$row[2]",
            'replace' => "\$row[1], '', \$row[2]",
            'rationale' => 'Third-party rows show no version marker.',
        ),
    ),
);
