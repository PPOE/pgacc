<?php

/*
 * The software table of the Impressum (impressum.php): the released pgacc
 * version, and only the major versions of PHP and PostgreSQL. Page code that
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
            'search' => "<div>' . PHP_MAJOR_VERSION . '</div>",
            'replace' => "<div>' . PHP_VERSION . '</div>",
            'rationale' => 'The PHP cell shows the exact version.',
        ),
        array(
            'id' => 'pgacc-version-major',
            'search' => "<div>' . PGACC_VERSION . '</div>",
            'replace' => "<div>' . intval(PGACC_VERSION) . '</div>",
            'rationale' => 'The pgacc cell shows the released version, not only its major.',
        ),
    ),
);
