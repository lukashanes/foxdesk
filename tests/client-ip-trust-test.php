<?php
require dirname(__DIR__) . '/includes/auth.php';
require dirname(__DIR__) . '/includes/security-helpers.php';
foreach (['172.1.2.3', '172.200.1.2', '8.8.8.8'] as $remote) {
    $_SERVER = ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'];
    if (get_client_ip() !== $remote) throw new RuntimeException('Public peer controls limiter identity: ' . $remote);
}
$_SERVER = ['REMOTE_ADDR' => '172.16.0.2', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 172.16.0.1'];
if (get_client_ip() !== '1.1.1.1') throw new RuntimeException('Private reverse proxy must keep forwarding support.');
$_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => 'TEST-arbitrary-key'];
if (get_client_ip() !== '127.0.0.1') throw new RuntimeException('Invalid forwarded identity must be rejected.');
$_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => '8.8.8.8', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'];
if (get_client_ip() !== '1.1.1.1') throw new RuntimeException('Unconfigured Cloudflare header overrode the local proxy.');
define('FOXDESK_TRUST_CF_CONNECTING_IP', true);
if (get_client_ip() !== '8.8.8.8') throw new RuntimeException('Explicit Cloudflare proxy configuration must keep working.');
echo "Client IP trust and limiter identity regression OK\n";
