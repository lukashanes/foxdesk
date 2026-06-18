<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

if (!function_exists('t')) {
    function t($value) { return $value; }
}
if (!function_exists('round_minutes_nearest')) {
    function round_minutes_nearest($minutes, $rounding) { return (int) ceil($minutes / max(1, $rounding)) * max(1, $rounding); }
}
if (!function_exists('calculate_timer_elapsed')) {
    function calculate_timer_elapsed($entry) { return (int) ($entry['elapsed_seconds'] ?? 0); }
}

require_once $root . '/includes/modules/reports/report-totals.php';
require_once $root . '/includes/modules/reports/billing-review.php';
require_once $root . '/includes/modules/reports/report-adjustments.php';

$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$page = file_get_contents($root . '/pages/admin/reports.php');
$adjustments = file_get_contents($root . '/includes/modules/reports/report-adjustments.php');

$assert($bootstrap !== false && $page !== false && $adjustments !== false, 'Report adjustment files must be readable.');
$assert(str_contains($bootstrap, '/reports/report-adjustments.php'), 'Module bootstrap must load report adjustments.');
$assert(str_contains($page, 'report_handle_admin_post_actions($_POST, $rounding)'), 'Reports page must delegate POST actions to report adjustments.');

foreach ([
    'function report_handle_admin_post_actions',
    'function report_handle_bulk_billable_update',
    'function report_handle_single_billable_adjustment',
    'function report_rate_for_adjustment',
    'function report_entry_rounded_actual_minutes',
    'bulk_update_billable_entries',
    'adjust_billable_entry',
    'save_agent_client_rate',
    'save_agent_default_rate',
    'create_report_share',
    'revoke_report_share',
] as $needle) {
    $assert(str_contains($adjustments, $needle), 'Report adjustment module missing: ' . $needle);
}

foreach ([
    "isset(\$_POST['save_agent_client_rate'])",
    "isset(\$_POST['bulk_update_billable_entries'])",
    "isset(\$_POST['adjust_billable_entry'])",
    "isset(\$_POST['create_report_share'])",
] as $needle) {
    $assert(!str_contains($page, $needle), 'Reports page must not own report POST handler: ' . $needle);
}

$entry = [
    'duration_minutes' => 90,
    'is_billable' => 0,
    'billable_rate' => 0,
];
$rate = report_rate_for_adjustment($entry, 'target_total', 3000.0, 15);
$assert($rate !== null && abs($rate - 2000.0) < 0.001, 'Target total must use actual rounded minutes even before an item is billable.');
$assert(report_rate_for_adjustment($entry, 'discount_percent', 110.0, 15) === null, 'Invalid discount percent must be rejected.');

echo "Report adjustment contract OK\n";
