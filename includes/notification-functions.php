<?php
/**
 * FoxDesk - In-App Notification Functions
 *
 * Handles creation, retrieval, and management of persistent notifications.
 * Notifications are created directly at each event dispatch point
 * (not derived from activity_log).
 */

// ── Guard ────────────────────────────────────────────────────────────────────

/**
 * Check if notifications table exists (backward compat during rolling updates).
 */
function notifications_table_exists(): bool
{
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $exists = (bool) db_fetch_one("SHOW TABLES LIKE 'notifications'");
    } catch (Throwable $e) {
        $exists = false;
    }
    return $exists;
}

/**
 * Auto-create notifications table if missing (rolling upgrade support).
 */
function ensure_notifications_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (notifications_table_exists()) {
        // Ensure actor_id column exists (may be missing from manually created tables)
        if (!column_exists('notifications', 'actor_id')) {
            try {
                db_query("ALTER TABLE notifications ADD COLUMN actor_id INT NULL AFTER type");
            } catch (Throwable $e) { /* ignore */ }
        }
        if (!column_exists('notifications', 'data')) {
            try {
                db_query("ALTER TABLE notifications ADD COLUMN data JSON NULL AFTER actor_id");
            } catch (Throwable $e) { /* ignore */ }
        }
        if (!column_exists('notifications', 'is_resolved')) {
            try {
                db_query("ALTER TABLE notifications ADD COLUMN is_resolved TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read");
                // One-time: resolve stale action notifications for already-closed tickets
                db_execute("UPDATE notifications n JOIN tickets t ON n.ticket_id = t.id
                    JOIN statuses s ON t.status_id = s.id
                    SET n.is_resolved = 1
                    WHERE s.is_closed = 1 AND n.is_resolved = 0
                    AND n.type IN ('assigned_to_you','due_date_reminder')");
            } catch (Throwable $e) { /* ignore */ }
        }
        // Ensure last_notifications_seen_at on users
        if (!column_exists('users', 'last_notifications_seen_at')) {
            try {
                db_query("ALTER TABLE users ADD COLUMN last_notifications_seen_at DATETIME NULL");
            } catch (Throwable $e) { /* ignore */ }
        }
        return;
    }

    try {
        db_query("CREATE TABLE notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ticket_id INT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            actor_id INT NULL,
            data JSON NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            is_resolved TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_user_read (user_id, is_read),
            INDEX idx_ticket (ticket_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Ensure last_notifications_seen_at on users
        if (!column_exists('users', 'last_notifications_seen_at')) {
            db_query("ALTER TABLE users ADD COLUMN last_notifications_seen_at DATETIME NULL");
        }
    } catch (Throwable $e) {
        // Silently ignore — next page load will retry
    }
}

// ── Create ───────────────────────────────────────────────────────────────────

/**
 * Create a single notification.
 *
 * @param int      $user_id   Recipient
 * @param string   $type      Event type (new_ticket, new_comment, status_changed, assigned_to_you, priority_changed, mentioned, due_date_reminder)
 * @param int|null $ticket_id Related ticket
 * @param int|null $actor_id  Who triggered the event
 * @param array    $data      Extra data (ticket_subject, actor_name, comment_preview, old_status, new_status, priority …)
 * @return int|false Inserted ID or false
 */
/**
 * Create notifications for multiple users.
 * Skips the actor (you don't notify yourself) and respects user preference.
 *
 * @param int[]    $user_ids
 * @param string   $type
 * @param int|null $ticket_id
 * @param int|null $actor_id
 * @param array    $data
 */
function create_notifications_for_users(array $user_ids, string $type, ?int $ticket_id, ?int $actor_id, array $data = []): void
{
    if (!notifications_table_exists()) return;

    $user_ids = array_unique(array_map('intval', $user_ids));
    // Remove actor — you don't notify yourself
    $user_ids = array_filter($user_ids, fn($id) => $id > 0 && $id !== $actor_id);
    if (empty($user_ids)) return;

    // Check which users have in-app notifications enabled
    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $users = db_fetch_all(
        "SELECT * FROM users WHERE id IN ($placeholders) AND is_active = 1
         AND (in_app_notifications_enabled IS NULL OR in_app_notifications_enabled = 1)",
        array_values($user_ids)
    );
    $ticket = $ticket_id && function_exists('get_ticket') ? get_ticket((int) $ticket_id) : null;

    $now = date('Y-m-d H:i:s');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);

    $notified_user_ids = [];

    foreach ($users as $u) {
        if ($ticket && !notification_is_visible_to_user([
            'ticket_id' => $ticket_id,
            'type' => $type,
            'data' => $data,
            'user_id' => (int) ($u['id'] ?? 0),
        ], $u)) {
            continue;
        }

        // Check per-type notification preference
        if (function_exists('user_wants_notification') && !user_wants_notification((int) $u['id'], $type)) {
            continue;
        }

        try {
            db_insert('notifications', [
                'user_id'    => (int) $u['id'],
                'ticket_id'  => $ticket_id,
                'type'       => $type,
                'actor_id'   => $actor_id,
                'data'       => $json,
                'is_read'    => 0,
                'created_at' => $now,
            ]);
            $notified_user_ids[] = (int) $u['id'];
        } catch (Throwable $e) {
            // Silently skip — don't break the main flow
        }
    }

    // Dispatch browser push notifications (N9)
    if (!empty($notified_user_ids)) {
        try {
            if (file_exists(BASE_PATH . '/includes/web-push.php')) {
                require_once BASE_PATH . '/includes/web-push.php';
                if (function_exists('dispatch_push_notifications')) {
                    dispatch_push_notifications($notified_user_ids);
                }
            }
        } catch (Throwable $e) {
            // Push failures should never break in-app notifications
        }
    }
}

// ── Per-type Notification Preferences ────────────────────────────────────────

/**
 * Ensure notification_preferences column exists on users table.
 */
function ensure_notification_preferences_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $cols = db_fetch_all("SHOW COLUMNS FROM users LIKE 'notification_preferences'");
        if (empty($cols)) {
            db_query("ALTER TABLE users ADD COLUMN notification_preferences TEXT NULL");
        }
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Get notification type labels for UI display.
 */
function get_notification_type_labels(): array
{
    return [
        'new_ticket'        => t('New ticket created'),
        'new_comment'       => t('New comment'),
        'status_changed'    => t('Status changed'),
        'assigned_to_you'   => t('Assigned to you'),
        'priority_changed'  => t('Priority changed'),
        'mentioned'         => t('Mentioned'),
        'due_date_reminder' => t('Due date reminder'),
    ];
}

/**
 * Get user's notification preferences (per-type).
 * Returns associative array: ['new_ticket' => true, 'new_comment' => false, ...]
 * All types default to enabled.
 */
function get_notification_preferences(int $user_id): array
{
    ensure_notification_preferences_column();
    $defaults = array_fill_keys(array_keys(get_notification_type_labels()), true);

    try {
        $row = db_fetch_one("SELECT notification_preferences FROM users WHERE id = ?", [$user_id]);
        if (!empty($row['notification_preferences'])) {
            $prefs = json_decode($row['notification_preferences'], true);
            if (is_array($prefs)) {
                return array_merge($defaults, $prefs);
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    return $defaults;
}

/**
 * Save user's notification preferences.
 */
function save_notification_preferences(int $user_id, array $prefs): bool
{
    ensure_notification_preferences_column();
    try {
        db_update('users', [
            'notification_preferences' => json_encode($prefs)
        ], 'id = ?', [$user_id]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if a user wants notifications of a given type.
 */
function user_wants_notification(int $user_id, string $type): bool
{
    $prefs = get_notification_preferences($user_id);
    return $prefs[$type] ?? true;
}

/**
 * Resolve the user record used for notification visibility checks.
 */
function get_notification_visibility_user(int $user_id): ?array
{
    static $cache = [];
    if (array_key_exists($user_id, $cache)) {
        return $cache[$user_id];
    }

    $current = function_exists('current_user') ? current_user() : null;
    if ($current && (int) ($current['id'] ?? 0) === $user_id) {
        $cache[$user_id] = $current;
        return $cache[$user_id];
    }

    $cache[$user_id] = function_exists('get_user') ? get_user($user_id) : null;
    return $cache[$user_id];
}

/**
 * Decode a notification data payload into an array.
 */
function get_notification_data_payload(array $notification): array
{
    $data = $notification['data'] ?? [];
    if (is_array($data)) {
        return $data;
    }

    if (is_string($data) && $data !== '') {
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

/**
 * Resolve a ticket record used in notification visibility checks.
 */
function get_notification_visibility_ticket(int $ticket_id): ?array
{
    static $cache = [];
    if (!array_key_exists($ticket_id, $cache)) {
        $cache[$ticket_id] = function_exists('get_ticket') ? get_ticket($ticket_id) : null;
    }

    return $cache[$ticket_id];
}

/**
 * Get all direct ticket participants relevant for non-admin notification visibility.
 */
function get_notification_ticket_relevant_user_ids(int $ticket_id): array
{
    static $cache = [];
    if (array_key_exists($ticket_id, $cache)) {
        return $cache[$ticket_id];
    }

    $ids = [];
    $ticket = get_notification_visibility_ticket($ticket_id);

    if ($ticket) {
        $creator_id = (int) ($ticket['user_id'] ?? 0);
        $assignee_id = (int) ($ticket['assignee_id'] ?? 0);

        if ($creator_id > 0) {
            $ids[] = $creator_id;
        }

        if ($assignee_id > 0) {
            $ids[] = $assignee_id;
        }
    }

    try {
        $commenters = db_fetch_all(
            "SELECT DISTINCT user_id FROM comments WHERE ticket_id = ? AND user_id IS NOT NULL",
            [$ticket_id]
        );

        foreach ($commenters as $commenter) {
            $commenter_id = (int) ($commenter['user_id'] ?? 0);
            if ($commenter_id > 0) {
                $ids[] = $commenter_id;
            }
        }
    } catch (Throwable $e) {
        // Ignore legacy installs with inconsistent comments state.
    }

    if (function_exists('ticket_access_table_exists') && ticket_access_table_exists()) {
        try {
            $shared_users = db_fetch_all(
                "SELECT DISTINCT user_id FROM ticket_access WHERE ticket_id = ? AND user_id IS NOT NULL",
                [$ticket_id]
            );

            foreach ($shared_users as $shared_user) {
                $shared_user_id = (int) ($shared_user['user_id'] ?? 0);
                if ($shared_user_id > 0) {
                    $ids[] = $shared_user_id;
                }
            }
        } catch (Throwable $e) {
            // Ignore missing table state during rolling updates.
        }
    }

    $cache[$ticket_id] = array_values(array_unique($ids));
    return $cache[$ticket_id];
}

/**
 * Check whether a ticket notification is still relevant to an agent user.
 * Agents only see notifications for tickets currently assigned to them.
 */
function notification_is_relevant_to_agent(array $notification, array $user, array $ticket): bool
{
    $user_id = (int) ($user['id'] ?? 0);
    if ($user_id <= 0) {
        return false;
    }

    $assignee_id = (int) ($ticket['assignee_id'] ?? 0);
    return $assignee_id > 0 && $assignee_id === $user_id;
}

/**
 * Check whether a ticket notification is still relevant to an end user.
 * End users only see notifications for tickets they created.
 */
function notification_is_relevant_to_regular_user(array $notification, array $user, array $ticket): bool
{
    $user_id = (int) ($user['id'] ?? 0);
    if ($user_id <= 0) {
        return false;
    }

    $creator_id = (int) ($ticket['user_id'] ?? 0);
    return $creator_id > 0 && $creator_id === $user_id;
}

/**
 * Check whether a notification still points to a ticket the user should see.
 */
function notification_is_visible_to_user(array $notification, array $user): bool
{
    $ticket_id = (int) ($notification['ticket_id'] ?? 0);
    if ($ticket_id <= 0) {
        return true;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    if (!function_exists('get_ticket') || !function_exists('can_see_ticket')) {
        return true;
    }

    $ticket = get_notification_visibility_ticket($ticket_id);
    if (!$ticket) {
        return false;
    }

    if (!can_see_ticket($ticket, $user)) {
        return false;
    }

    if (($user['role'] ?? '') === 'agent') {
        return notification_is_relevant_to_agent($notification, $user, $ticket);
    }

    if (($user['role'] ?? '') === 'user') {
        return notification_is_relevant_to_regular_user($notification, $user, $ticket);
    }

    return false;
}

/**
 * Filter out notifications the user should no longer see.
 */
function filter_notifications_for_user(array $notifications, int $user_id): array
{
    if (empty($notifications)) {
        return [];
    }

    $user = get_notification_visibility_user($user_id);
    if (!$user) {
        return [];
    }

    if (($user['role'] ?? '') === 'admin') {
        return array_values($notifications);
    }

    $visible = [];
    foreach ($notifications as $notification) {
        if (notification_is_visible_to_user($notification, $user)) {
            $visible[] = $notification;
        }
    }

    return $visible;
}

// ── Read ─────────────────────────────────────────────────────────────────────

/**
 * Get notifications for a user, newest first.
 *
 * @return array ['notifications' => [...], 'unread_count' => int]
 */
function get_user_notifications(int $user_id, int $limit = 50, int $offset = 0, bool $exclude_resolved = true): array
{
    ensure_notifications_table();
    if (!notifications_table_exists()) {
        return ['notifications' => [], 'unread_count' => 0];
    }

    $resolved_filter = ($exclude_resolved && column_exists('notifications', 'is_resolved'))
        ? 'AND (n.is_resolved = 0 OR n.is_resolved IS NULL)' : '';

    $target_count = max(0, $offset) + max(1, $limit);
    $batch_size = max(50, $limit);
    $scan_offset = 0;
    $scan_batches = 0;
    $visible_rows = [];

    while (count($visible_rows) < $target_count && $scan_batches < 10) {
        $rows = db_fetch_all(
            "SELECT n.*, u.first_name AS actor_first_name, u.last_name AS actor_last_name,
                    u.avatar AS actor_avatar, u.email AS actor_email
             FROM notifications n
             LEFT JOIN users u ON u.id = n.actor_id
             WHERE n.user_id = ? $resolved_filter
             ORDER BY n.created_at DESC
             LIMIT ? OFFSET ?",
            [$user_id, $batch_size, $scan_offset]
        );

        if (empty($rows)) {
            break;
        }

        foreach ($rows as &$row) {
            $row['data'] = json_decode($row['data'] ?? '{}', true) ?: [];
        }
        unset($row);

        $visible_rows = array_merge($visible_rows, filter_notifications_for_user($rows, $user_id));

        $fetched_count = count($rows);
        $scan_offset += $fetched_count;
        $scan_batches++;

        if ($fetched_count < $batch_size) {
            break;
        }
    }

    $rows = array_slice($visible_rows, max(0, $offset), $limit);

    $unread = get_unread_notification_count($user_id);

    return ['notifications' => $rows, 'unread_count' => $unread];
}

/**
 * Count unread, non-resolved notifications (badge number).
 */
function get_unread_notification_count(int $user_id): int
{
    if (!notifications_table_exists()) return 0;

    $resolved_filter = column_exists('notifications', 'is_resolved')
        ? 'AND is_resolved = 0' : '';

    $rows = db_fetch_all(
        "SELECT id, ticket_id FROM notifications
         WHERE user_id = ? AND is_read = 0 $resolved_filter
         ORDER BY created_at DESC",
        [$user_id]
    );

    return count(filter_notifications_for_user($rows, $user_id));
}

/**
 * Get count of notifications that are not yet read (for polling badge).
 * Lightweight — returns just the number.
 */
// ── Mark Read ────────────────────────────────────────────────────────────────

/**
 * Mark a single notification as read.
 */
function mark_notification_read(int $notification_id, int $user_id): bool
{
    if (!notifications_table_exists()) return false;

    db_update('notifications', ['is_read' => 1], 'id = ? AND user_id = ?', [$notification_id, $user_id]);
    return true;
}

/**
 * Mark all notifications for a specific ticket as read.
 */
function mark_ticket_notifications_read(int $ticket_id, int $user_id): bool
{
    if (!notifications_table_exists()) return false;

    db_execute(
        "UPDATE notifications SET is_read = 1 WHERE ticket_id = ? AND user_id = ? AND is_read = 0",
        [$ticket_id, $user_id]
    );

    if (column_exists('notifications', 'is_resolved')) {
        try {
            db_execute(
                "UPDATE notifications SET is_resolved = 1
                 WHERE ticket_id = ? AND user_id = ? AND is_resolved = 0
                 AND (type IN ('assigned_to_you', 'due_date_reminder')
                      OR (type = 'new_comment' AND JSON_EXTRACT(data, '$.action_required') = true))",
                [$ticket_id, $user_id]
            );
        } catch (Throwable $e) {
            // Silent — don't break read-state updates
        }
    }

    return true;
}

/**
 * Mark ALL notifications as read + update last_notifications_seen_at.
 */
function mark_all_notifications_read(int $user_id): bool
{
    if (!notifications_table_exists()) return false;

    $now = date('Y-m-d H:i:s');

    db_update('notifications', ['is_read' => 1], 'user_id = ? AND is_read = 0', [$user_id]);
    db_update('users', ['last_notifications_seen_at' => $now], 'id = ?', [$user_id]);
    return true;
}

/**
 * Resolve action-required notifications for a ticket.
 * Called when the underlying action is completed (ticket closed, assignee responded, reassigned).
 *
 * @param int      $ticket_id
 * @param int|null $user_id   If set, only resolve for this user. If null, resolve for all users.
 */
function resolve_action_notifications(int $ticket_id, ?int $user_id = null): void
{
    if (!notifications_table_exists()) return;
    if (!column_exists('notifications', 'is_resolved')) return;

    $params = [$ticket_id];
    $user_filter = '';
    if ($user_id !== null) {
        $user_filter = 'AND user_id = ?';
        $params[] = $user_id;
    }

    try {
        db_execute(
            "UPDATE notifications SET is_resolved = 1
             WHERE ticket_id = ? AND is_resolved = 0 $user_filter
             AND (type IN ('assigned_to_you', 'due_date_reminder')
                  OR (type = 'new_comment' AND JSON_EXTRACT(data, '$.action_required') = true))",
            $params
        );
    } catch (Throwable $e) {
        // Silent — don't break the main flow
    }
}

/**
 * Update the "last seen" timestamp (called when bell panel is opened).
 */
function update_notifications_seen(int $user_id): void
{
    try {
        db_update('users', ['last_notifications_seen_at' => date('Y-m-d H:i:s')], 'id = ?', [$user_id]);
    } catch (Throwable $e) {
        // silent
    }
}

/**
 * Get snippet text for a notification, with fallbacks for all types.
 */
function get_notification_snippet(array $notif): string
{
    $data = $notif['data'] ?? [];

    // Stored comment/description preview (new_comment, new_ticket with preview)
    if (!empty($data['comment_preview'])) {
        return $data['comment_preview'];
    }

    switch ($notif['type']) {
        case 'new_ticket':
            // Fallback: fetch ticket description from DB
            if (!empty($notif['ticket_id'])) {
                $ticket = db_fetch_one("SELECT description FROM tickets WHERE id = ?", [(int) $notif['ticket_id']]);
                if ($ticket && !empty($ticket['description'])) {
                    $text = strip_tags($ticket['description']);
                    return mb_strlen($text) > 80 ? mb_substr($text, 0, 77) . '...' : $text;
                }
            }
            break;

        case 'assigned_to_you':
            return $data['ticket_subject'] ?? '';

        case 'status_changed':
            if (!empty($data['old_status']) && !empty($data['new_status'])) {
                return $data['old_status'] . ' → ' . $data['new_status'];
            }
            break;

        case 'priority_changed':
            if (!empty($data['old_priority']) && !empty($data['new_priority'])) {
                return $data['old_priority'] . ' → ' . $data['new_priority'];
            }
            break;

        case 'new_comment':
            // Fallback: fetch latest comment text from DB
            if (!empty($data['comment_id'])) {
                $comment = db_fetch_one("SELECT body FROM ticket_comments WHERE id = ?", [(int) $data['comment_id']]);
                if ($comment && !empty($comment['body'])) {
                    $text = strip_tags($comment['body']);
                    return mb_strlen($text) > 80 ? mb_substr($text, 0, 77) . '...' : $text;
                }
            }
            break;
    }

    return '';
}

// ── Recipients ───────────────────────────────────────────────────────────────

/**
 * Get all users who participated in a ticket (creator, assignee, commenters).
 *
 * @param int      $ticket_id
 * @param int|null $exclude_user_id  Usually the actor
 * @return int[] User IDs
 */
function get_ticket_participants(int $ticket_id, ?int $exclude_user_id = null): array
{
    $ids = [];

    // Ticket creator + assignee
    $ticket = db_fetch_one("SELECT user_id, assignee_id FROM tickets WHERE id = ?", [$ticket_id]);
    if ($ticket) {
        if (!empty($ticket['user_id']))     $ids[] = (int) $ticket['user_id'];
        if (!empty($ticket['assignee_id'])) $ids[] = (int) $ticket['assignee_id'];
    }

    // Commenters
    $commenters = db_fetch_all(
        "SELECT DISTINCT user_id FROM comments WHERE ticket_id = ?",
        [$ticket_id]
    );
    foreach ($commenters as $c) {
        $ids[] = (int) $c['user_id'];
    }

    $ids = array_unique($ids);
    if ($exclude_user_id) {
        $ids = array_filter($ids, fn($id) => $id !== $exclude_user_id);
    }

    return array_values($ids);
}

/**
 * Get all active agents and admins (optionally filtered by organization).
 *
 * @param int|null $org_id   If set, only staff in this org
 * @param int|null $exclude  User ID to exclude
 * @return int[]
 */
function get_staff_user_ids(?int $exclude = null): array
{
    $rows = db_fetch_all(
        "SELECT id FROM users WHERE role IN ('admin','agent') AND is_active = 1"
    );
    $ids = array_map(fn($r) => (int) $r['id'], $rows);

    if ($exclude) {
        $ids = array_filter($ids, fn($id) => $id !== $exclude);
    }

    return array_values($ids);
}

// ── Action Required ──────────────────────────────────────────────────────────

/**
 * Determine if a notification requires user action.
 */
function is_action_required_notification(string $type, array $data = []): bool
{
    switch ($type) {
        case 'assigned_to_you':
        case 'due_date_reminder':
            return true;
        case 'new_comment':
            return !empty($data['action_required']);
        default:
            return false;
    }
}

// ── Central Dispatcher ───────────────────────────────────────────────────────

/**
 * Dispatch in-app notifications for a ticket event.
 *
 * @param string   $event_type  new_ticket | new_comment | status_changed | assigned_to_you | priority_changed | due_date_reminder
 * @param int      $ticket_id
 * @param int      $actor_id    Who performed the action
 * @param array    $extra       Additional context data
 */
function dispatch_ticket_notifications(string $event_type, int $ticket_id, int $actor_id, array $extra = []): void
{
    if (!notifications_table_exists()) return;

    // Fetch ticket for context
    $ticket = db_fetch_one("SELECT id, title, description, user_id, assignee_id, status_id, priority_id FROM tickets WHERE id = ?", [$ticket_id]);
    if (!$ticket) return;

    // Fetch actor name
    $actor = db_fetch_one("SELECT first_name, last_name FROM users WHERE id = ?", [$actor_id]);
    $actor_name = $actor ? trim($actor['first_name'] . ' ' . ($actor['last_name'] ?? '')) : 'System';

    // Build base data payload
    $data = array_merge([
        'ticket_subject' => $ticket['title'] ?? '',
        'ticket_id'      => (int) $ticket['id'],
        'actor_name'     => $actor_name,
    ], $extra);

    // Set action_required flag based on event type
    $data['action_required'] = in_array($event_type, ['assigned_to_you', 'due_date_reminder'], true);

    // Auto-add description preview for new_ticket if not provided
    if ($event_type === 'new_ticket' && empty($data['comment_preview']) && !empty($ticket['description'])) {
        $desc_text = strip_tags($ticket['description']);
        $data['comment_preview'] = mb_strlen($desc_text) > 80 ? mb_substr($desc_text, 0, 77) . '...' : $desc_text;
    }

    // Determine recipients based on event type
    $recipients = [];

    switch ($event_type) {
        case 'new_ticket':
            // Notify all staff (agents + admins)
            $recipients = get_staff_user_ids($actor_id);
            break;

        case 'new_comment':
            // Notify ticket participants (creator, assignee, previous commenters)
            $recipients = get_ticket_participants($ticket_id, $actor_id);
            $assignee_id = !empty($ticket['assignee_id']) ? (int) $ticket['assignee_id'] : 0;

            // Assignee gets action_required=true (they need to respond)
            if ($assignee_id > 0 && $assignee_id !== $actor_id && in_array($assignee_id, $recipients)) {
                $assignee_data = array_merge($data, ['action_required' => true, 'recipient_is_assignee' => true]);
                create_notifications_for_users([$assignee_id], $event_type, $ticket_id, $actor_id, $assignee_data);
                // Remove assignee from remaining recipients
                $recipients = array_filter($recipients, fn($id) => $id !== $assignee_id);
            }
            break;

        case 'status_changed':
            // Notify all ticket participants (creator, assignee, commenters)
            $recipients = get_ticket_participants($ticket_id, $actor_id);
            break;

        case 'assigned_to_you':
            // Notify the newly assigned agent
            $assignee_id = (int) ($extra['assignee_id'] ?? 0);
            if ($assignee_id > 0 && $assignee_id !== $actor_id) {
                $recipients = [$assignee_id];
            }
            break;

        case 'priority_changed':
            // Notify ticket participants
            $recipients = get_ticket_participants($ticket_id, $actor_id);
            break;

        case 'ticket_updated':
            // Generic ticket field change — notify participants
            $recipients = get_ticket_participants($ticket_id, $actor_id);
            break;

        case 'due_date_reminder':
            // Notify assignee, or all staff if unassigned
            if (!empty($ticket['assignee_id'])) {
                $recipients = [(int) $ticket['assignee_id']];
            } else {
                $recipients = get_staff_user_ids();
            }
            break;
    }

    if (!empty($recipients)) {
        create_notifications_for_users($recipients, $event_type, $ticket_id, $actor_id, $data);
    }
}

// ── Formatting ───────────────────────────────────────────────────────────────

/**
 * Format notification text for display.
 *
 * @param array $notification Full notification row with decoded data
 * @return string Translated text
 */
function format_notification_text(array $notification): string
{
    $data = $notification['data'] ?? [];
    $actor = $data['actor_name'] ?? t('Someone');
    $subject = $data['ticket_subject'] ?? '';

    // Truncate subject to 50 chars
    if (mb_strlen($subject) > 50) {
        $subject = mb_substr($subject, 0, 47) . '...';
    }

    switch ($notification['type'] ?? '') {
        case 'new_ticket':
            return t('{actor} created a new ticket: {subject}', ['actor' => $actor, 'subject' => $subject]);

        case 'new_comment':
            return t('{actor} commented on {subject}', ['actor' => $actor, 'subject' => $subject]);

        case 'status_changed':
            $new_status = $data['new_status'] ?? '';
            return t('{actor} changed status to {status} on {subject}', [
                'actor' => $actor, 'status' => $new_status, 'subject' => $subject
            ]);

        case 'assigned_to_you':
            return t('{actor} assigned you to {subject}', ['actor' => $actor, 'subject' => $subject]);

        case 'priority_changed':
            $new_priority = $data['new_priority'] ?? '';
            return t('{actor} changed priority to {priority} on {subject}', [
                'actor' => $actor, 'priority' => $new_priority, 'subject' => $subject
            ]);

        case 'ticket_updated':
            $field = $data['field'] ?? '';
            $detail = $data['detail'] ?? '';
            $field_labels = [
                'due_date' => t('due date'),
                'type'     => t('type'),
                'company'  => t('company'),
            ];
            $field_label = $field_labels[$field] ?? $field;
            if ($detail) {
                return t('{actor} changed {field} to {value} on {subject}', [
                    'actor' => $actor, 'field' => $field_label, 'value' => $detail, 'subject' => $subject
                ]);
            }
            return t('{actor} updated {field} on {subject}', [
                'actor' => $actor, 'field' => $field_label, 'subject' => $subject
            ]);

        case 'due_date_reminder':
            $due = $data['due_date'] ?? '';
            if (!empty($data['is_overdue'])) {
                return t('Ticket {subject} is overdue (was due {date})', ['subject' => $subject, 'date' => $due]);
            }
            return t('Ticket {subject} is due {date}', ['subject' => $subject, 'date' => $due]);

        case 'system':
            return $data['message'] ?? t('System notification');

        default:
            return t('New notification');
    }
}

/**
 * Format notification action text (without ticket subject) for ticket-first layout.
 */
function format_notification_action(array $notification): string
{
    $data = $notification['data'] ?? [];
    $actor = $data['actor_name'] ?? t('Someone');

    switch ($notification['type'] ?? '') {
        case 'new_ticket':
            return t('New ticket by {actor}', ['actor' => $actor]);

        case 'new_comment':
            return t('Comment by {actor}', ['actor' => $actor]);

        case 'status_changed':
            $new_status = $data['new_status'] ?? '';
            return t('Status → {status} • {actor}', ['status' => $new_status, 'actor' => $actor]);

        case 'assigned_to_you':
            return t('Assigned to you by {actor}', ['actor' => $actor]);

        case 'priority_changed':
            $new_priority = $data['new_priority'] ?? '';
            return t('Priority → {priority} • {actor}', ['priority' => $new_priority, 'actor' => $actor]);

        case 'ticket_updated':
            $field = $data['field'] ?? '';
            $detail = $data['detail'] ?? '';
            $field_labels = [
                'due_date' => t('due date'),
                'type'     => t('type'),
                'company'  => t('company'),
            ];
            $fl = $field_labels[$field] ?? $field;
            if ($detail) {
                return t('{field} → {value} • {actor}', ['field' => ucfirst($fl), 'value' => $detail, 'actor' => $actor]);
            }
            return t('{field} updated • {actor}', ['field' => ucfirst($fl), 'actor' => $actor]);

        case 'due_date_reminder':
            return t('Due {date}', ['date' => $data['due_date'] ?? '']);

        default:
            return '';
    }
}

/**
 * Relative time string ("2 min ago", "3 hours ago", "Yesterday", …).
 */
function notification_time_ago(string $datetime): string
{
    $now = time();
    $ts = strtotime($datetime);
    $diff = $now - $ts;

    if ($diff < 60)        return t('just now');
    if ($diff < 3600)      return t('{n} min ago', ['n' => (int) floor($diff / 60)]);
    if ($diff < 86400)     return t('{n}h ago', ['n' => (int) floor($diff / 3600)]);
    if ($diff < 172800)    return t('yesterday');
    if ($diff < 604800)    return t('{n} days ago', ['n' => (int) floor($diff / 86400)]);

    return date('j M', $ts);
}

/**
 * Group notifications into "today", "yesterday", "earlier".
 *
 * @param array $notifications
 * @return array ['today' => [...], 'yesterday' => [...], 'earlier' => [...]]
 */
function group_notifications(array $notifications): array
{
    $today_start = strtotime('today 00:00:00');
    $yesterday_start = strtotime('yesterday 00:00:00');

    $grouped = ['today' => [], 'yesterday' => [], 'earlier' => []];

    foreach ($notifications as $n) {
        $ts = strtotime($n['created_at']);
        if ($ts >= $today_start) {
            $grouped['today'][] = $n;
        } elseif ($ts >= $yesterday_start) {
            $grouped['yesterday'][] = $n;
        } else {
            $grouped['earlier'][] = $n;
        }
    }

    return $grouped;
}

/**
 * Sub-group a flat list of notifications by ticket_id.
 * Within each ticket group, action-required notifications come first, then newest.
 *
 * @param array $notifications Flat list (already in one date bucket)
 * @return array Array of ticket groups: [['ticket_id'=>int, 'primary'=>[...], 'others'=>[...], 'count'=>int, 'has_unread'=>bool], ...]
 */
function group_by_ticket(array $notifications): array
{
    $ticket_groups = [];
    $no_ticket = [];

    foreach ($notifications as $n) {
        $tid = $n['ticket_id'] ?? null;
        if ($tid) {
            $ticket_groups[$tid][] = $n;
        } else {
            $no_ticket[] = $n;
        }
    }

    $result = [];
    foreach ($ticket_groups as $tid => $items) {
        // Action-required first, then newest
        usort($items, function ($a, $b) {
            $a_act = is_action_required_notification($a['type'], $a['data'] ?? []);
            $b_act = is_action_required_notification($b['type'], $b['data'] ?? []);
            if ($a_act !== $b_act) return $b_act <=> $a_act;
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        $result[] = [
            'ticket_id'  => (int) $tid,
            'primary'    => $items[0],
            'others'     => array_slice($items, 1),
            'count'      => count($items),
            'has_unread' => (bool) array_filter($items, fn($i) => !$i['is_read']),
        ];
    }

    // Non-ticket notifications as single-item groups
    foreach ($no_ticket as $n) {
        $result[] = [
            'ticket_id'  => null,
            'primary'    => $n,
            'others'     => [],
            'count'      => 1,
            'has_unread' => !$n['is_read'],
        ];
    }

    return $result;
}

// ── Cleanup ──────────────────────────────────────────────────────────────────

/**
 * Delete old read notifications. Call from maintenance cron.
 *
 * @param int $days Keep notifications for this many days
 * @return int Number of deleted rows
 */
function cleanup_old_notifications(int $days = 90): int
{
    if (!notifications_table_exists()) return 0;

    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    try {
        db_query("DELETE FROM notifications WHERE is_read = 1 AND created_at < ?", [$cutoff]);
        $deleted = db_fetch_one("SELECT ROW_COUNT() AS cnt");
        return (int) ($deleted['cnt'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

// ── Due Date Reminder Processing ────────────────────────────────────────────

/**
 * Process due date reminders for tickets approaching or past their due date.
 *
 * Called from cron every hour. Sends in-app notifications (and optionally email)
 * for tickets due within the next 24 hours or already overdue.
 * Deduplicates by checking existing notifications to avoid spamming.
 *
 * @return array Summary of processed tickets
 */
function process_due_date_notifications(): array
{
    $result = ['due_soon' => 0, 'overdue' => 0, 'skipped' => 0];

    // Get closed status IDs to exclude
    $closed_statuses = db_fetch_all("SELECT id FROM statuses WHERE is_closed = 1");
    $closed_ids = array_map('intval', array_column($closed_statuses, 'id'));

    if (empty($closed_ids)) {
        $closed_placeholder = '0';
        $params = [];
    } else {
        $closed_placeholder = implode(',', array_fill(0, count($closed_ids), '?'));
        $params = $closed_ids;
    }

    // Find tickets that are:
    // 1. Due within the next 24 hours (due_soon)
    // 2. Already overdue (past due_date)
    // Exclude closed/archived tickets
    $tickets = db_fetch_all("
        SELECT t.id, t.title, t.due_date, t.assignee_id, t.hash
        FROM tickets t
        WHERE t.due_date IS NOT NULL
          AND t.due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
          AND (t.is_archived IS NULL OR t.is_archived = 0)
          AND t.status_id NOT IN ({$closed_placeholder})
        ORDER BY t.due_date ASC
        LIMIT 100
    ", $params);

    if (empty($tickets)) {
        return $result;
    }

    // Check which tickets already had a due_date_reminder notification in the last 20 hours
    // (prevents sending duplicates on each cron run)
    $ticket_ids = array_map(fn($t) => (int) $t['id'], $tickets);
    $id_placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
    $cutoff_time = date('Y-m-d H:i:s', strtotime('-20 hours'));

    $already_notified = db_fetch_all("
        SELECT DISTINCT ticket_id
        FROM notifications
        WHERE type = 'due_date_reminder'
          AND ticket_id IN ({$id_placeholders})
          AND created_at >= ?
    ", array_merge($ticket_ids, [$cutoff_time]));

    $notified_ticket_ids = array_flip(array_map(fn($r) => (int) $r['ticket_id'], $already_notified));

    foreach ($tickets as $ticket) {
        $tid = (int) $ticket['id'];

        // Skip if already notified recently
        if (isset($notified_ticket_ids[$tid])) {
            $result['skipped']++;
            continue;
        }

        $is_overdue = is_due_date_overdue($ticket['due_date'] ?? null);
        $due_formatted = function_exists('format_date') ? format_date($ticket['due_date'], 'd.m.Y H:i') : $ticket['due_date'];

        // Dispatch in-app notification
        if (function_exists('dispatch_ticket_notifications')) {
            dispatch_ticket_notifications('due_date_reminder', $tid, 0, [
                'due_date' => $due_formatted,
                'is_overdue' => $is_overdue,
            ]);
        }

        // Send email reminder if mailer is available
        if (function_exists('send_due_date_reminder')) {
            try {
                send_due_date_reminder($ticket, $is_overdue);
            } catch (Throwable $e) {
                // Email failure should not stop processing
            }
        }

        if ($is_overdue) {
            $result['overdue']++;
        } else {
            $result['due_soon']++;
        }
    }

    return $result;
}
