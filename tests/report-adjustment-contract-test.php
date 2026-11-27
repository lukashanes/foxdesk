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
require_once $root . '/includes/ticket-time-functions.php';
require_once $root . '/includes/modules/reports/report-totals.php';
require_once $root . '/includes/modules/reports/billing-review.php';
require_once $root . '/includes/modules/reports/report-adjustments.php';

$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$page = file_get_contents($root . '/includes/modules/reports/report-page-controller.php');
$adjustments = file_get_contents($root . '/includes/modules/reports/report-adjustments.php');

$assert($bootstrap !== false && $page !== false && $adjustments !== false, 'Report adjustment files must be readable.');
$assert(str_contains($bootstrap, '/reports/report-adjustments.php'), 'Module bootstrap must load report adjustments.');
$assert(str_contains($page, 'report_handle_admin_post_actions($post, $rounding)'), 'Reports controller must delegate POST actions to report adjustments.');

foreach ([
    'function report_handle_admin_post_actions',
    'function report_handle_bulk_billable_update',
    'function report_handle_single_billable_adjustment',
    'function report_rate_for_adjustment',
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
    $assert(!str_contains($page, $needle), 'Reports controller must not own report POST handler: ' . $needle);
}

$entry = [
    'duration_minutes' => 90,
    'is_billable' => 0,
    'billable_rate' => 0,
];
$rate = report_rate_for_adjustment($entry, 'target_total', 3000.0, 15);
$assert($rate !== null && abs($rate - 2000.0) < 0.001, 'Target total must be available even before an item is billable.');
$assert(report_rate_for_adjustment($entry, 'discount_percent', 110.0, 15) === null, 'Invalid discount percent must be rejected.');

$partial = ['duration_minutes' => 17, 'is_billable' => 0];
$rate = report_rate_for_adjustment($partial, 'target_total', 170.0, 15);
$after = report_entry_enrich(array_merge($partial, ['billable_rate' => round($rate, 2), 'is_billable' => 1]), 15);
$assert(abs($after['billable_amount'] - 170.0) < 0.001, 'A target amount must match the report total for time between rounding steps.');
$assert(report_rate_for_adjustment(['duration_minutes' => 0], 'target_total', 120.0, 15) === null, 'A positive target cannot be assigned without any minutes.');

final class ReportAdjustmentFlash extends RuntimeException
{
}

function flash($message, $type): void
{
    throw new ReportAdjustmentFlash($type);
}

$fixture_entries = [
    ['id' => 1, 'duration_minutes' => 17, 'is_billable' => 1],
    ['id' => 2, 'duration_minutes' => 11, 'is_billable' => 1],
];
$saved_rates = [];
function db_fetch_all($sql, $ids): array
{
    return array_values(array_filter($GLOBALS['fixture_entries'], static fn($entry) => in_array($entry['id'], $ids, true)));
}
function db_update($table, $values, $where, $ids): void
{
    $GLOBALS['saved_rates'][$ids[0]] = $values['billable_rate'];
}

$flash_type = null;
try {
    report_handle_bulk_billable_update(['entry_ids' => [1, 2], 'bulk_action' => 'target_total', 'bulk_target_total' => '280'], 15);
} catch (ReportAdjustmentFlash $e) {
    $flash_type = $e->getMessage();
}
$assert($flash_type === 'success' && $saved_rates === [1 => 600.0, 2 => 600.0],
    'Bulk adjustment must divide the target by summed actual minutes and save that rate on both items.');

echo "Report adjustment contract OK\n";
