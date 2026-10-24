<?php
// Canary for the PHP 5.3 floor of testVersion: production runs PHP 5.3.3,
// which has no short array syntax (PHP 5.4+).
function canary_php54_only()
{
  return [1, 2];
}
