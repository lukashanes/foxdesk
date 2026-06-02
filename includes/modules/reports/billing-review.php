<?php
/**
 * Billing review helpers.
 *
 * Report pages use this layer for item-level billing adjustments so the same
 * rate/discount/total math is testable outside the large admin view.
 */

function billing_review_adjustment_actions(): array
{
    return [
        'set_rate' => t('Rate'),
        'discount_percent' => t('Discount %'),
        'discount_amount' => t('Discount amount'),
        'target_total' => t('Item total'),
    ];
}

function billing_review_bulk_adjustment_actions(): array
{
    return [
        'set_rate' => t('Set hourly rate'),
        'discount_percent' => t('Discount hourly rate'),
        'discount_amount' => t('Discount amount'),
        'target_total' => t('Set target total'),
    ];
}

function billing_review_entry_actual_minutes(array $entry): int
{
    if (empty($entry['ended_at']) && !empty($entry['started_at']) && function_exists('calculate_timer_elapsed')) {
        return max(0, (int) floor(calculate_timer_elapsed($entry) / 60));
    }

    return max(0, (int) ($entry['duration_minutes'] ?? $entry['actual_minutes'] ?? 0));
}

function billing_review_entry_billable_minutes(array $entry, int $rounding): int
{
    if (empty($entry['is_billable'])) {
        return 0;
    }

    $minutes = (int) ($entry['billable_minutes'] ?? 0);
    if ($minutes > 0) {
        return $minutes;
    }

    $actual = billing_review_entry_actual_minutes($entry);
    return function_exists('round_minutes_nearest') ? round_minutes_nearest($actual, $rounding) : $actual;
}

function billing_review_entry_rate(array $entry): float
{
    if (function_exists('get_time_entry_effective_billable_rate')) {
        return get_time_entry_effective_billable_rate($entry);
    }

    return max(0.0, (float) ($entry['billable_rate'] ?? 0));
}

function billing_review_amount_from_rate(int $billable_minutes, float $rate): float
{
    if ($billable_minutes <= 0) {
        return 0.0;
    }

    return max(0.0, ($billable_minutes / 60) * max(0.0, $rate));
}

function billing_review_rate_from_target_amount(float $target_amount, int $billable_minutes): ?float
{
    if ($billable_minutes <= 0) {
        return null;
    }

    return max(0.0, $target_amount) / ($billable_minutes / 60);
}

function billing_review_adjusted_rate(array $entry, string $action, float $value, int $rounding, ?int $shared_billable_minutes = null): ?float
{
    $billable_minutes = billing_review_entry_billable_minutes($entry, $rounding);
    $current_rate = billing_review_entry_rate($entry);
    $current_amount = billing_review_amount_from_rate($billable_minutes, $current_rate);

    switch ($action) {
        case 'set_rate':
            return max(0.0, $value);

        case 'discount_percent':
            if ($value < 0 || $value > 100) {
                return null;
            }
            return max(0.0, $current_rate * (1 - ($value / 100)));

        case 'discount_amount':
            $target_amount = max(0.0, $current_amount - max(0.0, $value));
            return billing_review_rate_from_target_amount($target_amount, $billable_minutes);

        case 'target_total':
            $minutes = $shared_billable_minutes !== null ? $shared_billable_minutes : $billable_minutes;
            return billing_review_rate_from_target_amount(max(0.0, $value), $minutes);
    }

    return null;
}

function billing_review_total_billable_minutes(array $entries, int $rounding): int
{
    $total = 0;
    foreach ($entries as $entry) {
        $total += billing_review_entry_billable_minutes($entry, $rounding);
    }
    return $total;
}
