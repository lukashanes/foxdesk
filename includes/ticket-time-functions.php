<?php
/**
 * Ticket Time Tracking Functions
 *
 * Functions for managing time entries and tracking on tickets.
 */

/**
 * Check if the ticket_time_entries table exists
 */
function ticket_time_table_exists() {
    return table_exists('ticket_time_entries');
}

/**
 * Ensure the tickets table supports a custom per-ticket billable rate override.
 */
function ensure_ticket_custom_billable_rate_column(): bool {
    static $checked = false;
    static $exists = false;

    if ($checked) {
        return $exists;
    }

    $checked = true;
    $exists = column_exists('tickets', 'custom_billable_rate');
    if ($exists) {
        return true;
    }

    try {
        db_query("ALTER TABLE tickets ADD COLUMN custom_billable_rate DECIMAL(10,2) NULL DEFAULT NULL AFTER due_date");
    } catch (Throwable $e) {
        // Ignore duplicate/unsupported migrations and fall back to runtime detection below.
    }

    $exists = column_exists('tickets', 'custom_billable_rate');
    return $exists;
}

/**
 * Parse an optional money/rate input. Empty string becomes null.
 */
function parse_optional_rate_value($value): ?float {
    if ($value === null) {
        return null;
    }

    $normalized = str_replace(',', '.', trim((string) $value));
    if ($normalized === '') {
        return null;
    }

    return max(0, (float) $normalized);
}

/**
 * Return the ticket-level custom billable rate override, if any.
 *
 * @param int|array $ticket_or_id
 */
function get_ticket_custom_billable_rate($ticket_or_id): ?float {
    if (!ensure_ticket_custom_billable_rate_column()) {
        return null;
    }

    if (is_array($ticket_or_id)) {
        if (array_key_exists('custom_billable_rate', $ticket_or_id)) {
            return parse_optional_rate_value($ticket_or_id['custom_billable_rate']);
        }
        $ticket_id = (int) ($ticket_or_id['id'] ?? 0);
    } else {
        $ticket_id = (int) $ticket_or_id;
    }

    if ($ticket_id <= 0) {
        return null;
    }

    $row = db_fetch_one("SELECT custom_billable_rate FROM tickets WHERE id = ?", [$ticket_id]);
    return parse_optional_rate_value($row['custom_billable_rate'] ?? null);
}

/**
 * Get the effective billable rate for a ticket.
 *
 * @param int|array $ticket_or_id
 */
function get_ticket_effective_billable_rate($ticket_or_id): float {
    $custom_rate = get_ticket_custom_billable_rate($ticket_or_id);
    if ($custom_rate !== null) {
        return $custom_rate;
    }

    if (is_array($ticket_or_id)) {
        $ticket_id = (int) ($ticket_or_id['id'] ?? 0);
        if ($ticket_id <= 0) {
            $organization_id = (int) ($ticket_or_id['organization_id'] ?? 0);
            if ($organization_id > 0) {
                $org = get_organization($organization_id);
                return (float) ($org['billable_rate'] ?? 0);
            }
            return 0.0;
        }
    } else {
        $ticket_id = (int) $ticket_or_id;
    }

    if ($ticket_id <= 0) {
        return 0.0;
    }

    $row = db_fetch_one(
        "SELECT o.billable_rate
         FROM tickets t
         LEFT JOIN organizations o ON t.organization_id = o.id
         WHERE t.id = ?",
        [$ticket_id]
    );

    return (float) ($row['billable_rate'] ?? 0);
}

/**
 * Sync stored billable rates on time entries to the ticket's current effective rate.
 */
function sync_ticket_time_entry_billable_rates(int $ticket_id): bool {
    if ($ticket_id <= 0 || !ticket_time_table_exists()) {
        return false;
    }

    $rate = get_ticket_effective_billable_rate($ticket_id);

    try {
        db_query(
            "UPDATE ticket_time_entries
             SET billable_rate = ?
             WHERE ticket_id = ? AND is_billable = 1",
            [$rate, $ticket_id]
        );
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get time entries for a ticket
 */
function get_ticket_time_entries($ticket_id) {
    if (!ticket_time_table_exists()) {
        return [];
    }
    try {
        return db_fetch_all("SELECT tte.*, u.first_name, u.last_name, u.email
                             FROM ticket_time_entries tte
                             LEFT JOIN users u ON tte.user_id = u.id
                             WHERE tte.ticket_id = ?
                             ORDER BY tte.started_at DESC", [$ticket_id]);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get active timer for a user on a ticket
 */
function get_active_ticket_timer($ticket_id, $user_id) {
    if (!ticket_time_table_exists()) {
        return null;
    }
    try {
        return db_fetch_one("SELECT * FROM ticket_time_entries
                             WHERE ticket_id = ? AND user_id = ? AND ended_at IS NULL
                             ORDER BY started_at DESC LIMIT 1", [$ticket_id, $user_id]);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Add manual time entry
 *
 * @param int $ticket_id
 * @param int $user_id
 * @param array $data  Accepts: started_at, ended_at, duration_minutes, summary,
 *                     is_billable, source ('timer'|'manual'|'ai'), billable_rate, cost_rate
 */
function add_manual_time_entry($ticket_id, $user_id, $data) {
    if (!ticket_time_table_exists()) {
        return false;
    }

    try {
        $source = $data['source'] ?? 'manual';
        $insert = [
            'ticket_id' => $ticket_id,
            'user_id' => $user_id,
            'started_at' => $data['started_at'],
            'ended_at' => $data['ended_at'],
            'duration_minutes' => $data['duration_minutes'],
            'summary' => $data['summary'] ?? null,
            'is_billable' => $data['is_billable'] ?? 1,
            'is_manual' => ($source === 'timer') ? 0 : 1,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Set source column if it exists
        if (time_entry_source_column_exists()) {
            $insert['source'] = $source;
        }

        // Apply billable_rate: explicit > ticket override > org rate
        if (array_key_exists('billable_rate', $data) && $data['billable_rate'] !== null && $data['billable_rate'] !== '') {
            $insert['billable_rate'] = max(0, (float) $data['billable_rate']);
        } else {
            $insert['billable_rate'] = get_ticket_effective_billable_rate($ticket_id);
        }

        // Apply cost_rate: explicit > user's cost_rate > 0
        if (isset($data['cost_rate']) && (float) $data['cost_rate'] > 0) {
            $insert['cost_rate'] = (float) $data['cost_rate'];
        } else {
            // Auto-lookup from user profile
            try {
                $user_row = db_fetch_one("SELECT cost_rate FROM users WHERE id = ?", [$user_id]);
                if ($user_row && (float) ($user_row['cost_rate'] ?? 0) > 0) {
                    $insert['cost_rate'] = (float) $user_row['cost_rate'];
                }
            } catch (Exception $e) {
                // ignore
            }
        }

        return db_insert('ticket_time_entries', $insert);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if `source` column exists on ticket_time_entries
 */
function time_entry_source_column_exists() {
    return column_exists('ticket_time_entries', 'source');
}

/**
 * Get total time logged on a ticket in minutes
 */
function get_ticket_time_total($ticket_id) {
    if (!ticket_time_table_exists()) {
        return 0;
    }
    try {
        $dur = sql_timer_duration_minutes();
        $row = db_fetch_one(
            "SELECT SUM({$dur}) as total FROM ticket_time_entries WHERE ticket_id = ?",
            [$ticket_id]
        );
        return (int)($row['total'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}


/**
 * Update a time entry
 */
function update_time_entry($entry_id, $data) {
    if (!ticket_time_table_exists()) {
        return false;
    }
    try {
        // db_update returns rowCount which can be 0 even on success (no changes)
        // We need to verify the entry exists and run the update
        $entry = db_fetch_one("SELECT id FROM ticket_time_entries WHERE id = ?", [$entry_id]);
        if (!$entry) {
            return false;
        }
        db_update('ticket_time_entries', $data, 'id = ?', [$entry_id]);
        return true; // Query executed successfully
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Delete a time entry
 */
function delete_time_entry($entry_id) {
    if (!ticket_time_table_exists()) {
        return false;
    }
    try {
        return db_delete('ticket_time_entries', 'id = ?', [$entry_id]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if paused_at column exists (for migrations)
 */
function timer_pause_columns_exist() {
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $columns = db_fetch_all("SHOW COLUMNS FROM ticket_time_entries LIKE 'paused_at'");
        $exists = !empty($columns);
    } catch (Exception $e) {
        $exists = false;
    }
    return $exists;
}

/**
 * Migrate database to add pause columns
 */
function migrate_timer_pause_columns() {
    if (!ticket_time_table_exists()) {
        return false;
    }
    if (timer_pause_columns_exist()) {
        return true; // Already migrated
    }
    try {
        $db = get_db();
        $db->exec("ALTER TABLE ticket_time_entries ADD COLUMN paused_at DATETIME DEFAULT NULL AFTER ended_at");
        $db->exec("ALTER TABLE ticket_time_entries ADD COLUMN paused_seconds INT DEFAULT 0 AFTER paused_at");
        return true;
    } catch (Exception $e) {
        error_log("Failed to add pause columns: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all active/paused timers for a user (for dashboard)
 */
function get_user_all_active_timers($user_id) {
    if (!ticket_time_table_exists()) {
        return [];
    }
    try {
        return db_fetch_all("SELECT tte.*, t.title as ticket_title, t.id as ticket_id, t.hash as ticket_hash
                             FROM ticket_time_entries tte
                             LEFT JOIN tickets t ON tte.ticket_id = t.id
                             WHERE tte.user_id = ? AND tte.ended_at IS NULL
                             ORDER BY tte.started_at DESC", [$user_id]);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Check if timer is paused
 */
function is_timer_paused($timer) {
    if (!$timer) return false;
    // Timer is paused if paused_at is set and ended_at is null
    return !empty($timer['paused_at']) && empty($timer['ended_at']);
}

/**
 * Calculate elapsed time for a timer (accounting for pauses)
 */
function calculate_timer_elapsed($timer) {
    if (!$timer) return 0;

    $started_ts = strtotime($timer['started_at'] ?? '');
    if ($started_ts === false) return 0;

    $paused_seconds = (int)($timer['paused_seconds'] ?? 0);

    if (is_timer_paused($timer)) {
        // Timer is paused - calculate time up to pause point
        $paused_ts = strtotime($timer['paused_at'] ?? '');
        if ($paused_ts === false) return 0;
        return max(0, $paused_ts - $started_ts - $paused_seconds);
    } else if (empty($timer['ended_at'])) {
        // Timer is running - calculate current elapsed
        return max(0, time() - $started_ts - $paused_seconds);
    } else {
        // Timer is stopped - use stored duration
        return (int)($timer['duration_minutes'] ?? 0) * 60;
    }
}

/**
 * Return a SQL expression that calculates timer duration in minutes,
 * correctly handling paused and running timers.
 * Use this everywhere instead of inline TIMESTAMPDIFF patterns.
 *
 * @param string $prefix Table alias prefix (e.g. 'tte.') — include the dot
 */
function sql_timer_duration_minutes(string $prefix = ''): string
{
    $p = $prefix;
    if (timer_pause_columns_exist()) {
        return "CASE
            WHEN {$p}ended_at IS NOT NULL THEN {$p}duration_minutes
            WHEN {$p}paused_at IS NOT NULL THEN
                GREATEST(0, FLOOR((TIMESTAMPDIFF(SECOND, {$p}started_at, {$p}paused_at) - IFNULL({$p}paused_seconds, 0)) / 60))
            ELSE
                GREATEST(0, FLOOR((TIMESTAMPDIFF(SECOND, {$p}started_at, NOW()) - IFNULL({$p}paused_seconds, 0)) / 60))
            END";
    }
    return "CASE WHEN {$p}ended_at IS NULL THEN TIMESTAMPDIFF(MINUTE, {$p}started_at, NOW()) ELSE {$p}duration_minutes END";
}

/**
 * Pause a running timer
 */
function pause_ticket_timer($ticket_id, $user_id) {
    if (!ticket_time_table_exists()) {
        return ['success' => false, 'error' => t('Time tracking not available')];
    }

    // Ensure pause columns exist
    migrate_timer_pause_columns();

    $timer = get_active_ticket_timer($ticket_id, $user_id);
    if (!$timer) {
        return ['success' => false, 'error' => t('No active timer found')];
    }

    if (is_timer_paused($timer)) {
        return ['success' => false, 'error' => t('Timer is already paused')];
    }

    try {
        db_update('ticket_time_entries', [
            'paused_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$timer['id']]);

        $elapsed = calculate_timer_elapsed($timer);

        return [
            'success' => true,
            'entry_id' => $timer['id'],
            'elapsed_seconds' => $elapsed,
            'message' => t('Timer paused.')
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => t('Failed to pause timer')];
    }
}

/**
 * Resume a paused timer
 */
function resume_ticket_timer($ticket_id, $user_id) {
    if (!ticket_time_table_exists()) {
        return ['success' => false, 'error' => t('Time tracking not available')];
    }

    $timer = get_active_ticket_timer($ticket_id, $user_id);
    if (!$timer) {
        return ['success' => false, 'error' => t('No active timer found')];
    }

    if (!is_timer_paused($timer)) {
        return ['success' => false, 'error' => t('Timer is not paused')];
    }

    try {
        // Calculate how long it was paused and add to paused_seconds
        $paused_at_ts = strtotime($timer['paused_at'] ?? '');
        if ($paused_at_ts === false) {
            return ['success' => false, 'error' => t('Invalid pause timestamp')];
        }
        $paused_duration = time() - $paused_at_ts;
        $total_paused = (int)($timer['paused_seconds'] ?? 0) + $paused_duration;

        db_update('ticket_time_entries', [
            'paused_at' => null,
            'paused_seconds' => $total_paused
        ], 'id = ?', [$timer['id']]);

        return [
            'success' => true,
            'entry_id' => $timer['id'],
            'paused_seconds' => $total_paused,
            'message' => t('Timer resumed.')
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => t('Failed to resume timer')];
    }
}

/**
 * Discard (delete) a running timer without logging time
 */
function discard_ticket_timer($ticket_id, $user_id) {
    if (!ticket_time_table_exists()) {
        return ['success' => false, 'error' => t('Time tracking not available')];
    }

    $timer = get_active_ticket_timer($ticket_id, $user_id);
    if (!$timer) {
        return ['success' => false, 'error' => t('No active timer found')];
    }

    try {
        db_delete('ticket_time_entries', 'id = ?', [$timer['id']]);

        return [
            'success' => true,
            'message' => t('Timer discarded.')
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => t('Failed to discard timer')];
    }
}

/**
 * Get the display source for a time entry.
 * Falls back to is_manual when source column doesn't exist yet.
 *
 * @param array $entry  Time entry row
 * @return string  'timer' | 'manual' | 'ai'
 */
function get_time_entry_source($entry) {
    if (!empty($entry['source'])) {
        return $entry['source'];
    }
    // Fallback for pre-migration entries
    return !empty($entry['is_manual']) ? 'manual' : 'timer';
}

/**
 * Render an HTML badge for time entry source.
 *
 * @param string $source  'timer' | 'manual' | 'ai'
 * @return string  HTML badge
 */
function render_source_badge($source) {
    switch ($source) {
        case 'ai':
            return '<span class="text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded uppercase whitespace-nowrap flex-shrink-0">' . e(t('AI')) . '</span>';
        case 'manual':
            return '<span class="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded uppercase whitespace-nowrap flex-shrink-0">' . e(t('Manual')) . '</span>';
        case 'timer':
        default:
            return '<span class="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded uppercase whitespace-nowrap flex-shrink-0">' . e(t('Timer')) . '</span>';
    }
}

// =============================================================================
// AI Agent Helpers
// =============================================================================

/**
 * Check if `is_ai_agent` column exists on users table (migration safety).
 */
function ai_agent_column_exists() {
    return column_exists('users', 'is_ai_agent');
}

/**
 * Get all AI agent user IDs (cached).
 *
 * @return int[]
 */
function get_ai_user_ids() {
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    if (!ai_agent_column_exists()) {
        $ids = [];
        return $ids;
    }
    try {
        $rows = db_fetch_all("SELECT id FROM users WHERE is_ai_agent = 1");
        $ids = array_map('intval', array_column($rows, 'id'));
    } catch (Exception $e) {
        $ids = [];
    }
    return $ids;
}

/**
 * Check if a user_id belongs to an AI agent.
 *
 * @param int $user_id
 * @return bool
 */
function is_ai_user($user_id) {
    return in_array((int) $user_id, get_ai_user_ids(), true);
}

/**
 * Get time breakdown (total / human / AI) for a ticket.
 *
 * @param int $ticket_id
 * @return array ['total' => int, 'human' => int, 'ai' => int] minutes
 */
function get_ticket_time_breakdown($ticket_id) {
    if (!ticket_time_table_exists()) {
        return ['total' => 0, 'human' => 0, 'ai' => 0];
    }

    $ai_ids = get_ai_user_ids();

    // No AI agents exist — shortcut
    if (empty($ai_ids)) {
        $total = get_ticket_time_total($ticket_id);
        return ['total' => $total, 'human' => $total, 'ai' => 0];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($ai_ids), '?'));
        $dur = sql_timer_duration_minutes();
        $sql = "SELECT
                    SUM({$dur}) as total,
                    SUM(CASE WHEN user_id IN ($placeholders) THEN ({$dur}) ELSE 0 END) as ai
                FROM ticket_time_entries WHERE ticket_id = ?";
        $params = array_merge($ai_ids, [$ticket_id]);
        $row = db_fetch_one($sql, $params);

        $total = (int) ($row['total'] ?? 0);
        $ai = (int) ($row['ai'] ?? 0);
        return ['total' => $total, 'human' => $total - $ai, 'ai' => $ai];
    } catch (Exception $e) {
        return ['total' => 0, 'human' => 0, 'ai' => 0];
    }
}
