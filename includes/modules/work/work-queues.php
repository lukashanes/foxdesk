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
            'label' => 'Unassigned',
            'description' => 'New work that needs triage.',
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
    return get_tickets(work_queue_filters($queue_key, $user, $limit));
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
