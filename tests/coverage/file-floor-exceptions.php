<?php

/*
 * Reviewed exceptions to the 95% per-file line coverage floor
 * (check-file-floor.php). Only for files whose uncovered lines are
 * documented as unreachable; each change is reviewed. floor: the file's
 * current line coverage in percent (one decimal, as in summary.txt).
 */
return array(
    'donations.php' => array(
        'floor' => 77.3,
        'reason' => 'umlaute() (lines 12-21) is called nowhere, and the donor rows (120-121, 127-132) are dead: '
            . 'the collection of $donations is commented out for legal reasons, so the loops never run.',
    ),
    'tinyreport.php' => array(
        'floor' => 94.1,
        'reason' => '$unit is always -1, so the four "AND orga" else branches (27, 39, 59, 71) never run, and '
            . 'line 96 needs empty rights inside the strlen($rights) > 0 block.',
    ),
);
