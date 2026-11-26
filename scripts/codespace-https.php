<?php
// Only used by the disposable PHP preview server. No forwarded headers are trusted.
if (PHP_SAPI !== 'cli-server' || getenv('CODESPACES') !== 'true') return;
$host=getenv('SDP_CODESPACE_HOST');
if (!is_string($host) || !preg_match('/^[a-zA-Z0-9.-]+$/D',$host)) {
    http_response_code(503); exit('Preview not configured');
}
$_SERVER['HTTPS']='on';
$_SERVER['SERVER_PORT']='443';
$_SERVER['HTTP_HOST']=$host;
