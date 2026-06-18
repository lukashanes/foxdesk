<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/tickets/ticket-bulk-actions.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$redirect = ticket_bulk_action_redirect_params([
    'archived' => '1',
    'work_view' => 'done',
    'status' => '2',
    'search' => 'solar',
    'view' => 'board',
    'organization' => '',
    'ignored' => 'value',
]);

$assert($redirect === [
    'archived' => '1',
    'work_view' => 'done',
    'status' => '2',
    'search' => 'solar',
    'view' => 'board',
], 'Bulk redirect params must preserve only supported non-empty filters.');

$empty_update = ticket_bulk_update_data_from_post([
    'bulk_organization_id' => '__keep__',
    'bulk_status_id' => '',
    'bulk_priority_id' => '',
    'bulk_tags_mode' => 'replace',
    'bulk_tags' => 'urgent',
], $redirect);

$assert($empty_update['has_update'] === false, 'Unsupported tags must not count as an update when tags column helper is unavailable.');
$assert($empty_update['tags_mode'] === 'keep', 'Unsupported tags must force keep mode.');
$assert($empty_update['base_update_data'] === [], 'Empty bulk update must not produce base update data.');

$page = file_get_contents($root . '/pages/tickets.php');
$module = file_get_contents($root . '/includes/modules/tickets/ticket-bulk-actions.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$assert($page !== false && $module !== false && $bootstrap !== false, 'Bulk action files must be readable.');
$assert(str_contains($bootstrap, '/tickets/ticket-bulk-actions.php'), 'Module bootstrap must load ticket bulk actions.');
$assert(str_contains($page, 'ticket_bulk_action_redirect_params($_GET)'), 'Tickets page must delegate redirect param parsing.');
$assert(str_contains($page, "ticket_handle_bulk_actions(\$_SERVER['REQUEST_METHOD'] ?? 'GET', \$_POST, \$user, \$is_archive, \$_redirect_params)"), 'Tickets page must delegate bulk POST handling.');
$assert(!str_contains($page, '$collect_editable_tickets = function'), 'Tickets page must not own editable-ticket collection.');
$assert(!str_contains($page, "isset(\$_POST['bulk_delete'])"), 'Tickets page must not own bulk delete handling.');
$assert(!str_contains($page, "isset(\$_POST['bulk_archive'])"), 'Tickets page must not own bulk archive handling.');
$assert(!str_contains($page, "isset(\$_POST['bulk_update'])"), 'Tickets page must not own bulk update handling.');

foreach ([
    'function ticket_bulk_editable_tickets',
    'function ticket_bulk_delete_archived',
    'function ticket_bulk_archive',
    'function ticket_bulk_update_data_from_post',
    'function ticket_bulk_update',
    'function ticket_handle_bulk_actions',
] as $needle) {
    $assert(str_contains($module, $needle), 'Ticket bulk action module missing: ' . $needle);
}

echo "Ticket bulk actions contract OK\n";
