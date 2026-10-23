<?php
// Canary: file.php/new.php/import2.php read the constants.php $key inside a
// function without `global`, so the value is always undefined there.
function canary_key($id)
{
  return md5($id . $key . $id);
}
