<?php

// Writes a class-wrapped, line-aligned copy of functions.php for Infection;
// see tests/mutation/InfectionShim.php and tests/mutation/README.md.
// Errors are uncaught exceptions, so the exit status is nonzero on failure.
//
// Usage: php infection-shim.php <functions.php> <output directory>

require_once __DIR__ . '/InfectionShim.php';
PgaccInfectionShim::main($argv);
