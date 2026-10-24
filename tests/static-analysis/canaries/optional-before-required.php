<?php
// Canary: new.php:450 page_new_buchung($part = 0, $part_to_remove, ...)
// is deprecated on PHP 8 and its output broke the login redirect.
function canary_optional_first($part = 0, $required)
{
  return $part . $required;
}
