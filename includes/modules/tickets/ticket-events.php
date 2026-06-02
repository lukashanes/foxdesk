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
        'ticket.assigned',
        'ticket.waiting_for_customer',
        'ticket.waiting_for_agent',
        'ticket.completed',
        'ticket.due_soon',
        'ticket.overdue',
        'ticket.mentioned',
        'ticket.status_changed',
        'ticket.updated',
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
        'priority_changed' => 'ticket.updated',
        'ticket_updated' => 'ticket.updated',
        'due_date_reminder' => 'ticket.due_soon',
    ];

    return $legacy[$event] ?? $event;
}

function ticket_event_is_known(string $event): bool
{
    return in_array(ticket_event_normalize($event), ticket_event_names(), true);
}
