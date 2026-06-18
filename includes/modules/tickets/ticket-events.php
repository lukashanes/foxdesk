<?php
/**
 * Ticket event names.
 *
 * This is the first step away from direct calls scattered through page files.
 * Existing code can keep old calls while new code dispatches stable events.
 */

function ticket_event_names(): array
{
    return [
        'ticket.created',
        'ticket.customer_replied',
        'ticket.agent_replied',
        'ticket.internal_note',
        'ticket.assigned',
        'ticket.waiting_for_customer',
        'ticket.waiting_for_agent',
        'ticket.completed',
        'ticket.due_soon',
        'ticket.overdue',
        'ticket.mentioned',
        'ticket.status_changed',
        'ticket.priority_changed',
        'ticket.updated',
        'ticket.created.confirmation',
    ];
}

function ticket_event_normalize(string $event): string
{
    $event = strtolower(trim($event));
    $legacy = [
        'new_ticket' => 'ticket.created',
        'new_comment' => 'ticket.agent_replied',
        'status_changed' => 'ticket.status_changed',
        'assigned_to_you' => 'ticket.assigned',
        'priority_changed' => 'ticket.priority_changed',
        'ticket_updated' => 'ticket.updated',
        'due_date_reminder' => 'ticket.due_soon',
    ];

    return $legacy[$event] ?? $event;
}

function ticket_event_legacy_type(string $event): string
{
    $event = ticket_event_normalize($event);
    $stable = [
        'ticket.created' => 'new_ticket',
        'ticket.customer_replied' => 'new_comment',
        'ticket.agent_replied' => 'new_comment',
        'ticket.assigned' => 'assigned_to_you',
        'ticket.status_changed' => 'status_changed',
        'ticket.priority_changed' => 'priority_changed',
        'ticket.updated' => 'ticket_updated',
        'ticket.due_soon' => 'due_date_reminder',
    ];

    return $stable[$event] ?? '';
}

function ticket_event_comment_name(array $actor = [], bool $is_internal = false): string
{
    if ($is_internal) {
        return 'ticket.internal_note';
    }

    $role = strtolower((string) ($actor['role'] ?? ''));
    return in_array($role, ['admin', 'agent'], true)
        ? 'ticket.agent_replied'
        : 'ticket.customer_replied';
}

function ticket_event_dispatch_in_app(string $event, int $ticket_id, int $actor_id, array $extra = []): bool
{
    $legacy_type = ticket_event_legacy_type($event);
    if ($legacy_type === '' || !function_exists('dispatch_ticket_notifications')) {
        return false;
    }

    $extra['event_name'] = ticket_event_normalize($event);
    dispatch_ticket_notifications($legacy_type, $ticket_id, $actor_id, $extra);
    return true;
}

function ticket_event_is_known(string $event): bool
{
    return in_array(ticket_event_normalize($event), ticket_event_names(), true);
}
