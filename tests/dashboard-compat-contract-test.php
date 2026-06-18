<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$page = file_get_contents($root . '/pages/dashboard.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$module = file_get_contents($root . '/includes/modules/app/dashboard-compat.php');

$assert($page !== false && $bootstrap !== false && $module !== false, 'Dashboard compat contract files must be readable.');
$assert(str_contains($bootstrap, '/app/dashboard-compat.php'), 'Module bootstrap must load dashboard compatibility helpers.');

foreach ([
    'dashboard_tags_from_query($_GET)',
    'dashboard_selected_agent_activity((int) ($_GET[\'agent_id\'] ?? 0), $is_admin)',
] as $needle) {
    $assert(str_contains($page, $needle), 'Dashboard page must delegate compatibility behavior: ' . $needle);
}

foreach ([
    '$dashboard_tags = []',
    'function dashboard_scale_class',
    'function dashboard_notification_type_class',
    'DATE_SUB(NOW(), INTERVAL 30 DAY)',
    'SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.avatar',
] as $needle) {
    $assert(!str_contains($page, $needle), 'Dashboard page must not own extracted compatibility logic: ' . $needle);
}

foreach ([
    'function dashboard_tags_from_query',
    'function dashboard_selected_agent_activity',
    'function dashboard_legacy_tenant_filter',
    'function dashboard_scale_class',
    'function dashboard_width_class',
    'function dashboard_avatar_class',
    'function dashboard_status_group',
    'function dashboard_priority_badge_class',
    'function dashboard_notification_type_class',
] as $needle) {
    $assert(str_contains($module, $needle), 'Dashboard compatibility module missing: ' . $needle);
}

echo "Dashboard compat contract OK\n";
