<?php
// Fixture for tests/unit/InfectionShimTest.php: a library file in src/.
function shim_fixture_library($value)
{
  return shim_fixture_greet($value) * 2;
}
