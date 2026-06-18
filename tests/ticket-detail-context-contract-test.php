<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/tickets/ticket-share-state.php';
require_once $root . '/includes/modules/tickets/ticket-detail-context.php';

$GLOBALS['test_is_agent'] = true;
$GLOBALS['test_is_admin'] = false;

if (!function_exists('t')) {
    function t(string $key, array $params = []): string
    {
        return $key;
    }
}

if (!function_exists('url')) {
    function url(string $page, array $params = []): string
    {
        return 'index.php?page=' . $page . ($params ? '&' . http_build_query($params) : '');
    }
}

function is_agent(): bool { return (bool) $GLOBALS['test_is_agent']; }
function is_admin(): bool { return (bool) $GLOBALS['test_is_admin']; }
function get_ticket_comments(int $ticket_id): array { return [['id' => 1, 'ticket_id' => $ticket_id]]; }
function get_ticket_attachments(int $ticket_id): array { return [['id' => 2, 'ticket_id' => $ticket_id]]; }
function get_statuses(): array { return [['id' => 1, 'name' => 'Open']]; }
function ticket_tags_column_exists(): bool { return true; }
function get_ticket_tags_array(string $tags): array { return array_filter(array_map('trim', explode(',', $tags))); }
function get_all_users(): array { return [['id' => 11, 'first_name' => 'Agent']]; }
function get_ticket_access_users(int $ticket_id): array { return [['id' => 12]]; }
function get_latest_ticket_share(int $ticket_id): ?array { return ['is_revoked' => 0, 'expires_at' => null]; }
function get_ticket_share_url(string $token): string { return 'https://share.test/' . $token; }
function get_organizations(bool $active_only = true): array
{
    return [
        ['id' => 1, 'name' => 'Hidden'],
        ['id' => 2, 'name' => 'Allowed'],
        ['id' => 3, 'name' => 'Current'],
    ];
}
function get_user_organization_ids(int $user_id): array { return [2]; }

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$ticket = ['id' => 42, 'organization_id' => 3, 'tags' => 'alpha, beta', 'is_archived' => 1];
$user = ['id' => 9, 'role' => 'agent'];
$session = ['share_token' => 'token-1', 'share_token_ticket_id' => 42];

$context = ticket_detail_context(42, $ticket, $user, $session);
$assert(count($context['all_comments']) === 1, 'Context must include comments.');
$assert(count($context['attachments']) === 1, 'Context must include attachments.');
$assert($context['statuses'][0]['name'] === 'Open', 'Context must include statuses.');
$assert($context['tags_supported'] === true, 'Context must include tag support state.');
$assert($context['ticket_tags'] === ['alpha', 'beta'], 'Context must parse ticket tags.');
$assert(array_column($context['organizations'], 'id') === [2, 3], 'Context must include allowed and current organizations only.');
$assert($context['all_users'][0]['id'] === 11, 'Context must include users for agent CC selection.');
$assert($context['share_state']['share_url'] === 'https://share.test/token-1', 'Context must delegate share state.');
$assert(!isset($session['share_token'], $session['share_token_ticket_id']), 'Context must consume share session through share state.');
$assert(ticket_detail_tag_filter_url($ticket, 'alpha') === 'index.php?page=tickets&tags=alpha&archived=1', 'Tag filter URL must preserve archive state.');

$GLOBALS['test_is_admin'] = true;
$admin_orgs = ticket_detail_available_organizations($ticket, ['id' => 1, 'role' => 'admin']);
$assert(array_column($admin_orgs, 'id') === [1, 2, 3], 'Admins must see all active organizations.');

$GLOBALS['test_is_agent'] = false;
$assert(ticket_detail_available_organizations($ticket, ['id' => 2, 'role' => 'user']) === [], 'Customers must not receive agent organization options.');

echo "Ticket detail context contract OK\n";
