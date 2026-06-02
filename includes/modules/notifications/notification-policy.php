<?php
/**
 * Notification policy.
 *
 * Email is not an audit log. It should only be sent when the recipient likely
 * needs to act or when a customer-facing milestone happens.
 */

function should_send_ticket_email(string $event, array $ticket = [], array $actor = [], array $context = []): bool
{
    $event = function_exists('ticket_event_normalize') ? ticket_event_normalize($event) : $event;

    switch ($event) {
        case 'ticket.created':
        case 'ticket.customer_replied':
        case 'ticket.agent_replied':
        case 'ticket.assigned':
        case 'ticket.waiting_for_customer':
        case 'ticket.waiting_for_agent':
        case 'ticket.completed':
        case 'ticket.due_soon':
        case 'ticket.overdue':
        case 'ticket.mentioned':
            return true;

        case 'ticket.status_changed':
            return should_send_status_change_email($ticket, $actor, $context);

        case 'ticket.updated':
            $field = strtolower(trim((string) ($context['field'] ?? '')));
            return in_array($field, ['assignee_id', 'assignee', 'customer_reply_required'], true);
    }

    return false;
}

function should_send_status_change_email(array $ticket, array $actor, array $context): bool
{
    $comment = trim(strip_tags((string) ($context['comment_text'] ?? '')));
    $time_spent = (int) ($context['time_spent'] ?? 0);
    if ($comment !== '' || $time_spent > 0) {
        return true;
    }

    $old_status = is_array($context['old_status'] ?? null) ? $context['old_status'] : [];
    $new_status = is_array($context['new_status'] ?? null) ? $context['new_status'] : [];
    $old_group = function_exists('ticket_status_group_from_status') ? ticket_status_group_from_status($old_status) : '';
    $new_group = function_exists('ticket_status_group_from_status') ? ticket_status_group_from_status($new_status) : '';

    if ($new_group === '' || $new_group === $old_group) {
        return false;
    }

    return in_array($new_group, ['waiting', 'done'], true);
}

function ticket_email_suppression_reason(string $event, array $context = []): string
{
    $event = function_exists('ticket_event_normalize') ? ticket_event_normalize($event) : $event;
    if ($event === 'ticket.status_changed') {
        return 'status_change_not_actionable';
    }
    if ($event === 'ticket.updated') {
        return 'field_update_not_actionable';
    }
    return 'event_not_email_actionable';
}
