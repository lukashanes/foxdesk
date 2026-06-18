<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$page = file_get_contents($root . '/pages/admin/reports.php');
$export = file_get_contents($root . '/includes/modules/reports/report-export.php');
$query = file_get_contents($root . '/includes/modules/reports/report-query.php');
$totals = file_get_contents($root . '/includes/modules/reports/report-totals.php');

$assert($bootstrap !== false && $page !== false && $export !== false && $query !== false && $totals !== false, 'Report module files must be readable.');
$assert(str_contains($bootstrap, '/reports/report-totals.php'), 'Module bootstrap must load report totals.');
$assert(str_contains($bootstrap, '/reports/report-query.php'), 'Module bootstrap must load report query.');
$assert(str_contains($bootstrap, '/reports/report-export.php'), 'Module bootstrap must load report export.');
$assert(str_contains($page, 'report_query_time_entries($report_filter_state'), 'Reports page must load entries through the report query module.');
$assert(str_contains($page, 'report_export_csv_if_requested($_GET'), 'Reports page must delegate CSV export.');

foreach ([
    'function report_query_time_entries',
    'function report_query_tenant_ticket_filter',
    'report_group_enriched_entries($entries',
] as $needle) {
    $assert(str_contains($query, $needle), 'Report query module missing: ' . $needle);
}

foreach ([
    'function report_totals_empty',
    'function report_entry_enrich',
    'function report_group_enriched_entries',
] as $needle) {
    $assert(str_contains($totals, $needle), 'Report totals module missing: ' . $needle);
}

foreach ([
    'function report_export_csv_if_requested',
    "['detailed', 'worklog', 'summary']",
    'Content-Type: text/csv',
    'fputcsv($csv',
] as $needle) {
    $assert(str_contains($export, $needle), 'Report export module missing: ' . $needle);
}

foreach ([
    'Content-Type: text/csv',
    'fputcsv($csv',
    '$csv = fopen',
    '$sql = "SELECT tte.*',
] as $needle) {
    $assert(!str_contains($page, $needle), 'Reports page must not own query/export logic: ' . $needle);
}

echo "Report export contract OK\n";
