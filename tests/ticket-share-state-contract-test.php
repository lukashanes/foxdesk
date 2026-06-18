<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/tickets/ticket-share-state.php';

if (!function_exists('t')) {
    function t(string $key, array $params = []): string
    {
        return $key;
    }
}

if (!function_exists('get_ticket_access_users')) {
    function get_ticket_access_users(int $ticket_id): array
    {
        return $ticket_id === 42 ? [
            ['id' => '7', 'first_name' => 'Ada'],
            ['id' => 8, 'first_name' => 'Linus'],
        ] : [];
    }
}

if (!function_exists('get_latest_ticket_share')) {
    function get_latest_ticket_share(int $ticket_id): ?array
    {
        if ($ticket_id === 42) {
            return ['is_revoked' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + 3600)];
        }
        if ($ticket_id === 43) {
            return ['is_revoked' => 1, 'expires_at' => null];
        }
        if ($ticket_id === 44) {
            return ['is_revoked' => 0, 'expires_at' => date('Y-m-d H:i:s', time() - 3600)];
        }
        return null;
    }
}

if (!function_exists('get_ticket_share_url')) {
    function get_ticket_share_url(string $token): string
    {
        return 'https://example.test/ticket-share.php?token=' . $token;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$session = ['share_token' => 'abc123', 'share_token_ticket_id' => 42];
$state = ticket_detail_share_state(42, true, $session);
$assert($state['share_status'] === 'active', 'Future non-revoked share must be active.');
$assert($state['share_status_label'] === 'Active', 'Active share label mismatch.');
$assert($state['share_status_class'] === 'text-green-600', 'Active share class mismatch.');
$assert($state['share_url'] === 'https://example.test/ticket-share.php?token=abc123', 'Share URL must use consumed session token.');
$assert(!isset($session['share_token'], $session['share_token_ticket_id']), 'Share session token must be consumed.');
$assert($state['shared_user_ids'] === [7, 8], 'Shared user IDs must be normalized to integers.');

$session = [];
$revoked = ticket_detail_share_state(43, false, $session);
$assert($revoked['share_status'] === 'revoked', 'Revoked share status mismatch.');
$assert($revoked['share_status_class'] === 'text-red-600', 'Revoked share class mismatch.');
$assert($revoked['shared_users'] === [], 'Shared users must be omitted when not requested.');

$expired = ticket_detail_share_state(44, true, $session);
$assert($expired['share_status'] === 'expired', 'Expired share status mismatch.');
$assert($expired['share_status_class'] === 'text-orange-600', 'Expired share class mismatch.');

$none = ticket_detail_share_state(99, true, $session);
$assert($none['share_status'] === 'none', 'Missing share must use none status.');
$assert($none['share_status_class'] === 'td-text-muted', 'None share class mismatch.');

echo "Ticket share state contract OK\n";
