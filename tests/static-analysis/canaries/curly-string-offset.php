<?php
// Canary: pre-fix functions.php:14 used $ascii{$i}, a parse error on PHP 8.
function canary_hex($ascii)
{
  $hex = '';
  for ($i = 0; $i < strlen($ascii); $i++) {
    $hex .= dechex(ord($ascii{$i}));
  }
  return $hex;
}
