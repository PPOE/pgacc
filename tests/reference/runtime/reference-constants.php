<?php

// Reference runs only (tests/reference/runtime.Dockerfile): the test
// constants plus a synthetic membership-system key, so that getusers() can
// decrypt the local stub's synthetic member list
// (tests/reference/membership/). Never a real key.
require __DIR__ . '/tests/runtime/test-constants.php';
$datakey = 'synthetic-reference-membership-key';
