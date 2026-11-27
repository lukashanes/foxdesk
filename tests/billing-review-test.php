<?php
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/includes/functions.php';

function assert_billing_review($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$entry = [
    'is_billable' => 1,
    'duration_minutes' => 61,
    'billable_rate' => 1000,
];

assert_billing_review(billing_review_entry_billable_minutes($entry, 15) === 75, 'Billable minutes should round up to 75.');
assert_billing_review(abs(billing_review_amount_from_rate(75, 1000) - 1250) < 0.001, 'Amount should use rounded billable minutes.');
assert_billing_review(abs(billing_review_adjusted_rate($entry, 'discount_percent', 20, 15) - 800) < 0.001, 'Percent discount should reduce rate.');
assert_billing_review(abs(billing_review_adjusted_rate($entry, 'discount_amount', 250, 15) - 800) < 0.001, 'Amount discount should convert to an effective rate.');
assert_billing_review(abs(billing_review_adjusted_rate($entry, 'target_total', 500, 15) - 400) < 0.001, 'Target total should convert to an effective rate.');
assert_billing_review(billing_review_adjusted_rate(['is_billable' => 0, 'duration_minutes' => 60, 'billable_rate' => 1000], 'discount_amount', 100, 15) === null, 'Non-billable amount discount should not produce a rate.');
assert_billing_review(array_key_exists('discount_amount', billing_review_adjustment_actions()), 'Item adjustment actions should include discount amount.');
assert_billing_review(array_key_exists('discount_amount', billing_review_bulk_adjustment_actions()), 'Bulk adjustment actions should include discount amount.');

foreach ([
    'full percentage discount' => ['discount_percent', 100, 0.0],
    'negative percentage discount' => ['discount_percent', -1, null],
    'percentage discount over 100' => ['discount_percent', 101, null],
    'flat discount greater than the item amount' => ['discount_amount', 1300, 0.0],
    'zero target total' => ['target_total', 0, 0.0],
    'unknown adjustment' => ['unknown', 20, null],
] as $case => [$action, $value, $expected]) {
    assert_billing_review(billing_review_adjusted_rate($entry, $action, $value, 15) === $expected, $case);
}

$entries = [
    $entry,
    array_replace($entry, ['duration_minutes' => 31, 'billable_minutes' => 30]),
    array_replace($entry, ['is_billable' => 0, 'billable_minutes' => 60]),
];
$minutes = billing_review_total_billable_minutes($entries, 15);
assert_billing_review($minutes === 105, 'Bulk basis combines rounded and stored minutes and excludes non-billable items.');
$rate = billing_review_adjusted_rate($entries[0], 'target_total', 875, 15, $minutes);
assert_billing_review($rate !== null && abs(billing_review_amount_from_rate($minutes, $rate) - 875.0) < 0.001,
    'The bulk target amount must be distributed over all billable minutes.');

$base = ['ticket_id' => 4, 'organization_id' => 3, 'organization_name' => 'Test company', 'cost_rate' => 60, 'billable_rate' => 120];
$billable = report_entry_enrich($base + ['user_id' => 1, 'duration_minutes' => 30, 'is_billable' => 1], 1);
$non_billable = report_entry_enrich($base + ['user_id' => 2, 'duration_minutes' => 15, 'is_billable' => 0], 1);
$grouped = report_group_enriched_entries([$billable, $non_billable]);
$expected = ['minutes' => 45, 'billable_minutes' => 30, 'billable_amount' => 60.0, 'cost_amount' => 45.0, 'profit' => 15.0];
assert_billing_review(array_intersect_key($grouped['totals'], $expected) === $expected, 'Report totals must keep non-billable labor costs.');
assert_billing_review(array_intersect_key($grouped['by_ticket'][4], $expected) === $expected, 'Ticket totals must match the report for a single ticket.');

echo "Billing review tests passed (8 additional cases).\n";
