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
$target = web_push_resolve_target('https://push.example.test/x', static fn($host) => ['2606:4700:4700::1111', '8.8.8.8']);
$assert(($target['ip'] ?? '') === '8.8.8.8', 'Dual-stack targets must prefer IPv4 on hosts without IPv6 connectivity.');
$assert(web_push_resolve_target('https://push.xn--p1ai/x', $public) !== null, 'ASCII IDN hostname must remain supported.');
$source = file_get_contents(dirname(__DIR__) . '/includes/web-push.php');
$assert(str_contains($source, 'CURLOPT_RESOLVE') && str_contains($source, 'CURLPROTO_HTTPS'), 'Transport must pin validated DNS and restrict protocol.');
$handler = file_get_contents(dirname(__DIR__) . '/includes/api/push-handler.php');
$assert(substr_count($handler, 'require_csrf_token(true)') >= 2, 'Push subscription writes need CSRF protection.');

// Execute send_web_push itself with a recording transport, never a network request.
$dns = ['2606:4700:4700::1111', '8.8.8.8']; $transport = []; $status = 201;
function push_fixture_resolve($endpoint) { return web_push_resolve_target($endpoint, static fn($host) => $GLOBALS['dns']); }
function push_fixture_keys() { return ['public' => 'TEST-public', 'private' => 'TEST-private']; }
function push_fixture_jwt(...$args) { return 'TEST-jwt'; }
function push_fixture_init($endpoint) { $GLOBALS['transport']['endpoint'] = $endpoint; return new stdClass(); }
function push_fixture_options($ch, $options) { $GLOBALS['transport']['options'] = $options; }
function push_fixture_exec($ch) { return true; }
function push_fixture_info($ch, $key) { return $GLOBALS['status']; }
function push_fixture_close($ch) {}
$start = strpos($source, 'function send_web_push(');
$end = strpos($source, 'function create_vapid_jwt(', $start);
$send = substr($source, $start, $end - $start);
$send = str_replace(['function send_web_push(', 'web_push_resolve_target(', 'get_vapid_keys(', 'create_vapid_jwt(',
    'curl_init(', 'curl_setopt_array(', 'curl_exec(', 'curl_getinfo(', 'curl_close('],
    ['function push_fixture_send(', 'push_fixture_resolve(', 'push_fixture_keys(', 'push_fixture_jwt(',
    'push_fixture_init(', 'push_fixture_options(', 'push_fixture_exec(', 'push_fixture_info(', 'push_fixture_close('], $send);
eval($send);
$assert(push_fixture_send('https://push.example.test/x'), 'Valid delivery result was rejected.');
$options = $transport['options'];
$assert($options[CURLOPT_RESOLVE] === ['push.example.test:443:8.8.8.8'], 'Transport must pin the validated IPv4 address.');
$assert($options[CURLOPT_PROXY] === '' && $options[CURLOPT_FOLLOWLOCATION] === false
    && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'Proxy/redirect/protocol SSRF boundary changed.');
$dns = ['2606:4700:4700::1111'];
$assert(push_fixture_send('https://push.example.test/x'), 'IPv6-only delivery result was rejected.');
$assert($transport['options'][CURLOPT_RESOLVE] === ['push.example.test:443:[2606:4700:4700::1111]'], 'IPv6 pin must be bracketed.');
$transport = []; $dns = ['127.0.0.1'];
$assert(!push_fixture_send('https://push.example.test/x') && $transport === [], 'Invalid DNS must never reach transport.');
$dns = ['8.8.8.8']; $status = 410;
$before = $writes;
$assert(!push_fixture_send('https://push.example.test/x') && $writes === $before + 1, 'Expired subscription must still be removed.');
echo "Web push target, DNS rebinding and CSRF regression OK (offline resolver)\n";
