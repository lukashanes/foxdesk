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

function ticket_time_work_metadata_columns_exist(): bool
{
    return ticket_time_table_exists()
        && column_exists('ticket_time_entries', 'worked_on')
        && column_exists('ticket_time_entries', 'time_precision');
}

function ticket_time_entry_precision(array $entry): string
{
    $precision = strtolower(trim((string) ($entry['time_precision'] ?? 'exact')));
    return in_array($precision, ['exact', 'duration_only', 'allocated'], true) ? $precision : 'exact';
}

function ticket_time_entry_display_date(array $entry): string
{
    $worked_on = trim((string) ($entry['worked_on'] ?? ''));
    if (ticket_time_entry_precision($entry) !== 'exact' && $worked_on !== '') {
        return function_exists('format_date') ? format_date($worked_on, 'd.m.Y') : $worked_on;
    }
    $started_at = trim((string) ($entry['started_at'] ?? ''));
    return $started_at !== '' && function_exists('format_date') ? format_date($started_at) : $started_at;
}

function ticket_time_entry_public_timestamps(array $entry): array
{
    if (ticket_time_entry_precision($entry) !== 'exact') {
        return ['started_at' => null, 'ended_at' => null];
    }
    return [
        'started_at' => $entry['started_at'] ?? null,
        'ended_at' => $entry['ended_at'] ?? null,
    ];
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

function ensure_user_billable_rate_column(): bool {
    static $checked = false;
    static $exists = false;

    if ($checked) {
        return $exists;
    }

    $checked = true;
    $exists = column_exists('users', 'billable_rate');
    return $exists;
}

function get_user_default_billable_rate(int $user_id): ?float {
    if ($user_id <= 0 || !ensure_user_billable_rate_column()) {
        return null;
    }

    $row = db_fetch_one("SELECT billable_rate FROM users WHERE id = ?", [$user_id]);
    $rate = parse_optional_rate_value($row['billable_rate'] ?? null);
    return $rate !== null && $rate > 0 ? $rate : null;
}

function get_agent_default_billable_rates(): array {
    if (!ensure_user_billable_rate_column()) {
        return [];
    }

    return db_fetch_all(
        "SELECT id, first_name, last_name, email, billable_rate
         FROM users
         WHERE role IN ('agent', 'admin') AND is_active = 1
         ORDER BY first_name, last_name, email"
    );
}

function save_agent_default_billable_rate(int $user_id, float $billable_rate): bool {
    if ($user_id <= 0 || !ensure_user_billable_rate_column()) {
        return false;
    }

    db_update('users', ['billable_rate' => max(0, $billable_rate)], 'id = ?', [$user_id]);
    return true;
}

function ensure_agent_client_billable_rates_table(): bool {
    static $checked = false;
    static $exists = false;

    if ($checked) {
        return $exists;
    }

    $checked = true;
    $exists = table_exists('agent_client_billable_rates');
    return $exists;
}

function get_agent_client_billable_rates(): array {
    if (!ensure_agent_client_billable_rates_table()) {
        return [];
    }

    return db_fetch_all(
        "SELECT acr.*, o.name AS organization_name, u.first_name, u.last_name, u.email
         FROM agent_client_billable_rates acr
         JOIN organizations o ON acr.organization_id = o.id
         JOIN users u ON acr.user_id = u.id
         ORDER BY o.name, u.first_name, u.last_name, u.email"
    );
}

function get_agent_client_billable_rate(int $organization_id, int $user_id): ?float {
    if ($organization_id <= 0 || $user_id <= 0 || !ensure_agent_client_billable_rates_table()) {
        return null;
    }

    $row = db_fetch_one(
        "SELECT billable_rate
         FROM agent_client_billable_rates
         WHERE organization_id = ? AND user_id = ? AND is_active = 1
         LIMIT 1",
        [$organization_id, $user_id]
    );

    if (!$row || $row['billable_rate'] === null || $row['billable_rate'] === '') {
        return null;
    }

    return max(0, (float) $row['billable_rate']);
}

function save_agent_client_billable_rate(int $organization_id, int $user_id, float $billable_rate, string $notes = ''): bool {
    if ($organization_id <= 0 || $user_id <= 0 || !ensure_agent_client_billable_rates_table()) {
        return false;
    }

    db_query(
        "INSERT INTO agent_client_billable_rates
            (organization_id, user_id, billable_rate, notes, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            billable_rate = VALUES(billable_rate),
            notes = VALUES(notes),
            is_active = 1,
            updated_at = NOW()",
        [$organization_id, $user_id, max(0, $billable_rate), trim($notes)]
    );

    return true;
}

function delete_agent_client_billable_rate(int $rate_id): bool {
    if ($rate_id <= 0 || !ensure_agent_client_billable_rates_table()) {
        return false;
    }

    db_query("DELETE FROM agent_client_billable_rates WHERE id = ?", [$rate_id]);
    return true;
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
function get_ticket_effective_billable_rate($ticket_or_id, ?int $user_id = null): float {
    $custom_rate = get_ticket_custom_billable_rate($ticket_or_id);
    if ($custom_rate !== null) {
        return $custom_rate;
    }

    $organization_id = 0;
    if (is_array($ticket_or_id)) {
        $ticket_id = (int) ($ticket_or_id['id'] ?? 0);
        $organization_id = (int) ($ticket_or_id['organization_id'] ?? 0);
        if ($ticket_id <= 0) {
            if ($organization_id > 0) {
                if ($user_id !== null) {
                    $agent_rate = get_agent_client_billable_rate($organization_id, $user_id);
                    if ($agent_rate !== null) {
                        return $agent_rate;
                    }
                    $user_rate = get_user_default_billable_rate($user_id);
                    if ($user_rate !== null) {
                        return $user_rate;
                    }
                }
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
        "SELECT t.organization_id, o.billable_rate
         FROM tickets t
         LEFT JOIN organizations o ON t.organization_id = o.id
         WHERE t.id = ?",
        [$ticket_id]
    );

    $organization_id = (int) ($row['organization_id'] ?? $organization_id);
    if ($organization_id > 0 && $user_id !== null) {
        $agent_rate = get_agent_client_billable_rate($organization_id, $user_id);
        if ($agent_rate !== null) {
            return $agent_rate;
        }
    }

    if ($user_id !== null) {
        $user_rate = get_user_default_billable_rate($user_id);
        if ($user_rate !== null) {
            return $user_rate;
        }
    }

    return (float) ($row['billable_rate'] ?? 0);
}

function get_time_entry_effective_billable_rate(array $entry, array $template = []): float {
    $template_rate = $template['custom_billable_rate'] ?? null;
    if ($template_rate !== null && trim((string) $template_rate) !== '') {
        return max(0, (float) str_replace(',', '.', trim((string) $template_rate)));
    }

    $stored_rate = isset($entry['billable_rate']) ? (float) $entry['billable_rate'] : 0.0;
    if ($stored_rate > 0) {
        return $stored_rate;
    }

    $ticket_custom_billable_rate = $entry['ticket_custom_billable_rate'] ?? null;
    if ($ticket_custom_billable_rate !== null && trim((string) $ticket_custom_billable_rate) !== '') {
        return max(0, (float) $ticket_custom_billable_rate);
    }

    $organization_id = (int) ($entry['organization_id'] ?? 0);
    $user_id = (int) ($entry['user_id'] ?? 0);
    if ($organization_id > 0 && $user_id > 0) {
        $agent_rate = get_agent_client_billable_rate($organization_id, $user_id);
        if ($agent_rate !== null) {
            return $agent_rate;
        }
    }

    $entry_user_rate = parse_optional_rate_value($entry['user_billable_rate'] ?? null);
    if ($entry_user_rate !== null && $entry_user_rate > 0) {
        return $entry_user_rate;
    }

    if ($user_id > 0) {
        $user_rate = get_user_default_billable_rate($user_id);
        if ($user_rate !== null) {
            return $user_rate;
        }
    }

    return (float) ($entry['org_billable_rate'] ?? 0);
}

/**
 * Sync stored billable rates on time entries to the ticket's current effective rate.
 */
function sync_ticket_time_entry_billable_rates(int $ticket_id): bool {
    if ($ticket_id <= 0 || !ticket_time_table_exists()) {
        return false;
    }

    try {
        $entries = db_fetch_all("SELECT id, user_id FROM ticket_time_entries WHERE ticket_id = ? AND is_billable = 1", [$ticket_id]);
        foreach ($entries as $entry) {
            db_update('ticket_time_entries', [
                'billable_rate' => get_ticket_effective_billable_rate($ticket_id, (int) $entry['user_id'])
            ], 'id = ?', [(int) $entry['id']]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get time entries for a ticket
 */
function get_ticket_time_entries($ticket_id, bool $strict = false) {
    if (!ticket_time_table_exists()) {
        return [];
    }
    try {
        $order_by = ticket_time_work_metadata_columns_exist()
            ? 'COALESCE(tte.worked_on, DATE(tte.started_at)) DESC, tte.started_at DESC'
            : 'tte.started_at DESC';
        return db_fetch_all("SELECT tte.*, u.first_name, u.last_name, u.email
                             FROM ticket_time_entries tte
                             LEFT JOIN users u ON tte.user_id = u.id
                             WHERE tte.ticket_id = ?
                             ORDER BY {$order_by}", [$ticket_id]);
    } catch (Throwable $e) {
        if ($strict) {
            throw new RuntimeException('Unable to read ticket time entries.', 0, $e);
        }
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
 * Stop the current user's active timer on a ticket and persist the elapsed time.
 */
function stop_active_ticket_timer($ticket_id, $user_id, ?int $comment_id = null)
{
    if (!ticket_time_table_exists()) {
        return null;
    }

    $active = get_active_ticket_timer($ticket_id, $user_id);
    if (!$active) {
        return null;
    }

    $elapsed_seconds = function_exists('calculate_timer_elapsed')
        ? calculate_timer_elapsed($active)
        : max(0, time() - strtotime((string) ($active['started_at'] ?? 'now')));
    $duration = max(0, (int) floor($elapsed_seconds / 60));

    $update = [
        'ended_at' => date('Y-m-d H:i:s'),
        'duration_minutes' => $duration,
    ];
    if ($comment_id !== null) {
        $update['comment_id'] = $comment_id;
    }
    if (function_exists('timer_pause_columns_exist') && timer_pause_columns_exist()) {
        $update['paused_at'] = null;
    }

    db_update('ticket_time_entries', $update, 'id = ?', [(int) $active['id']]);
    log_activity($ticket_id, $user_id, 'time_stopped', "Timer stopped ({$duration} min)");
    notify_saved_time_entry((int) $active['id'], (int) $user_id);

    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user_id, 'timer_stopped', null, date('Y-m-d H:i:s'));
    }

    return [
        'entry_id' => (int) $active['id'],
        'duration_minutes' => $duration,
    ];
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

        if (!empty($data['comment_id']) && column_exists('ticket_time_entries', 'comment_id')) {
            $linked_comment = db_fetch_one('SELECT id FROM comments WHERE id = ? AND ticket_id = ?', [(int) $data['comment_id'], (int) $ticket_id]);
            if (!$linked_comment) return false;
            $insert['comment_id'] = (int) $linked_comment['id'];
        }
        if (ticket_time_work_metadata_columns_exist()) {
            $worked_on = trim((string) ($data['worked_on'] ?? ''));
            if ($worked_on === '' && !empty($data['started_at'])) {
                $worked_on = date('Y-m-d', strtotime((string) $data['started_at']));
            }
            $insert['worked_on'] = $worked_on !== '' ? $worked_on : null;
            $insert['time_precision'] = ticket_time_entry_precision($data);
        }

        // Set source column if it exists
        if (time_entry_source_column_exists()) {
            $insert['source'] = $source;
        }

        // Apply billable_rate: explicit > ticket override > agent/client rate > org rate
        if (array_key_exists('billable_rate', $data) && $data['billable_rate'] !== null && $data['billable_rate'] !== '') {
            $insert['billable_rate'] = max(0, (float) $data['billable_rate']);
        } else {
            $insert['billable_rate'] = get_ticket_effective_billable_rate($ticket_id, (int) $user_id);
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

        $entry_id = db_insert('ticket_time_entries', $insert);
        notify_saved_time_entry((int) $entry_id, (int) $user_id);
        return $entry_id;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if `source` column exists on ticket_time_entries
 */
/**
 * Persist an in-app review item in the same transaction as saved work.
 * No email/push is sent here: a surrounding transaction may not have committed yet.
 * Only participating staff receive details; visibility and preferences still apply.
 */
function notify_saved_time_entry(int $entry_id, int $actor_id): void {
    if ($entry_id <= 0 || !function_exists('create_notifications_for_users')) return;
    try {
        $entry = db_fetch_one('SELECT id, ticket_id, duration_minutes, ended_at FROM ticket_time_entries WHERE id = ?', [$entry_id]);
        if (!$entry || empty($entry['ended_at']) || (int) $entry['duration_minutes'] <= 0) return;
        $ticket = get_ticket((int) $entry['ticket_id']);
        if (!$ticket) return;
        $recipients = array_values(array_intersect(
            get_ticket_participants((int) $ticket['id'], $actor_id), get_staff_user_ids($actor_id)
        ));
        if (!$recipients) return;
        $actor = get_user($actor_id);
        create_notifications_for_users($recipients, 'ticket_updated', (int) $ticket['id'], $actor_id, [
            'ticket_id' => (int) $ticket['id'], 'ticket_subject' => $ticket['title'],
            'actor_name' => trim(($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? '')),
            'field' => 'time_entry', 'time_entry_id' => $entry_id,
            'detail' => format_duration_minutes((int) $entry['duration_minutes']),
            'action_required' => true,
        ], false);
    } catch (Throwable $error) {
        error_log('Work-log review notification could not be stored.');
    }
}

/** Display a saved interval without inventing times for duration-only records. */
function ticket_time_entry_display_range(array $entry): string
{
    $date = (string) ($entry['worked_on'] ?? $entry['started_at'] ?? '');
    if (ticket_time_entry_precision($entry) !== 'exact') {
        return format_date($date, 'd.m.Y') . ' · ' . t('ticket.workspace.no_exact_time');
    }
    $start = (string) ($entry['started_at'] ?? '');
    if ($start === '') return '—';
    $end = (string) ($entry['ended_at'] ?? '');
    $end_label = $end === '' ? t(empty($entry['paused_at']) ? 'Running' : 'Paused')
        : format_date($end, substr($start, 0, 10) === substr($end, 0, 10) ? 'H:i' : 'd.m.Y H:i');
    return format_date($start, 'd.m.Y') . ' · ' . format_date($start, 'H:i') . ' – ' . $end_label;
}

function time_entry_source_column_exists() {
    return column_exists('ticket_time_entries', 'source');
}

/**
 * Get total time logged on a ticket in minutes
 */
function get_ticket_time_total($ticket_id, bool $strict = false) {
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
    } catch (Throwable $e) {
        if ($strict) {
            throw new RuntimeException('Unable to read ticket time total.', 0, $e);
        }
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

/** Compatibility check retained for callers; schema changes run in migrations. */
function migrate_timer_pause_columns() {
    return ticket_time_table_exists()
        && column_exists('ticket_time_entries', 'paused_at')
        && column_exists('ticket_time_entries', 'paused_seconds');
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
function get_ticket_time_breakdown($ticket_id, bool $strict = false) {
    if (!ticket_time_table_exists()) {
        return ['total' => 0, 'human' => 0, 'ai' => 0];
    }

    $ai_ids = get_ai_user_ids();

    // No AI agents exist — shortcut
    if (empty($ai_ids)) {
        $total = get_ticket_time_total($ticket_id, $strict);
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
    } catch (Throwable $e) {
        if ($strict) {
            throw new RuntimeException('Unable to read ticket time breakdown.', 0, $e);
        }
        return ['total' => 0, 'human' => 0, 'ai' => 0];
    }
}
