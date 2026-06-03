<?php

$root = dirname(__DIR__);

$module = file_get_contents($root . '/includes/modules/tickets/ticket-detail-actions.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$page = file_get_contents($root . '/pages/ticket-detail.php');

if ($module === false || $bootstrap === false || $page === false) {
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
$assert(str_contains($page, 'class="card ticket-work-panel"'), 'Ticket detail must render the work panel.');
$assert(str_contains($module, "'id' => 'toolbar-timer-btn'"), 'Timer button id must stay stable in the action model.');
$assert(str_contains($page, "document.getElementById('toolbar-timer-btn')"), 'Existing timer JS must still target toolbar-timer-btn.');
$assert(str_contains($page, 'id="ticket-side-panel"'), 'Assign action must target the side properties panel.');
$assert(str_contains($page, "t('Ticket properties')"), 'Side panel must have a clear properties heading.');

if (!function_exists('ticket_status_group_from_status')) {
    function ticket_status_group_from_status(array $status): string
    {
        if (isset($status['status_group']) && trim((string) $status['status_group']) !== '') {
            return (string) $status['status_group'];
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
$assert(ticket_detail_first_done_status_id([
    ['id' => 4, 'name' => 'Canceled', 'is_closed' => 1],
    ['id' => 7, 'name' => 'Rejected', 'is_closed' => 1],
]) === null, 'Complete action must not appear when only canceled-like terminal statuses exist.');

$assert(ticket_detail_first_done_status_id([
    ['id' => 8, 'name' => 'Zrušeno', 'is_closed' => 1],
    ['id' => 9, 'name' => 'Dokončeno', 'is_closed' => 1],
]) === 9, 'Complete must prefer accented Czech done statuses over canceled terminal statuses.');

echo "Ticket detail action contract OK\n";
