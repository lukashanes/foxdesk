<?php
/**
 * API Handler: Ticket Operations
 *
 * Handles ticket-related API actions like status changes.
 */

function api_ticket_column_exists($column) {
    static $cache = [];

    $column = (string)$column;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }

    if (!array_key_exists($column, $cache)) {
        $cache[$column] = function_exists('column_exists')
            ? column_exists('tickets', $column)
            : (bool)db_fetch_one("SHOW COLUMNS FROM tickets LIKE ?", [$column]);
    }

    return $cache[$column];
}

function api_get_active_user_by_id($user_id) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        return null;
    }

    $sql = "SELECT * FROM users WHERE id = ? AND is_active = 1";
    if (function_exists('users_deleted_at_column_exists') && users_deleted_at_column_exists()) {
        $sql .= " AND deleted_at IS NULL";
    }

    return db_fetch_one($sql, [$user_id]);
}

function api_get_active_staff_user_by_id($user_id) {
    $user = api_get_active_user_by_id($user_id);
    if (!$user || !in_array((string)($user['role'] ?? ''), ['agent', 'admin'], true)) {
        return null;
    }

    return $user;
}

function api_require_staff_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    return $user;
}

function api_get_active_organization_by_id($organization_id) {
    $organization_id = (int)$organization_id;
    if ($organization_id <= 0) {
        return null;
    }

    $sql = "SELECT * FROM organizations WHERE id = ?";
    if (function_exists('column_exists') && column_exists('organizations', 'is_active')) {
        $sql .= " AND is_active = 1";
    }

    return db_fetch_one($sql, [$organization_id]);
}

function api_get_active_ticket_type_by_slug($slug) {
    $slug = trim((string)$slug);
    if ($slug === '') {
        return null;
    }

    $params = [$slug];
    $sql = "SELECT * FROM ticket_types WHERE slug = ?";
    if (function_exists('column_exists') && column_exists('ticket_types', 'is_active')) {
        $sql .= " AND is_active = 1";
    }
    if (function_exists('workflow_reference_sql_filter')) {
        $sql .= workflow_reference_sql_filter('ticket_types', $params);
    }

    return db_fetch_one($sql, $params);
}

/**
 * Handle change ticket status
 *
 * Security: only staff users who can see/edit the ticket can change status.
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

    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    if (!can_see_ticket($ticket, $user) || !can_edit_ticket($ticket, $user)) {
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
    $old_status_name = $old_status['name'] ?? t('Unknown');
    $new_status_name = $new_status['name'] ?? t('Unknown');

    db_update('tickets', ['status_id' => $status_id], 'id = ?', [$ticket_id]);
    log_activity(
        $ticket_id,
        $user['id'],
        'status_changed',
        "Status changed from '{$old_status_name}' to '{$new_status_name}'"
    );

    // Send notification
    require_once BASE_PATH . '/includes/mailer.php';
    send_status_change_notification($ticket, $old_status, $new_status);

    // In-app notification for status change
    if (function_exists('ticket_event_dispatch_in_app')) {
        ticket_event_dispatch_in_app('ticket.status_changed', $ticket_id, $user['id'], [
            'old_status' => $old_status_name,
            'new_status' => $new_status_name,
        ]);
    }

    // Auto-resolve action notifications if ticket is now closed
    if (!empty($new_status['is_closed']) && function_exists('resolve_action_notifications')) {
        resolve_action_notifications($ticket_id);
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
    $ticket_billable_rate = function_exists('get_ticket_effective_billable_rate')
        ? get_ticket_effective_billable_rate($ticket, (int) $user['id'])
        : 0.0;
    $user_cost_rate = (float)($user['cost_rate'] ?? 0);

    // Start the timer
    $entry_id = db_insert('ticket_time_entries', [
        'ticket_id' => $ticket_id,
        'user_id' => $user['id'],
        'started_at' => date('Y-m-d H:i:s'),
        'ended_at' => null,
        'duration_minutes' => 0,
        'is_billable' => 1,
        'billable_rate' => $ticket_billable_rate,
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
    $user = api_require_staff_post();

    if (!ticket_time_table_exists()) {
        api_error(t('Time tracking is not available.'), 400);
    }

    require_once BASE_PATH . '/includes/ticket-crud-functions.php';

    // Create ticket with minimal data
    $ticket_id = create_ticket([
        'title' => t('Quick ticket'),
        'description' => '',
        'user_id' => $user['id'],
        'organization_id' => null,
        'assignee_id' => $user['id'],
    ]);

    if (!$ticket_id) {
        api_error('Failed to create ticket', 500);
    }

    $ticket = get_ticket($ticket_id);

    // Start timer (same logic as api_start_timer)
    $user_cost_rate = (float)($user['cost_rate'] ?? 0);
    $ticket_billable_rate = function_exists('get_ticket_effective_billable_rate')
        ? get_ticket_effective_billable_rate($ticket, (int) $user['id'])
        : 0.0;

    db_insert('ticket_time_entries', [
        'ticket_id' => $ticket_id,
        'user_id' => $user['id'],
        'started_at' => date('Y-m-d H:i:s'),
        'ended_at' => null,
        'duration_minutes' => 0,
        'is_billable' => 1,
        'billable_rate' => $ticket_billable_rate,
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
    if (!can_see_ticket($ticket, $user) || !can_edit_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $old_assignee_id = $ticket['assignee_id'] ?? null;
    $assignee_raw = trim((string)($_POST['assignee_id'] ?? ''));
    $assignee_id = $assignee_raw !== '' ? (int)$assignee_raw : null;
    $assigned_user = null;
    if ($assignee_id !== null) {
        $assigned_user = api_get_active_staff_user_by_id($assignee_id);
        if (!$assigned_user) {
            api_error(t('Invalid assignee.'), 400);
        }
    }

    db_update('tickets', ['assignee_id' => $assignee_id], 'id = ?', [$ticket_id]);
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'assignee_id', $old_assignee_id, $assignee_id);
    }

    if ($assignee_id) {
        // Auto-grant ticket access so the assignee can always see the ticket
        if (function_exists('add_ticket_access')) {
            add_ticket_access($ticket_id, $assignee_id, $user['id']);
        }

        log_activity($ticket_id, $user['id'], 'assigned', "Ticket assigned to {$assigned_user['first_name']} {$assigned_user['last_name']}");

        require_once BASE_PATH . '/includes/mailer.php';
        send_ticket_assignment_notification($ticket, $assigned_user, $user);

        if (function_exists('ticket_event_dispatch_in_app')) {
            ticket_event_dispatch_in_app('ticket.assigned', $ticket_id, $user['id'], [
                'assignee_id' => $assignee_id,
            ]);
        }
    } else {
        log_activity($ticket_id, $user['id'], 'unassigned', "Assignment removed");
    }

    // Resolve old assignee's action notifications on reassign
    if ($old_assignee_id && function_exists('resolve_action_notifications')) {
        resolve_action_notifications($ticket_id, (int)$old_assignee_id);
    }

    api_success([
        'message' => t('Ticket updated.'),
        'assignee_id' => $assignee_id,
    ]);
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
    if (!can_see_ticket($ticket, $user) || !can_edit_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    // Check if column exists (feature may not be enabled)
    try {
        $cols = db_fetch_all("SHOW COLUMNS FROM tickets LIKE 'created_for_user_id'");
        if (empty($cols)) { api_error('Feature not available', 400); }
    } catch (Exception $e) { api_error('Feature not available', 400); }

    $old_value = $ticket['created_for_user_id'] ?? null;
    $behalf_raw = trim((string)($_POST['created_for_user_id'] ?? ''));
    $new_value = $behalf_raw !== '' ? (int)$behalf_raw : null;
    if ($new_value !== null && !api_get_active_user_by_id($new_value)) {
        api_error(t('Invalid user.'), 400);
    }

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
    if (!can_see_ticket($ticket, $user) || !can_edit_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $old_due_date = $ticket['due_date'] ?? null;
    $due_date_input = trim((string) ($_POST['due_date'] ?? ''));
    $due_date = normalize_due_date_input($due_date_input);
    if ($due_date_input !== '' && $due_date === false) {
        api_error(t('Invalid due date.'), 400);
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
    if (function_exists('ticket_event_dispatch_in_app')) {
        ticket_event_dispatch_in_app('ticket.updated', $ticket_id, $user['id'], [
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
    if (!can_see_ticket($ticket, $user) || !can_edit_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $old_value = $ticket['priority_id'] ?? null;
    $priority_raw = trim((string)($_POST['priority_id'] ?? ''));
    $new_value = $priority_raw !== '' ? (int)$priority_raw : null;
    $priority = null;
    if ($new_value !== null) {
        $priority = get_priority($new_value);
        if (!$priority) {
            api_error(t('Invalid priority.'), 400);
        }
    }

    db_update('tickets', ['priority_id' => $new_value], 'id = ?', [$ticket_id]);
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'priority_id', $old_value, $new_value);
    }

    $priority_name = '';
    if ($priority) {
        $priority_name = $priority['name'] ?? '';
    }
    log_activity($ticket_id, $user['id'], 'ticket_edited', 'Priority changed' . ($priority_name ? " to {$priority_name}" : ''));

    // Notify ticket participants about priority change
    if (function_exists('ticket_event_dispatch_in_app')) {
        ticket_event_dispatch_in_app('ticket.priority_changed', $ticket_id, $user['id'], [
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
    if (!can_see_ticket($ticket, $user) || !can_edit_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $old_value = $ticket['type'] ?? null;
    $new_value = trim((string)($_POST['type'] ?? ''));
    $new_value = $new_value !== '' ? $new_value : null;
    $type_row = null;
    if ($new_value !== null) {
        $type_row = api_get_active_ticket_type_by_slug($new_value);
        if (!$type_row) {
            api_error(t('Invalid ticket type.'), 400);
        }
    }

    $update = ['type' => $new_value];
    if (api_ticket_column_exists('ticket_type_id')) {
        $update['ticket_type_id'] = $type_row ? (int)$type_row['id'] : null;
    }

    db_update('tickets', $update, 'id = ?', [$ticket_id]);
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'type', $old_value, $new_value);
        if (array_key_exists('ticket_type_id', $update)) {
            log_ticket_history($ticket_id, $user['id'], 'ticket_type_id', $ticket['ticket_type_id'] ?? null, $update['ticket_type_id']);
        }
    }
    log_activity($ticket_id, $user['id'], 'ticket_edited', 'Ticket type changed' . ($new_value ? " to {$new_value}" : ''));

    // Notify ticket participants about type change
    if (function_exists('ticket_event_dispatch_in_app')) {
        ticket_event_dispatch_in_app('ticket.updated', $ticket_id, $user['id'], [
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
    if (!can_see_ticket($ticket, $user) || !can_edit_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $old_org_id = $ticket['organization_id'] ?? null;
    $org_input = trim((string)($_POST['organization_id'] ?? ''));
    $new_org_id = null;
    $org = null;
    if ($org_input !== '') {
        $candidate_org_id = (int)$org_input;
        if ($candidate_org_id > 0) {
            $org = api_get_active_organization_by_id($candidate_org_id);
            if (!$org) {
                api_error(t('Invalid company.'), 400);
            }
            $new_org_id = $candidate_org_id;
        }
    }

    db_update('tickets', ['organization_id' => $new_org_id], 'id = ?', [$ticket_id]);
    if (function_exists('sync_ticket_time_entry_billable_rates')) {
        sync_ticket_time_entry_billable_rates($ticket_id);
    }
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'organization_id', $old_org_id, $new_org_id);
    }
    // Get org name for notification
    $org_name = '';
    if ($org) {
        $org_name = $org['name'] ?? '';
    }
    log_activity($ticket_id, $user['id'], 'company_updated', 'Company updated' . ($org_name ? " to {$org_name}" : ''));

    // Notify ticket participants about company change
    if (function_exists('ticket_event_dispatch_in_app')) {
        ticket_event_dispatch_in_app('ticket.updated', $ticket_id, $user['id'], [
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
    if (!is_admin() && (int)($entry['user_id'] ?? 0) !== (int)$user['id']) {
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
 * Quick-log a manual time entry from any page (tickets list popover, etc.).
 * Duration semantics: end = now, start = now − duration_minutes.
 * POST params: ticket_id (int), duration_minutes (int 1..1440), note (optional string).
 */
function api_quick_log_time() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    require_csrf_token(true);

    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) {
        api_error('Unauthorized', 401);
    }

    $ticket_id = (int) ($_POST['ticket_id'] ?? 0);
    $duration  = (int) ($_POST['duration_minutes'] ?? 0);
    $note      = trim((string) ($_POST['note'] ?? ''));

    if ($duration <= 0 || $duration > 1440) {
        api_error(t('Duration must be between 1 and 1440 minutes.'), 400);
    }

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

    require_once BASE_PATH . '/includes/ticket-time-functions.php';

    // Resolve billable / cost rates like the form handler does.
    $ticket_billable_rate = function_exists('get_ticket_effective_billable_rate')
        ? get_ticket_effective_billable_rate($ticket, (int) $user['id'])
        : 0.0;
    $user_cost_rate = (float) ($user['cost_rate'] ?? 0);

    $now = new DateTime();
    $start = (clone $now)->modify('-' . $duration . ' minutes');

    $comment_id = null;
    if ($note !== '') {
        $comment_id = db_insert('comments', [
            'ticket_id'  => $ticket_id,
            'user_id'    => $user['id'],
            'content'    => $note,
            'is_internal' => 0,
            'time_spent' => $duration,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        db_update('tickets', ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$ticket_id]);
    }

    $insert = [
        'ticket_id'        => $ticket_id,
        'user_id'          => $user['id'],
        'comment_id'       => $comment_id,
        'started_at'       => $start->format('Y-m-d H:i:s'),
        'ended_at'         => $now->format('Y-m-d H:i:s'),
        'duration_minutes' => $duration,
        'is_billable'      => 1,
        'billable_rate'    => $ticket_billable_rate,
        'cost_rate'        => $user_cost_rate,
        'is_manual'        => 1,
        'created_at'       => date('Y-m-d H:i:s'),
    ];
    if (function_exists('time_entry_source_column_exists') && time_entry_source_column_exists()) {
        $insert['source'] = 'manual';
    }
    $entry_id = (int) db_insert('ticket_time_entries', $insert);

    log_activity($ticket_id, $user['id'], 'time_manual', "Quick-logged {$duration} min");
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'time_manual', null, "{$duration} min");
    }

    api_success([
        'success'          => true,
        'entry_id'         => $entry_id,
        'duration_minutes' => $duration,
        'message'          => format_duration_minutes($duration) . ' ' . t('logged.'),
    ]);
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

    $filters = [];
    if (function_exists('build_ticket_visibility_filters_for_user')) {
        $filters = build_ticket_visibility_filters_for_user($user, $filters);
    }
    if (function_exists('column_exists') && column_exists('tickets', 'is_archived')) {
        $filters['is_archived'] = 0;
    }

    $params = [];
    $sql = "SELECT DISTINCT t.tags FROM tickets t";
    if (function_exists('build_ticket_where_clause')) {
        $sql .= build_ticket_where_clause($filters, $params);
        $sql .= " AND t.tags IS NOT NULL AND t.tags != ''";
    } else {
        $sql .= " WHERE t.tags IS NOT NULL AND t.tags != ''";
    }

    $rows = db_fetch_all($sql, $params);

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

    if (!can_see_ticket($ticket, $user) || !can_edit_ticket($ticket, $user)) {
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
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $filters = ['search' => $q, 'sort' => 'ticket_number', 'limit' => 8];
    if (function_exists('column_exists') && column_exists('tickets', 'is_archived')) {
        $filters['is_archived'] = 0;
    }
    if (function_exists('build_ticket_visibility_filters_for_user')) {
        $filters = build_ticket_visibility_filters_for_user($user, $filters);
    }

    try {
        $rows = get_tickets($filters);
    } catch (Exception $e) {
        $rows = [];
    }

    $code_id = null;
    if (function_exists('parse_ticket_code')) {
        $code_id = parse_ticket_code(strtoupper($q));
    }
    if ($code_id === null && preg_match('/^\d+$/', $q)) {
        $code_id = (int) $q - 10000;
    }
    if ($code_id !== null && $code_id > 0) {
        $code_ticket = get_ticket($code_id);
        $code_visible = $code_ticket
            && (empty($code_ticket['is_archived']) || !function_exists('column_exists') || !column_exists('tickets', 'is_archived'))
            && (!function_exists('can_user_access_ticket_in_listing_scope') || can_user_access_ticket_in_listing_scope((int) $code_ticket['id'], $user));
        if ($code_visible) {
            $rows = array_values(array_filter($rows, static function ($row) use ($code_ticket) {
                return (int) ($row['id'] ?? 0) !== (int) $code_ticket['id'];
            }));
            array_unshift($rows, $code_ticket);
            $rows = array_slice($rows, 0, 8);
        }
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
 * Global Spotlight search.
 * Returns grouped sections so UI can show open work, completed history, and clients.
 */
function api_global_search() {
    $q = trim((string) ($_GET['q'] ?? ''));
    $limit = (int) ($_GET['limit'] ?? 6);

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    if (!function_exists('global_search')) {
        api_error('Global search is not available.', 500);
    }

    try {
        api_success(global_search($q, $user, $limit));
    } catch (Throwable $e) {
        error_log('api_global_search failed: ' . $e->getMessage());
        api_error(t('Search failed.'), 500);
    }
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

/**
 * Quick-edit: Change ticket subject/title (AJAX)
 */
function api_quick_subject() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error('Method not allowed', 405); }
    require_csrf_token(true);
    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) { api_error('Unauthorized', 401); }

    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $ticket = get_ticket($ticket_id);
    if (!$ticket) { api_error('Ticket not found', 404); }
    if (!can_see_ticket($ticket, $user) || !can_edit_ticket($ticket, $user)) { api_error('Forbidden', 403); }

    $new_title = trim((string)($_POST['title'] ?? ''));
    if ($new_title === '') { api_error(t('Subject cannot be empty.'), 400); }
    if (mb_strlen($new_title) > 500) { $new_title = mb_substr($new_title, 0, 500); }

    $old_title = $ticket['title'] ?? '';
    if ($old_title === $new_title) {
        api_success(['message' => t('Ticket updated.'), 'title' => $new_title]);
    }

    db_update('tickets', ['title' => $new_title], 'id = ?', [$ticket_id]);
    if (function_exists('log_ticket_history')) {
        log_ticket_history($ticket_id, $user['id'], 'title', $old_title, $new_title);
    }
    log_activity($ticket_id, $user['id'], 'ticket_edited', 'Subject updated');

    if (function_exists('ticket_event_dispatch_in_app')) {
        ticket_event_dispatch_in_app('ticket.updated', $ticket_id, $user['id'], [
            'field' => 'title',
            'detail' => $new_title,
        ]);
    }

    api_success(['message' => t('Ticket updated.'), 'title' => $new_title]);
}

/**
 * Quick-create: Create a ticket from the inline "new row" on the tickets list.
 * Only agents/admins can use this. Minimum required field is title.
 */
function api_quick_create_ticket() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error('Method not allowed', 405); }
    require_csrf_token(true);
    $user = current_user();
    if (!$user || (!is_agent() && !is_admin())) { api_error('Unauthorized', 401); }

    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') { api_error(t('Subject cannot be empty.'), 400); }
    if (mb_strlen($title) > 500) { $title = mb_substr($title, 0, 500); }

    $data = [
        'title' => $title,
        'description' => '',
        'user_id' => (int)$user['id'],
    ];

    $type_raw = trim((string)($_POST['type'] ?? ''));
    if ($type_raw !== '') {
        if (!api_get_active_ticket_type_by_slug($type_raw)) {
            api_error(t('Invalid ticket type.'), 400);
        }
        $data['type'] = $type_raw;
    }

    $assignee_raw = trim((string)($_POST['assignee_id'] ?? ''));
    if ($assignee_raw !== '') {
        $aid = (int)$assignee_raw;
        if ($aid > 0) {
            $assignee = api_get_active_staff_user_by_id($aid);
            if (!$assignee || !can_user_assign_to_staff($assignee, $user)) {
                api_error(t('Invalid assignee.'), 400);
            }
            $data['assignee_id'] = $aid;
        }
    }

    $org_raw = trim((string)($_POST['organization_id'] ?? ''));
    if ($org_raw !== '') {
        $oid = (int)$org_raw;
        if ($oid > 0) {
            if (!api_get_active_organization_by_id($oid)) {
                api_error(t('Invalid company.'), 400);
            }
            if (!can_user_use_organization($oid, $user)) {
                api_error(t('Selected organization is not available.'), 403);
            }
            $data['organization_id'] = $oid;
        } else {
            $data['organization_id'] = null;
        }
    }

    $priority_raw = trim((string)($_POST['priority_id'] ?? ''));
    if ($priority_raw !== '') {
        $pid = (int)$priority_raw;
        if ($pid > 0) {
            if (!get_priority($pid)) {
                api_error(t('Invalid priority.'), 400);
            }
            $data['priority_id'] = $pid;
        }
    }

    $status_raw = trim((string)($_POST['status_id'] ?? ''));
    if ($status_raw !== '') {
        $sid = (int)$status_raw;
        if ($sid > 0) {
            if (!get_status($sid)) {
                api_error(t('Invalid status.'), 400);
            }
            $data['status_id'] = $sid;
        }
    }

    $due_raw = trim((string)($_POST['due_date'] ?? ''));
    if ($due_raw !== '') {
        $normalized_due_date = normalize_due_date_input($due_raw);
        if ($normalized_due_date === false) {
            api_error(t('Invalid due date.'), 400);
        }
        $data['due_date'] = $normalized_due_date;
    }

    $new_id = create_ticket($data);
    if (!$new_id) { api_error(t('Failed to create ticket.'), 500); }

    // Auto-grant access to assignee
    if (!empty($data['assignee_id']) && function_exists('add_ticket_access')) {
        add_ticket_access($new_id, (int)$data['assignee_id'], (int)$user['id']);
    }

    log_activity($new_id, $user['id'], 'created', 'Ticket created from inline row');

    if (!empty($data['assignee_id'])) {
        $assigned_user = get_user((int)$data['assignee_id']);
        if ($assigned_user) {
            require_once BASE_PATH . '/includes/mailer.php';
            $new_ticket = get_ticket($new_id);
            if ($new_ticket) {
                send_ticket_assignment_notification($new_ticket, $assigned_user, $user);
            }
            if (function_exists('ticket_event_dispatch_in_app')) {
                ticket_event_dispatch_in_app('ticket.assigned', $new_id, $user['id'], [
                    'assignee_id' => (int)$data['assignee_id'],
                ]);
            }
        }
    }

    api_success([
        'message' => t('Ticket created.'),
        'ticket_id' => $new_id,
    ]);
}
