<?php
// Canary for the PHP 5.6 floor of testVersion: the application must still
// run on PHP 5.6, which has no null coalescing operator (PHP 7.0+).
function canary_php7_only($values)
{
  return $values['missing'] ?? 'default';
}
