<?php
/*
 * Local stand-in for the membership system (reference runs only). The
 * compose network alias mitglieder.piratenpartei.at points at this server,
 * so getusers() never reaches the real service. It serves the synthetic,
 * encrypted member list for /adm_api/adm_names.php, 404 for anything else,
 * and logs each request so the recorder can prove where getusers() went.
 * Must stay compatible with PHP 5.6.
 */
$pgaccStubLog = getenv('PGACC_REFERENCE_STUB_LOG');
if ($pgaccStubLog !== false && $pgaccStubLog !== '') {
    file_put_contents($pgaccStubLog, $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND | LOCK_EX);
}
if ($_SERVER['REQUEST_URI'] === '/adm_api/adm_names.php') {
    header('Content-Type: text/plain');
    readfile(__DIR__ . '/adm_names.b64');
    return true;
}
http_response_code(404);
echo "not found\n";
return true;
