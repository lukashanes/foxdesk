<?php
/**
 * Ticket detail primary action model.
 *
 * Keeps page rendering simple: pages decide where to render actions, this module
 * decides which stable actions belong on the ticket detail surface.
 */

function ticket_detail_status_search_text(array $status): string
{
    $parts = [];
    foreach (['name', 'slug', 'key', 'code'] as $field) {
        if (isset($status[$field]) && trim((string) $status[$field]) !== '') {
            $parts[] = (string) $status[$field];
        }
    }

    $text = trim(implode(' ', $parts));
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

    return strtr($text, [
        'á' => 'a',
        'č' => 'c',
        'ď' => 'd',
        'é' => 'e',
        'ě' => 'e',
        'í' => 'i',
        'ň' => 'n',
        'ó' => 'o',
        'ř' => 'r',
        'š' => 's',
        'ť' => 't',
        'ú' => 'u',
        'ů' => 'u',
        'ý' => 'y',
        'ž' => 'z',
    ]);
}

function ticket_detail_status_is_canceled(array $status): bool
{
    $text = ticket_detail_status_search_text($status);
    if ($text === '') {
        return false;
    }

    return (bool) preg_match('/\b(cancel|canceled|cancelled|storno|zrusen|reject|rejected|decline|declined|void|deleted|trash)\b/u', $text);
}

function ticket_detail_done_status_score(array $status): int
{
    if (empty($status['id']) || ticket_detail_status_is_canceled($status)) {
        return -1;
    }

    $text = ticket_detail_status_search_text($status);

    if ($text !== '') {
        if ($text === 'done') {
            return 120;
        }
        if (preg_match('/\bdone\b/u', $text)) {
            return 110;
        }
        if (preg_match('/\b(complete|completed|hotovo|dokonceno)\b/u', $text)) {
            return 100;
        }
        if (preg_match('/\b(resolve|resolved|vyreseno)\b/u', $text)) {
            return 90;
        }
        if (preg_match('/\b(close|closed|uzavreno)\b/u', $text)) {
            return 80;
        }
        if (preg_match('/\b(finish|finished)\b/u', $text)) {
            return 70;
        }
    }

    return ticket_status_group_from_status($status) === 'done' ? 50 : -1;
}

function ticket_detail_first_done_status_id(array $statuses): ?int
{
    $selected_status_id = null;
    $selected_score = -1;

    foreach ($statuses as $status) {
        $score = ticket_detail_done_status_score($status);
        if ($score > $selected_score) {
            $selected_status_id = (int) ($status['id'] ?? 0);
            $selected_score = $score;
        }
    }

    return $selected_score >= 0 ? $selected_status_id : null;
}

function ticket_detail_is_done(array $ticket): bool
{
    if (!empty($ticket['is_closed'])) {
        return true;
    }

    if (!empty($ticket['status_name']) || array_key_exists('status_group', $ticket)) {
        return ticket_status_group_from_status([
            'name' => (string) ($ticket['status_name'] ?? ''),
            'status_group' => (string) ($ticket['status_group'] ?? ''),
            'is_closed' => $ticket['is_closed'] ?? 0,
        ]) === 'done';
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
    $has_active_timer = $timer_state !== 'stopped';

    $actions = [
        [
            'key' => 'reply',
            'label' => 'Reply',
            'icon' => 'comment',
            'style' => 'primary',
            'type' => 'anchor',
            'href' => '#comment-form',
            'title' => 'Write a reply to this ticket.',
            'visible' => true,
        ],
    ];

    if ($is_agent_user && $time_available && (!$is_done || $has_active_timer)) {
        $timer_title = 'Start a timer for this ticket.';
        if ($timer_state === 'running') {
            $timer_title = 'Pause this timer without logging time yet.';
        } elseif ($timer_state === 'paused') {
            $timer_title = 'Resume the paused timer.';
        }

        $actions[] = [
            'key' => 'start_work',
            'label' => $timer_state === 'running' ? 'Pause timer' : ($timer_state === 'paused' ? 'Resume timer' : 'Start timer'),
            'icon' => $timer_state === 'running' ? 'pause' : 'play',
            'style' => $timer_state === 'running' ? 'warning' : 'secondary',
            'type' => 'button',
            'id' => 'toolbar-timer-btn',
            'title' => $timer_title,
            'visible' => true,
        ];
    }

    if ($is_agent_user && $can_edit) {
        $actions[] = [
            'key' => 'assign',
            'label' => 'Assign',
            'icon' => 'user-plus',
            'style' => 'secondary',
            'type' => 'anchor',
            'href' => '#ticket-side-panel',
            'title' => 'Assign this ticket or change ticket properties.',
            'visible' => true,
        ];
    }

    if ($is_agent_user && $done_status_id && (!$is_done || $has_active_timer)) {
        $complete_title = $timer_state === 'stopped'
            ? 'Mark this ticket as done.'
            : 'Mark this ticket as done and stop the active timer.';
        $actions[] = [
            'key' => 'complete',
            'label' => $has_active_timer ? 'Complete & stop timer' : 'Complete',
            'icon' => 'check-circle',
            'style' => 'success',
            'type' => 'submit',
            'name' => 'change_status',
            'status_id' => $done_status_id,
            'title' => $complete_title,
            'stops_timer' => $has_active_timer,
            'visible' => true,
        ];
    }

    if ($is_agent_user && $can_edit && empty($ticket['is_archived'])) {
        if ((int) ($ticket['assignee_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
            $actions[] = ['key' => 'claim', 'label' => 'workflow.claim', 'title' => 'workflow.claim', 'icon' => 'user-plus', 'style' => 'secondary', 'type' => 'button', 'visible' => true];
        }
        if ($is_done && function_exists('ticket_workflow_target') && ticket_workflow_target($statuses, 'active')) {
            $actions[] = ['key' => 'reopen', 'label' => 'workflow.reopen', 'title' => 'workflow.reopen', 'icon' => 'undo', 'style' => 'primary', 'type' => 'button', 'visible' => true];
        }
    }

    if ($can_edit) {
        $actions[] = [
            'key' => 'edit',
            'label' => 'Edit',
            'icon' => 'edit',
            'style' => 'ghost',
            'type' => 'button',
            'title' => 'Edit ticket details.',
            'visible' => true,
        ];
    }

    return array_values(array_filter($actions, static function (array $action): bool {
        return !empty($action['visible']);
    }));
}
