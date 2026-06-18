<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/tickets/ticket-status-groups.php';
require_once $root . '/includes/modules/tickets/ticket-list-views.php';
require_once $root . '/includes/modules/tickets/ticket-row-view-model.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$statuses = [
    ['id' => 1, 'name' => 'Open', 'is_closed' => 0],
    ['id' => 2, 'name' => 'Done', 'is_closed' => 1],
];
$tickets = [
    ['id' => 10, 'status_id' => 1, 'is_closed' => 0, 'priority_name' => 'High'],
    ['id' => 11, 'status_id' => 2, 'is_closed' => 1, 'priority_name' => 'Low'],
];

$open_model = ticket_registry_split_model($statuses, $tickets, null, 'open');
$assert($open_model['show_closed_tickets_inline'] === false, 'Open view must not show closed tickets inline.');
$assert(count($open_model['active_tickets']) === 1, 'Open model must keep one active ticket.');
$assert(count($open_model['closed_tickets']) === 1, 'Open model must split one closed ticket.');
$assert(count($open_model['ticket_groups']) === 2, 'Open model must expose a collapsed closed group.');
$assert($open_model['ticket_groups'][1]['hidden'] === true, 'Closed ticket group must be hidden by default.');

$all_model = ticket_registry_split_model($statuses, $tickets, null, 'all');
$assert($all_model['show_closed_tickets_inline'] === true, 'All view must show closed tickets inline.');
$assert(count($all_model['active_tickets']) === 2, 'All model must keep all tickets inline.');
$assert(count($all_model['closed_tickets']) === 0, 'All model must not create a closed split.');

$kanban = ticket_registry_kanban_model(
    $statuses,
    $tickets,
    $open_model['statuses_by_id'],
    $open_model['board_active_statuses'],
    $open_model['board_closed_statuses'],
    $open_model['show_closed_tickets_inline']
);
$assert(isset($kanban['main_tickets_by_status'][1]), 'Kanban model must create active status buckets.');
$assert(isset($kanban['main_tickets_by_status'][2]), 'Kanban model must create closed status buckets.');
$assert(count($kanban['main_statuses']) >= 1, 'Kanban model must expose main statuses.');

$assert(ticket_registry_status_group_from_status($statuses[1]) === 'done', 'Closed status must map to done group.');
$assert(str_contains(ticket_registry_status_accent_class($tickets[1], $statuses), 'ticket-status-accent--done'), 'Closed ticket accent class must use done group.');
$assert(ticket_registry_status_dot_class('waiting', 'kanban-dot') === 'kanban-dot kanban-dot--waiting', 'Status dot helper must compose base and group.');
$assert(ticket_registry_priority_badge_class('Urgent') === 'badge-inline ticket-priority-inline ticket-priority-inline--medium', 'Priority helper must compose fallback priority badge class.');

$page = file_get_contents($root . '/pages/tickets.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$assert($page !== false && $bootstrap !== false, 'Ticket registry files must be readable.');
$assert(str_contains($bootstrap, '/tickets/ticket-row-view-model.php'), 'Module bootstrap must load row view model.');
$assert(str_contains($page, 'ticket_registry_split_model($statuses, $tickets, $status_id, $ticket_list_view)'), 'Tickets page must use split view model.');
$assert(str_contains($page, 'ticket_registry_kanban_model('), 'Tickets page must use kanban view model.');
$assert(!str_contains($page, '$statuses_by_id = [];'), 'Tickets page must not rebuild status lookup inline.');
$assert(!str_contains($page, '$kanban_main_status_ids = [];'), 'Tickets page must not build kanban main status ids inline.');

echo "Ticket row view model contract OK\n";
