<?php

$root = dirname(__DIR__);

function assert_shared_workflow(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function read_shared_workflow_file(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assert_shared_workflow($contents !== false, 'Unable to read ' . $path);
    return $contents;
}

$bootstrap = read_shared_workflow_file($root, 'includes/modules/bootstrap.php');
foreach ([
    'tickets/ticket-status-groups.php',
    'tickets/ticket-list-views.php',
    'tickets/ticket-detail-actions.php',
    'work/work-queues.php',
    'inbox/inbox-service.php',
    'search/global-search.php',
    'clients/client-overview.php',
    'reports/reporting-flow.php',
    'reports/billing-review.php',
] as $module) {
    assert_shared_workflow(str_contains($bootstrap, $module), 'Module bootstrap must load ' . $module);
}

$work = read_shared_workflow_file($root, 'includes/modules/work/work-queues.php');
foreach (['mine', 'unassigned', 'overdue', 'waiting', 'done_today'] as $key) {
    assert_shared_workflow(str_contains($work, "'" . $key . "'"), 'Work queue contract missing key: ' . $key);
}
assert_shared_workflow(str_contains($work, "ticket_status_group_normalize"), 'Work queues must use status group normalization.');

$inbox = read_shared_workflow_file($root, 'includes/modules/inbox/inbox-service.php');
foreach (['triage', 'customer_replies', 'email_imports'] as $key) {
    assert_shared_workflow(str_contains($inbox, "'" . $key . "'"), 'Inbox contract missing key: ' . $key);
}

$ticket_views = read_shared_workflow_file($root, 'includes/modules/tickets/ticket-list-views.php');
foreach (['open', 'waiting', 'done', 'all', 'archived'] as $key) {
    assert_shared_workflow(str_contains($ticket_views, "'" . $key . "'"), 'Ticket view contract missing key: ' . $key);
}
assert_shared_workflow(str_contains($ticket_views, 'ticket_list_view_apply_filters'), 'Ticket views must expose shared filter application.');

$search = read_shared_workflow_file($root, 'includes/modules/search/global-search.php');
foreach (['open_tickets', 'done_tickets', 'archived_tickets', 'clients', 'contacts', 'reports'] as $section) {
    assert_shared_workflow(str_contains($search, "'" . $section . "'"), 'Global search section missing: ' . $section);
}
assert_shared_workflow(str_contains($search, "global_search_ticket_section"), 'Global search must route ticket sections through one read model.');

$client = read_shared_workflow_file($root, 'includes/modules/clients/client-overview.php');
foreach ([
    'function client_overview_ticket_counts',
    'function client_overview_recent_tickets',
    'function client_overview_contacts',
    'function client_overview_time_summary',
    'function client_overview(',
] as $needle) {
    assert_shared_workflow(str_contains($client, $needle), 'Client overview model missing: ' . $needle);
}

$reports = read_shared_workflow_file($root, 'includes/modules/reports/reporting-flow.php');
$billing = read_shared_workflow_file($root, 'includes/modules/reports/billing-review.php');
foreach ([
    'function reporting_flow_steps',
    'function reporting_flow_time_presets',
    'function reporting_flow_review_url',
    'function reporting_flow_builder_url',
] as $needle) {
    assert_shared_workflow(str_contains($reports, $needle), 'Reporting flow model missing: ' . $needle);
}
foreach ([
    'function billing_review_payload',
    'function billing_review_adjustment_actions',
    'function billing_review_bulk_adjustment_actions',
    'function billing_review_total_labels',
] as $needle) {
    assert_shared_workflow(str_contains($billing, $needle), 'Billing review model missing: ' . $needle);
}

$pages = [
    'pages/work.php' => ['work_queue_summary', 'workspace_render_queue_page'],
    'pages/inbox.php' => ['inbox_summary', 'workspace_render_queue_page'],
    'pages/tickets.php' => ['ticket_list_view_from_request', 'ticket_list_view_apply_filters', 'ticket_registry_render_view_tabs'],
    'pages/client.php' => ['client_overview(', 'ticket_list_view_normalize'],
    'pages/admin/reports.php' => ['reporting_flow_steps()', 'billing_review_adjustment_actions()', 'billing_review_bulk_adjustment_actions()'],
    'pages/ticket-detail.php' => ['ticket_detail_primary_actions('],
];

foreach ($pages as $path => $needles) {
    $contents = read_shared_workflow_file($root, $path);
    foreach ($needles as $needle) {
        assert_shared_workflow(str_contains($contents, $needle), $path . ' must use shared workflow contract: ' . $needle);
    }
}

$registry = read_shared_workflow_file($root, 'includes/components/ticket-registry-surface.php');
assert_shared_workflow(str_contains($registry, 'function ticket_registry_render_view_tabs'), 'Ticket registry tabs component is missing.');

echo "Shared workflow contract OK\n";
