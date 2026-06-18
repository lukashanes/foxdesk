<?php
/**
 * Report totals and grouping helpers.
 */

function report_totals_empty(): array
{
    return [
        'minutes' => 0,
        'billable_minutes' => 0,
        'billable_amount' => 0.0,
        'cost_amount' => 0.0,
        'profit' => 0.0,
    ];
}

function report_scale_class(string $prefix, $value, $max = 100, int $steps = 20): string
{
    $value = max(0, (float) $value);
    $max = max(1, (float) $max);
    $steps = max(1, $steps);
    $index = (int) round($steps * $value / $max);
    if ($value > 0) {
        $index = max(1, $index);
    }
    $index = min($steps, max(0, $index));
    return $prefix . $index;
}

function report_width_class($percent): string
{
    return report_scale_class('report-width--', $percent, 100, 20);
}

function report_tone_class($index): string
{
    return 'report-tone--' . ((int) $index % 8);
}

function report_entry_enrich(array $entry, int $rounding): array
{
    if (empty($entry['ended_at']) && !empty($entry['started_at']) && function_exists('calculate_timer_elapsed')) {
        $actual_minutes = max(0, (int) floor(calculate_timer_elapsed($entry) / 60));
    } else {
        $actual_minutes = max(0, (int) ($entry['duration_minutes'] ?? 0));
    }

    $source = function_exists('get_time_entry_source')
        ? get_time_entry_source($entry)
        : (!empty($entry['is_manual']) ? 'manual' : 'timer');

    $billable_rate = function_exists('get_time_entry_effective_billable_rate')
        ? get_time_entry_effective_billable_rate($entry)
        : (float) ($entry['billable_rate'] ?? 0);

    $cost_rate = isset($entry['cost_rate']) ? (float) $entry['cost_rate'] : 0.0;
    if ($cost_rate <= 0 && isset($entry['user_cost_rate'])) {
        $cost_rate = (float) $entry['user_cost_rate'];
    }

    $billable_minutes = !empty($entry['is_billable'])
        ? (function_exists('round_minutes_nearest') ? round_minutes_nearest($actual_minutes, $rounding) : $actual_minutes)
        : 0;
    $billable_amount = ($billable_minutes / 60) * $billable_rate;
    $cost_amount = ($actual_minutes / 60) * $cost_rate;

    $entry['_source'] = $source;
    $entry['actual_minutes'] = $actual_minutes;
    $entry['billable_minutes'] = $billable_minutes;
    $entry['billable_rate'] = $billable_rate;
    $entry['cost_rate'] = $cost_rate;
    $entry['billable_amount'] = $billable_amount;
    $entry['cost_amount'] = $cost_amount;
    $entry['profit'] = $billable_amount - $cost_amount;

    return $entry;
}

function report_group_enriched_entries(array $entries, array $ai_user_ids = []): array
{
    $totals = report_totals_empty();
    $by_org = [];
    $by_agent = [];
    $by_ticket = [];
    $by_week = [];
    $by_source = [];

    foreach ($entries as $entry) {
        $actual_minutes = (int) ($entry['actual_minutes'] ?? 0);
        $billable_minutes = (int) ($entry['billable_minutes'] ?? 0);
        $billable_amount = (float) ($entry['billable_amount'] ?? 0);
        $cost_amount = (float) ($entry['cost_amount'] ?? 0);
        $profit = (float) ($entry['profit'] ?? 0);

        $totals['minutes'] += $actual_minutes;
        $totals['billable_minutes'] += $billable_minutes;
        $totals['billable_amount'] += $billable_amount;
        $totals['cost_amount'] += $cost_amount;
        $totals['profit'] += $profit;

        if (in_array((int) ($entry['user_id'] ?? 0), $ai_user_ids, true)) {
            $totals['ai_minutes'] = ($totals['ai_minutes'] ?? 0) + $actual_minutes;
            $totals['ai_billable'] = ($totals['ai_billable'] ?? 0) + $billable_amount;
            $totals['ai_cost'] = ($totals['ai_cost'] ?? 0) + $cost_amount;
        } else {
            $totals['human_minutes'] = ($totals['human_minutes'] ?? 0) + $actual_minutes;
            $totals['human_billable'] = ($totals['human_billable'] ?? 0) + $billable_amount;
            $totals['human_cost'] = ($totals['human_cost'] ?? 0) + $cost_amount;
        }

        $org_id = $entry['organization_id'] ?? 0;
        $org_key = (string) $org_id;
        if (!isset($by_org[$org_key])) {
            $by_org[$org_key] = [
                'id' => $org_id,
                'name' => $entry['organization_name'] ?: t('-- No organization --'),
                'rate' => (float) ($entry['billable_rate'] ?? 0),
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0,
            ];
        }
        $by_org[$org_key]['minutes'] += $actual_minutes;
        $by_org[$org_key]['billable_minutes'] += $billable_minutes;
        $by_org[$org_key]['billable_amount'] += $billable_amount;
        $by_org[$org_key]['cost_amount'] += $cost_amount;
        $by_org[$org_key]['profit'] += $profit;

        $agent_id = $entry['user_id'] ?? 0;
        $agent_key = (string) $agent_id;
        if (!isset($by_agent[$agent_key])) {
            $by_agent[$agent_key] = [
                'id' => $agent_id,
                'name' => trim(($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? '')),
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0,
            ];
        }
        $by_agent[$agent_key]['minutes'] += $actual_minutes;
        $by_agent[$agent_key]['billable_minutes'] += $billable_minutes;
        $by_agent[$agent_key]['billable_amount'] += $billable_amount;
        $by_agent[$agent_key]['cost_amount'] += $cost_amount;
        $by_agent[$agent_key]['profit'] += $profit;

        $ticket_key = (string) ($entry['ticket_id'] ?? 0);
        if (!isset($by_ticket[$ticket_key])) {
            $by_ticket[$ticket_key] = [
                'id' => $entry['ticket_id'] ?? 0,
                'title' => $entry['ticket_title'] ?? '',
                'organization_name' => $entry['organization_name'] ?? '',
                'tags' => $entry['ticket_tags'] ?? '',
                'is_closed' => !empty($entry['ticket_is_closed']),
                'status_name' => $entry['ticket_status_name'] ?? '',
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0,
            ];
        }
        $by_ticket[$ticket_key]['minutes'] += $actual_minutes;
        $by_ticket[$ticket_key]['billable_minutes'] += $billable_minutes;
        $by_ticket[$ticket_key]['billable_amount'] += $billable_amount;
        $by_ticket[$ticket_key]['cost_amount'] += $cost_amount;
        $by_ticket[$ticket_key]['profit'] += $profit;

        $week_key = !empty($entry['started_at']) ? date('o-W', strtotime($entry['started_at'])) : 'unknown';
        if (!isset($by_week[$week_key])) {
            $week_start = !empty($entry['started_at']) ? new DateTime($entry['started_at']) : new DateTime();
            $week_start->setISODate((int) $week_start->format('o'), (int) $week_start->format('W'));
            $week_end = clone $week_start;
            $week_end->modify('+6 days');
            $by_week[$week_key] = [
                'label' => $week_start->format('Y-m-d'),
                'label_formatted' => $week_start->format('M j') . ' - ' . $week_end->format('M j, Y'),
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0,
                'agents' => [],
            ];
        }
        $by_week[$week_key]['minutes'] += $actual_minutes;
        $by_week[$week_key]['billable_minutes'] += $billable_minutes;
        $by_week[$week_key]['billable_amount'] += $billable_amount;
        $by_week[$week_key]['cost_amount'] += $cost_amount;
        $by_week[$week_key]['profit'] += $profit;

        $week_agent_key = (string) ($entry['user_id'] ?? 0);
        if (!isset($by_week[$week_key]['agents'][$week_agent_key])) {
            $by_week[$week_key]['agents'][$week_agent_key] = [
                'name' => trim(($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? '')),
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
            ];
        }
        $by_week[$week_key]['agents'][$week_agent_key]['minutes'] += $actual_minutes;
        $by_week[$week_key]['agents'][$week_agent_key]['billable_minutes'] += $billable_minutes;
        $by_week[$week_key]['agents'][$week_agent_key]['billable_amount'] += $billable_amount;

        $source = (string) ($entry['_source'] ?? 'timer');
        if (!isset($by_source[$source])) {
            $source_labels = ['timer' => t('Timer'), 'manual' => t('Manual'), 'ai' => t('AI')];
            $by_source[$source] = [
                'source' => $source,
                'label' => $source_labels[$source] ?? ucfirst($source),
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0,
                'count' => 0,
            ];
        }
        $by_source[$source]['minutes'] += $actual_minutes;
        $by_source[$source]['billable_minutes'] += $billable_minutes;
        $by_source[$source]['billable_amount'] += $billable_amount;
        $by_source[$source]['cost_amount'] += $cost_amount;
        $by_source[$source]['profit'] += $profit;
        $by_source[$source]['count']++;
    }

    return [
        'entries' => $entries,
        'totals' => $totals,
        'by_org' => $by_org,
        'by_agent' => $by_agent,
        'by_ticket' => $by_ticket,
        'by_week' => $by_week,
        'by_source' => $by_source,
    ];
}
