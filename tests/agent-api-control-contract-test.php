<?php

$root = dirname(__DIR__);

$files = [
    'schema' => $root . '/includes/schema.sql',
    'auth' => $root . '/includes/auth.php',
    'router' => $root . '/includes/api/router.php',
    'app' => $root . '/includes/api/app-handler.php',
    'upload' => $root . '/includes/api/upload-handler.php',
    'profile' => $root . '/pages/profile.php',
    'docs' => $root . '/docs/AGENT_API_CONTROL.md',
];

$contents = [];
foreach ($files as $key => $path) {
    $contents[$key] = file_get_contents($path);
    if ($contents[$key] === false) {
        fwrite(STDERR, "Unable to read {$path}\n");
        exit(1);
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

foreach ([
    'scopes_json TEXT NULL',
    'revoked_at DATETIME NULL',
    'last_used_ip VARCHAR(45) NULL',
    'CREATE TABLE IF NOT EXISTS api_token_audit_logs',
    'CREATE TABLE IF NOT EXISTS api_idempotency_keys',
    'UNIQUE KEY uniq_api_idempotency_token_key',
] as $needle) {
    $assert(str_contains($contents['schema'], $needle), 'Agent API schema missing: ' . $needle);
}

foreach ([
    'function api_token_scope_catalog',
    'function api_token_allowed_scopes_for_user',
    'function api_token_normalize_scopes',
    'function api_token_has_scope',
    'function api_token_required_scope_for_action',
    'function api_token_enforce_action_scope',
    'function api_token_rate_limit_check',
    'function api_token_log_action',
    'function api_idempotency_replay_if_available',
    'function api_idempotency_store_success',
    '$GLOBALS[\'api_token_row\']',
    '$_SESSION[\'api_token_id\']',
    'scopes_json',
    'revoked_at IS NULL',
    '$scopes = null',
] as $needle) {
    $assert(str_contains($contents['auth'], $needle), 'Agent API auth missing: ' . $needle);
}

foreach ([
    'str_starts_with($action, \'app-\')',
    'api_token_enforce_action_scope($action)',
    'api_token_rate_limit_check($action)',
    'api_idempotency_replay_if_available($action)',
    "'app-create-ticket' => 'api_app_create_ticket'",
    "'app-log-time' => 'api_app_log_time'",
] as $needle) {
    $assert(str_contains($contents['router'], $needle), 'Agent API router missing: ' . $needle);
}

foreach ([
    'empty($GLOBALS[\'is_api_token_auth\'])',
    'function api_app_create_ticket',
    'function api_app_log_time',
    'api_app_contract_success',
    'can_user_create_ticket_for',
    'add_manual_time_entry',
] as $needle) {
    $assert(str_contains($contents['app'], $needle), 'Agent API app handler missing: ' . $needle);
}

$assert(str_contains($contents['upload'], "empty(\$GLOBALS['is_api_token_auth'])"), 'Upload endpoint must allow scoped API-token writes without browser CSRF.');
$assert(str_contains($contents['profile'], 'create_api_token'), 'Profile must allow users to create scoped API keys.');
$assert(str_contains($contents['profile'], 'revoke_api_token'), 'Profile must allow users to revoke scoped API keys.');
$assert(str_contains($contents['profile'], 'api_token_scope_catalog'), 'Profile must render the shared scope catalog.');
$assert(str_contains($contents['docs'], 'idempotency'), 'Agent API docs must describe idempotency.');
$assert(str_contains($contents['docs'], 'inherits the creator'), 'Agent API docs must describe permission inheritance.');

echo "Agent API control contract OK\n";
