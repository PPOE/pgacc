<?php

/*
 * Reviewed exceptions to the 95% per-file line coverage floor
 * (check-file-floor.php). Only for files whose uncovered lines are
 * documented as unreachable; each change is reviewed. floor: the file's
 * current line coverage in percent (one decimal, as in summary.txt).
 */
return array(
    'donations.php' => array(
        'floor' => 84.4,
        'reason' => 'umlaute() (lines 12-21) is called by no page; it stays because the reference helper '
            . 'recordings (tests/reference/helpers.php) characterize it.',
    ),
);
