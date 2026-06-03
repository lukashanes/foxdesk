<?php
$root = dirname(__DIR__);
require_once $root . '/includes/modules/tickets/ticket-list-views.php';

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
assert_ticket_list_view(ticket_list_view_from_request(['search' => 'router'], false) === 'all', 'Ticket search without a view should search all non-archived tickets.');
assert_ticket_list_view(ticket_list_view_from_request(['work_view' => 'waiting', 'search' => 'router', 'search_scope' => 'all'], false) === 'all', 'Ticket search form should override the current agenda view.');
assert_ticket_list_view(ticket_list_view_from_request(['work_view' => 'done', 'search' => 'router'], false) === 'done', 'Explicit view tabs should still allow scoped searching.');
assert_ticket_list_view(ticket_list_view_from_request(['work_view' => 'done'], true) === 'archived', 'Archive mode should override work_view.');

$base = ['is_archived' => 0, 'search' => 'router', 'status_group' => 'done'];
$open = ticket_list_view_apply_filters($base, 'open');
assert_ticket_list_view(($open['is_archived'] ?? null) === 0, 'Open view should stay non-archived.');
assert_ticket_list_view(($open['status_group_not'] ?? []) === ['done'], 'Open view should exclude done tickets.');
assert_ticket_list_view(empty($open['status_group']), 'Open view should remove stale status_group filters.');

$waiting = ticket_list_view_apply_filters($base, 'waiting');
assert_ticket_list_view(($waiting['status_group'] ?? '') === 'waiting', 'Waiting view should filter waiting tickets.');

$explicit_status = ['is_archived' => 0, 'status_id' => 5];
$open_with_status = ticket_list_view_apply_filters($explicit_status, 'open');
assert_ticket_list_view(($open_with_status['status_id'] ?? null) === 5, 'Explicit status filter should be preserved.');
assert_ticket_list_view(empty($open_with_status['status_group_not']), 'Explicit status filter must override Open view group exclusion.');

$done_with_status = ticket_list_view_apply_filters($explicit_status, 'done');
assert_ticket_list_view(($done_with_status['status_id'] ?? null) === 5, 'Explicit status filter should be preserved in Done view.');
assert_ticket_list_view(empty($done_with_status['status_group']), 'Explicit status filter must override Done view group filter.');

$all = ticket_list_view_apply_filters($base, 'all');
assert_ticket_list_view(($all['is_archived'] ?? null) === 0, 'All view should stay non-archived.');
assert_ticket_list_view(empty($all['status_group']), 'All view should remove stale status_group filters.');
assert_ticket_list_view(empty($all['status_group_not']), 'All view must not exclude done tickets.');

$archived = ticket_list_view_apply_filters($base, 'archived');
assert_ticket_list_view(($archived['is_archived'] ?? null) === 1, 'Archive view should force archived tickets.');

$url = ticket_list_view_url('done', ['page' => 'tickets', 'p' => 3, 'search' => 'router'], true);
assert_ticket_list_view(strpos($url, 'work_view=done') !== false, 'Done view URL should include work_view.');
assert_ticket_list_view(strpos($url, 'p=3') === false, 'View URL should reset pagination.');
assert_ticket_list_view(strpos($url, 'search_scope=') === false, 'View URL should remove one-shot search scope.');

$open_url = ticket_list_view_url('open', ['page' => 'tickets', 'work_view' => 'done'], true);
assert_ticket_list_view(strpos($open_url, 'work_view=') === false, 'Open view URL should be the clean default.');

$all_url = ticket_list_view_url('all', [], true);
assert_ticket_list_view(strpos($all_url, 'work_view=all') !== false, 'All view URL should explicitly request all tickets.');

assert_ticket_list_view(!ticket_list_view_shows_closed_inline('open'), 'Open view should keep closed tickets tucked away.');
assert_ticket_list_view(!ticket_list_view_shows_closed_inline('waiting'), 'Waiting view should keep closed tickets tucked away.');
assert_ticket_list_view(ticket_list_view_shows_closed_inline('done'), 'Done view should show closed tickets inline.');
assert_ticket_list_view(ticket_list_view_shows_closed_inline('all'), 'All view should show closed tickets inline.');
assert_ticket_list_view(ticket_list_view_shows_closed_inline('open', true), 'Explicit closed status filter should show closed tickets inline.');

$tickets_page = file_get_contents($root . '/pages/tickets.php');
assert_ticket_list_view($tickets_page !== false, 'Tickets page must be readable.');
assert_ticket_list_view(str_contains($tickets_page, '$ticket_show_all_url'), 'Tickets page should use a real All view URL for Show all.');
assert_ticket_list_view(str_contains($tickets_page, '$ticket_clear_url'), 'Tickets page should preserve the current view when clearing filters.');
assert_ticket_list_view(substr_count($tickets_page, 'name="search_scope" value="all"') >= 2, 'Ticket search forms should request all-ticket search by default.');
assert_ticket_list_view(str_contains($tickets_page, 'ticket_list_view_shows_closed_inline'), 'Tickets page must use the closed visibility helper.');
assert_ticket_list_view(str_contains($tickets_page, 'if (!$show_closed_tickets_inline'), 'Board closed-ticket hiding must follow the same closed visibility helper.');

echo "Ticket list view tests passed\n";
