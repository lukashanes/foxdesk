<?php
/**
 * API Handler: Ticket Operations
 *
 * Handles ticket-related API actions like status changes.
 */

/**
 * Handle change ticket status
 *
 * Security: Uses can_see_ticket() for consistent permission checking
 * across the application. Only users who can view the ticket can
 * change its status.
 */
function api_change_status() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $status_id = (int)($_POST['status_id'] ?? 0);

    $ticket = get_ticket($ticket_id);
    $new_status = get_status($status_id);

    if (!$ticket || !$new_status) {
        api_error('Not found', 404);
    }

    // Check permission using centralized permission function
    $user = current_user();

    if (!$user) {
        api_error('Unauthorized', 401);
    }

    // Use can_see_ticket for consistent permission checking
    // Admin and agents with appropriate scope can change status
    // Ticket owner can also change status on their own tickets
    if (!can_see_ticket($ticket, $user)) {
        // Log security event for audit trail
        if (function_exists('log_security_event')) {
            log_security_event('status_change_denied', $user['id'], json_encode([
                'ticket_id' => $ticket_id,
                'attempted_status' => $status_id
            ]));
        }
        api_error('Forbidden', 403);
    }

    $old_status = get_status($ticket['status_id']);

    db_update('tickets', ['status_id' => $status_id], 'id = ?', [$ticket_id]);
    log_activity(
        $ticket_id,
        $user['id'],
        'status_changed',
        "Status changed from '{$old_status['name']}' to '{$new_status['name']}'"
    );

    // Send notification
    require_once BASE_PATH . '/includes/mailer.php';
    send_status_change_notification($ticket, $old_status, $new_status);

    // In-app notification for status change
    if (function_exists('dispatch_ticket_notifications')) {
        dispatch_ticket_notifications('status_changed', $ticket_id, $user['id'], [
            'old_status' => $old_status['name'] ?? '',
            'new_status' => $new_status['name'] ?? '',
        ]);
    }

    api_success(['status' => $new_status]);
}

/**
 * Start timer for a ticket (AJAX)
 */
function api_start_timer() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);

    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    if (!can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    if (!ticket_time_table_exists()) {
        api_error(t('Time tracking is not available.'), 400);
    }

    // Check if timer already running
    $active = get_active_ticket_timer($ticket_id, $user['id']);
    if ($active) {
        api_error(t('Timer is already running.'), 400);
    }

    // Get billing rates
    $org_billable_rate = 0.0;
    if (!empty($ticket['organization_id'])) {
        $org = get_organization($ticket['organization_id']);
        $org_billable_rate = (float)($org['billable_rate'] ?? 0);
    }
    $user_cost_rate = (float)($user['cost_rate'] ?? 0);

    // Start the timer
    $entry_id = db_insert('ticket_time_entries', [
        'ticket_id' => $ticket_id,
        'user_id' => $user['id'],
        'started_at' => date('Y-m-d H:i:s'),
        'ended_at' => null,
        'duration_minutes' => 0,
        'is_billable' => 1,
        'billable_rate' => $org_billable_rate,
        'cost_rate' => $user_cost_rate,
        'is_manual' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    log_activity($ticket_id, $user['id'], 'time_started', 'Timer started');

    // Log to ticket history for timeline
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'timer_started', null, date('Y-m-d H:i:s'));
    }

    api_success([
        'entry_id' => $entry_id,
        'started_at' => date('Y-m-d H:i:s'),
        'message' => t('Timer started.')
    ]);
}

/**
 * Quick start — instantly create a ticket and start a timer
 */
function api_quick_start() {
    require_admin_post();

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    if (!ticket_time_table_exists()) {
        api_error(t('Time tracking is not available.'), 400);
    }

    require_once BASE_PATH . '/includes/ticket-crud-functions.php';

    // Create ticket with minimal data
    $ticket_id = create_ticket([
        'title' => t('Quick ticket'),
        'description' => '',
        'user_id' => $user['id'],
        'assignee_id' => $user['id'],
    ]);

    if (!$ticket_id) {
        api_error('Failed to create ticket', 500);
    }

    $ticket = get_ticket($ticket_id);

    // Start timer (same logic as api_start_timer)
    $user_cost_rate = (float)($user['cost_rate'] ?? 0);
    $org_billable_rate = 0.0;
    if (!empty($ticket['organization_id'])) {
        $org = get_organization($ticket['organization_id']);
        $org_billable_rate = (float)($org['billable_rate'] ?? 0);
    }

    db_insert('ticket_time_entries', [
        'ticket_id' => $ticket_id,
        'user_id' => $user['id'],
        'started_at' => date('Y-m-d H:i:s'),
        'ended_at' => null,
        'duration_minutes' => 0,
        'is_billable' => 1,
        'billable_rate' => $org_billable_rate,
        'cost_rate' => $user_cost_rate,
        'is_manual' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    log_activity($ticket_id, $user['id'], 'time_started', 'Timer started');

    api_success([
        'ticket_id' => $ticket_id,
        'url' => ticket_url($ticket),
    ]);
}

/**
 * Pause timer for a ticket (AJAX)
 */
function api_pause_timer() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);

    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    if (!can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    require_once BASE_PATH . '/includes/ticket-time-functions.php';
    $result = pause_ticket_timer($ticket_id, $user['id']);

    if ($result['success']) {
        log_activity($ticket_id, $user['id'], 'time_paused', 'Timer paused');
        if (function_exists('log_ticket_history')) {
            log_ticket_history($ticket_id, $user['id'], 'timer_paused', null, date('Y-m-d H:i:s'));
        }
        api_success($result);
    } else {
        api_error($result['error'], 400);
    }
}

/**
 * Resume timer for a ticket (AJAX)
 */
function api_resume_timer() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);

    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    if (!can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    require_once BASE_PATH . '/includes/ticket-time-functions.php';
    $result = resume_ticket_timer($ticket_id, $user['id']);

    if ($result['success']) {
        log_activity($ticket_id, $user['id'], 'time_resumed', 'Timer resumed');
        if (function_exists('log_ticket_history')) {
            log_ticket_history($ticket_id, $user['id'], 'timer_resumed', null, date('Y-m-d H:i:s'));
        }
        api_success($result);
    } else {
        api_error($result['error'], 400);
    }
}

/**
 * Stop timer for a ticket (AJAX) - ends timer and saves the logged time
 */
function api_stop_timer() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);

    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    if (!can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    require_once BASE_PATH . '/includes/ticket-time-functions.php';

    $active = get_active_ticket_timer($ticket_id, $user['id']);
    if (!$active) {
        api_error(t('No active timer found'), 400);
    }

    // Calculate duration accounting for pauses
    $elapsed = calculate_timer_elapsed($active);
    $duration = max(1, (int) floor($elapsed / 60));

    db_update('ticket_time_entries', [
        'ended_at' => date('Y-m-d H:i:s'),
        'duration_minutes' => $duration,
        'paused_at' => null
    ], 'id = ?', [$active['id']]);

    log_activity($ticket_id, $user['id'], 'time_stopped', "Timer stopped ({$duration} min)");
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'timer_stopped', null, date('Y-m-d H:i:s'));
    }

    api_success([
        'success' => true,
        'entry_id' => $active['id'],
        'duration_minutes' => $duration,
        'message' => t('Timer stopped.') . ' ' . format_duration_minutes($duration) . ' ' . t('logged.')
    ]);
}

/**
 * Discard timer for a ticket (AJAX) - deletes without logging time
 */
function api_discard_timer() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);

    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    if (!can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    require_once BASE_PATH . '/includes/ticket-time-functions.php';
    $result = discard_ticket_timer($ticket_id, $user['id']);

    if ($result['success']) {
        log_activity($ticket_id, $user['id'], 'time_discarded', 'Timer discarded');
        api_success($result);
    } else {
        api_error($result['error'], 400);
    }
}

/**
 * Cancel (delete) a ticket with running timer — for quick-start tickets.
 * Only allowed if ticket has no comments and no completed time entries.
 */
function api_cancel_ticket() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);

    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    if (!can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    // Safety: only allow cancellation if ticket has no comments and no completed time entries
    $comment_count = (int)db_fetch_one("SELECT COUNT(*) AS cnt FROM comments WHERE ticket_id = ?", [$ticket_id])['cnt'];
    if ($comment_count > 0) {
        api_error(t('Cannot cancel ticket with existing comments or time entries.'), 400);
    }

    if (ticket_time_table_exists()) {
        $completed_entries = (int)db_fetch_one(
            "SELECT COUNT(*) AS cnt FROM ticket_time_entries WHERE ticket_id = ? AND ended_at IS NOT NULL",
            [$ticket_id]
        )['cnt'];
        if ($completed_entries > 0) {
            api_error(t('Cannot cancel ticket with existing comments or time entries.'), 400);
        }
    }

    // Discard active timer if any
    require_once BASE_PATH . '/includes/ticket-time-functions.php';
    discard_ticket_timer($ticket_id, $user['id']);

    // Delete the ticket entirely
    require_once BASE_PATH . '/includes/ticket-crud-functions.php';
    delete_ticket($ticket_id);

    api_success(['message' => t('Ticket cancelled.')]);
}

// ===================================================================
// AJAX Quick-Edit Endpoints (used by ticket-detail sidebar)
// ===================================================================

/**
 * Quick-edit: Assign agent (AJAX, no page reload)
 */
function api_quick_assign() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error('Method not allowed', 405); }
    require_csrf_token(true);
    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) { api_error('Unauthorized', 401); }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);
    if (!$ticket) { api_error('Ticket not found', 404); }
    if (!can_see_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $old_assignee_id = $ticket['assignee_id'] ?? null;
    $assignee_id = !empty($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : null;

    db_update('tickets', ['assignee_id' => $assignee_id], 'id = ?', [$ticket_id]);
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'assignee_id', $old_assignee_id, $assignee_id);
    }

    if ($assignee_id) {
        // Auto-grant ticket access so the assignee can always see the ticket
        if (function_exists('add_ticket_access')) {
            add_ticket_access($ticket_id, $assignee_id, $user['id']);
        }

        $assigned_user = get_user($assignee_id);
        log_activity($ticket_id, $user['id'], 'assigned', "Ticket assigned to {$assigned_user['first_name']} {$assigned_user['last_name']}");

        require_once BASE_PATH . '/includes/mailer.php';
        send_ticket_assignment_notification($ticket, $assigned_user, $user);

        if (function_exists('dispatch_ticket_notifications')) {
            dispatch_ticket_notifications('assigned_to_you', $ticket_id, $user['id'], [
                'assignee_id' => $assignee_id,
            ]);
        }
    } else {
        log_activity($ticket_id, $user['id'], 'unassigned', "Assignment removed");
    }

    api_success(['message' => t('Ticket updated.')]);
}

/**
 * Quick-edit: Change "on behalf of" user (AJAX)
 */
function api_quick_behalf() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error('Method not allowed', 405); }
    require_csrf_token(true);
    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) { api_error('Unauthorized', 401); }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);
    if (!$ticket) { api_error('Ticket not found', 404); }
    if (!can_see_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    // Check if column exists (feature may not be enabled)
    try {
        $cols = db_fetch_all("SHOW COLUMNS FROM tickets LIKE 'created_for_user_id'");
        if (empty($cols)) { api_error('Feature not available', 400); }
    } catch (Exception $e) { api_error('Feature not available', 400); }

    $old_value = $ticket['created_for_user_id'] ?? null;
    $new_value = !empty($_POST['created_for_user_id']) ? (int)$_POST['created_for_user_id'] : null;

    db_update('tickets', ['created_for_user_id' => $new_value], 'id = ?', [$ticket_id]);
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'created_for_user_id', $old_value, $new_value);
    }
    log_activity($ticket_id, $user['id'], 'ticket_edited', 'On behalf of updated');

    api_success(['message' => t('Ticket updated.')]);
}

/**
 * Quick-edit: Change due date (AJAX)
 */
function api_quick_due_date() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error('Method not allowed', 405); }
    require_csrf_token(true);
    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) { api_error('Unauthorized', 401); }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);
    if (!$ticket) { api_error('Ticket not found', 404); }
    if (!can_see_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $old_due_date = $ticket['due_date'] ?? null;
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;

    if ($due_date) {
        $due_date = date('Y-m-d H:i:s', strtotime($due_date));
    }

    db_update('tickets', ['due_date' => $due_date], 'id = ?', [$ticket_id]);
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'due_date', $old_due_date, $due_date);
    }

    if ($due_date) {
        log_activity($ticket_id, $user['id'], 'due_date_updated', "Due date set to " . format_date($due_date));
    } else {
        log_activity($ticket_id, $user['id'], 'due_date_removed', "Due date removed");
    }

    // Notify ticket participants about due date change
    if (function_exists('dispatch_ticket_notifications')) {
        dispatch_ticket_notifications('ticket_updated', $ticket_id, $user['id'], [
            'field' => 'due_date',
            'detail' => $due_date ? format_date($due_date) : '',
        ]);
    }

    api_success(['message' => t('Ticket updated.')]);
}

/**
 * Quick-edit: Change priority (AJAX)
 */
function api_quick_priority() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error('Method not allowed', 405); }
    require_csrf_token(true);
    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) { api_error('Unauthorized', 401); }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);
    if (!$ticket) { api_error('Ticket not found', 404); }
    if (!can_see_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $old_value = $ticket['priority_id'] ?? null;
    $new_value = !empty($_POST['priority_id']) ? (int)$_POST['priority_id'] : null;

    db_update('tickets', ['priority_id' => $new_value], 'id = ?', [$ticket_id]);
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'priority_id', $old_value, $new_value);
    }

    $priority_name = '';
    if ($new_value) {
        $p = db_fetch_one("SELECT name FROM priorities WHERE id = ?", [$new_value]);
        $priority_name = $p['name'] ?? '';
    }
    log_activity($ticket_id, $user['id'], 'ticket_edited', 'Priority changed' . ($priority_name ? " to {$priority_name}" : ''));

    // Notify ticket participants about priority change
    if (function_exists('dispatch_ticket_notifications')) {
        dispatch_ticket_notifications('priority_changed', $ticket_id, $user['id'], [
            'new_priority' => $priority_name,
        ]);
    }

    api_success(['message' => t('Ticket updated.')]);
}

/**
 * Quick-edit: Change ticket type (AJAX)
 */
function api_quick_type() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error('Method not allowed', 405); }
    require_csrf_token(true);
    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) { api_error('Unauthorized', 401); }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);
    if (!$ticket) { api_error('Ticket not found', 404); }
    if (!can_see_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $old_value = $ticket['type'] ?? null;
    $new_value = !empty($_POST['type']) ? trim($_POST['type']) : null;

    db_update('tickets', ['type' => $new_value], 'id = ?', [$ticket_id]);
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'type', $old_value, $new_value);
    }
    log_activity($ticket_id, $user['id'], 'ticket_edited', 'Ticket type changed' . ($new_value ? " to {$new_value}" : ''));

    // Notify ticket participants about type change
    if (function_exists('dispatch_ticket_notifications')) {
        dispatch_ticket_notifications('ticket_updated', $ticket_id, $user['id'], [
            'field' => 'type',
            'detail' => $new_value ?? '',
        ]);
    }

    api_success(['message' => t('Ticket updated.')]);
}

/**
 * Quick-edit: Change company/organization (AJAX)
 */
function api_quick_company() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error('Method not allowed', 405); }
    require_csrf_token(true);
    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) { api_error('Unauthorized', 401); }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);
    if (!$ticket) { api_error('Ticket not found', 404); }
    if (!can_see_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $old_org_id = $ticket['organization_id'] ?? null;
    $org_input = trim((string)($_POST['organization_id'] ?? ''));
    $new_org_id = ($org_input !== '') ? (int)$org_input : null;

    db_update('tickets', ['organization_id' => $new_org_id], 'id = ?', [$ticket_id]);
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'organization_id', $old_org_id, $new_org_id);
    }
    // Get org name for notification
    $org_name = '';
    if ($new_org_id) {
        $org = db_fetch_one("SELECT name FROM organizations WHERE id = ?", [$new_org_id]);
        $org_name = $org['name'] ?? '';
    }
    log_activity($ticket_id, $user['id'], 'company_updated', 'Company updated' . ($org_name ? " to {$org_name}" : ''));

    // Notify ticket participants about company change
    if (function_exists('dispatch_ticket_notifications')) {
        dispatch_ticket_notifications('ticket_updated', $ticket_id, $user['id'], [
            'field' => 'company',
            'detail' => $org_name,
        ]);
    }

    api_success(['message' => t('Ticket updated.')]);
}

/**
 * Delete time entry (AJAX)
 */
function api_delete_time_entry() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    $entry_id = (int)($_POST['entry_id'] ?? 0);

    if (!ticket_time_table_exists()) {
        api_error(t('Time tracking is not available.'), 400);
    }

    $entry = db_fetch_one("SELECT * FROM ticket_time_entries WHERE id = ?", [$entry_id]);
    if (!$entry) {
        api_error('Time entry not found', 404);
    }

    $ticket = get_ticket($entry['ticket_id']);
    if (!$ticket || !can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    require_once BASE_PATH . '/includes/ticket-time-functions.php';
    if (delete_time_entry($entry_id)) {
        log_activity($entry['ticket_id'], $user['id'], 'time_deleted', "Deleted time entry (" . format_duration_minutes($entry['duration_minutes'] ?? 0) . ")");
        api_success(['message' => t('Time entry deleted.')]);
    } else {
        api_error(t('Failed to delete time entry.'), 500);
    }
}

/**
 * Get all unique tags across tickets (for autocomplete)
 * GET — returns [{id: "tag", name: "tag"}, ...]
 */
function api_get_tags() {
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    if (!function_exists('ticket_tags_column_exists') || !ticket_tags_column_exists()) {
        api_success(['tags' => []]);
        return;
    }

    $rows = db_fetch_all(
        "SELECT DISTINCT tags FROM tickets WHERE tags IS NOT NULL AND tags != ''"
    );

    $all_tags = [];
    $seen = [];
    foreach ($rows as $row) {
        $parts = explode(',', $row['tags']);
        foreach ($parts as $part) {
            $tag = trim($part);
            if ($tag === '') continue;
            $key = mb_strtolower($tag, 'UTF-8');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $all_tags[] = ['id' => $tag, 'name' => $tag];
        }
    }

    usort($all_tags, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    api_success(['tags' => $all_tags]);
}

/**
 * Update ticket tags via AJAX
 * POST — requires CSRF + edit permission
 */
function api_update_tags() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $ticket_id = (int) ($_POST['ticket_id'] ?? 0);
    $tags_raw  = trim((string) ($_POST['tags'] ?? ''));

    $ticket = get_ticket($ticket_id);
    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    if (!can_edit_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    if (!function_exists('ticket_tags_column_exists') || !ticket_tags_column_exists()) {
        api_error('Tags not supported', 400);
    }

    $normalized = normalize_ticket_tags($tags_raw);
    $update_data = ['tags' => $normalized !== '' ? $normalized : null];

    update_ticket_with_history($ticket_id, $update_data, $user['id']);
    log_activity($ticket_id, $user['id'], 'ticket_edited', 'Tags updated');

    $new_tags = get_ticket_tags_array($normalized);
    api_success(['tags' => $new_tags]);
}

/**
 * Update time entry inline (AJAX) – used by worklog tab
 */
function api_update_time_inline() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || !is_admin()) {
        api_error('Unauthorized', 401);
    }

    if (!ticket_time_table_exists()) {
        api_error(t('Time tracking is not available.'), 400);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $entry_id   = (int) ($input['entry_id']   ?? 0);
    $entry_date = $input['entry_date']         ?? date('Y-m-d');
    $start_time = $input['start_time']         ?? '';
    $end_time   = $input['end_time']           ?? '';

    if ($entry_id <= 0 || !$start_time || !$end_time) {
        api_error(t('Missing required fields.'), 400);
    }

    $entry = db_fetch_one("SELECT * FROM ticket_time_entries WHERE id = ?", [$entry_id]);
    if (!$entry) {
        api_error('Time entry not found', 404);
    }

    $start_dt = DateTime::createFromFormat('Y-m-d H:i', $entry_date . ' ' . $start_time);
    $end_dt   = DateTime::createFromFormat('Y-m-d H:i', $entry_date . ' ' . $end_time);

    if (!$start_dt || !$end_dt) {
        api_error(t('Invalid time format.'), 400);
    }

    // If end time is before start time, assume it's the next day
    if ($end_dt <= $start_dt) {
        $end_dt->modify('+1 day');
    }

    $duration = max(1, (int) floor(($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60));

    db_update('ticket_time_entries', [
        'started_at'       => $start_dt->format('Y-m-d H:i:s'),
        'ended_at'         => $end_dt->format('Y-m-d H:i:s'),
        'duration_minutes' => $duration
    ], 'id = ?', [$entry_id]);

    api_success([
        'duration_minutes'  => $duration,
        'duration_formatted' => format_duration_minutes($duration),
        'started_at'        => $start_dt->format('Y-m-d H:i:s'),
        'ended_at'          => $end_dt->format('Y-m-d H:i:s'),
    ]);
}

/**
 * Edit a comment (AJAX)
 */
function api_edit_comment() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    $comment_id = (int)($_POST['comment_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if (empty($content)) {
        api_error(t('Comment cannot be empty.'), 400);
    }

    // Get the comment
    $comment = db_fetch_one("SELECT * FROM comments WHERE id = ?", [$comment_id]);
    if (!$comment) {
        api_error('Comment not found', 404);
    }

    // Get the ticket to verify access
    $ticket = get_ticket($comment['ticket_id']);
    if (!$ticket || !can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    // Agents can only edit their own comments; admins can edit any
    if (is_agent() && !is_admin() && (int)$comment['user_id'] !== (int)$user['id']) {
        api_error('Forbidden', 403);
    }

    // Store original content for activity log
    $original_content = $comment['content'];
    $content_preview = mb_strlen($original_content) > 50
        ? mb_substr($original_content, 0, 50) . '...'
        : $original_content;

    // Update the comment
    try {
        db_update('comments', [
            'content' => $content
        ], 'id = ?', [$comment_id]);

        if (function_exists('log_ticket_history')) {
            log_ticket_history($comment['ticket_id'], $user['id'], 'comment_content', $original_content, $content);
        }

        // Log the activity
        log_activity(
            $comment['ticket_id'],
            $user['id'],
            'comment_edited',
            t('Comment edited') . ': "' . $content_preview . '"'
        );

        api_success([
            'message' => t('Comment updated.'),
            'content_html' => render_content($content)
        ]);
    } catch (Exception $e) {
        api_error(t('Failed to update comment.'), 500);
    }
}

/**
 * Delete a comment (AJAX)
 */
function api_delete_comment() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    $comment_id = (int)($_POST['comment_id'] ?? 0);

    // Get the comment
    $comment = db_fetch_one("SELECT * FROM comments WHERE id = ?", [$comment_id]);
    if (!$comment) {
        api_error('Comment not found', 404);
    }

    $linked_attachments = db_fetch_all(
        "SELECT original_name, filename FROM attachments WHERE comment_id = ?",
        [$comment_id]
    );

    // Get the ticket to verify access
    $ticket = get_ticket($comment['ticket_id']);
    if (!$ticket || !can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    // Agents can only delete their own comments; admins can delete any
    if (is_agent() && !is_admin() && (int)$comment['user_id'] !== (int)$user['id']) {
        api_error('Forbidden', 403);
    }

    // Store content preview for activity log
    $content_preview = mb_strlen($comment['content']) > 50
        ? mb_substr($comment['content'], 0, 50) . '...'
        : $comment['content'];

    // Delete the comment
    try {
        if (function_exists('log_ticket_history')) {
            log_ticket_history($comment['ticket_id'], $user['id'], 'comment_deleted', $comment['content'], null);
            foreach ($linked_attachments as $attachment) {
                $attachment_name = trim((string) ($attachment['original_name'] ?? $attachment['filename'] ?? ''));
                if ($attachment_name !== '') {
                    log_ticket_history($comment['ticket_id'], $user['id'], 'attachment_unlinked', $attachment_name, null);
                }
            }
        }

        db_delete('comments', 'id = ?', [$comment_id]);

        // Log the activity
        log_activity(
            $comment['ticket_id'],
            $user['id'],
            'comment_deleted',
            t('Comment deleted') . ': "' . $content_preview . '"'
        );

        api_success([
            'message' => t('Comment deleted.')
        ]);
    } catch (Exception $e) {
        api_error(t('Failed to delete comment.'), 500);
    }
}

/**
 * Search tickets for command palette / quick search
 * Returns top 8 matching tickets (title + ticket_code)
 */
function api_search_tickets() {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        api_success(['tickets' => []]);
        return;
    }

    $user = current_user();
    $user_id = $user['id'] ?? 0;
    $is_staff = is_admin() || is_agent();

    // Build search: title LIKE + optional ticket-code-to-ID lookup
    $like = '%' . $q . '%';
    $params = [$like];

    // Check if query looks like a ticket code (e.g. "TK-10001" or just "10001")
    $code_id = null;
    if (function_exists('parse_ticket_code')) {
        $code_id = parse_ticket_code(strtoupper($q));
    }
    if ($code_id === null && preg_match('/^\d+$/', $q)) {
        // Bare number — could be ticket ID offset
        $code_id = (int)$q - 10000;
    }

    $code_sql = '';
    if ($code_id !== null && $code_id > 0) {
        $code_sql = ' OR t.id = ?';
        $params[] = $code_id;
    }

    // Scope: staff sees all, users see only own
    $scope_sql = '';
    if (!$is_staff) {
        $scope_sql = ' AND t.user_id = ?';
        $params[] = $user_id;
    }

    // Exclude archived
    $archive_sql = '';
    if (function_exists('ticket_archive_column_exists') && ticket_archive_column_exists()) {
        $archive_sql = ' AND (t.archived_at IS NULL)';
    }

    $sql = "SELECT t.id, t.title,
                   s.name AS status_name, s.color AS status_color
            FROM tickets t
            LEFT JOIN statuses s ON s.id = t.status_id
            WHERE (t.title LIKE ?{$code_sql})
            {$scope_sql}
            {$archive_sql}
            ORDER BY t.id DESC
            LIMIT 8";

    try {
        $rows = db_fetch_all($sql, $params);
    } catch (Exception $e) {
        $rows = [];
    }

    $tickets = [];
    foreach ($rows as $row) {
        $ticket_code = function_exists('get_ticket_code') ? get_ticket_code($row['id']) : ('TK-' . $row['id']);
        $url = function_exists('ticket_url') ? ticket_url($row) : ('index.php?page=ticket-detail&id=' . $row['id']);
        $tickets[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'ticket_code' => $ticket_code,
            'status_name' => $row['status_name'] ?? '',
            'status_color' => $row['status_color'] ?? '',
            'url' => $url,
        ];
    }

    api_success(['tickets' => $tickets]);
}

/**
 * Get ticket activity timeline (AJAX)
 */
function api_get_timeline() {
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $ticket_id = (int)($_GET['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);

    if (!$ticket || !can_see_ticket($ticket, $user)) {
        api_error('Forbidden', 403);
    }

    if (!function_exists('can_view_timeline') || !can_view_timeline($user)) {
        api_error('No permission', 403);
    }

    require_once BASE_PATH . '/includes/ticket-crud-functions.php';

    $include_internal = is_agent() || is_admin();
    $events = get_ticket_timeline($ticket_id, $include_internal);

    api_success(['events' => $events]);
}


