<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$helper = file_get_contents($root . '/includes/admin-crud-helper.php');
$statuses = file_get_contents($root . '/pages/admin/statuses-content.php');
$priorities = file_get_contents($root . '/pages/admin/priorities-content.php');
$types = file_get_contents($root . '/pages/admin/ticket-types-content.php');

$assert($helper !== false && $statuses !== false && $priorities !== false && $types !== false, 'Workflow CRUD files must be readable.');

foreach ([
    'function admin_crud_slug_from_name',
    'function admin_crud_unique_slug',
    'function admin_crud_next_sort_order',
    'function admin_crud_tenant_filter',
    'function admin_crud_record_where',
    'function admin_crud_fetch_record',
    'function admin_crud_fetch_ordered',
    'function admin_crud_update_record',
    'function admin_crud_delete_record',
    'function admin_crud_clear_default',
    'function admin_crud_delete_if_unused',
] as $needle) {
    $assert(str_contains($helper, $needle), 'Admin CRUD helper missing shared function: ' . $needle);
}

$requiredByFile = [
    'statuses-content.php' => [
        $statuses,
        [
            "admin_crud_slug_from_name(\$name, '-')",
            "admin_crud_fetch_record('statuses'",
            "admin_crud_next_sort_order('statuses')",
            "admin_crud_update_record('statuses'",
            "admin_crud_delete_if_unused('statuses'",
            "admin_crud_clear_default('statuses')",
            "admin_crud_tenant_filter('tickets'",
        ],
        [
            'SELECT MAX(sort_order) as max_order FROM statuses',
            "db_update('statuses'",
            "db_delete('statuses'",
            'UPDATE statuses SET is_default = 0',
        ],
    ],
    'priorities-content.php' => [
        $priorities,
        [
            "admin_crud_unique_slug('priorities'",
            "admin_crud_next_sort_order('priorities')",
            "admin_crud_update_record('priorities'",
            "admin_crud_delete_if_unused('priorities'",
            "admin_crud_clear_default('priorities')",
            "admin_crud_tenant_filter('tickets'",
        ],
        [
            'SELECT id FROM priorities WHERE slug',
            'SELECT MAX(sort_order) as max_order FROM priorities',
            "db_update('priorities'",
            'DELETE FROM priorities WHERE id',
            'UPDATE priorities SET is_default = 0',
        ],
    ],
    'ticket-types-content.php' => [
        $types,
        [
            "admin_crud_unique_slug('ticket_types'",
            "admin_crud_next_sort_order('ticket_types')",
            "admin_crud_fetch_record('ticket_types'",
            "admin_crud_fetch_ordered('ticket_types')",
            "admin_crud_update_record('ticket_types'",
            "admin_crud_delete_record('ticket_types'",
            "admin_crud_clear_default('ticket_types')",
            "admin_crud_tenant_filter('tickets'",
        ],
        [
            'SELECT id FROM ticket_types WHERE slug',
            'SELECT MAX(sort_order) as max_order FROM ticket_types',
            "db_update('ticket_types'",
            'DELETE FROM ticket_types WHERE id',
            'SELECT * FROM ticket_types ORDER BY sort_order',
        ],
    ],
];

foreach ($requiredByFile as $file => [$contents, $required, $forbidden]) {
    foreach ($required as $needle) {
        $assert(str_contains($contents, $needle), "{$file} must delegate workflow CRUD through helper: {$needle}");
    }

    foreach ($forbidden as $needle) {
        $assert(!str_contains($contents, $needle), "{$file} must not keep legacy inline workflow CRUD: {$needle}");
    }
}

echo "Workflow CRUD contract OK\n";
