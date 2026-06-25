<?php
/**
 * Work queues.
 *
 * Work is the action-oriented layer above the ticket registry. It answers
 * "what needs attention now" without turning dashboard/tickets pages into
 * another monolith.
 */

function work_queue_definitions(): array
{
    return [
        'mine' => [
            'label' => 'My work',
            'description' => 'Tickets assigned to the current user.',
            'scope' => 'personal',
        ],
        'unassigned' => [
            'label' => 'New tickets',
            'description' => 'Tickets waiting for an owner.',
            'scope' => 'team',
        ],
        'overdue' => [
            'label' => 'Overdue',
            'description' => 'Open work past its due date.',
            'scope' => 'team',
        ],
        'waiting' => [
            'label' => 'Waiting',
            'description' => 'Tickets waiting for a customer, vendor, or another person.',
            'scope' => 'team',
        ],
        'done_today' => [
            'label' => 'Done today',
            'description' => 'Completed work updated today.',
            'scope' => 'team',
        ],
    ];
}

function work_status_ids_for_group(string $group): array
{
    $group = ticket_status_group_normalize($group);
    if (!function_exists('get_statuses')) {
        return [];
    }

    $ids = [];
    foreach (get_statuses() as $status) {
        if (ticket_status_group_from_status($status) === $group) {
            $ids[] = (int) $status['id'];
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

function work_queue_filters(string $queue_key, array $user, int $limit = 8): array
{
    $queue_key = array_key_exists($queue_key, work_queue_definitions()) ? $queue_key : 'mine';
    $filters = [
        'is_archived' => 0,
        'sort' => 'due_date',
        'limit' => max(1, min(50, $limit)),
    ];

    switch ($queue_key) {
        case 'mine':
            if (($user['role'] ?? '') === 'user') {
                $filters['viewer_user_id'] = (int) ($user['id'] ?? 0);
            } else {
                $filters['assigned_to'] = (int) ($user['id'] ?? 0);
            }
            $filters['status_group_not'] = ['done', 'archived'];
            break;

        case 'unassigned':
            $filters['assignee_unassigned'] = true;
            $filters['status_group_not'] = ['done', 'archived'];
            break;

        case 'overdue':
            $filters['due_date_overdue'] = true;
            $filters['status_group_not'] = ['done', 'archived'];
            break;

        case 'waiting':
            $filters['status_group'] = 'waiting';
            $filters['sort'] = 'last_updated';
            break;

        case 'done_today':
            $filters['status_group'] = 'done';
            $filters['updated_from'] = date('Y-m-d 00:00:00');
            $filters['updated_to'] = date('Y-m-d 23:59:59');
            $filters['sort'] = 'last_updated';
            break;
    }

    if (function_exists('build_ticket_visibility_filters_for_user')) {
        $filters = build_ticket_visibility_filters_for_user($user, $filters);
    }

    return $filters;
}

function work_queue_items(string $queue_key, array $user, int $limit = 8): array
{
    if (!function_exists('get_tickets')) {
        return [];
    }
    $items = get_tickets(work_queue_filters($queue_key, $user, $limit));
    if (($user['role'] ?? '') === 'user') {
        return $items;
    }

    $scope_user_id = $queue_key === 'mine' ? (int) ($user['id'] ?? 0) : 0;
    return work_queue_attach_worked_minutes($items, $scope_user_id);
}

function work_queue_ticket_minutes_map(array $ticket_ids, int $user_id = 0): array
{
    $ticket_ids = array_values(array_unique(array_filter(array_map('intval', $ticket_ids))));
    if (empty($ticket_ids) || !function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
    $params = $ticket_ids;
    $user_clause = '';
    if ($user_id > 0) {
        $user_clause = ' AND tte.user_id = ?';
        $params[] = $user_id;
    }

    $duration_sql = function_exists('time_activity_duration_sql')
        ? time_activity_duration_sql()
        : 'tte.duration_minutes';
    $tenant_filter = function_exists('time_activity_tenant_filter')
        ? time_activity_tenant_filter('tickets', 't', $params)
        : '';

    $rows = db_fetch_all("
        SELECT tte.ticket_id, SUM({$duration_sql}) AS worked_minutes
        FROM ticket_time_entries tte
        JOIN tickets t ON t.id = tte.ticket_id
        WHERE tte.ticket_id IN ({$placeholders})
          {$user_clause}
          {$tenant_filter}
        GROUP BY tte.ticket_id
    ", $params);

    $map = [];
    foreach ($rows as $row) {
        $map[(int) ($row['ticket_id'] ?? 0)] = max(0, (int) ($row['worked_minutes'] ?? 0));
    }

    return $map;
}

function work_queue_attach_worked_minutes(array $items, int $user_id = 0): array
{
    $ticket_ids = [];
    foreach ($items as $item) {
        $ticket_ids[] = (int) ($item['id'] ?? 0);
    }

    $minutes = work_queue_ticket_minutes_map($ticket_ids, $user_id);
    foreach ($items as &$item) {
        $ticket_id = (int) ($item['id'] ?? 0);
        $item['worked_minutes'] = (int) ($minutes[$ticket_id] ?? 0);
    }
    unset($item);

    return $items;
}

function work_queue_count(string $queue_key, array $user): int
{
    if (!function_exists('get_tickets_count')) {
        return 0;
    }

    $filters = work_queue_filters($queue_key, $user, 1);
    unset($filters['limit'], $filters['offset']);
    return get_tickets_count($filters);
}

function work_queue_summary(array $user, int $limit = 8): array
{
    $summary = [];
    foreach (work_queue_definitions() as $key => $definition) {
        if (($definition['scope'] ?? '') === 'team' && ($user['role'] ?? '') === 'user') {
            continue;
        }
        $summary[$key] = [
            'definition' => $definition,
            'count' => work_queue_count($key, $user),
            'items' => work_queue_items($key, $user, $limit),
        ];
    }
    return $summary;
}
