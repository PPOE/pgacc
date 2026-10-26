<?php

// Infection's own bootstrap (tests/mutation/infection.json): loads the
// class-wrapped copies that tests/mutation/infection-shim.php writes.
require_once '/tmp/infection-src/functions.php';
foreach (glob('/tmp/infection-src/src/*.php') as $file) {
    require_once $file;
}
