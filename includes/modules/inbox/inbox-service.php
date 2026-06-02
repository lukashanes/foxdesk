<?php
/**
 * Inbox triage service.
 *
 * Inbox is not the whole ticket registry. It is the place for items that need a
 * decision: assign, start work, wait, merge, or close.
 */

function inbox_queue_definitions(): array
{
    return [
        'triage' => [
            'label' => 'Triage',
            'description' => 'New or unassigned tickets that need a decision.',
        ],
        'customer_replies' => [
            'label' => 'Customer replies',
            'description' => 'Tickets where the latest public reply came from a client user.',
        ],
        'email_imports' => [
            'label' => 'Email imports',
            'description' => 'Tickets created from inbound email that still need triage.',
        ],
    ];
}

function inbox_queue_filters(string $queue_key, array $user, int $limit = 12): array
{
    $queue_key = array_key_exists($queue_key, inbox_queue_definitions()) ? $queue_key : 'triage';
    $filters = [
        'is_archived' => 0,
        'status_group_not' => ['done', 'archived'],
        'sort' => 'last_updated',
        'limit' => max(1, min(50, $limit)),
    ];

    switch ($queue_key) {
        case 'triage':
            $filters['assignee_unassigned'] = true;
            break;

        case 'customer_replies':
            $filters['last_public_comment_role'] = 'user';
            break;

        case 'email_imports':
            $filters['source'] = 'email';
            $filters['assignee_unassigned'] = true;
            break;
    }

    if (function_exists('build_ticket_visibility_filters_for_user')) {
        $filters = build_ticket_visibility_filters_for_user($user, $filters);
    }

    return $filters;
}

function inbox_queue_items(string $queue_key, array $user, int $limit = 12): array
{
    if (!function_exists('get_tickets')) {
        return [];
    }
    return get_tickets(inbox_queue_filters($queue_key, $user, $limit));
}

function inbox_queue_count(string $queue_key, array $user): int
{
    if (!function_exists('get_tickets_count')) {
        return 0;
    }

    $filters = inbox_queue_filters($queue_key, $user, 1);
    unset($filters['limit'], $filters['offset']);
    return get_tickets_count($filters);
}

function inbox_summary(array $user, int $limit = 12): array
{
    if (($user['role'] ?? '') === 'user') {
        return [];
    }

    $summary = [];
    foreach (inbox_queue_definitions() as $key => $definition) {
        $summary[$key] = [
            'definition' => $definition,
            'count' => inbox_queue_count($key, $user),
            'items' => inbox_queue_items($key, $user, $limit),
        ];
    }

    return $summary;
}
