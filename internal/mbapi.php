<?php

// The internal sync endpoint (src/sync-api.php), the only file of the
// document root of the internal listener. The code and sync-secrets.php are in the
// release root, outside the document root. Fail closed if that is not
// possible: nothing below may run elsewhere.
if (!chdir(dirname(__DIR__))) {
    error_log('pgacc: cannot change to the release root ' . dirname(__DIR__));
    http_response_code(500);
    exit(1);
}

require 'src/sync-api.php';
sync_api_main();
