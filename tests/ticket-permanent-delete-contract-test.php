<?php

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$module = file_get_contents($root . '/includes/modules/tickets/ticket-permanent-delete.php');
$agent = file_get_contents($root . '/includes/api/agent-handler.php');
$router = file_get_contents($root . '/includes/api/router.php');
$auth = file_get_contents($root . '/includes/auth.php');
$ticketHandler = file_get_contents($root . '/includes/api/ticket-handler.php');
$sidebar = file_get_contents($root . '/includes/components/ticket-detail-sidebar.php');
$modals = file_get_contents($root . '/includes/components/ticket-detail-modals.php');
$script = file_get_contents($root . '/assets/js/ticket-detail-admin.js');
$bulk = file_get_contents($root . '/includes/modules/tickets/ticket-bulk-actions.php');
$crud = file_get_contents($root . '/includes/ticket-crud-functions.php');
$cron = file_get_contents($root . '/pages/cron.php');
$teamModel = file_get_contents($root . '/includes/modules/team/team-users.php');
$teamUi = file_get_contents($root . '/includes/components/team/users-surface.php')
    . "\n" . file_get_contents($root . '/includes/components/team/user-edit-surface.php');

foreach ([$module, $agent, $router, $auth, $ticketHandler, $sidebar, $modals, $script, $bulk, $crud, $cron, $teamModel, $teamUi] as $source) {
    $assert($source !== false, 'A permanent-delete contract source file is missing.');
}

$assert(str_contains($module, 'function ticket_permanent_delete_preflight'), 'Deletion preflight is missing.');
$assert(!preg_match('/CREATE\s+TABLE|ALTER\s+TABLE/i', $module), 'Permanent deletion must not mutate the schema during a request.');
$assert(str_contains($module, 'Database upgrade required before permanent ticket deletion.'), 'Missing-schema failure is not actionable.');
$assert(str_contains($module, 'hash_equals($preflight[\'ticket_code\']'), 'Exact ticket-code confirmation is not timing-safe.');
$assert(str_contains($module, 'db_begin_owned(') && str_contains($module, 'db_rollback_owned('), 'Database deletion is not transactional.');
$assert(!str_contains($module, 'beginTransaction()'), 'Deletion must join an outer (Idempotency-Key) transaction instead of opening a nested one.');
$assert(str_contains($module, 'ticket_storage_deletion_outbox'), 'Attachment cleanup outbox is missing.');
$assert(str_contains($module, 'ticket_permanent_delete_related_records'), 'Related records are not explicitly removed.');
$assert(str_contains($module, "'ticket_history'"), 'Ticket field history is not removed.');
$assert(str_contains($module, "'recurring_task_runs'"), 'Recurring-task ticket references are not removed.');
$assert(str_contains($module, 'ticket_permanent_delete_remove_idempotency_references'), 'Stale API replays are not invalidated.');
$assert(str_contains($module, "column_exists('report_templates', 'tenant_id')") && str_contains($module, "db_delete('report_snapshots', '1 = 1')"), 'Self-hosted report snapshot invalidation must not require a tenant_id column.');
$assert(str_contains($module, "column_exists('api_idempotency_keys', 'tenant_id')"), 'Self-hosted api_idempotency_keys has no tenant_id column; stale replay cleanup must not require it.');
$assert(str_contains($module, 'ticket_permanent_delete_retry_pending_storage'), 'Failed storage cleanup has no retry worker.');
$assert(str_contains($module, "'attachment_payload' => '{}'"), 'Successful storage cleanup retains attachment metadata.');
$assert(str_contains($cron, 'ticket_permanent_delete_retry_pending_storage(25)'), 'Pseudo-cron does not retry pending storage deletion.');
$assert(str_contains($cron, "storage_cleanup['failed']"), 'Permanently failed storage cleanup is not surfaced.');
$assert(str_contains($module, "log_security_event('ticket_permanently_deleted'"), 'Minimal deletion audit event is missing.');

$assert(str_contains($auth, "'agent-delete-ticket-permanently' => 'delete:write'"), 'Permanent-delete API scope is missing.');
$assert(str_contains($agent, "api_token_has_scope('tickets:read')"), 'Permanent deletion does not require ticket read scope.');
$assert(str_contains($agent, 'Partial ticket deletion is not supported.'), 'Partial deletion is not rejected.');
$assert(str_contains($router, "'agent-delete-ticket-preflight' => 'api_agent_delete_ticket_preflight'"), 'Agent preflight route is missing.');
$assert(str_contains($router, "'agent-delete-ticket-permanently' => 'api_agent_delete_ticket_permanently'"), 'Agent deletion route is missing.');
$assert(str_contains($ticketHandler, 'function api_permanent_delete_ticket_preflight'), 'Browser preflight endpoint is missing.');
$assert(str_contains($ticketHandler, 'function api_permanent_delete_ticket'), 'Browser deletion endpoint is missing.');

$assert(str_contains($sidebar, 'data-open-permanent-delete'), 'Authorized admin delete action is missing.');
$assert(!str_contains($modals, 'data-permanent-delete-confirmation'), 'Permanent delete still asks the user to type a ticket reference.');
$assert(str_contains($script, 'if (!expectedCode) return;'), 'Delete confirmation is not gated by a successful preflight.');
$assert(str_contains($script, "formData.append('confirmation', expectedCode)"), 'Second-click confirmation does not send the server-required ticket reference.');
$assert(str_contains($bulk, 'Bulk permanent deletion is not available'), 'Unsafe bulk deletion has not been disabled.');
$assert(str_contains($crud, 'Use ticket_permanent_delete()'), 'Legacy delete_ticket path is still destructive.');
$assert(str_contains($teamModel, "'can_delete_tickets_permanently' => \$role === 'agent'"), 'Agent permission payload does not persist the explicit permanent-delete capability.');
$assert(str_contains($teamUi, 'name="can_delete_tickets_permanently"'), 'Admin UI cannot explicitly grant permanent-delete capability.');

echo "Ticket permanent delete contract OK\n";
