<?php

// Reference runs only (tests/reference/runtime.Dockerfile): the test
// constants plus a synthetic membership export key (mbapi.php). Never a
// real key.
require __DIR__ . '/tests/runtime/test-constants.php';
$datakey = 'synthetic-reference-membership-key';
