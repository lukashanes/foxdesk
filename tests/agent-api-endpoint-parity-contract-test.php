<?php

$root = dirname(__DIR__);

$router = file_get_contents($root . '/includes/api/router.php');
$handler = file_get_contents($root . '/includes/api/app-handler.php');
$auth = file_get_contents($root . '/includes/auth.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$appContract = file_get_contents($root . '/includes/modules/app/app-contract.php');
$clientOverview = file_get_contents($root . '/includes/modules/clients/client-overview.php');
$docs = file_get_contents($root . '/docs/AGENT_API_CONTROL.md');

foreach ([
    'router' => $router,
    'handler' => $handler,
    'auth' => $auth,
    'bootstrap' => $bootstrap,
    'appContract' => $appContract,
    'clientOverview' => $clientOverview,
    'docs' => $docs,
] as $name => $contents) {
    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$name}.\n");
        exit(1);
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$routes = [
    'app-shell' => 'api_app_shell',
    'app-home' => 'api_app_home',
    'app-ticket-list' => 'api_app_ticket_list',
    'app-ticket-detail' => 'api_app_ticket_detail',
    'app-ticket-actions' => 'api_app_ticket_actions',
    'app-create-ticket' => 'api_app_create_ticket',
    'app-add-comment' => 'api_app_add_comment',
    'app-attachment-metadata' => 'api_app_attachment_metadata',
    'app-ticket-timer' => 'api_app_ticket_timer',
    'app-ticket-timer-action' => 'api_app_ticket_timer_action',
    'app-log-time' => 'api_app_log_time',
    'app-client-overview' => 'api_app_client_overview',
    'app-reporting-review' => 'api_app_reporting_review',
    'app-notifications' => 'api_app_notifications',
    'app-notifications-summary' => 'api_app_notifications_summary',
    'app-notification-read-state' => 'api_app_notification_read_state',
];

foreach ($routes as $action => $handlerName) {
    $assert(str_contains($router, "'{$action}' => '{$handlerName}'"), "Missing route {$action}.");
    $assert(str_contains($handler, "function {$handlerName}"), "Missing handler {$handlerName}.");
}

foreach ([
    'app-ticket-list' => 'tickets:read',
    'app-ticket-detail' => 'tickets:read',
    'app-create-ticket' => 'tickets:write',
    'app-add-comment' => 'comments:write',
    'app-attachment-metadata' => 'attachments:read',
    'app-ticket-timer' => 'time:read',
    'app-ticket-timer-action' => 'time:write',
    'app-log-time' => 'time:write',
    'app-client-overview' => 'clients:read',
    'app-reporting-review' => 'reports:read',
    'app-notifications' => 'notifications:read',
    'app-notification-read-state' => 'notifications:write',
] as $action => $scope) {
    $assert(str_contains($auth, "'{$action}' => '{$scope}'"), "Missing scope mapping {$action}.");
}

foreach ([
    '/app/app-contract.php',
    'function app_contract_ticket_payload',
    'function app_contract_ticket_filters_from_request',
    'function app_contract_attachment_payload',
    'function app_contract_ticket_actions',
    'function app_contract_client_overview_payload',
    'function app_contract_notification_summary_item',
] as $needle) {
    $haystack = str_starts_with($needle, '/app/') ? $bootstrap : $appContract;
    $assert(str_contains($haystack, $needle), 'Missing app contract behavior: ' . $needle);
}

foreach ([
    'ticket_list_view_apply_filters',
    'app_contract_ticket_filters_from_request($_GET, $user, $limit, $offset)',
    'app_contract_ticket_actions($ticket, $user)',
    'app_contract_attachment_payload($attachment)',
    'client_overview($organization_id, $view)',
    'billing_review_payload($filters, $user, $limit, $offset)',
    'get_user_notifications((int) $user[\'id\']',
    'mark_all_notifications_read((int) $user[\'id\'])',
] as $needle) {
    $assert(str_contains($handler, $needle), 'Endpoint handler does not reuse shared service: ' . $needle);
}

$assert(!str_contains($clientOverview, 'current_tenant_id()'), 'Self-hosted client overview must not require SaaS tenant context.');
$assert(!str_contains($router, "'app-tenant-state'"), 'Self-hosted app router must not expose SaaS tenant-state.');
$assert(str_contains($docs, 'Full self-hosted app read surface parity'), 'Agent API docs must record the Milestone 1 parity state.');

echo "Agent API endpoint parity contract OK\n";
