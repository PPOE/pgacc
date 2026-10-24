<?php
// Canary: pre-fix rights2orgasql() appended to $rights2 without initializing
// it, then passed it to count(); count(null) throws TypeError on PHP 8.
function canary_rights($rights)
{
  foreach (explode(',', $rights) as $right) {
    if ($right !== '') {
      $rights2[] = "'" . $right . "'";
    }
  }
  if (count($rights2) > 0) {
    return implode(',', $rights2);
  }
  return 'false';
}
