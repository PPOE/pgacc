<?php

/*
 * Reviewed exceptions to the 95% per-file line coverage floor
 * (check-file-floor.php). Only for files whose uncovered lines are
 * documented as unreachable; each change is reviewed. floor: the file's
 * current line coverage in percent (one decimal, as in summary.txt).
 */
return array(
    'tinyreport.php' => array(
        'floor' => 94.1,
        'reason' => '$unit is always -1, so the four "AND orga" else branches (27, 39, 59, 71) never run, and '
            . 'line 96 needs empty rights inside the strlen($rights) > 0 block.',
    ),
);
