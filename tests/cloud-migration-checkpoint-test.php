<?php

require_once dirname(__DIR__) . '/includes/migration-functions.php';

$settings = [];
$existing_tables = ['statuses', 'priorities', 'organizations', 'users', 'ticket_message_attachments'];
$table_queries = [];

function get_setting($name, $default = null)
{
    return $GLOBALS['settings'][$name] ?? $default;
}

function save_setting($name, $value): void
{
    $GLOBALS['settings'][$name] = $value;
}

function table_exists($table): bool
{
    return in_array($table, $GLOBALS['existing_tables'], true);
}

function validate_sql_identifier($table): void
{
    if (!preg_match('/^[a-z_]+$/D', $table)) {
        throw new InvalidArgumentException('Invalid fixture table name.');
    }
}

function db_fetch_all($sql): array
{
    if (str_starts_with($sql, 'SHOW COLUMNS FROM ')) {
        return [['Field' => 'id']];
    }
    $GLOBALS['table_queries'][] = $sql;
    return []; // Empty fixture chunk, so the migration never calls Cloud.
}

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;
    $failures += $condition ? 0 : 1;
};
$state_key = 'migration_cloud_sync_state_json';
$settings[$state_key] = '{bad';
$state = migration_cloud_sync_state();
$check($state['stage'] === 'idle' && $state['tables'] === ['statuses', 'priorities', 'organizations', 'users']
    && $state['limits']['rows'] === 250, 'Unreadable checkpoint starts idle using the current schema.');

$settings[$state_key] = json_encode(['stage' => 'tables', 'tables' => [], 'post_attachment_tables' => []]);
$state = migration_cloud_sync_state();
$check($state['tables'] === [] && $state['post_attachment_tables'] === [], 'Explicit empty queues remain empty on reload.');

$settings[$state_key] = json_encode([
    'stage' => 'tables', 'tables' => ['organizations', 'users'], 'current_table_index' => 1,
    'offset' => 75, 'limits' => ['rows' => 2000, 'attachments' => 0, 'seconds' => 2],
]);
$state = migration_cloud_sync_state();
$check($state['tables'] === ['organizations', 'users'] && $state['offset'] === 75
    && $state['limits'] === ['rows' => 1000, 'attachments' => 1, 'seconds' => 5],
    'Resume keeps the saved order and offset, with bounded batch sizes.');

$existing_tables = ['users', 'ticket_message_attachments'];
$state = migration_cloud_sync_state();
migration_cloud_sync_one_table_batch($state, '', '', $state['tables'], 'attachments');
$check($table_queries === ['SELECT * FROM users ORDER BY id ASC LIMIT 1000 OFFSET 75']
    && $state['current_table_index'] === 2 && $state['offset'] === 0,
    'A missing earlier table does not shift the saved cursor to another table.');

$table_queries = [];
$settings[$state_key] = json_encode([
    'stage' => 'tables', 'tables' => ['settings', 'users'], 'current_table_index' => 0, 'offset' => 40,
]);
$state = migration_cloud_sync_state();
migration_cloud_sync_one_table_batch($state, '', '', $state['tables'], 'attachments');
$check($table_queries === ['SELECT * FROM users ORDER BY id ASC LIMIT 250 OFFSET 0'],
    'A disallowed saved table is skipped without applying its offset to the next table.');
migration_cloud_sync_one_table_batch($state, '', '', $state['tables'], 'attachments');
$check($state['stage'] === 'attachments' && $state['current_table_index'] === 0 && $state['current_table'] === null,
    'An exhausted queue resets its cursor when advancing to the attachments stage.');

if ($failures > 0) {
    exit(1);
}
