<?php

// Synthetic configuration of the internal sync endpoint (internal/mbapi.php)
// for the test servers (tests/http-sync.php): sync.php of the image and of
// the development loop. Never a real token.
return [
    'token' => 'synthetic-test-only-sync-token',
    'peers' => ['127.0.0.1'],
];
