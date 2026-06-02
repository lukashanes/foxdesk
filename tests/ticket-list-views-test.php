<?php
require_once dirname(__DIR__) . '/includes/modules/tickets/ticket-list-views.php';

function assert_ticket_list_view($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

if (!function_exists('url')) {
    function url($page, $params = [])
    {
        $query = ['page' => $page] + $params;
        return 'index.php?' . http_build_query($query);
    }
}

assert_ticket_list_view(ticket_list_view_normalize('waiting') === 'waiting', 'Known view should normalize to itself.');
assert_ticket_list_view(ticket_list_view_normalize('unknown') === 'open', 'Unknown view should fall back to open.');
assert_ticket_list_view(ticket_list_view_from_request(['work_view' => 'done'], false) === 'done', 'Request work_view should be honored.');
assert_ticket_list_view(ticket_list_view_from_request(['work_view' => 'done'], true) === 'archived', 'Archive mode should override work_view.');

$base = ['is_archived' => 0, 'search' => 'router', 'status_group' => 'done'];
$open = ticket_list_view_apply_filters($base, 'open');
assert_ticket_list_view(($open['is_archived'] ?? null) === 0, 'Open view should stay non-archived.');
assert_ticket_list_view(($open['status_group_not'] ?? []) === ['done'], 'Open view should exclude done tickets.');
assert_ticket_list_view(empty($open['status_group']), 'Open view should remove stale status_group filters.');

$waiting = ticket_list_view_apply_filters($base, 'waiting');
assert_ticket_list_view(($waiting['status_group'] ?? '') === 'waiting', 'Waiting view should filter waiting tickets.');

$archived = ticket_list_view_apply_filters($base, 'archived');
assert_ticket_list_view(($archived['is_archived'] ?? null) === 1, 'Archive view should force archived tickets.');

$url = ticket_list_view_url('done', ['page' => 'tickets', 'p' => 3, 'search' => 'router'], true);
assert_ticket_list_view(strpos($url, 'work_view=done') !== false, 'Done view URL should include work_view.');
assert_ticket_list_view(strpos($url, 'p=3') === false, 'View URL should reset pagination.');

$open_url = ticket_list_view_url('open', ['page' => 'tickets', 'work_view' => 'done'], true);
assert_ticket_list_view(strpos($open_url, 'work_view=') === false, 'Open view URL should be the clean default.');

echo "Ticket list view tests passed\n";
