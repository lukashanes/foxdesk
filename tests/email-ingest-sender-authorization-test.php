<?php
/**
 * Inbound email sender authorization: explicit allowlist entries stay
 * authoritative, active users are accepted without a duplicate allowlist
 * entry, and unknown, inactive or deleted senders remain blocked.
 */

define('BASE_PATH', dirname(__DIR__));

$scenario = 'allowlist';
$queries = [];

function column_exists(string $table, string $column): bool
{
    return $table === 'users' && $column === 'deleted_at';
}

function t(string $value): string
{
    return $value;
}

function db_fetch_one(string $sql, array $params = [])
{
    global $scenario, $queries;
    $queries[] = ['sql' => $sql, 'params' => $params];

    if (str_contains($sql, 'FROM allowed_senders')) {
        if ($scenario === 'allowlist') {
            return [
                'id' => 10,
                'type' => 'email',
                'value' => 'member@example.test',
                'user_id' => null,
                'active' => 1,
            ];
        }
        return null;
    }

    if (str_contains($sql, 'FROM users')) {
        if ($scenario === 'active_user') {
            return [
                'user_id' => 42,
                'value' => 'member@example.test',
            ];
        }
        return null;
    }

    return null;
}

function assert_sender_authorization(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'Email sender authorization test failed: ' . $message . PHP_EOL);
        exit(1);
    }
}

require_once BASE_PATH . '/includes/email-ingest-functions.php';

$allowed = email_ingest_allowed_sender('member@example.test');
assert_sender_authorization(($allowed['source'] ?? '') === 'allowlist', 'Explicit allowlist entries must remain authoritative.');

$scenario = 'active_user';
$queries = [];
$active_user = email_ingest_allowed_sender('MEMBER@example.test');
assert_sender_authorization(($active_user['source'] ?? '') === 'workspace_user', 'Active users must be accepted automatically.');
assert_sender_authorization((int) ($active_user['user_id'] ?? 0) === 42, 'User identity must be preserved for ticket ownership.');

$user_query = end($queries);
assert_sender_authorization(str_contains((string) ($user_query['sql'] ?? ''), 'is_active = 1'), 'Inactive users must not be accepted.');
assert_sender_authorization(str_contains((string) ($user_query['sql'] ?? ''), 'deleted_at IS NULL'), 'Deleted users must not be accepted.');
assert_sender_authorization(($user_query['params'] ?? []) === ['member@example.test'], 'User lookup must use the normalized sender address.');
assert_sender_authorization(!str_contains((string) ($user_query['sql'] ?? ''), 'tenant_id'), 'Self-hosted user lookup must not require a tenant_id column.');

$scenario = 'unknown';
$queries = [];
assert_sender_authorization(email_ingest_allowed_sender('unknown@example.test') === null, 'Unknown senders must remain blocked.');
assert_sender_authorization(email_ingest_allowed_sender('') === null, 'Empty senders must remain blocked.');

echo "Email sender authorization tests passed\n";
