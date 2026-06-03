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
        if (preg_match('/\b(done|complete|completed|hotovo|dokonceno)\b/u', $text)) {
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
