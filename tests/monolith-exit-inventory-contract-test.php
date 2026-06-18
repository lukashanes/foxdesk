<?php

$root = dirname(__DIR__);
$inventory_path = $root . '/docs/MONOLITH_EXIT_INVENTORY.md';
$inventory = file_get_contents($inventory_path);

if ($inventory === false) {
    fwrite(STDERR, "Unable to read monolith exit inventory.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

foreach ([
    'already modular',
    'needs module extraction',
    'self-hosted migration/update page',
] as $status) {
    $assert(str_contains($inventory, $status), "Inventory must define status label: {$status}.");
}

$page_files = glob($root . '/pages/*.php') ?: [];
$admin_page_files = glob($root . '/pages/admin/*.php') ?: [];
$all_page_files = array_merge($page_files, $admin_page_files);
sort($all_page_files);

foreach ($all_page_files as $file) {
    $relative = str_replace($root . '/', '', $file);
    $assert(str_contains($inventory, "`{$relative}`"), "Inventory must include {$relative}.");
}

$priority_targets = [
    'pages/tickets.php' => [
        'max_lines' => 2325,
        'modules' => [
            'includes/modules/tickets/ticket-bulk-actions.php',
            'includes/modules/tickets/ticket-list-filters.php',
            'includes/modules/tickets/ticket-list-views.php',
            'includes/modules/tickets/ticket-row-view-model.php',
            'assets/js/ticket-list.js',
        ],
        'tests' => [
            'tests/ticket-bulk-actions-contract-test.php',
            'tests/ticket-list-filter-contract-test.php',
            'tests/ticket-row-view-model-contract-test.php',
            'tests/ticket-list-js-contract-test.php',
            'tests/shared-workflow-contract-test.php',
            'tests/core-ux-flow-parity-contract-test.php',
        ],
    ],
    'pages/ticket-detail.php' => [
        'max_lines' => 1305,
        'modules' => [
            'includes/modules/tickets/ticket-detail-context.php',
            'includes/modules/tickets/ticket-detail-timeline.php',
            'includes/modules/tickets/ticket-share-state.php',
            'includes/components/ticket-detail-composer.php',
            'includes/components/ticket-detail-modals.php',
            'includes/components/ticket-detail-sidebar.php',
            'includes/components/ticket-detail-surface.php',
            'assets/js/ticket-detail.js',
        ],
        'tests' => [
            'tests/ticket-detail-actions-test.php',
            'tests/module-extraction-contract-test.php',
            'tests/ticket-detail-context-contract-test.php',
            'tests/ticket-detail-timeline-contract-test.php',
            'tests/ticket-share-state-contract-test.php',
            'tests/ticket-detail-js-contract-test.php',
            'tests/ticket-composer-surface-contract-test.php',
            'tests/ticket-detail-modals-contract-test.php',
        ],
    ],
    'pages/admin/reports.php' => [
        'max_lines' => 3070,
        'modules' => [
            'includes/modules/reports/report-filters.php',
            'includes/modules/reports/report-query.php',
            'includes/modules/reports/report-totals.php',
            'includes/modules/reports/report-adjustments.php',
            'includes/modules/reports/report-export.php',
            'assets/js/report-billing-review.js',
        ],
        'tests' => [
            'tests/reporting-flow-contract-test.php',
            'tests/report-rate-parity-contract-test.php',
            'tests/report-filter-contract-test.php',
            'tests/report-adjustment-contract-test.php',
            'tests/report-export-contract-test.php',
        ],
    ],
    'pages/admin/settings.php' => [
        'max_lines' => 3100,
        'modules' => [
            'includes/modules/settings/settings-actions.php',
            'includes/modules/settings/settings-email.php',
            'includes/modules/settings/settings-updates.php',
            'includes/modules/settings/settings-workflow.php',
            'includes/modules/settings/settings-security.php',
            'includes/modules/settings/settings-view-model.php',
            'includes/modules/settings/settings-templates.php',
            'includes/components/admin-settings-tabs.php',
            'includes/components/admin-workflow-card.php',
        ],
        'tests' => [
            'tests/security-debt-contract-test.php',
            'tests/email-routing-plus-address-contract-test.php',
            'tests/settings-action-contract-test.php',
            'tests/settings-email-contract-test.php',
            'tests/settings-update-contract-test.php',
            'tests/settings-render-contract-test.php',
        ],
    ],
    'pages/admin/users.php' => [
        'max_lines' => 2450,
        'modules' => [
            'includes/modules/team/team-users.php',
        ],
        'tests' => [
            'tests/team-users-contract-test.php',
        ],
    ],
    'pages/dashboard.php' => [
        'max_lines' => 2400,
        'modules' => [
            'includes/modules/app/dashboard-compat.php',
            'includes/modules/app/app-shell.php',
            'includes/modules/app/app-feed.php',
        ],
        'tests' => [
            'tests/dashboard-compat-contract-test.php',
        ],
    ],
    'pages/admin/statuses-content.php' => [
        'max_lines' => 330,
        'modules' => [
            'includes/admin-crud-helper.php',
            'includes/components/admin-workflow-card.php',
        ],
        'tests' => [
            'tests/workflow-crud-contract-test.php',
            'tests/settings-render-contract-test.php',
        ],
    ],
    'pages/admin/priorities-content.php' => [
        'max_lines' => 315,
        'modules' => [
            'includes/admin-crud-helper.php',
            'includes/components/admin-workflow-card.php',
        ],
        'tests' => [
            'tests/workflow-crud-contract-test.php',
            'tests/settings-render-contract-test.php',
        ],
    ],
    'pages/admin/ticket-types-content.php' => [
        'max_lines' => 355,
        'modules' => [
            'includes/admin-crud-helper.php',
            'includes/components/admin-workflow-card.php',
        ],
        'tests' => [
            'tests/workflow-crud-contract-test.php',
            'tests/settings-render-contract-test.php',
        ],
    ],
];

foreach ($priority_targets as $page => $targets) {
    $page_path = $root . '/' . $page;
    $line_count = count(file($page_path) ?: []);
    $assert($line_count <= $targets['max_lines'], "{$page} has {$line_count} lines; expected at most {$targets['max_lines']}.");
    $assert(str_contains($inventory, "`{$page}`"), "Inventory must include priority page {$page}.");

    foreach ($targets['modules'] as $module) {
        $assert(str_contains($inventory, "`{$module}`"), "{$page} must name target module {$module}.");
    }

    foreach ($targets['tests'] as $test) {
        $assert(str_contains($inventory, "`{$test}`"), "{$page} must name contract test {$test}.");
    }
}

foreach ([
    'includes/components/ticket-detail-sidebar.php',
    'includes/components/ticket-detail-composer.php',
    'includes/components/ticket-detail-modals.php',
    'includes/components/ticket-detail-surface.php',
    'includes/modules/tickets/ticket-detail-context.php',
    'includes/modules/tickets/ticket-detail-read-model.php',
    'includes/modules/tickets/ticket-share-state.php',
    'includes/modules/tickets/ticket-bulk-actions.php',
    'includes/modules/tickets/ticket-list-filters.php',
    'includes/modules/tickets/ticket-row-view-model.php',
    'assets/js/ticket-list.js',
    'assets/js/ticket-detail.js',
    'includes/modules/settings/settings-templates.php',
    'includes/components/admin-workflow-card.php',
    'assets/js/report-billing-review.js',
    'includes/modules/team/team-users.php',
    'includes/modules/app/dashboard-compat.php',
    'includes/admin-crud-helper.php',
] as $extracted_path) {
    $assert(is_file($root . '/' . $extracted_path), "Extracted module/component missing: {$extracted_path}.");
}

$ticket_page = file_get_contents($root . '/pages/ticket-detail.php');
$assert($ticket_page !== false, 'Ticket detail page must be readable.');
$assert(str_contains($ticket_page, "/includes/components/ticket-detail-sidebar.php"), 'Ticket detail page must include the extracted sidebar component.');
$assert(str_contains($ticket_page, "/includes/components/ticket-detail-composer.php"), 'Ticket detail page must include the extracted composer component.');
$assert(str_contains($ticket_page, "/includes/components/ticket-detail-modals.php"), 'Ticket detail page must include the extracted modals component.');
$assert(!str_contains($ticket_page, 'data-ticket-sidebar-surface'), 'Sidebar markup must not move back into the route file.');
$assert(!str_contains($ticket_page, 'data-ticket-composer-surface'), 'Composer markup must not move back into the route file.');
$assert(!str_contains($ticket_page, '<div id="edit-ticket-modal"'), 'Modal markup must not move back into the route file.');

$assert(
    str_contains($inventory, 'Every extraction starts with a named contract test.'),
    'Inventory must enforce test-first extraction.'
);
$assert(
    str_contains($inventory, 'Route files keep request routing, authorization handoff, and final includes.'),
    'Inventory must define route-file responsibility.'
);
$assert(
    !str_contains($inventory, '`pages/billing.php`')
        && !str_contains($inventory, '`pages/platform.php`')
        && !str_contains($inventory, '`includes/modules/platform/'),
    'Self-hosted inventory must not introduce SaaS platform pages or modules.'
);

echo "Monolith exit inventory contract OK\n";
