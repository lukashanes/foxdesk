<?php
/**
 * App home feed for web and native clients.
 *
 * The feed is intentionally small: it gives clients enough data to render the
 * first screen without exposing page-specific HTML assumptions.
 */

function app_feed_ticket_card(array $ticket): array
{
    $status_group = function_exists('ticket_status_group_from_status')
        ? ticket_status_group_from_status([
            'name' => $ticket['status_name'] ?? '',
            'is_closed' => $ticket['is_closed'] ?? 0,
            'status_group' => $ticket['status_group'] ?? null,
        ])
        : 'active';

    if (!empty($ticket['is_archived'])) {
        $status_group = 'archived';
    }

    $tags = [];
    if (function_exists('normalize_ticket_tags')) {
        $tags = normalize_ticket_tags($ticket['tags'] ?? '', true);
    } elseif (!empty($ticket['tags'])) {
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $ticket['tags']))));
    }

    $ticket_id = (int) ($ticket['id'] ?? 0);

    return [
        'id' => $ticket_id,
        'hash' => $ticket['hash'] ?? null,
        'code' => function_exists('get_ticket_code') ? get_ticket_code($ticket_id) : ('#' . $ticket_id),
        'title' => (string) ($ticket['title'] ?? ''),
        'description_preview' => mb_substr(trim(strip_tags((string) ($ticket['description'] ?? ''))), 0, 180),
        'status' => [
            'name' => (string) ($ticket['status_name'] ?? ''),
            'color' => $ticket['status_color'] ?? null,
            'group' => $status_group,
        ],
        'priority' => [
            'name' => (string) ($ticket['priority_name'] ?? ''),
            'color' => $ticket['priority_color'] ?? null,
        ],
        'client' => [
            'id' => isset($ticket['organization_id']) ? (int) $ticket['organization_id'] : null,
            'name' => (string) ($ticket['organization_name'] ?? ''),
        ],
        'requester' => trim((string) (($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? ''))),
        'assignee' => trim((string) (($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? ''))),
        'source' => (string) ($ticket['source'] ?? 'web'),
        'tags' => $tags,
        'due_date' => $ticket['due_date'] ?? null,
        'created_at' => $ticket['created_at'] ?? null,
        'updated_at' => $ticket['updated_at'] ?? null,
        'url' => function_exists('ticket_url') ? ticket_url($ticket) : url('ticket', ['id' => $ticket_id]),
    ];
}

function app_feed_queue_sections(array $summary): array
{
    $sections = [];
    foreach ($summary as $key => $queue) {
        $items = [];
        foreach (($queue['items'] ?? []) as $ticket) {
            $items[] = app_feed_ticket_card($ticket);
        }

        $sections[$key] = [
            'definition' => $queue['definition'] ?? [],
            'count' => (int) ($queue['count'] ?? count($items)),
            'items' => $items,
        ];
    }

    return $sections;
}

function app_feed_active_timers(array $user): array
{
    if (($user['role'] ?? '') === 'user' || !function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return [];
    }
    if (!function_exists('get_user_all_active_timers')) {
        return [];
    }

    $timers = [];
    foreach (get_user_all_active_timers((int) ($user['id'] ?? 0)) as $timer) {
        $elapsed_seconds = function_exists('calculate_timer_elapsed') ? calculate_timer_elapsed($timer) : 0;
        $elapsed_minutes = (int) floor(max(0, $elapsed_seconds) / 60);
        $timers[] = [
            'entry_id' => (int) ($timer['id'] ?? 0),
            'ticket_id' => (int) ($timer['ticket_id'] ?? 0),
            'ticket_hash' => $timer['ticket_hash'] ?? null,
            'ticket_title' => (string) ($timer['ticket_title'] ?? ''),
            'started_at' => $timer['started_at'] ?? null,
            'is_paused' => function_exists('is_timer_paused') ? is_timer_paused($timer) : !empty($timer['paused_at']),
            'elapsed_minutes' => $elapsed_minutes,
            'elapsed_label' => function_exists('format_duration_minutes') ? format_duration_minutes($elapsed_minutes) : ($elapsed_minutes . ' min'),
            'url' => !empty($timer['ticket_hash'])
                ? url('ticket', ['t' => $timer['ticket_hash']])
                : url('ticket', ['id' => (int) ($timer['ticket_id'] ?? 0)]),
        ];
    }

    return $timers;
}

function app_feed_notifications(array $user): array
{
    return [
        'unread_count' => function_exists('get_unread_notification_count')
            ? get_unread_notification_count((int) ($user['id'] ?? 0))
            : 0,
    ];
}

function app_feed_payload(array $user, int $limit = 5): array
{
    $limit = max(1, min(20, $limit));

    return [
        'schema_version' => 1,
        'generated_at' => date('c'),
        'limit' => $limit,
        'work' => function_exists('work_queue_summary')
            ? app_feed_queue_sections(work_queue_summary($user, $limit))
            : [],
        'inbox' => function_exists('inbox_summary')
            ? app_feed_queue_sections(inbox_summary($user, $limit))
            : [],
        'timers' => app_feed_active_timers($user),
        'notifications' => app_feed_notifications($user),
    ];
}
