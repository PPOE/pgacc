<?php

/*
 * page_report() in report.php: the $prevcond initialization that replaced
 * the undefined variable passed to unit_report() (KD-REPORT-PREVCOND).
 * unit_report() has never read its first parameter (checked in every
 * revision of report.php), so the value cannot change the report; the
 * initialization only removes the diagnostic. Tested with
 * tests/http-smoke.php, which renders the complete 2024 report. See
 * tests/mutation/README.md for the format.
 */
return array(
    'description' => 'report.php page_report() $prevcond initialization (HTTP report)',
    'file' => 'report.php',
    'region' => array('start' => 'function page_report(', 'end' => '$cond = date_condition($year,1);'),
    'tests' => array(
        array('type' => 'http', 'script' => 'tests/http-smoke.php'),
    ),
    'timeout' => 60,
    'mutations' => array(
        array(
            'id' => 'prevcond-initialization-removed',
            'search' => "\$prevcond = null;\n",
            'replace' => "\n",
            'rationale' => 'The pre-fix state: every unit_report() call reads an undefined variable.',
        ),
        array(
            'id' => 'prevcond-passes-prev-cond',
            'search' => '$prevcond = null;',
            'replace' => '$prevcond = $prev_cond;',
            'rationale' => 'Documents the alternative "fix" of passing the similarly named $prev_cond.',
            'equivalent' => array(
                'reason' => 'unit_report() never reads $prevcond (its only occurrence is the parameter '
                    . 'list, in every revision of report.php), so the report output cannot change.',
            ),
        ),
    ),
);
