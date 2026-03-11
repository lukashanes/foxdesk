<?php
/**
 * API Handler: Notification Operations
 *
 * Handles in-app notification endpoints:
 *   - get-notifications        : Fetch grouped notifications + unread count
 *   - get-notification-count   : Lightweight badge count (for polling)
 *   - mark-notification-read   : Mark single notification as read
 *   - mark-all-notifications-read : Mark all as read
 */

/**
 * GET: Fetch notifications for the current user.
 *
 * Returns grouped notifications (today / yesterday / earlier) plus unread count.
 * Also updates last_notifications_seen_at so the badge resets.
 */
function api_get_notifications()
{
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $result = get_user_notifications((int) $user['id'], $limit, $offset);

    // Update "seen" timestamp so badge count resets
    update_notifications_seen((int) $user['id']);

    // Group by date, then sub-group by ticket
    $grouped = group_notifications($result['notifications']);
    foreach (['today', 'yesterday', 'earlier'] as $grp) {
        $grouped[$grp] = group_by_ticket($grouped[$grp]);
    }

    // Format a single notification for JSON output
    $format_notif = function (array $n) {
        $actor_name = trim(($n['actor_first_name'] ?? '') . ' ' . ($n['actor_last_name'] ?? ''));
        return [
            'id'               => (int) $n['id'],
            'type'             => $n['type'],
            'ticket_id'        => $n['ticket_id'] ? (int) $n['ticket_id'] : null,
            'is_read'          => (bool) $n['is_read'],
            'created_at'       => $n['created_at'],
            'time_ago'         => notification_time_ago($n['created_at']),
            'formatted_text'   => format_notification_text($n),
            'text'             => format_notification_text($n),
            'actor_name'       => $actor_name,
            'actor_first_name' => $n['actor_first_name'] ?? '',
            'actor_last_name'  => $n['actor_last_name'] ?? '',
            'actor_avatar'     => $n['actor_avatar'] ?? null,
            'actor_email'      => $n['actor_email'] ?? null,
            'avatar_hue'       => abs(crc32($actor_name)) % 360,
            'data'             => $n['data'] ?? [],
        ];
    };

    // Format ticket groups for JSON output
    $format_ticket_groups = function (array $ticket_groups) use ($format_notif) {
        $out = [];
        foreach ($ticket_groups as $tg) {
            $out[] = [
                'ticket_id'  => $tg['ticket_id'],
                'count'      => $tg['count'],
                'has_unread' => $tg['has_unread'],
                'primary'    => $format_notif($tg['primary']),
                'others'     => array_map($format_notif, $tg['others']),
            ];
        }
        return $out;
    };

    api_success([
        'unread_count' => $result['unread_count'],
        'groups'       => [
            'today'     => $format_ticket_groups($grouped['today']),
            'yesterday' => $format_ticket_groups($grouped['yesterday']),
            'earlier'   => $format_ticket_groups($grouped['earlier']),
        ],
    ]);
}

/**
 * GET: Lightweight badge count (for 60-second polling).
 */
function api_get_notification_count()
{
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $count = get_unread_notification_count((int) $user['id']);

    api_success(['unread_count' => $count]);
}

/**
 * POST: Mark a single notification as read.
 */
function api_mark_notification_read()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $notification_id = (int) ($_POST['notification_id'] ?? 0);
    if ($notification_id <= 0) {
        api_error('Missing notification_id', 422);
    }

    mark_notification_read($notification_id, (int) $user['id']);

    api_success(['ok' => true]);
}

/**
 * POST: Mark all notifications as read.
 */
function api_mark_all_notifications_read()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    mark_all_notifications_read((int) $user['id']);

    api_success(['ok' => true]);
}
