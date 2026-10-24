<?php
/*
 * PHP 5.6-8.3 fallback for reading HTTP response headers. Only included when
 * http_get_last_response_headers() is unavailable: PHP 8.5 deprecates the
 * magic $http_response_header variable at compile time, so this file must not
 * be compiled there. The variable has to be referenced literally; PHP only
 * populates it for compiled local variables of the calling function.
 */

function legacy_file_get_contents_with_headers($url, $context)
{
    $body = @file_get_contents($url, false, $context);
    $headers = isset($http_response_header) ? $http_response_header : array();
    return array($body, $headers);
}
