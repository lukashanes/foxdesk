<?php
/**
 * Ticket status groups.
 *
 * Internal groups stay English and stable. UI labels are translated elsewhere.
 * Custom workflow statuses map into these groups so queues, search, reporting,
 * and notifications can share one mental model.
 */

function ticket_status_group_keys(): array
{
    return ['new', 'active', 'waiting', 'done', 'archived'];
}

function ticket_status_group_default_labels(): array
{
    return [
        'new' => 'New',
        'active' => 'Active',
        'waiting' => 'Waiting',
        'done' => 'Done',
        'archived' => 'Archived',
    ];
}

function ticket_status_group_normalize(?string $group): string
{
    $group = strtolower(trim((string) $group));
    return in_array($group, ticket_status_group_keys(), true) ? $group : 'active';
}

function ticket_status_group_from_status(array $status): string
{
    if (isset($status['status_group']) && trim((string) $status['status_group']) !== '') {
        return ticket_status_group_normalize((string) $status['status_group']);
    }

    if (!empty($status['is_closed'])) {
        return 'done';
    }

    $name = strtolower(trim((string) ($status['name'] ?? '')));
    if ($name === '') {
        return 'active';
    }

    if (preg_match('/\b(new|open|todo|to do|received|created)\b/u', $name)) {
        return 'new';
    }
    if (preg_match('/\b(wait|waiting|pending|hold|blocked|client|customer|vendor|third party)\b/u', $name)) {
        return 'waiting';
    }
    if (preg_match('/\b(done|closed|resolved|complete|completed|finished)\b/u', $name)) {
        return 'done';
    }

    return 'active';
}

function ticket_status_group_for_status_id(?int $status_id): string
{
    if (!$status_id || !function_exists('get_status')) {
        return 'active';
    }

    $status = get_status($status_id);
    return $status ? ticket_status_group_from_status($status) : 'active';
}

function ticket_status_group_is_customer_waiting(string $group): bool
{
    return ticket_status_group_normalize($group) === 'waiting';
}

function ticket_status_group_is_done(string $group): bool
{
    return ticket_status_group_normalize($group) === 'done';
}
