<?php

$root = dirname(__DIR__);
$helper = file_get_contents($root . '/includes/modules/tickets/ticket-status-transition.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$web = file_get_contents($root . '/includes/components/ticket-form-handlers.php');
$api = file_get_contents($root . '/includes/api/ticket-handler.php');
$agent = file_get_contents($root . '/includes/api/agent-handler.php');

if ($helper === false || $bootstrap === false || $web === false || $api === false || $agent === false) {
    fwrite(STDERR, "Unable to read ticket status transition sources.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(str_contains($bootstrap, '/tickets/ticket-status-transition.php'), 'Module bootstrap must load the ticket status transition helper.');
$assert(str_contains($helper, 'db_begin_owned(') && str_contains($helper, 'db_commit_owned(') && str_contains($helper, 'db_rollback_owned('), 'Status and timer changes must share a database transaction.');
$assert(!str_contains($helper, 'beginTransaction()'), 'The helper must join an outer (Idempotency-Key) transaction instead of opening a nested one.');
$assert(str_contains($helper, "ticket_status_group_from_status(\$new_status) === 'done'"), 'Canonical Done must trigger completion side effects.');
$assert(str_contains($helper, 'stop_active_ticket_timer($ticket_id, $actor_id)'), 'Completing a ticket must stop the active timer in the shared helper.');
$assert(substr_count($web, 'ticket_transition_status(') >= 2, 'Both web status-change paths must use the shared helper.');
$assert(str_contains($api, 'ticket_transition_status('), 'The browser API status path must use the shared helper.');
$assert(str_contains($agent, 'ticket_transition_status('), 'The agent API status path must use the shared helper.');
$assert(str_contains($api, "'timer_stopped' => !empty(\$transition['timer_stopped'])"), 'Browser API must report timer stop state.');
$assert(str_contains($agent, "'timer_stopped'") && str_contains($agent, 'ticket_workflow_apply('), 'Agent API must report timer stop state.');

echo "Ticket status transition contract passed.\n";
