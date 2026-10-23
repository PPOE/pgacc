<?php
// Fixture for tests/unit/InfectionShimTest.php: global functions using the
// constructs that functions.php relies on. Must stay PHP 5.6-compatible.

/**
 * Defaults, globals, and output.
 */
function shim_fixture_greet($name = 'world', $suffix = '!')
{
  global $shim_fixture_prefix;
  echo "{$shim_fixture_prefix}Hello, {$name}$suffix\n";
  return strlen($name);
}
# A comment between functions.
function shim_fixture_sum(array $values, $start = 0)
{
  $total = $start;
  foreach ($values as $value) {
    if ($value > 0) { $total += $value; }
  }
  $doubled = array_map(function ($value) use (&$total) { return $value * 2; }, $values);
  return array($total, $doubled);
}
function shim_fixture_nothing() {}
?>
