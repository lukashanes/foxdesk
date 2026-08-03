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

$route = $read('pages/admin/reports.php');
$bootstrap = $read('includes/modules/bootstrap.php');
$controller = $read('includes/modules/reports/report-page-controller.php');
$viewModel = $read('includes/modules/reports/report-page-view-model.php');
$renderer = $read('includes/modules/reports/report-page-render.php');
$pageView = $read('includes/modules/reports/views/page.php');
$filtersView = $read('includes/modules/reports/views/filters.php');
$timeView = $read('includes/modules/reports/views/time.php');
$weeklyView = $read('includes/modules/reports/views/weekly.php');
$billingView = $read('includes/modules/reports/views/billing.php');
$worklogView = $read('includes/modules/reports/views/worklog.php');
$ratesView = $read('includes/modules/reports/views/rates.php');
$publishedView = $read('includes/modules/reports/views/published.php');
$entryModal = $read('includes/modules/reports/views/entry-modal.php');
$pageJs = $read('assets/js/report-page.js');
$pageCss = $read('assets/css/report-page.css');

$routeLines = count(file($root . '/pages/admin/reports.php') ?: []);
$jsLines = count(file($root . '/assets/js/report-page.js') ?: []);
$assert($routeLines < 700, "Reports route has {$routeLines} lines; expected fewer than 700.");
$assert($jsLines < 900, "Reports browser module has {$jsLines} lines; expected fewer than 900.");

foreach ([
    '/reports/report-page-view-model.php',
    '/reports/report-page-controller.php',
    '/reports/report-page-render.php',
] as $module) {
    $assert(str_contains($bootstrap, $module), 'Module bootstrap must load ' . $module);
}

foreach ([
    'report_admin_page_context($_GET, $_POST, $_SERVER)',
    'report_render_admin_page($report_context)',
] as $needle) {
    $assert(str_contains($route, $needle), 'Thin reports route missing delegation: ' . $needle);
}
foreach ([
    'report_filter_state_from_request($request, is_admin())',
    'report_handle_admin_post_actions($post, $rounding)',
    'report_query_time_entries($report_filter_state',
    'report_export_csv_if_requested($request',
    'report_page_client_script_config(',
] as $needle) {
    $assert(str_contains($controller, $needle), 'Report controller missing orchestration: ' . $needle);
}

$assert(!str_contains($route, '<script'), 'Reports route must not own browser code.');
$assert(!str_contains($route, '<form'), 'Reports route must not own report forms.');
$assert(!str_contains($route, 'db_fetch_'), 'Reports route must not own data queries.');
$assert(!str_contains($route, "\$_SERVER['REQUEST_METHOD']"), 'Reports route must not own request actions.');

foreach ([
    'function report_page_time_range_labels',
    'function report_page_selected_client',
    'function report_page_selected_agent',
    'function report_page_billing_columns',
    'function report_page_active_filters',
    'function report_page_worklog_model',
    'function report_page_weekly_model',
    'function report_page_client_script_config',
] as $needle) {
    $assert(str_contains($viewModel, $needle), 'Report view model missing: ' . $needle);
}

foreach ([
    "'filters'",
    "'time'",
    "'weekly'",
    "'billing'",
    "'worklog'",
    "'rates'",
    "'published'",
    "'entry-modal'",
] as $partial) {
    $assert(str_contains($renderer, $partial), 'Renderer must allow report partial ' . $partial);
}

foreach ([
    'data-core-workflow-surface="reports"',
    'data-report-create-form',
    'data-report-client-select',
    'data-report-agent-select',
    'data-report-period-select',
    'reporting_flow_steps()',
    'report_render_partial(\'filters\'',
    'report_render_partial($report_partial',
    'window.FoxDeskReportPageConfig',
    'assets/js/report-page.js',
    'assets/js/report-billing-review.js',
    'assets/js/report-time-delete.js',
    'assets/css/report-page.css',
] as $needle) {
    $assert(str_contains($pageView, $needle), 'Reports page view missing: ' . $needle);
}
$assert(str_contains($pageJs, "new Event('change', { bubbles: true })"), 'Quick report ranges must bubble their synthetic change event.');
$assert(str_contains($pageView, '$report_page_asset_version'), 'Report page assets must use a cache-busting version helper.');
$assert(str_contains($pageView, 'assets/js/report-page.js?v='), 'Report page JS must use a versioned URL.');
$assert(!str_contains($pageView, 'required data-report-client-select'), 'The main report form must allow an all-client time overview.');

foreach ([
    'report-filter-summary',
    'id="report-confirm"',
    'id="cs-orgs"',
    'id="cs-agents"',
] as $needle) {
    $assert(str_contains($filtersView, $needle), 'Report filters view missing: ' . $needle);
}
foreach ([
    'report_time_overview_work_log_rows($entries, 120)',
    'data-report-time-overview-log',
    'report-worklog-table',
] as $needle) {
    $assert(str_contains($timeView, $needle), 'Time overview view missing: ' . $needle);
}
$assert(str_contains($weeklyView, 'toggleWeekAgents'), 'Weekly view must preserve agent expansion.');
foreach ([
    'billing_review_adjustment_actions()',
    'billing_review_bulk_adjustment_actions()',
    'data-report-preview',
    'data-report-entry-field="rate"',
    'data-report-currency=',
] as $needle) {
    $assert(str_contains($billingView, $needle), 'Billing view missing: ' . $needle);
}
foreach (['updateTimeInline', 'data-report-delete-time'] as $needle) {
    $assert(str_contains($worklogView, $needle), 'Work log view missing: ' . $needle);
}
foreach (['save_agent_default_rate', 'save_agent_client_rate'] as $needle) {
    $assert(str_contains($ratesView, $needle), 'Rates view missing: ' . $needle);
}
$assert(str_contains($publishedView, 'create_report_share'), 'Published reports view must preserve report sharing.');
$assert(str_contains($entryModal, 'id="entryModal"'), 'Entry modal view must preserve entry editing.');

foreach ([
    'window.updateTimeInline',
    'window.openEntryModal',
    'window.toggleReportTicketPreview',
    'window.toggleWeekAgents',
    'window.setTimeRange',
] as $needle) {
    $assert(str_contains($pageJs, $needle), 'Report browser module missing compatibility handler: ' . $needle);
}
$assert(!str_contains($pageView, 'function updateTimeInline'), 'Report page view must not own browser functions.');
$assert(str_contains($pageCss, '@media print'), 'Report page CSS must preserve print behavior.');

echo "Report page extraction contract OK\n";
