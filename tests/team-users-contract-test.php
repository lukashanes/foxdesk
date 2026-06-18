<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$page = file_get_contents($root . '/pages/admin/users.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$module = file_get_contents($root . '/includes/modules/team/team-users.php');

$assert($page !== false && $bootstrap !== false && $module !== false, 'Team users contract files must be readable.');
$assert(str_contains($bootstrap, '/team/team-users.php'), 'Module bootstrap must load team users helpers.');

foreach ([
    'team_users_table_capabilities()',
    'team_users_filter_state($_GET)',
    'team_users_valid_organization_ids($organizations)',
    'team_users_normalize_organization_assignment(',
    'team_users_permission_payload(',
    'team_users_fetch($filter_state, $user_table_capabilities)',
    'team_users_time_totals($range_start, $range_end)',
    'team_ai_agents_fetch($deleted_at_column_exists)',
    'team_ai_agent_tokens_fetch($ai_agents)',
] as $needle) {
    $assert(str_contains($page, $needle), 'Users page must delegate team behavior: ' . $needle);
}

foreach ([
    'SELECT u.*, o.name as organization_name',
    'SELECT user_id, SUM({$dur})',
    'SELECT tte.user_id, SUM({$dur})',
    'SELECT * FROM api_tokens WHERE user_id IN',
    '$filter_tenant_organization_ids',
    '$scope_organization_ids = array_map',
    '$scope_organization_ids = $filter_tenant_organization_ids',
    '$effective_organization_ids = normalize_organization_ids(array_merge(',
] as $needle) {
    $assert(!str_contains($page, $needle), 'Users page must not own extracted team logic: ' . $needle);
}

foreach ([
    'function team_users_table_capabilities',
    'function team_users_filter_state',
    'function team_users_tenant_filter',
    'function team_users_normalize_organization_assignment',
    'function team_users_permission_payload',
    'function team_users_fetch',
    'function team_users_time_totals',
    'function team_ai_agents_fetch',
    'function team_ai_agent_tokens_fetch',
] as $needle) {
    $assert(str_contains($module, $needle), 'Team users module missing: ' . $needle);
}

echo "Team users contract OK\n";
