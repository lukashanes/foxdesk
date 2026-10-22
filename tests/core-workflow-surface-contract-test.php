<?php

$root = dirname(__DIR__);

$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read ' . $path . PHP_EOL);
        exit(1);
    }
    return $contents;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$theme = $read('theme.css');
$workspace = $read('includes/components/workspace-surface.php');
$tickets = $read('pages/tickets.php')
    . "
" . $read('includes/modules/tickets/ticket-list-page-controller.php')
    . "\n" . $read('includes/components/ticket-list-page.php')
    . "\n" . $read('includes/components/ticket-list-board.php')
    . "\n" . $read('includes/components/ticket-list-table.php');
$ticketDetail = $read('pages/ticket-detail.php') . "
" . $read('includes/components/ticket-workspace-actions.php') . "
" . $read('includes/components/ticket-workspace-timer.php')
    . "\n" . $read('includes/components/ticket-detail-content.php');
$ticketSidebar = $read('includes/components/ticket-detail-sidebar.php');
$client = $read('pages/client.php');
$reports = $read('includes/modules/reports/views/page.php')
    . "\n" . $read('includes/modules/reports/views/filters.php');

foreach ([
    '.workflow-surface',
    '.workspace-queue-page',
    '.ticket-registry-page',
    '.ticket-detail-page',
    '.client-center',
    '.report-page-toolbar',
] as $selector) {
    $assert(str_contains($theme, $selector), 'Workflow theme is missing selector: ' . $selector);
}

$surfaces = [
    'work' => $workspace,
    'tickets' => $tickets,
    'ticket-detail' => $ticketDetail,
    'client' => $client,
    'reports' => $reports,
];

foreach ($surfaces as $surface => $contents) {
    $assert(str_contains($contents, 'workflow-surface'), $surface . ' must use the shared workflow surface class.');
    $assert(str_contains($contents, 'data-core-workflow-surface="' . $surface . '"'), $surface . ' must expose a core workflow surface contract.');
}

$assert(str_contains($workspace, "t('All clear')"), 'Work empty state must use concise shared copy.');
$assert(str_contains($client, "t('All clear')"), 'Client tickets empty state must use the shared concise empty model.');
$assert(!str_contains($client, '<style>'), 'Client workflow page must not carry local style blocks.');
$assert(str_contains($tickets, 'ticket_registry_render_view_tabs('), 'Tickets must use the shared registry tabs.');
$assert(str_contains($reports, 'report-page-toolbar'), 'Reports must use the shared toolbar class.');
$assert(str_contains($reports, 'report-mini-action'), 'Reports secondary actions must use compact shared action styling.');
$assert(str_contains($reports, 'report-filter-summary'), 'Reports filters must use the shared summary model.');
$assert(str_contains($ticketDetail, 'ticket_detail_primary_action_class($action)'), 'Ticket detail primary actions must be rendered through the action helper.');
$assert(str_contains($ticketSidebar, 'data-ticket-sidebar-surface'), 'Ticket metadata must stay in the sidebar surface.');
$assert(!is_file($root . '/pages/billing.php'), 'Self-hosted edition must not ship the SaaS billing workflow page.');

echo "Core workflow surface contract OK\n";
