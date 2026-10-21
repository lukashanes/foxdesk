<?php
$root = dirname(__DIR__);
require_once __DIR__ . '/support/ticket-list-source.php';
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
assert_ticket_list_view(($open['status_group_not'] ?? []) === ['done', 'archived'], 'Open view should show everything that is not completed (new, active and waiting tickets).');
assert_ticket_list_view(!array_key_exists('triage_state', $open), 'Open view must not hide untriaged tickets.');
assert_ticket_list_view(ticket_list_view_tab_keys('open', true) === ['open', 'done', 'archived'], 'Ticket list tabs stay simple: Open, Done, Archive.');
assert_ticket_list_view(ticket_list_view_tab_keys('done', false) === ['open', 'done'], 'Without archive access the tabs are Open and Done.');
assert_ticket_list_view(ticket_list_view_tab_keys('all', true) === ['open', 'done', 'archived', 'all'], 'A deep-linked view (e.g. search results) is appended while active.');
assert_ticket_list_view(ticket_list_view_default_sort('open') === 'last_updated', 'Open view defaults to the most recently updated tickets.');
assert_ticket_list_view(ticket_list_view_default_sort('new') === 'newest', 'New view defaults to the newest tickets.');

$new = ticket_list_view_apply_filters($base, 'new');
assert_ticket_list_view(($new['status_group_not'] ?? []) === ['waiting', 'done', 'archived'], 'New view should exclude waiting and completed tickets.');
assert_ticket_list_view(($new['triage_state'] ?? '') === 'new', 'New view should contain only recent unassigned tickets.');
assert_ticket_list_view(ticket_list_new_window_hours() === 48, 'New ticket triage window should be 48 hours.');

$paging = ticket_list_page_model(['page_size' => '10', 'p' => 99], 21);
assert_ticket_list_view($paging['page'] === 3 && $paging['offset'] === 20, 'List paging clamps to the last page.');
assert_ticket_list_view(ticket_list_page_model(['page_size' => 'all'], 101)['offset'] === 0, 'All rows option keeps every ticket on one page.');
assert_ticket_list_view(ticket_list_page_model(['page_size' => '7'], 5)['size'] === '20', 'Unknown page sizes fall back to 20.');
assert_ticket_list_view(empty($open['status_group']), 'Open view should remove stale status_group filters.');

$waiting = ticket_list_view_apply_filters($base, 'waiting');
assert_ticket_list_view(($waiting['status_group'] ?? '') === 'waiting', 'Waiting view should filter waiting tickets.');

$done = ticket_list_view_apply_filters(['is_archived' => 0], 'done');
assert_ticket_list_view(($done['status_group'] ?? '') === 'done', 'Done view should filter done tickets.');
assert_ticket_list_view(($done['sort'] ?? '') === 'completed', 'Done view should default to completed sort.');

$done_explicit_sort = ticket_list_view_apply_filters(['is_archived' => 0, 'sort' => 'newest'], 'done');
assert_ticket_list_view(($done_explicit_sort['sort'] ?? '') === 'newest', 'Explicit Done sort must be preserved.');

assert_ticket_list_view(ticket_list_view_default_sort('done') === 'completed', 'Done view default sort should be completed.');
assert_ticket_list_view(ticket_list_view_effective_sort('done', 'newest', false) === 'completed', 'Implicit Done sort should become completed.');
assert_ticket_list_view(ticket_list_view_effective_sort('done', 'newest', true) === 'newest', 'Explicit Done newest sort should stay newest.');

$explicit_status = ['is_archived' => 0, 'status_id' => 5];
$open_with_status = ticket_list_view_apply_filters($explicit_status, 'open');
assert_ticket_list_view(($open_with_status['status_id'] ?? null) === 5, 'Explicit status filter should be preserved.');
assert_ticket_list_view(empty($open_with_status['status_group_not']), 'Explicit status filter must override Open view group exclusion.');
assert_ticket_list_view(empty($open_with_status['triage_state']), 'Explicit status filter must override the Open triage split.');

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

$tickets_route = ticket_list_route_and_controller_source($root);
$tickets_surface = file_get_contents($root . '/includes/components/ticket-list-page.php');
$tickets_board = file_get_contents($root . '/includes/components/ticket-list-board.php');
$tickets_table = file_get_contents($root . '/includes/components/ticket-list-table.php');
$tickets_page = implode("\n", [(string) $tickets_route, (string) $tickets_surface, (string) $tickets_board, (string) $tickets_table]);
$ticket_row_model = file_get_contents($root . '/includes/modules/tickets/ticket-row-view-model.php');
$ticket_registry_surface = file_get_contents($root . '/includes/components/ticket-registry-surface.php');
assert_ticket_list_view($tickets_route !== false && $tickets_surface !== false && $tickets_board !== false && $tickets_table !== false, 'Tickets route and surfaces must be readable.');
assert_ticket_list_view($ticket_row_model !== false, 'Ticket row model must be readable.');
assert_ticket_list_view($ticket_registry_surface !== false, 'Ticket registry surface must be readable.');
assert_ticket_list_view(str_contains($tickets_page, 'ticket_registry_render_view_tabs'), 'Tickets page should render view tabs through the registry surface component.');
assert_ticket_list_view(str_contains($tickets_page, '$ticket_show_all_url'), 'Tickets page should use a real All view URL for Show all.');
assert_ticket_list_view(str_contains($tickets_page, '$ticket_clear_url'), 'Tickets page should preserve the current view when clearing filters.');
assert_ticket_list_view(str_contains($tickets_page, "tn('tickets.subtitle.overdue', \$ticket_overdue_count)"), 'Ticket page subtitle only names overdue tickets; counts live in the view tabs.');
assert_ticket_list_view(str_contains($tickets_page, 'ticket_list_page_model($_GET'), 'Ticket list paging must be server-owned.');
assert_ticket_list_view(str_contains($tickets_page, 'ticket_registry_render_pagination('), 'Ticket list must render paging controls.');

$ticket_query = file_get_contents($root . '/includes/ticket-query-functions.php');
assert_ticket_list_view(str_contains($ticket_query, "\$triage_state === 'new'"), 'Ticket query must implement the New triage branch.');
assert_ticket_list_view(str_contains($ticket_query, 't.assignee_id IS NULL AND t.created_at > DATE_SUB(NOW(), INTERVAL {$new_window_hours} HOUR)'), 'New triage query must require an unassigned ticket inside the 48-hour window.');
assert_ticket_list_view(str_contains($ticket_query, 't.assignee_id IS NOT NULL OR t.created_at <= DATE_SUB(NOW(), INTERVAL {$new_window_hours} HOUR)'), 'Open query must include assigned tickets and expired unassigned tickets.');
assert_ticket_list_view(!str_contains($ticket_registry_surface, 'ticket-filter-summary__count'), 'Ticket registry summary must not repeat the selected view count below the view tabs.');
assert_ticket_list_view(substr_count($tickets_page, 'name="search_scope" value="all"') >= 2, 'Ticket search forms should request all-ticket search by default.');
assert_ticket_list_view(str_contains($ticket_row_model, 'ticket_list_view_shows_closed_inline'), 'Ticket row model must use the closed visibility helper.');
assert_ticket_list_view(str_contains($ticket_row_model, 'if (!$show_closed_tickets_inline'), 'Board closed-ticket hiding must follow the same closed visibility helper.');

echo "Ticket list view tests passed\n";
