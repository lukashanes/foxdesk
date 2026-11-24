<?php
require dirname(__DIR__) . '/includes/web-push.php';
$writes = 0;
function schema_require(...$args) {}
function db_query(...$args) { $GLOBALS['writes']++; return true; }
function db_insert(...$args) { $GLOBALS['writes']++; return 1; }
$assert = static function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$assert(!save_push_subscription(1, 'http://127.0.0.1/internal'), 'Internal push target was stored.');
$assert($writes === 0, 'Invalid subscription must not touch storage.');
$public = static fn($host) => ['8.8.8.8'];
foreach (['http://push.example.test/x', 'https://user:pass@push.example.test/x', 'https://push.example.test:8080/x',
    'file:///etc/passwd', 'https://127.0.0.1/x', 'https://[::1]/x', 'https://2130706433/x', 'https://0x7f000001/x', 'https://push.example.test/x#fragment'] as $url) {
    $assert(web_push_resolve_target($url, $public) === null, 'Invalid URL accepted: ' . $url);
}
foreach (['10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '192.0.2.1', '198.18.0.1', '2001:db8::1', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1'] as $ip) {
    $assert(web_push_resolve_target('https://push.example.test/x', static fn($host) => [$ip]) === null, 'Private/reserved DNS answer accepted: ' . $ip);
}
$assert(web_push_resolve_target('https://push.example.test/x', static fn($host) => ['8.8.8.8', '127.0.0.1']) === null, 'Mixed public/private DNS must fail closed.');
$assert(web_push_resolve_target('https://push.example.test/x', static fn($host) => []) === null, 'Unresolved host accepted.');
$target = web_push_resolve_target('https://push.example.test/x', $public);
$assert($target === ['host' => 'push.example.test', 'ip' => '8.8.8.8'], 'Public HTTPS target must remain supported.');
$target = web_push_resolve_target('https://push.example.test/x', static fn($host) => ['2606:4700:4700::1111']);
$assert(($target['ip'] ?? '') === '2606:4700:4700::1111', 'Public IPv6 target must remain supported.');
$source = file_get_contents(dirname(__DIR__) . '/includes/web-push.php');
$assert(str_contains($source, 'CURLOPT_RESOLVE') && str_contains($source, 'CURLPROTO_HTTPS'), 'Transport must pin validated DNS and restrict protocol.');
$handler = file_get_contents(dirname(__DIR__) . '/includes/api/push-handler.php');
$assert(substr_count($handler, 'require_csrf_token(true)') >= 2, 'Push subscription writes need CSRF protection.');
echo "Web push target, DNS rebinding and CSRF regression OK (offline resolver)\n";
