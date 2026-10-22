<?php

$root = dirname(__DIR__);

$module = file_get_contents($root . '/includes/modules/tickets/ticket-detail-actions.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$page = file_get_contents($root . '/pages/ticket-detail.php');
$content = file_get_contents($root . '/includes/components/ticket-detail-content.php') . "\n" . file_get_contents($root . '/includes/components/ticket-workspace-actions.php');
$sidebar = file_get_contents($root . '/includes/components/ticket-detail-sidebar.php');
$handlers = file_get_contents($root . '/includes/components/ticket-form-handlers.php');
$status_transition = file_get_contents($root . '/includes/modules/tickets/ticket-status-transition.php');
$time_functions = file_get_contents($root . '/includes/ticket-time-functions.php');
$detail_js = file_get_contents($root . '/assets/js/ticket-detail-timer.js');

if ($module === false || $bootstrap === false || $page === false || $content === false || $sidebar === false || $handlers === false || $status_transition === false || $time_functions === false || $detail_js === false) {
    fwrite(STDERR, "Unable to read ticket detail action files.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(str_contains($bootstrap, '/tickets/ticket-detail-actions.php'), 'Module bootstrap must load ticket detail actions.');
$assert(str_contains($module, 'function ticket_detail_primary_actions'), 'Primary action model is missing.');
$assert(str_contains($module, "'key' => 'reply'"), 'Reply must be a primary action.');
$assert(str_contains($module, "'key' => 'start_work'"), 'Start work must be a primary action.');
$assert(str_contains($module, "'key' => 'assign'"), 'Assign must be a primary action.');
$assert(str_contains($module, "'key' => 'complete'"), 'Complete must be a primary action.');
$assert(str_contains($page, 'ticket_detail_primary_actions('), 'Ticket detail page must consume the action model.');
$assert(str_contains($page, '/includes/components/ticket-detail-content.php'), 'Ticket detail route must delegate its content surface.');
$assert(str_contains($content, 'class="ticket-work-panel"'), 'Ticket detail content must render the work panel.');
$assert(str_contains($module, "'id' => 'toolbar-timer-btn'"), 'Timer button id must stay stable in the action model.');
$assert(str_contains($detail_js, "document.getElementById('toolbar-timer-btn')"), 'Existing timer JS must still target toolbar-timer-btn.');
$assert(str_contains($content, "/includes/components/ticket-detail-sidebar.php"), 'Ticket detail content must include the side properties panel component.');
$assert(str_contains($sidebar, 'id="ticket-side-panel"'), 'Assign action must target the side properties panel.');
$assert(str_contains($sidebar, "t('Ticket properties')"), 'Side panel must have a clear properties heading.');
$assert(str_contains($content, 'title="<?php echo e($action_title); ?>"'), 'Primary actions must expose mouse-over help text.');
$assert(str_contains($content, 'aria-label="<?php echo e($action_title); ?>"'), 'Primary actions must expose accessible labels.');
$assert(str_contains($time_functions, 'function stop_active_ticket_timer'), 'Shared active timer stop helper is missing.');
$assert(str_contains($handlers, 'ticket_transition_status('), 'Status changes must use the shared transactional transition helper.');
$assert(str_contains($status_transition, 'stop_active_ticket_timer($ticket_id, $actor_id)'), 'The shared status transition must stop an active timer when completing work.');

if (!function_exists('ticket_status_group_from_status')) {
    function ticket_status_group_from_status(array $status): string
    {
        if (isset($status['status_group']) && trim((string) $status['status_group']) !== '') {
            return (string) $status['status_group'];
        }

        $name = strtolower(strtr((string) ($status['name'] ?? ''), ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z']));
        if (preg_match('/\b(done|complete|completed|hotovo|dokonceno|vyreseno)\b/u', $name)) {
            return 'done';
        }

        return !empty($status['is_closed']) ? 'done' : 'active';
    }
}

if (!function_exists('ticket_status_group_for_status_id')) {
    function ticket_status_group_for_status_id(?int $status_id): string
    {
        return 'active';
    }
}

require_once $root . '/includes/modules/tickets/ticket-detail-actions.php';

$statuses_with_canceled_first = [
    ['id' => 4, 'name' => 'Canceled', 'is_closed' => 1],
    ['id' => 5, 'name' => 'Done', 'is_closed' => 1],
    ['id' => 6, 'name' => 'Resolved', 'is_closed' => 1],
];

$assert(
    ticket_detail_first_done_status_id($statuses_with_canceled_first) === 5,
    'Complete must prefer a real done status over canceled even when canceled is the first closed status.'
);

$complete_actions = array_values(array_filter(
    ticket_detail_primary_actions(['status_id' => 1, 'is_closed' => 0], ['role' => 'admin'], $statuses_with_canceled_first),
    static fn (array $action): bool => ($action['key'] ?? '') === 'complete'
));

$assert(!empty($complete_actions), 'Complete action should be visible for active agent tickets.');
$assert((int) $complete_actions[0]['status_id'] === 5, 'Complete action must submit the Done status id, not Canceled.');
$assert(($complete_actions[0]['title'] ?? '') === 'Mark this ticket as done.', 'Complete action must explain what it does.');

$running_complete_actions = array_values(array_filter(
    ticket_detail_primary_actions(['status_id' => 1, 'is_closed' => 0], ['role' => 'admin'], $statuses_with_canceled_first, ['timer_state' => 'running']),
    static fn (array $action): bool => ($action['key'] ?? '') === 'complete'
));
$assert(
    ($running_complete_actions[0]['title'] ?? '') === 'Mark this ticket as done and stop the active timer.',
    'Complete action must explain that it stops the active timer.'
);

$done_actions = array_values(array_filter(
    ticket_detail_primary_actions(['status_id' => 5, 'is_closed' => 1], ['role' => 'admin'], $statuses_with_canceled_first),
    static fn (array $action): bool => ($action['key'] ?? '') === 'complete'
));
$assert(empty($done_actions), 'Complete action must not be visible after the ticket is already done.');

$hotovo_actions = array_values(array_filter(
    ticket_detail_primary_actions(['status_id' => 10, 'status_name' => 'Hotovo', 'is_closed' => 0], ['role' => 'admin'], $statuses_with_canceled_first),
    static fn (array $action): bool => ($action['key'] ?? '') === 'complete'
));
$assert(empty($hotovo_actions), 'Complete action must not be visible for Czech Hotovo status.');

$hotovo_running_actions = array_values(array_filter(
    ticket_detail_primary_actions(['status_id' => 10, 'status_name' => 'Hotovo', 'is_closed' => 0], ['role' => 'admin'], $statuses_with_canceled_first, ['timer_state' => 'running']),
    static fn (array $action): bool => ($action['key'] ?? '') === 'complete'
));
$assert(!empty($hotovo_running_actions), 'Complete action must remain available to stop an active timer on an already done ticket.');
$assert(
    ($hotovo_running_actions[0]['title'] ?? '') === 'Mark this ticket as done and stop the active timer.',
    'Done ticket with active timer must explain that Complete will stop the timer.'
);

$assert(ticket_detail_first_done_status_id([
    ['id' => 4, 'name' => 'Canceled', 'is_closed' => 1],
    ['id' => 7, 'name' => 'Rejected', 'is_closed' => 1],
]) === null, 'Complete action must not appear when only canceled-like terminal statuses exist.');

$assert(ticket_detail_first_done_status_id([
    ['id' => 8, 'name' => 'Zrušeno', 'is_closed' => 1],
    ['id' => 9, 'name' => 'Dokončeno', 'is_closed' => 1],
]) === 9, 'Complete must prefer accented Czech done statuses over canceled terminal statuses.');

echo "Ticket detail action contract OK\n";

// A client may edit their own subject, but cannot use the staff assignment controls.
if (!function_exists('can_edit_ticket')) {
    function can_edit_ticket(array $ticket, array $user): bool
    {
        return ($user['role'] ?? '') !== 'user' || (int) ($ticket['created_by'] ?? 0) === (int) ($user['id'] ?? -1);
    }
}
$client_action_keys = array_column(ticket_detail_primary_actions(
    ['status_id' => 1, 'is_closed' => 0, 'created_by' => 42],
    ['role' => 'user', 'id' => 42],
    $statuses_with_canceled_first
), 'key');
$assert(in_array('edit', $client_action_keys, true), 'A client must retain editing of their own ticket.');
$assert(!in_array('assign', $client_action_keys, true), 'Clients must not see an assignment action without staff controls.');
