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

function report_billable_time_notice(array $totals, int $rounding): ?array
{
    if ($rounding <= 1) {
        return null;
    }

    $actual_minutes = max(0, (int) ($totals['minutes'] ?? 0));
    $billable_minutes = max(0, (int) ($totals['billable_minutes'] ?? 0));

    if ($billable_minutes <= 0) {
        return null;
    }

    $rounding = max(1, $rounding);
    $delta_minutes = $billable_minutes - $actual_minutes;

    if ($delta_minutes > 0) {
        return [
            'tone' => 'warning',
            'title' => t('Why is billable time higher?'),
            'text' => t('Billable time is rounded up per billable item by the report rounding setting ({minutes} min). It can be higher than tracked time.', ['minutes' => $rounding]),
            'delta' => t('Rounding difference: {duration}', ['duration' => format_duration_minutes($delta_minutes)]),
        ];
    }

    return [
        'tone' => 'info',
        'title' => t('How billable time is calculated'),
        'text' => t('Billable time includes entries marked billable and is rounded by the report rounding setting ({minutes} min).', ['minutes' => $rounding]),
        'delta' => null,
    ];
}

function report_detail_plain_text($html): string
{
    if (function_exists('app_contract_plain_text')) {
        return app_contract_plain_text($html);
    }

    $text = (string) ($html ?? '');
    $text = preg_replace('/<(br|hr)\b[^>]*>/i', "\n", $text);
    $text = preg_replace('/<\/(p|div|li|ul|ol|h[1-6]|tr|blockquote)>/i', "\n", $text);
    $text = preg_replace('/<(p|div|li|ul|ol|h[1-6]|tr|blockquote)\b[^>]*>/i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
    $text = preg_replace('/ *\R */u', "\n", $text);
    $text = preg_replace('/\R{2,}/u', "\n", $text);

    return trim($text ?? '');
}

function report_entry_public_comment_visible(array $entry, bool $public): bool
{
    return !$public || empty($entry['comment_is_internal']);
}

function report_entry_comment_text(array $entry, bool $public): string
{
    if (!report_entry_public_comment_visible($entry, $public)) {
        return '';
    }

    return report_detail_plain_text($entry['comment_content'] ?? '');
}

function report_entry_comment_html(array $entry, bool $public): string
{
    if (!report_entry_public_comment_visible($entry, $public)) {
        return '';
    }

    return trim((string) ($entry['comment_content'] ?? ''));
}

function report_entry_work_summary(array $entry, bool $public): string
{
    $comment_text = report_entry_comment_text($entry, $public);
    if ($comment_text !== '') {
        return mb_substr(preg_replace('/\s+/u', ' ', $comment_text), 0, 180);
    }

    $summary = trim((string) ($entry['time_entry_summary'] ?? $entry['summary'] ?? ''));
    if ($summary !== '') {
        return $summary;
    }

    return t('Work logged');
}

function report_entry_model_minutes(array $entry, array $template): int
{
    if (isset($entry['actual_minutes'])) {
        return max(0, (int) $entry['actual_minutes']);
    }

    $minutes = max(0, (int) ($entry['duration_minutes'] ?? 0));
    $rounding = max(0, (int) ($template['rounding_minutes'] ?? 0));
    if ($rounding > 0 && function_exists('round_minutes_nearest')) {
        return round_minutes_nearest($minutes, $rounding);
    }

    return $minutes;
}

function report_entry_model_billable_minutes(array $entry, array $template): int
{
    if (isset($entry['billable_minutes'])) {
        return max(0, (int) $entry['billable_minutes']);
    }

    if (empty($entry['is_billable'])) {
        return 0;
    }

    return report_entry_model_minutes($entry, $template);
}

function report_entry_model_amount(array $entry, array $template): float
{
    if (isset($entry['billable_amount'])) {
        return (float) $entry['billable_amount'];
    }

    if (empty($entry['is_billable'])) {
        return 0.0;
    }

    $rate = function_exists('get_report_entry_billable_rate')
        ? get_report_entry_billable_rate($entry, $template)
        : (float) ($entry['billable_rate'] ?? 0);

    return (report_entry_model_billable_minutes($entry, $template) / 60) * $rate;
}

function report_ticket_detail_model(array $entries, array $template = [], bool $public = false): array
{
    $tickets = [];
    $ticket_order = [];

    foreach ($entries as $entry) {
        $ticket_id = (int) ($entry['ticket_id'] ?? 0);
        $ticket_key = (string) $ticket_id;
        if (!isset($tickets[$ticket_key])) {
            $ticket_order[] = $ticket_key;
            $tickets[$ticket_key] = [
                'id' => $ticket_id,
                'code' => function_exists('get_ticket_code') ? get_ticket_code($ticket_id) : ('#' . $ticket_id),
                'title' => (string) ($entry['ticket_title'] ?? ''),
                'organization_name' => (string) ($entry['organization_name'] ?? ''),
                'tags' => $entry['ticket_tags'] ?? '',
                'minutes' => 0,
                'billable_minutes' => 0,
                'amount' => 0.0,
                'entries_count' => 0,
                'entries' => [],
            ];
        }

        $actual_minutes = report_entry_model_minutes($entry, $template);
        $billable_minutes = report_entry_model_billable_minutes($entry, $template);
        $amount = report_entry_model_amount($entry, $template);
        $comment_text = report_entry_comment_text($entry, $public);
        $comment_html = report_entry_comment_html($entry, $public);

        $tickets[$ticket_key]['minutes'] += $actual_minutes;
        $tickets[$ticket_key]['billable_minutes'] += $billable_minutes;
        $tickets[$ticket_key]['amount'] += $amount;
        $tickets[$ticket_key]['entries_count']++;
        $tickets[$ticket_key]['entries'][] = [
            'id' => (int) ($entry['id'] ?? 0),
            'ticket_id' => $ticket_id,
            'comment_id' => isset($entry['comment_id']) ? (int) $entry['comment_id'] : null,
            'comment_is_internal' => !empty($entry['comment_is_internal']),
            'comment_html' => $comment_html,
            'comment_text' => $comment_text,
            'summary' => report_entry_work_summary($entry, $public),
            'has_public_comment' => $comment_text !== '',
            'date' => (string) ($entry['entry_date'] ?? (!empty($entry['started_at']) ? date('Y-m-d', strtotime($entry['started_at'])) : '')),
            'started_at' => $entry['started_at'] ?? null,
            'ended_at' => $entry['ended_at'] ?? null,
            'time_range' => function_exists('format_time_range') ? format_time_range($entry) : '',
            'duration_minutes' => $actual_minutes,
            'billable_minutes' => $billable_minutes,
            'amount' => $amount,
            'agent_name' => trim((string) (($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? ''))),
            'source' => (string) ($entry['_source'] ?? $entry['source'] ?? ''),
        ];
    }

    $ordered = [];
    foreach ($ticket_order as $ticket_key) {
        $ordered[] = $tickets[$ticket_key];
    }

    return [
        'tickets' => $ordered,
        'ticket_count' => count($ordered),
        'entry_count' => count($entries),
    ];
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

    $billable_minutes = !empty($entry['is_billable']) ? $actual_minutes : 0;
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
