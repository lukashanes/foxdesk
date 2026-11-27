<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/security-helpers.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['token' => 'TEST-reset', 'share_token' => 'TEST-share', 'code' => 'TEST-oauth'];
$_POST = ['password' => 'TEST-password', 'nested' => ['authorization' => 'TEST-secret']];
if (!function_exists('security_request_log_context')) {
    throw new RuntimeException('Request metadata allowlist is missing.');
}
if (security_request_log_context() !== ['method' => 'POST']) {
    throw new RuntimeException('Request log includes user data.');
}
$_SERVER['REQUEST_METHOD'] = 'TEST-secret';
if (security_request_log_context() !== ['method' => 'OTHER']) {
    throw new RuntimeException('Arbitrary method leaked into the log.');
}
$index = file_get_contents(BASE_PATH . '/index.php');
if (!str_contains($index, "debug_log('Request', security_request_log_context()")
    || str_contains($index, '$log_post') || str_contains($index, '$log_get')) {
    throw new RuntimeException('Entry point still logs request parameters.');
}
echo "Request log privacy regression OK\n";
