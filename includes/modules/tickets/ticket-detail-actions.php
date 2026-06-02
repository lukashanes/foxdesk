<?php
/**
 * Ticket detail primary action model.
 *
 * Keeps page rendering simple: pages decide where to render actions, this module
 * decides which stable actions belong on the ticket detail surface.
 */

function ticket_detail_first_done_status_id(array $statuses): ?int
{
    foreach ($statuses as $status) {
        if (ticket_status_group_from_status($status) === 'done') {
            return (int) ($status['id'] ?? 0);
        }
    }

    return null;
}

function ticket_detail_is_done(array $ticket): bool
{
    if (!empty($ticket['is_closed'])) {
        return true;
    }

    return ticket_status_group_for_status_id(isset($ticket['status_id']) ? (int) $ticket['status_id'] : null) === 'done';
}

function ticket_detail_primary_actions(array $ticket, array $user, array $statuses, array $options = []): array
{
    $is_agent_user = function_exists('is_agent') ? is_agent() : (($user['role'] ?? '') !== 'user');
    $can_edit = function_exists('can_edit_ticket') ? can_edit_ticket($ticket, $user) : $is_agent_user;
    $time_available = !empty($options['time_tracking_available']);
    $timer_state = (string) ($options['timer_state'] ?? 'stopped');
    $done_status_id = ticket_detail_first_done_status_id($statuses);
    $is_done = ticket_detail_is_done($ticket);

    $actions = [
        [
            'key' => 'reply',
            'label' => 'Reply',
            'icon' => 'comment',
            'style' => 'primary',
            'type' => 'anchor',
            'href' => '#comment-form',
            'visible' => true,
        ],
    ];

    if ($is_agent_user && $time_available) {
        $actions[] = [
            'key' => 'start_work',
            'label' => $timer_state === 'running' ? 'Pause work' : ($timer_state === 'paused' ? 'Resume work' : 'Start work'),
            'icon' => $timer_state === 'running' ? 'pause' : 'play',
            'style' => $timer_state === 'running' ? 'warning' : 'secondary',
            'type' => 'button',
            'id' => 'toolbar-timer-btn',
            'visible' => true,
        ];
    }

    if ($can_edit) {
        $actions[] = [
            'key' => 'assign',
            'label' => 'Assign',
            'icon' => 'user-plus',
            'style' => 'secondary',
            'type' => 'anchor',
            'href' => '#ticket-side-panel',
            'visible' => true,
        ];
    }

    if ($is_agent_user && !$is_done && $done_status_id) {
        $actions[] = [
            'key' => 'complete',
            'label' => 'Complete',
            'icon' => 'check-circle',
            'style' => 'success',
            'type' => 'submit',
            'name' => 'change_status',
            'status_id' => $done_status_id,
            'visible' => true,
        ];
    }

    if ($can_edit) {
        $actions[] = [
            'key' => 'edit',
            'label' => 'Edit',
            'icon' => 'edit',
            'style' => 'ghost',
            'type' => 'button',
            'onclick' => 'openEditTicketModal()',
            'visible' => true,
        ];
    }

    return array_values(array_filter($actions, static function (array $action): bool {
        return !empty($action['visible']);
    }));
}
