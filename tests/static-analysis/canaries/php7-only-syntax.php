<?php
// Canary for the floor of testVersion: production runs PHP 5.3.3, which has
// no null coalescing operator (PHP 7.0+).
function canary_php7_only($values)
{
  return $values['missing'] ?? 'default';
}
