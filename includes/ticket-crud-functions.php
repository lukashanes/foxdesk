<?php
/**
 * Ticket CRUD Functions
 *
 * Core ticket operations: create, read, update, delete, and comments.
 */

// =============================================================================
// TICKET HASH FUNCTIONS
// =============================================================================

/**
 * Generate a unique ticket hash (12 characters, URL-safe)
 */
function generate_ticket_hash() {
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed confusing chars (0,O,1,l,I)
    $hash_length = 12;
    $max_attempts = 10;

    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $hash = '';
        for ($i = 0; $i < $hash_length; $i++) {
            $hash .= $chars[random_int(0, strlen($chars) - 1)];
        }

        // Check if hash already exists
        $existing = db_fetch_one("SELECT id FROM tickets WHERE hash = ?", [$hash]);
        if (!$existing) {
            return $hash;
        }
    }

    // Fallback: use timestamp + random
    return substr(bin2hex(random_bytes(6)), 0, 12);
}

/**
 * Get ticket by hash
 */
function get_ticket_by_hash($hash) {
    if (empty($hash) || strlen($hash) < 8) {
        return null;
    }

    return db_fetch_one("SELECT t.*,
                                s.name as status_name, s.color as status_color,
                                u.first_name, u.last_name, u.email, u.avatar,
                                o.name as organization_name,
                                p.name as priority_name, p.color as priority_color,
                                a.first_name as assignee_first_name, a.last_name as assignee_last_name
                         FROM tickets t
                         LEFT JOIN statuses s ON t.status_id = s.id
                         LEFT JOIN users u ON t.user_id = u.id
                         LEFT JOIN organizations o ON t.organization_id = o.id
                         LEFT JOIN priorities p ON t.priority_id = p.id
                         LEFT JOIN users a ON t.assignee_id = a.id
                         WHERE t.hash = ?", [$hash]);
}

/**
 * Get ticket ID by hash (for internal use)
 */
function get_ticket_id_by_hash($hash) {
    if (empty($hash)) {
        return null;
    }
    $ticket = db_fetch_one("SELECT id FROM tickets WHERE hash = ?", [$hash]);
    return $ticket ? (int)$ticket['id'] : null;
}

/**
 * Migrate existing tickets to have hash values
 */
function migrate_ticket_hashes() {
    static $migrated = false;
    if ($migrated) return true;

    try {
        // Check if hash column exists
        if (!column_exists('tickets', 'hash')) {
            // Add hash column
            db_query("ALTER TABLE tickets ADD COLUMN hash VARCHAR(16) DEFAULT NULL AFTER id");
            db_query("CREATE UNIQUE INDEX idx_hash ON tickets(hash)");
        }

        // Generate hashes for tickets without one (batch via CASE WHEN)
        $tickets_without_hash = db_fetch_all("SELECT id FROM tickets WHERE hash IS NULL OR hash = ''");
        if (!empty($tickets_without_hash)) {
            $cases = [];
            $params = [];
            $ids = [];
            foreach ($tickets_without_hash as $ticket) {
                $hash = generate_ticket_hash();
                $cases[] = "WHEN id = ? THEN ?";
                $params[] = $ticket['id'];
                $params[] = $hash;
                $ids[] = $ticket['id'];
            }
            $id_placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE tickets SET hash = CASE " . implode(' ', $cases)
                 . " END WHERE id IN ({$id_placeholders})";
            db_query($sql, array_merge($params, $ids));
        }

        $migrated = true;
        return true;
    } catch (Exception $e) {
        error_log("Failed to migrate ticket hashes: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if ticket hash column exists
 */
function ticket_hash_column_exists() {
    return column_exists('tickets', 'hash');
}

/**
 * Normalize tag list to a stable, comma-separated string.
 */
function normalize_ticket_tags($value) {
    $raw = (string) $value;
    if ($raw === '') {
        return '';
    }

    $parts = preg_split('/[,;\n\r]+/', $raw);
    $seen = [];
    $clean = [];
    foreach ((array) $parts as $part) {
        $tag = trim((string) $part);
        if ($tag === '') {
            continue;
        }
        $tag = preg_replace('/\s+/', ' ', $tag);
        $tag = ltrim($tag, '#');
        if ($tag === '') {
            continue;
        }

        $key = function_exists('mb_strtolower')
            ? mb_strtolower($tag, 'UTF-8')
            : strtolower($tag);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $clean[] = $tag;
    }

    return implode(', ', $clean);
}

/**
 * Return ticket tags as array.
 */
function get_ticket_tags_array($value) {
    $normalized = normalize_ticket_tags($value);
    if ($normalized === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $normalized))));
}

// =============================================================================
// TICKET CRUD OPERATIONS
// =============================================================================

/**
 * Get tickets
 */
function get_tickets($filters = []) {
    $sql = "SELECT t.*,
                   s.name as status_name, s.color as status_color, s.is_closed,
                   u.first_name, u.last_name, u.email,
                   o.name as organization_name,
                   p.name as priority_name, p.color as priority_color,
                   a.first_name as assignee_first_name, a.last_name as assignee_last_name,
                   IFNULL(ac.attachment_count, 0) as attachment_count
            FROM tickets t
            LEFT JOIN statuses s ON t.status_id = s.id
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN organizations o ON t.organization_id = o.id
            LEFT JOIN priorities p ON t.priority_id = p.id
            LEFT JOIN users a ON t.assignee_id = a.id
            LEFT JOIN (SELECT ticket_id, COUNT(*) AS attachment_count FROM attachments GROUP BY ticket_id) ac ON ac.ticket_id = t.id";
    $params = [];
    $sql .= build_ticket_where_clause($filters, $params);

    $order_by = "t.created_at DESC";
    if (!empty($filters['sort'])) {
        switch ($filters['sort']) {
            case 'oldest':
                $order_by = "t.created_at ASC";
                break;
            case 'priority':
                $order_by = "p.sort_order ASC, t.created_at DESC";
                break;
            case 'status':
                $order_by = "s.sort_order ASC, t.created_at DESC";
                break;
            case 'tags':
                $order_by = "CASE WHEN t.tags IS NULL OR t.tags = '' THEN 1 ELSE 0 END, t.tags ASC, t.created_at DESC";
                break;
            case 'due_date':
                $order_by = "CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC, t.created_at DESC";
                break;
            case 'last_updated':
                $order_by = "t.updated_at DESC";
                break;
            case 'ticket_number':
                $order_by = "t.id DESC";
                break;
            case 'ticket_number_asc':
                $order_by = "t.id ASC";
                break;
            default:
                $order_by = "t.created_at DESC";
                break;
        }
    }

    $sql .= " ORDER BY " . $order_by;

    if (!empty($filters['limit'])) {
        $limit = max(1, (int)$filters['limit']);
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $sql .= " LIMIT " . $limit . " OFFSET " . $offset;
    }

    return db_fetch_all($sql, $params);
}

/**
 * Get tickets count (for pagination)
 */
function get_tickets_count($filters = []) {
    $sql = "SELECT COUNT(*) as total
            FROM tickets t
            LEFT JOIN statuses s ON t.status_id = s.id
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN organizations o ON t.organization_id = o.id
            LEFT JOIN priorities p ON t.priority_id = p.id
            LEFT JOIN users a ON t.assignee_id = a.id";
    $params = [];
    $sql .= build_ticket_where_clause($filters, $params);
    $row = db_fetch_one($sql, $params);
    return (int)($row['total'] ?? 0);
}

/**
 * Get ticket by ID
 */
function get_ticket($id) {
    return db_fetch_one("SELECT t.*,
                                s.name as status_name, s.color as status_color, s.is_closed,
                                u.first_name, u.last_name, u.email, u.avatar,
                                o.name as organization_name,
                                p.name as priority_name, p.color as priority_color,
                                a.first_name as assignee_first_name, a.last_name as assignee_last_name
                         FROM tickets t
                         LEFT JOIN statuses s ON t.status_id = s.id
                         LEFT JOIN users u ON t.user_id = u.id
                         LEFT JOIN organizations o ON t.organization_id = o.id
                         LEFT JOIN priorities p ON t.priority_id = p.id
                         LEFT JOIN users a ON t.assignee_id = a.id
                         WHERE t.id = ?", [$id]);
}

/**
 * Get multiple tickets by IDs in a single query
 */
function get_tickets_by_ids(array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = db_fetch_all("SELECT t.*,
                                s.name as status_name, s.color as status_color, s.is_closed,
                                u.first_name, u.last_name, u.email, u.avatar,
                                o.name as organization_name,
                                p.name as priority_name, p.color as priority_color,
                                a.first_name as assignee_first_name, a.last_name as assignee_last_name
                         FROM tickets t
                         LEFT JOIN statuses s ON t.status_id = s.id
                         LEFT JOIN users u ON t.user_id = u.id
                         LEFT JOIN organizations o ON t.organization_id = o.id
                         LEFT JOIN priorities p ON t.priority_id = p.id
                         LEFT JOIN users a ON t.assignee_id = a.id
                         WHERE t.id IN ({$placeholders})", $ids);
    $keyed = [];
    foreach ($rows as $row) {
        $keyed[(int)$row['id']] = $row;
    }
    return $keyed;
}

/**
 * Create ticket
 */
function normalize_due_date_input($value) {
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $formats = [
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $raw);
        if (!$dt) {
            continue;
        }

        $errors = DateTime::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            continue;
        }

        if ($format === 'Y-m-d') {
            // Date-only deadlines should stay active through the whole day.
            $dt->setTime(23, 59, 59);
        } elseif ($format === 'Y-m-d\TH:i' || $format === 'Y-m-d H:i') {
            $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0);
        }

        return $dt->format('Y-m-d H:i:s');
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return false;
    }

    return date('Y-m-d H:i:s', $ts);
}

function is_due_date_overdue($due_date, $is_closed = false) {
    if ($is_closed || empty($due_date)) {
        return false;
    }

    $dt = date_create((string) $due_date);
    if (!$dt) {
        return false;
    }

    return $dt < new DateTime();
}

function get_kanban_closed_archive_days(): int
{
    $raw = trim((string) get_setting('kanban_hide_closed_after_days', '7'));
    $days = (int) $raw;
    if ($days < 1) {
        $days = 7;
    }

    return min($days, 365);
}

function should_hide_closed_ticket_in_board(array $ticket, ?int $days = null): bool
{
    $is_closed = !empty($ticket['is_closed']) || !empty($ticket['status_is_closed']);
    if (!$is_closed) {
        return false;
    }

    $days = $days ?? get_kanban_closed_archive_days();
    if ($days < 1) {
        return false;
    }

    $reference = trim((string) ($ticket['updated_at'] ?? $ticket['created_at'] ?? ''));
    if ($reference === '') {
        return false;
    }

    try {
        $updated_at = new DateTimeImmutable($reference);
        $cutoff = (new DateTimeImmutable())->sub(new DateInterval('P' . $days . 'D'));
        return $updated_at < $cutoff;
    } catch (Throwable $e) {
        return false;
    }
}

function create_ticket($data) {
    $default_status = get_default_status();
    $default_priority = get_default_priority();

    // Default to user's primary organization unless explicitly provided.
    $user = get_user($data['user_id']);
    $organization_id = !empty($user['organization_id']) ? (int) $user['organization_id'] : null;
    if (array_key_exists('organization_id', $data)) {
        $candidate_org = (int) ($data['organization_id'] ?? 0);
        $organization_id = $candidate_org > 0 ? $candidate_org : null;
    }

    $ticket_data = [
        'title' => $data['title'],
        'description' => $data['description'] ?? '',
        'type' => $data['type'] ?? 'general',
        'user_id' => $data['user_id'],
        'organization_id' => $organization_id,
        'status_id' => $data['status_id'] ?? $default_status['id'],
        'created_at' => date('Y-m-d H:i:s')
    ];

    // Generate unique hash for secure URL
    if (ticket_hash_column_exists()) {
        $ticket_data['hash'] = generate_ticket_hash();
    }

    // Handle priority - check if priorities table exists
    if (!empty($data['priority_id'])) {
        $ticket_data['priority_id'] = $data['priority_id'];
    } elseif ($default_priority) {
        $ticket_data['priority_id'] = $default_priority['id'];
    }

    if (array_key_exists('due_date', $data)) {
        $normalized_due_date = normalize_due_date_input($data['due_date']);
        if ($normalized_due_date === false) {
            return false;
        }
        $ticket_data['due_date'] = $normalized_due_date;
    }

    if (array_key_exists('tags', $data) && function_exists('ticket_tags_column_exists') && ticket_tags_column_exists()) {
        $normalized_tags = normalize_ticket_tags($data['tags']);
        $ticket_data['tags'] = $normalized_tags !== '' ? $normalized_tags : null;
    }

    // Handle assignee
    if (!empty($data['assignee_id'])) {
        $ticket_data['assignee_id'] = (int) $data['assignee_id'];
    }

    // Set ticket_type_id from the type slug
    if (!empty($ticket_data['type'])) {
        $type_row = db_fetch_one("SELECT id FROM ticket_types WHERE slug = ?", [$ticket_data['type']]);
        if ($type_row) {
            $ticket_data['ticket_type_id'] = $type_row['id'];
        }
    }

    return db_insert('tickets', $ticket_data);
}

/**
 * Update ticket
 */
function update_ticket($id, $data) {
    $data['updated_at'] = date('Y-m-d H:i:s');
    return db_update('tickets', $data, 'id = ?', [$id]);
}

/**
 * Delete ticket
 */
function delete_ticket($id) {
    // Delete related records first
    try {
        db_delete('comments', 'ticket_id = ?', [$id]);
        db_delete('attachments', 'ticket_id = ?', [$id]);
        db_delete('activity_log', 'ticket_id = ?', [$id]);
        if (ticket_time_table_exists()) {
            db_delete('ticket_time_entries', 'ticket_id = ?', [$id]);
        }
        if (ticket_access_table_exists()) {
            db_delete('ticket_access', 'ticket_id = ?', [$id]);
        }
    } catch (Exception $e) {
        // Ignore if tables don't exist
    }

    return db_delete('tickets', 'id = ?', [$id]);
}

/**
 * Count tickets by status
 */
// =============================================================================
// COMMENTS
// =============================================================================

/**
 * Get ticket comments
 */
function get_ticket_comments($ticket_id) {
    return db_fetch_all("SELECT c.*, u.first_name, u.last_name, u.email, u.avatar
                         FROM comments c
                         LEFT JOIN users u ON c.user_id = u.id
                         WHERE c.ticket_id = ?
                         ORDER BY c.created_at ASC", [$ticket_id]);
}

/**
 * Return the canonical comment table/column mapping for this installation.
 *
 * Newer installs use `comments.content`, while some legacy code paths still
 * reference `ticket_comments.body`. We resolve the active storage once here so
 * rendering and mail/notification helpers can stay schema-safe.
 *
 * @return array{table:string, content_column:string}|null
 */
function get_comment_storage_definition(): ?array
{
    static $definition = null;
    static $resolved = false;

    if ($resolved) {
        return $definition;
    }

    $resolved = true;

    if (function_exists('table_exists') && table_exists('comments')) {
        $definition = ['table' => 'comments', 'content_column' => 'content'];
        return $definition;
    }

    if (function_exists('table_exists') && table_exists('ticket_comments')) {
        $definition = ['table' => 'ticket_comments', 'content_column' => 'body'];
        return $definition;
    }

    return null;
}

/**
 * Fetch a single comment body/content as plain text.
 */
function get_comment_text_by_id(int $comment_id): ?string
{
    if ($comment_id <= 0) {
        return null;
    }

    $storage = get_comment_storage_definition();
    if (!$storage) {
        return null;
    }

    try {
        if ($storage['table'] === 'comments') {
            $row = db_fetch_one("SELECT content AS comment_text FROM comments WHERE id = ?", [$comment_id]);
        } else {
            $row = db_fetch_one("SELECT body AS comment_text FROM ticket_comments WHERE id = ?", [$comment_id]);
        }
    } catch (Throwable $e) {
        return null;
    }

    $text = trim((string) ($row['comment_text'] ?? ''));
    return $text !== '' ? $text : null;
}

/**
 * Get distinct participant user IDs from comments on a ticket.
 *
 * @return int[]
 */
function get_ticket_comment_user_ids(int $ticket_id, ?int $exclude_user_id = null): array
{
    if ($ticket_id <= 0) {
        return [];
    }

    $storage = get_comment_storage_definition();
    if (!$storage) {
        return [];
    }

    $params = [$ticket_id];
    $exclude_sql = '';
    if ($exclude_user_id !== null) {
        $exclude_sql = ' AND user_id != ?';
        $params[] = $exclude_user_id;
    }

    try {
        $sql = "SELECT DISTINCT user_id FROM {$storage['table']} WHERE ticket_id = ? AND user_id IS NOT NULL{$exclude_sql}";
        $rows = db_fetch_all($sql, $params);
    } catch (Throwable $e) {
        return [];
    }

    $ids = [];
    foreach ($rows as $row) {
        $user_id = (int) ($row['user_id'] ?? 0);
        if ($user_id > 0) {
            $ids[] = $user_id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Add comment to ticket
 */
function add_comment($ticket_id, $user_id, $content, $is_internal = 0) {
    $id = db_insert('comments', [
        'ticket_id' => $ticket_id,
        'user_id' => $user_id,
        'content' => $content,
        'is_internal' => $is_internal,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // Update ticket's updated_at so "Last updated" sorting works
    db_query("UPDATE tickets SET updated_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $ticket_id]);

    return $id;
}


// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Log activity
 */
function log_activity($ticket_id, $user_id, $action, $details = '') {
    return db_insert('activity_log', [
        'ticket_id' => $ticket_id,
        'user_id' => $user_id,
        'action' => $action,
        'details' => $details,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Generate ticket code from ID
 */
function get_ticket_code($id) {
    try {
        $settings = get_settings();
        $prefix = !empty($settings['ticket_prefix']) ? $settings['ticket_prefix'] : 'TK';
    } catch (Exception $e) {
        $prefix = 'TK';
    }
    return $prefix . '-' . str_pad((int)$id + 10000, 5, '0', STR_PAD_LEFT);
}

/**
 * Parse ticket code to get ID
 */
function parse_ticket_code($code) {
    if (preg_match('/^[A-Z]+-(\d+)$/', $code, $matches)) {
        return (int)$matches[1] - 10000;
    }
    return null;
}

/**
 * Get ticket type label
 */
function get_type_label($type) {
    // Try to get from database first
    $ticket_type = get_ticket_type($type);
    if ($ticket_type) {
        return $ticket_type['name'];
    }

    // Fallback for legacy types
    $types = [
        'general' => 'General request',
        'quote' => 'Quote request',
        'inquiry' => 'Inquiry',
        'bug' => 'Bug report'
    ];
    return $types[$type] ?? $type;
}

// =============================================================================
// TICKET HISTORY FUNCTIONS
// =============================================================================

/**
 * Check if ticket_history table exists
 */
function ticket_history_table_exists() {
    return table_exists('ticket_history');
}

/**
 * Create ticket_history table if it doesn't exist
 */
function ensure_ticket_history_table() {
    if (ticket_history_table_exists()) {
        return true;
    }

    try {
        db_query("
            CREATE TABLE IF NOT EXISTS ticket_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                user_id INT NOT NULL,
                field_name VARCHAR(100) NOT NULL,
                old_value TEXT,
                new_value TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ticket (ticket_id),
                INDEX idx_user (user_id),
                INDEX idx_created (created_at),
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Exception $e) {
        error_log("Failed to create ticket_history table: " . $e->getMessage());
        return false;
    }
}

/**
 * Log a ticket field change to history
 */
function log_ticket_history($ticket_id, $user_id, $field_name, $old_value, $new_value) {
    if (!ensure_ticket_history_table()) {
        return false;
    }

    // Don't log if values are the same
    if ($old_value === $new_value) {
        return true;
    }

    try {
        return db_insert('ticket_history', [
            'ticket_id' => $ticket_id,
            'user_id' => $user_id,
            'field_name' => $field_name,
            'old_value' => $old_value,
            'new_value' => $new_value,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        error_log("Failed to log ticket history: " . $e->getMessage());
        return false;
    }
}

/**
 * Get ticket history entries
 */
function get_ticket_history($ticket_id) {
    if (!ticket_history_table_exists()) {
        return [];
    }

    return db_fetch_all("
        SELECT h.*, u.first_name, u.last_name, u.email
        FROM ticket_history h
        LEFT JOIN users u ON h.user_id = u.id
        WHERE h.ticket_id = ?
        ORDER BY h.created_at DESC
    ", [$ticket_id]);
}

/**
 * Get full ticket timeline — merges creation, history, comments, time entries
 */
function get_ticket_timeline($ticket_id, $include_internal = false) {
    $events = [];
    $ticket = get_ticket($ticket_id);
    if (!$ticket) return $events;

    // 1. Ticket creation event
    $creator = get_user((int)($ticket['user_id'] ?? 0));
    $creator_name = $creator ? trim(($creator['first_name'] ?? '') . ' ' . ($creator['last_name'] ?? '')) : t('System');
    $events[] = [
        'type' => 'created',
        'timestamp' => $ticket['created_at'],
        'user_name' => $creator_name,
        'icon' => 'plus-circle',
        'color' => '#10b981',
        'label' => t('Ticket created'),
        'detail' => e($ticket['title']),
    ];

    // 2. History entries
    if (ticket_history_table_exists()) {
        $history = db_fetch_all("
            SELECT h.*, u.first_name, u.last_name
            FROM ticket_history h
            LEFT JOIN users u ON h.user_id = u.id
            WHERE h.ticket_id = ?
            ORDER BY h.created_at ASC
        ", [$ticket_id]);

        $field_icons = [
            'status_id' => ['refresh', '#3b82f6'],
            'priority_id' => ['flag', '#f59e0b'],
            'assignee_id' => ['user', '#8b5cf6'],
            'due_date' => ['clock', '#6366f1'],
            'title' => ['edit', '#6b7280'],
            'description' => ['edit', '#6b7280'],
            'type' => ['tag', '#6b7280'],
            'organization_id' => ['building', '#6b7280'],
            'tags' => ['tag', '#6b7280'],
            'timer_started' => ['play', '#10b981'],
            'timer_paused' => ['pause', '#f59e0b'],
            'timer_resumed' => ['play', '#10b981'],
            'timer_stopped' => ['clock', '#ef4444'],
            'comment_content' => ['edit', '#3b82f6'],
            'comment_deleted' => ['trash', '#ef4444'],
            'attachment_added' => ['paperclip', '#6366f1'],
            'attachment_unlinked' => ['trash', '#ef4444'],
        ];

        $timer_labels = [
            'timer_started' => t('Timer started'),
            'timer_paused' => t('Timer paused'),
            'timer_resumed' => t('Timer resumed'),
            'timer_stopped' => t('Timer stopped'),
        ];

        foreach ($history as $h) {
            $field = $h['field_name'];
            $icon_info = $field_icons[$field] ?? ['history', '#6b7280'];
            $user_name = trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''));

            if (isset($timer_labels[$field])) {
                $events[] = [
                    'type' => 'timer',
                    'timestamp' => $h['created_at'],
                    'user_name' => $user_name ?: t('System'),
                    'icon' => $icon_info[0],
                    'color' => $icon_info[1],
                    'label' => $timer_labels[$field],
                    'detail' => '',
                ];
            } else {
                $label = get_history_field_label($field);
                $old_formatted = format_history_value($field, $h['old_value']);
                $new_formatted = format_history_value($field, $h['new_value']);
                $events[] = [
                    'type' => 'change',
                    'timestamp' => $h['created_at'],
                    'user_name' => $user_name ?: t('System'),
                    'icon' => $icon_info[0],
                    'color' => $icon_info[1],
                    'label' => $label,
                    'old_value' => $old_formatted,
                    'new_value' => $new_formatted,
                ];
            }
        }
    }

    // 3. Comments
    $comment_where = $include_internal ? '' : 'AND c.is_internal = 0';
    $comments = db_fetch_all("
        SELECT c.*, u.first_name, u.last_name
        FROM comments c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.ticket_id = ? {$comment_where}
        ORDER BY c.created_at ASC
    ", [$ticket_id]);

    foreach ($comments as $c) {
        $user_name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
        $is_internal = !empty($c['is_internal']);
        $preview = mb_substr(strip_tags((string)($c['content'] ?? '')), 0, 120);
        if (mb_strlen(strip_tags((string)($c['content'] ?? ''))) > 120) $preview .= '…';

        $events[] = [
            'type' => $is_internal ? 'internal_comment' : 'comment',
            'timestamp' => $c['created_at'],
            'user_name' => $user_name ?: t('System'),
            'icon' => $is_internal ? 'lock' : 'comment',
            'color' => $is_internal ? '#f59e0b' : '#3b82f6',
            'label' => $is_internal ? t('Internal note') : t('Comment'),
            'detail' => e($preview),
        ];
    }

    // 4. Completed time entries
    if (function_exists('ticket_time_table_exists') && ticket_time_table_exists()) {
        $time_entries = db_fetch_all("
            SELECT tte.*, u.first_name, u.last_name
            FROM ticket_time_entries tte
            LEFT JOIN users u ON tte.user_id = u.id
            WHERE tte.ticket_id = ? AND tte.ended_at IS NOT NULL
            ORDER BY tte.ended_at ASC
        ", [$ticket_id]);

        foreach ($time_entries as $te) {
            $user_name = trim(($te['first_name'] ?? '') . ' ' . ($te['last_name'] ?? ''));
            $minutes = (int)($te['duration_minutes'] ?? 0);
            $hours = floor($minutes / 60);
            $mins = $minutes % 60;
            $duration_label = $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m";

            $events[] = [
                'type' => 'time_entry',
                'timestamp' => $te['ended_at'],
                'user_name' => $user_name ?: t('System'),
                'icon' => 'clock',
                'color' => '#6366f1',
                'label' => t('Time logged') . ': ' . $duration_label,
                'detail' => e($te['description'] ?? ''),
            ];
        }
    }

    // Sort chronologically
    usort($events, function($a, $b) {
        return strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? '');
    });

    return $events;
}

/**
 * Update ticket with history tracking
 */
function update_ticket_with_history($ticket_id, $data, $user_id) {
    if (function_exists('ensure_ticket_custom_billable_rate_column')) {
        ensure_ticket_custom_billable_rate_column();
    }

    // Get current ticket values
    $current = get_ticket($ticket_id);
    if (!$current) {
        return false;
    }

    // Track changes
    $tracked_fields = ['title', 'description', 'type', 'priority_id', 'status_id', 'due_date', 'assignee_id', 'organization_id'];
    if (function_exists('ensure_ticket_custom_billable_rate_column') && ensure_ticket_custom_billable_rate_column()) {
        $tracked_fields[] = 'custom_billable_rate';
    }
    if (function_exists('ticket_tags_column_exists') && ticket_tags_column_exists()) {
        $tracked_fields[] = 'tags';
    }

    foreach ($tracked_fields as $field) {
        if (isset($data[$field])) {
            $old_value = $current[$field] ?? null;
            $new_value = $data[$field];

            // Log the change
            log_ticket_history($ticket_id, $user_id, $field, $old_value, $new_value);
        }
    }

    // Update the ticket
    return db_update('tickets', $data, 'id = ?', [$ticket_id]);
}

/**
 * Check if user can edit ticket
 */
function can_edit_ticket($ticket, $user) {
    if (empty($ticket) || empty($user)) {
        return false;
    }

    // Admin can edit all tickets
    if ($user['role'] === 'admin') {
        return true;
    }

    // Agent can edit assigned tickets or tickets they can see
    if ($user['role'] === 'agent') {
        return true; // Agents can edit any ticket they can access
    }

    // Regular user can only edit their own tickets
    if ((int)$ticket['user_id'] === (int)$user['id']) {
        return true;
    }

    return false;
}

/**
 * Get field display name for history
 */
function get_history_field_label($field_name) {
    $labels = [
        'title' => t('Subject'),
        'description' => t('Description'),
        'type' => t('Ticket type'),
        'priority_id' => t('Priority'),
        'status_id' => t('Status'),
        'due_date' => t('Due date'),
        'assignee_id' => t('Assignee'),
        'organization_id' => t('Company'),
        'custom_billable_rate' => t('Custom billable rate'),
        'tags' => t('Tags'),
        'comment_content' => t('Comment'),
        'comment_deleted' => t('Comment'),
        'attachment_added' => t('Attachments'),
        'attachment_unlinked' => t('Attachments'),
        'timer_started' => t('Timer'),
        'timer_paused' => t('Timer'),
        'timer_resumed' => t('Timer'),
        'timer_stopped' => t('Timer')
    ];
    return $labels[$field_name] ?? $field_name;
}

/**
 * Format history value for display
 */
function format_history_value($field_name, $value) {
    if ($value === null || $value === '') {
        return '<em class="text-gray-400">' . t('(empty)') . '</em>';
    }

    switch ($field_name) {
        case 'priority_id':
            $priority = get_priority((int)$value);
            return $priority ? e($priority['name']) : $value;

        case 'status_id':
            $status = get_status((int)$value);
            return $status ? e($status['name']) : $value;

        case 'type':
            return e(get_type_label($value));

        case 'due_date':
            return format_date($value);

        case 'assignee_id':
            $assignee = get_user((int)$value);
            if ($assignee) {
                return e(trim(($assignee['first_name'] ?? '') . ' ' . ($assignee['last_name'] ?? '')));
            }
            return '<em class="text-gray-400">' . t('(empty)') . '</em>';

        case 'organization_id':
            $organization = get_organization((int) $value);
            return $organization ? e((string) ($organization['name'] ?? '')) : '<em class="text-gray-400">' . t('(empty)') . '</em>';

        case 'custom_billable_rate':
            if ($value === null || $value === '') {
                return '<em class="text-gray-400">' . t('(empty)') . '</em>';
            }
            return format_money((float) $value);

        case 'tags':
            $tags = get_ticket_tags_array($value);
            return !empty($tags) ? e(implode(', ', $tags)) : '<em class="text-gray-400">' . t('(empty)') . '</em>';

        case 'description':
            return nl2br(e(strip_tags((string) $value)));

        case 'comment_content':
        case 'comment_deleted':
            return nl2br(e((string) $value));

        case 'attachment_added':
        case 'attachment_unlinked':
            return e((string) $value);

        case 'timer_started':
        case 'timer_paused':
        case 'timer_resumed':
        case 'timer_stopped':
            return $value ? format_date($value, 'H:i') : '';

        default:
            return e($value);
    }
}
