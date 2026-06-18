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

        case 'ticket.internal_note':
        case 'ticket.created.confirmation':
            return false;

        case 'ticket.status_changed':
            return should_send_status_change_email($ticket, $actor, $context);

        case 'ticket.updated':
            $field = strtolower(trim((string) ($context['field'] ?? '')));
            return in_array($field, ['assignee_id', 'assignee', 'customer_reply_required'], true);
    }

    return false;
}

function ticket_email_action_plan(array $events, array $context = []): array
{
    $normalized = [];
    foreach ($events as $event) {
        $event = function_exists('ticket_event_normalize')
            ? ticket_event_normalize((string) $event)
            : strtolower(trim((string) $event));
        if ($event !== '') {
            $normalized[] = $event;
        }
    }

    $has_public_reply = in_array('ticket.customer_replied', $normalized, true)
        || in_array('ticket.agent_replied', $normalized, true);
    $email_events = [];
    $suppressed = [];

    foreach ($normalized as $event) {
        if (in_array($event, $email_events, true)) {
            $suppressed[$event] = 'duplicate_event';
            continue;
        }

        if ($event === 'ticket.internal_note') {
            $suppressed[$event] = 'internal_note_no_email';
            continue;
        }

        if ($event === 'ticket.created.confirmation') {
            if (!empty($context['requester_is_staff'])) {
                $suppressed[$event] = 'internal_requester_confirmation';
                continue;
            }
            $email_events[] = $event;
            continue;
        }

        if ($event === 'ticket.assigned' && !empty($context['assignment_is_self'])) {
            $suppressed[$event] = 'self_assignment';
            continue;
        }

        if ($event === 'ticket.status_changed' && $has_public_reply) {
            $suppressed[$event] = 'status_change_covered_by_reply';
            continue;
        }

        if ($event === 'ticket.created' && !empty($context['created_by_staff']) && !empty($context['actor_is_only_staff_recipient'])) {
            $suppressed[$event] = 'staff_creator_has_no_staff_recipient';
            continue;
        }

        $ticket = is_array($context['ticket'] ?? null) ? $context['ticket'] : [];
        $actor = is_array($context['actor'] ?? null) ? $context['actor'] : [];
        if (should_send_ticket_email($event, $ticket, $actor, $context)) {
            $email_events[] = $event;
            continue;
        }

        $suppressed[$event] = ticket_email_suppression_reason($event, $context);
    }

    return [
        'email_events' => $email_events,
        'email_count' => count($email_events),
        'suppressed' => $suppressed,
    ];
}

function ticket_notification_user_id(array $user): int
{
    return (int) ($user['id'] ?? 0);
}

function ticket_notification_is_staff_user(array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['admin', 'agent'], true);
}

function should_send_new_ticket_admin_email(array $ticket, array $admin, array $requester = [], array $context = []): bool
{
    if (empty($admin['email'])) {
        return false;
    }

    $admin_id = ticket_notification_user_id($admin);
    $requester_id = ticket_notification_user_id($requester);
    $ticket_user_id = (int) ($ticket['user_id'] ?? 0);

    if ($admin_id > 0 && ($admin_id === $requester_id || $admin_id === $ticket_user_id)) {
        return false;
    }

    if (!empty($requester['email']) && strcasecmp((string) $admin['email'], (string) $requester['email']) === 0) {
        return false;
    }

    $assignee_id = (int) ($ticket['assignee_id'] ?? 0);
    if ($assignee_id > 0 && $admin_id > 0 && $admin_id === $assignee_id) {
        return false;
    }

    return should_send_ticket_email('ticket.created', $ticket, $requester, $context);
}

function should_send_ticket_confirmation_email(array $ticket, array $requester, array $actor = [], array $context = []): bool
{
    if (empty($requester['email'])) {
        return false;
    }

    if (ticket_notification_is_staff_user($requester)) {
        return false;
    }

    return should_send_ticket_email('ticket.created', $ticket, $actor, $context);
}

function should_send_ticket_assignment_email(array $ticket, array $assigned_agent, array $assigner, array $context = []): bool
{
    if (empty($assigned_agent['email'])) {
        return false;
    }

    $assigned_id = ticket_notification_user_id($assigned_agent);
    $assigner_id = ticket_notification_user_id($assigner);
    if ($assigned_id <= 0) {
        return false;
    }

    if ($assigner_id > 0 && $assigner_id === $assigned_id) {
        return false;
    }

    return should_send_ticket_email('ticket.assigned', $ticket, $assigner, $context);
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
