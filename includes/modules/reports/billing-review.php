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

function billing_review_int_list($value): array
{
    $values = is_array($value) ? $value : ($value === null || $value === '' ? [] : explode(',', (string) $value));
    $ids = [];
    foreach ($values as $item) {
        $ids[] = (int) $item;
    }
    return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
}

function billing_review_filters_from_request(array $request): array
{
    $time_range = trim((string) ($request['time_range'] ?? 'this_month'));
    if ($time_range === '') {
        $time_range = 'this_month';
    }

    return [
        'time_range' => $time_range,
        'from_date' => trim((string) ($request['from_date'] ?? '')),
        'to_date' => trim((string) ($request['to_date'] ?? '')),
        'organization_ids' => billing_review_int_list($request['organizations'] ?? $request['organization_ids'] ?? $request['organization_id'] ?? []),
        'agent_ids' => billing_review_int_list($request['agents'] ?? $request['agent_ids'] ?? $request['agent_id'] ?? []),
        'tags' => function_exists('normalize_ticket_tags') ? normalize_ticket_tags($request['tags'] ?? '', true) : [],
    ];
}

function billing_review_range_bounds(array $filters): array
{
    if (function_exists('get_time_range_bounds')) {
        $range = get_time_range_bounds(
            (string) ($filters['time_range'] ?? 'this_month'),
            (string) ($filters['from_date'] ?? ''),
            (string) ($filters['to_date'] ?? '')
        );
        return [
            'range' => (string) ($range['range'] ?? ($filters['time_range'] ?? 'this_month')),
            'start' => $range['start'] ?? null,
            'end' => $range['end'] ?? null,
        ];
    }

    $start = date('Y-m-01 00:00:00');
    $end = date('Y-m-t 23:59:59');
    return ['range' => 'this_month', 'start' => $start, 'end' => $end];
}

function billing_review_entry_source(array $entry): string
{
    if (function_exists('get_time_entry_source')) {
        return get_time_entry_source($entry);
    }

    return !empty($entry['is_manual']) ? 'manual' : 'timer';
}

function billing_review_entry_api_payload(array $entry, int $rounding): array
{
    $actual_minutes = billing_review_entry_actual_minutes($entry);
    $billable_minutes = billing_review_entry_billable_minutes($entry, $rounding);
    $billable_rate = billing_review_entry_rate($entry);
    $billable_amount = billing_review_amount_from_rate($billable_minutes, $billable_rate);
    $cost_rate = isset($entry['cost_rate']) ? (float) $entry['cost_rate'] : 0.0;
    if ($cost_rate <= 0 && isset($entry['user_cost_rate'])) {
        $cost_rate = (float) $entry['user_cost_rate'];
    }
    $cost_amount = ($actual_minutes / 60) * max(0.0, $cost_rate);

    return [
        'id' => (int) ($entry['id'] ?? 0),
        'ticket' => [
            'id' => (int) ($entry['ticket_id'] ?? 0),
            'hash' => $entry['ticket_hash'] ?? null,
            'code' => function_exists('get_ticket_code') ? get_ticket_code((int) ($entry['ticket_id'] ?? 0)) : ('#' . (int) ($entry['ticket_id'] ?? 0)),
            'title' => (string) ($entry['ticket_title'] ?? ''),
            'status' => [
                'id' => isset($entry['ticket_status_id']) ? (int) $entry['ticket_status_id'] : null,
                'name' => (string) ($entry['ticket_status_name'] ?? ''),
                'is_closed' => !empty($entry['ticket_is_closed']),
            ],
            'url' => function_exists('url') ? url('ticket', ['id' => (int) ($entry['ticket_id'] ?? 0)]) : '',
        ],
        'client' => [
            'id' => isset($entry['organization_id']) ? (int) $entry['organization_id'] : null,
            'name' => (string) ($entry['organization_name'] ?? ''),
        ],
        'agent' => [
            'id' => (int) ($entry['user_id'] ?? 0),
            'name' => trim((string) (($entry['first_name'] ?? '') . ' ' . ($entry['last_name'] ?? ''))),
        ],
        'started_at' => $entry['started_at'] ?? null,
        'ended_at' => $entry['ended_at'] ?? null,
        'source' => billing_review_entry_source($entry),
        'summary' => $entry['summary'] ?? null,
        'is_billable' => !empty($entry['is_billable']),
        'actual_minutes' => $actual_minutes,
        'billable_minutes' => $billable_minutes,
        'billable_rate' => $billable_rate,
        'billable_amount' => $billable_amount,
        'cost_rate' => $cost_rate,
        'cost_amount' => $cost_amount,
        'profit' => $billable_amount - $cost_amount,
        'tags' => function_exists('normalize_ticket_tags') ? normalize_ticket_tags($entry['ticket_tags'] ?? '', true) : [],
    ];
}

function billing_review_total_labels(array $totals): array
{
    return [
        'minutes' => function_exists('format_duration_minutes') ? format_duration_minutes((int) ($totals['minutes'] ?? 0)) : ((int) ($totals['minutes'] ?? 0) . ' min'),
        'billable_minutes' => function_exists('format_duration_minutes') ? format_duration_minutes((int) ($totals['billable_minutes'] ?? 0)) : ((int) ($totals['billable_minutes'] ?? 0) . ' min'),
        'billable_amount' => function_exists('format_money') ? format_money((float) ($totals['billable_amount'] ?? 0)) : (string) ($totals['billable_amount'] ?? 0),
        'cost_amount' => function_exists('format_money') ? format_money((float) ($totals['cost_amount'] ?? 0)) : (string) ($totals['cost_amount'] ?? 0),
        'profit' => function_exists('format_money') ? format_money((float) ($totals['profit'] ?? 0)) : (string) ($totals['profit'] ?? 0),
    ];
}

function billing_review_payload(array $filters, array $user, int $limit = 100, int $offset = 0): array
{
    $limit = max(1, min(250, $limit));
    $offset = max(0, $offset);
    $rounding = function_exists('get_billing_rounding_increment') ? get_billing_rounding_increment() : 1;
    $range = billing_review_range_bounds($filters);

    $empty_totals = [
        'minutes' => 0,
        'billable_minutes' => 0,
        'billable_amount' => 0.0,
        'cost_amount' => 0.0,
        'profit' => 0.0,
    ];

    if (!function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return [
            'filters' => $filters,
            'range' => $range,
            'entries' => [],
            'totals' => $empty_totals,
            'total_labels' => billing_review_total_labels($empty_totals),
            'actions' => billing_review_adjustment_actions(),
            'bulk_actions' => billing_review_bulk_adjustment_actions(),
            'pagination' => ['limit' => $limit, 'offset' => $offset, 'has_more' => false],
        ];
    }

    $ticket_tags_select = (function_exists('ticket_tags_column_exists') && ticket_tags_column_exists()) ? ', t.tags AS ticket_tags' : ', NULL AS ticket_tags';
    $ticket_custom_rate_select = (function_exists('column_exists') && column_exists('tickets', 'custom_billable_rate'))
        ? 't.custom_billable_rate AS ticket_custom_billable_rate,'
        : 'NULL AS ticket_custom_billable_rate,';
    $user_billable_rate_select = (function_exists('column_exists') && column_exists('users', 'billable_rate'))
        ? 'u.billable_rate AS user_billable_rate,'
        : 'NULL AS user_billable_rate,';

    $sql = "SELECT tte.*,
                   t.title AS ticket_title,
                   t.hash AS ticket_hash,
                   t.organization_id,
                   {$ticket_custom_rate_select}
                   t.status_id AS ticket_status_id,
                   s.is_closed AS ticket_is_closed,
                   s.name AS ticket_status_name,
                   o.name AS organization_name,
                   o.billable_rate AS org_billable_rate,
                   u.first_name,
                   u.last_name,
                   {$user_billable_rate_select}
                   u.cost_rate AS user_cost_rate
                   {$ticket_tags_select}
            FROM ticket_time_entries tte
            JOIN tickets t ON tte.ticket_id = t.id
            LEFT JOIN statuses s ON t.status_id = s.id
            LEFT JOIN organizations o ON t.organization_id = o.id
            LEFT JOIN users u ON tte.user_id = u.id
            WHERE 1 = 1";
    $params = [];
    if (function_exists('tenant_sql_filter')) {
        $sql .= tenant_sql_filter('tickets', 't', $params);
    } elseif (function_exists('column_exists') && column_exists('tickets', 'tenant_id') && function_exists('current_tenant_id')) {
        $sql .= " AND t.tenant_id = ?";
        $params[] = current_tenant_id();
    }

    if (!empty($range['start']) && !empty($range['end'])) {
        $sql .= " AND tte.started_at >= ? AND tte.started_at <= ?";
        $params[] = $range['start'];
        $params[] = $range['end'];
    }

    $organization_ids = billing_review_int_list($filters['organization_ids'] ?? []);
    if (!empty($organization_ids)) {
        $sql .= " AND t.organization_id IN (" . implode(',', array_fill(0, count($organization_ids), '?')) . ")";
        foreach ($organization_ids as $id) {
            $params[] = $id;
        }
    }

    $agent_ids = billing_review_int_list($filters['agent_ids'] ?? []);
    if (!empty($agent_ids)) {
        $sql .= " AND tte.user_id IN (" . implode(',', array_fill(0, count($agent_ids), '?')) . ")";
        foreach ($agent_ids as $id) {
            $params[] = $id;
        }
    }

    if (($user['role'] ?? '') !== 'admin') {
        $sql .= " AND tte.user_id = ?";
        $params[] = (int) ($user['id'] ?? 0);
    }

    $tags = is_array($filters['tags'] ?? null) ? $filters['tags'] : [];
    if (!empty($tags) && function_exists('ticket_tags_column_exists') && ticket_tags_column_exists()) {
        $clauses = [];
        foreach ($tags as $tag) {
            $clauses[] = "FIND_IN_SET(?, REPLACE(IFNULL(t.tags, ''), ', ', ',')) > 0";
            $params[] = $tag;
        }
        $sql .= " AND (" . implode(' OR ', $clauses) . ")";
    }

    $sql .= " ORDER BY tte.started_at DESC, tte.id DESC LIMIT " . ($limit + 1) . " OFFSET " . $offset;
    $rows = db_fetch_all($sql, $params);
    $has_more = count($rows) > $limit;
    if ($has_more) {
        array_pop($rows);
    }

    $entries = [];
    $totals = $empty_totals;
    foreach ($rows as $row) {
        $entry = billing_review_entry_api_payload($row, $rounding);
        $entries[] = $entry;
        $totals['minutes'] += $entry['actual_minutes'];
        $totals['billable_minutes'] += $entry['billable_minutes'];
        $totals['billable_amount'] += $entry['billable_amount'];
        $totals['cost_amount'] += $entry['cost_amount'];
        $totals['profit'] += $entry['profit'];
    }

    return [
        'filters' => $filters,
        'range' => $range,
        'entries' => $entries,
        'totals' => $totals,
        'total_labels' => billing_review_total_labels($totals),
        'actions' => billing_review_adjustment_actions(),
        'bulk_actions' => billing_review_bulk_adjustment_actions(),
        'pagination' => ['limit' => $limit, 'offset' => $offset, 'has_more' => $has_more],
    ];
}
