<?php

$root = dirname(__DIR__);

function assert_core_ux_flow(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function read_core_ux_flow_file(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assert_core_ux_flow($contents !== false, 'Unable to read ' . $path);
    return $contents;
}

$bootstrap = read_core_ux_flow_file($root, 'includes/modules/bootstrap.php');
foreach ([
    'work/work-queues.php',
    'inbox/inbox-service.php',
    'tickets/ticket-list-views.php',
    'tickets/ticket-row-view-model.php',
    'tickets/ticket-detail-actions.php',
    'search/global-search.php',
    'clients/client-overview.php',
    'reports/reporting-flow.php',
    'reports/billing-review.php',
] as $module) {
    assert_core_ux_flow(str_contains($bootstrap, $module), 'Core UX bootstrap must load ' . $module);
}

$header = read_core_ux_flow_file($root, 'includes/header.php');
foreach ([
    "t('Dashboard')",
    "t('Tickets')",
    "t('New ticket')",
    "t('Reports')",
    "t('Settings')",
] as $needle) {
    assert_core_ux_flow(str_contains($header, $needle), 'Workspace navigation is missing ' . $needle);
}
assert_core_ux_flow(!str_contains($header, "url('dashboard')"), 'Legacy dashboard must not be exposed as a primary workspace agenda.');
assert_core_ux_flow(!str_contains($header, "url('inbox')"), 'Inbox must not be exposed as a separate workspace agenda.');

$shortcuts = read_core_ux_flow_file($root, 'assets/js/shortcuts.js');
assert_core_ux_flow(str_contains($shortcuts, "label: 'Dashboard'"), 'Command palette must label the primary workspace dashboard as Dashboard.');
assert_core_ux_flow(!str_contains($shortcuts, "label: 'Work'"), 'Command palette must not expose the old Work label.');
assert_core_ux_flow(str_contains($shortcuts, "label: 'Analytics'"), 'Command palette must keep the legacy analytics dashboard distinct.');

$work = read_core_ux_flow_file($root, 'includes/modules/work/work-queues.php');
foreach (['mine', 'unassigned', 'overdue', 'waiting', 'done_today'] as $key) {
    assert_core_ux_flow(str_contains($work, "'" . $key . "'"), 'Work queue key missing: ' . $key);
}

$inbox = read_core_ux_flow_file($root, 'includes/modules/inbox/inbox-service.php');
foreach (['triage', 'customer_replies', 'email_imports'] as $key) {
    assert_core_ux_flow(str_contains($inbox, "'" . $key . "'"), 'Inbox queue key missing: ' . $key);
}
assert_core_ux_flow(str_contains($inbox, "'label' => 'New tickets'"), 'Internal triage queue must present as New tickets.');

$ticket_views = read_core_ux_flow_file($root, 'includes/modules/tickets/ticket-list-views.php');
foreach (['open', 'waiting', 'done', 'all', 'archived'] as $key) {
    assert_core_ux_flow(str_contains($ticket_views, "'" . $key . "'"), 'Ticket registry view missing: ' . $key);
}

$ticket_row_model = read_core_ux_flow_file($root, 'includes/modules/tickets/ticket-row-view-model.php');
foreach ([
    'function ticket_registry_split_model',
    'function ticket_registry_kanban_model',
    'ticket_list_view_shows_closed_inline',
] as $needle) {
    assert_core_ux_flow(str_contains($ticket_row_model, $needle), 'Ticket row model must preserve registry split behavior: ' . $needle);
}
foreach ([
    'function ticket_list_view_from_request',
    'function ticket_list_view_apply_filters',
    'function ticket_list_view_shows_closed_inline',
    "\$search_scope === 'all'",
    "['done', 'all', 'archived']",
    "unset(\$filters['status_group'], \$filters['status_group_not'])",
] as $needle) {
    assert_core_ux_flow(str_contains($ticket_views, $needle), 'Ticket registry must preserve done/all/search behavior: ' . $needle);
}

$ticket_actions = read_core_ux_flow_file($root, 'includes/modules/tickets/ticket-detail-actions.php');
foreach (['reply', 'start_work', 'assign', 'complete', 'edit'] as $key) {
    assert_core_ux_flow(str_contains($ticket_actions, "'key' => '" . $key . "'"), 'Ticket detail action missing: ' . $key);
}
foreach ([
    'function ticket_detail_first_done_status_id',
    'function ticket_detail_is_done',
    'ticket_detail_done_status_score',
    'ticket_detail_status_is_canceled',
    "\$has_active_timer = \$timer_state !== 'stopped';",
    '$is_agent_user && $done_status_id && (!$is_done || $has_active_timer)',
    'cancel|canceled|cancelled|storno|zrusen|reject',
] as $needle) {
    assert_core_ux_flow(str_contains($ticket_actions, $needle), 'Ticket complete flow guard missing: ' . $needle);
}

$search = read_core_ux_flow_file($root, 'includes/modules/search/global-search.php');
foreach (['open_tickets', 'done_tickets', 'archived_tickets', 'clients', 'contacts', 'reports'] as $section) {
    assert_core_ux_flow(str_contains($search, "'" . $section . "'"), 'Global search section missing: ' . $section);
}

$pages = [
    'pages/work.php' => ['work_queue_summary', 'workspace_render_queue_page'],
    'pages/inbox.php' => ["redirect('work'", "'triage' => 'unassigned'"],
    'pages/tickets.php' => ['ticket_list_view_from_request', 'ticket_list_view_apply_filters', 'ticket_registry_split_model', 'name="search_scope" value="all"'],
    'pages/ticket-detail.php' => ['ticket_detail_primary_actions(', 'Ticket Work Panel'],
    'pages/client.php' => ['client_overview(', 'All tickets'],
    'pages/admin/reports.php' => ['reporting_flow_steps()', 'billing_review_adjustment_actions()', 'billing_review_bulk_adjustment_actions()'],
];

foreach ($pages as $path => $needles) {
    $contents = read_core_ux_flow_file($root, $path);
    foreach ($needles as $needle) {
        assert_core_ux_flow(str_contains($contents, $needle), $path . ' must use the shared core UX flow: ' . $needle);
    }
}

$new_ticket = read_core_ux_flow_file($root, 'pages/new-ticket.php');
assert_core_ux_flow(str_contains($new_ticket, "'organization_id' => \$organization_id"), 'New ticket must pass the selected client explicitly, including a blank client.');
assert_core_ux_flow(str_contains($new_ticket, '$default_organization_id = null'), 'New ticket must default to no selected client.');
assert_core_ux_flow(str_contains($new_ticket, 'data-reset-on-fresh-ticket="1"'), 'New ticket form must reset client selection for fresh tickets.');
assert_core_ux_flow(str_contains($new_ticket, 'assets/js/attachment-paste-drop.js'), 'New ticket must load paste/drop attachment support.');
assert_core_ux_flow(str_contains($new_ticket, 'FoxDeskAttachmentPasteDrop.bind'), 'New ticket must bind pasted and dropped files to attachments.');
assert_core_ux_flow(str_contains($new_ticket, "targetSelectors: ['#new-ticket-form', '#upload-zone']"), 'New ticket paste/drop support must cover the form and upload zone.');

$ticket_crud = read_core_ux_flow_file($root, 'includes/ticket-crud-functions.php');
foreach ([
    "(\$user['role'] ?? '') === 'user'",
    "array_key_exists('organization_id', \$data)",
    "\$candidate_org > 0 ? \$candidate_org : null",
] as $needle) {
    assert_core_ux_flow(str_contains($ticket_crud, $needle), 'Ticket creation must avoid random staff client fallback: ' . $needle);
}

$ticket_api = read_core_ux_flow_file($root, 'includes/api/ticket-handler.php');
assert_core_ux_flow(str_contains($ticket_api, "'organization_id' => null"), 'Quick ticket must explicitly start without a client.');

echo "Core UX flow parity contract OK\n";
