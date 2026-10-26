<?php

// Writes class-wrapped, line-aligned copies of application files for Infection;
// see tests/mutation/InfectionShim.php and tests/mutation/README.md.
// Errors are uncaught exceptions, so the exit status is nonzero on failure.
//
// Usage: php infection-shim.php <application directory> <output directory> <file>...

require_once __DIR__ . '/InfectionShim.php';
PgaccInfectionShim::main($argv);
