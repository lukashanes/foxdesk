<?php
define('BASE_PATH', dirname(__DIR__));

function t($value, $params = [])
{
    foreach ($params as $key => $replacement) {
        $value = str_replace('{' . $key . '}', (string) $replacement, (string) $value);
    }
    return (string) $value;
}

function round_minutes_nearest($minutes, $increment)
{
    $minutes = max(0, (int) $minutes);
    $increment = max(1, (int) $increment);
    return (int) ceil($minutes / $increment) * $increment;
}

require_once BASE_PATH . '/includes/modules/reports/billing-review.php';

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

echo "Billing review tests passed\n";
