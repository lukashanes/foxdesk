<?php
/** Shared compact workspace browser actions. Self-hosted authorization remains authoritative. */
function api_app_time_comment_id(array $input, int $ticket_id): ?int
{
    $comment_id = (int) ($input['comment_id'] ?? 0);
    if ($comment_id <= 0) return null;
    $comment = db_fetch_one('SELECT id FROM comments WHERE id = ? AND ticket_id = ? LIMIT 1', [$comment_id, $ticket_id]);
    if (!$comment) api_error('Comment does not belong to this ticket.', 422);
    return $comment_id;
}

function api_app_update_time_entry()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    // Do not silently expand the public API token surface.
    if (!empty($GLOBALS['is_api_token_auth'])) api_error('Browser session required.', 403);
    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Forbidden', 403);
    }

    $input = get_json_input();
    $entry_id = (int) ($input['time_entry_id'] ?? $input['entry_id'] ?? $input['id'] ?? 0);
    if ($entry_id <= 0) {
        api_error('time_entry_id is required.', 422);
    }
    $entry = db_fetch_one('SELECT * FROM ticket_time_entries WHERE id = ? LIMIT 1', [$entry_id]);
    if (!$entry) {
        api_error('Time entry not found.', 404);
    }
    $ticket = get_ticket((int) $entry['ticket_id']);
    if (!$ticket || !can_see_ticket($ticket, $user) || !can_manage_time_entry($entry, $user)) {
        api_error('Forbidden', 403);
    }

    $duration = array_key_exists('duration_minutes', $input)
        ? (int) $input['duration_minutes']
        : (int) ($entry['duration_minutes'] ?? 0);
    $has_started_at = array_key_exists('started_at', $input);
    $has_ended_at = array_key_exists('ended_at', $input);
    $started_at_input = $has_started_at ? $input['started_at'] : ($entry['started_at'] ?? null);
    $ended_at_input = $has_ended_at
        ? $input['ended_at']
        : ($has_started_at ? null : (array_key_exists('duration_minutes', $input) ? null : ($entry['ended_at'] ?? null)));
    $time_input = api_app_resolve_log_time_input([
        'duration_minutes' => $duration,
        'started_at' => $started_at_input,
        'ended_at' => $ended_at_input,
        'worked_on' => $input['worked_on'] ?? ($entry['worked_on'] ?? ''),
        'time_precision' => $input['time_precision'] ?? ticket_time_entry_precision($entry),
    ]);
    $updates = $time_input;
    if (array_key_exists('comment_id', $input)) {
        $updates['comment_id'] = api_app_time_comment_id($input, (int) $entry['ticket_id']);
    }
    if (array_key_exists('summary', $input)) {
        $updates['summary'] = trim((string) $input['summary']);
    }
    if (array_key_exists('is_billable', $input)) {
        $updates['is_billable'] = filter_var($input['is_billable'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    require_once BASE_PATH . '/includes/ticket-time-functions.php';
    if (!update_time_entry($entry_id, $updates)) {
        api_error('Failed to update time entry.', 500);
    }
    db_update('tickets', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $entry['ticket_id']]);
    log_activity((int) $entry['ticket_id'], (int) $user['id'], 'time_updated', 'Time entry updated through API');

    api_app_contract_success([
        'ticket_id' => (int) $entry['ticket_id'],
        'time_entry_id' => $entry_id,
        'started_at' => $updates['started_at'],
        'ended_at' => $updates['ended_at'],
        'duration_minutes' => (int) $updates['duration_minutes'],
        'summary' => $updates['summary'] ?? ($entry['summary'] ?? null),
        'is_billable' => array_key_exists('is_billable', $updates)
            ? (bool) $updates['is_billable']
            : !empty($entry['is_billable']),
    ], ['resource' => 'update_time_entry']);
}

function api_app_notification_review()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error('Method not allowed', 405);
    // This browser-only interaction must not extend API-token permissions.
    require_csrf_token(true);
    $user = current_user();
    if (!$user) api_error('Unauthorized', 401);
    $input = get_json_input();
    $id = (int) ($input['notification_id'] ?? 0);
    if ($id <= 0 || !isset($input['is_resolved']) || !is_bool($input['is_resolved'])) {
        api_error('Invalid notification review.', 422);
    }
    if (!notifications_table_exists() || !column_exists('notifications', 'is_resolved')) {
        api_error('Notification review is not available.', 409);
    }
    $notification = db_fetch_one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, (int) $user['id']]);
    if (!$notification || !notification_is_visible_to_user($notification, $user)) api_error('Not found', 404);
    db_update('notifications', ['is_resolved' => (int) $input['is_resolved']], 'id = ? AND user_id = ?', [$id, (int) $user['id']]);
    api_app_contract_success(['notification_id' => $id, 'is_resolved' => $input['is_resolved']], ['resource' => 'notification_review']);
}

