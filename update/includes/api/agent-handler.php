<?php
/**
 * Agent API Handler
 *
 * REST API endpoints for external agents (AI assistants, automation scripts).
 * Authenticated via Bearer token (see auth.php — authenticate_api_token()).
 *
 * All endpoints follow the standard response format:
 *   Success: {"success": true, ...data}
 *   Error:   {"success": false, "error": "message"}
 */

// =============================================================================
// AGENT-ME — current token's user info
// =============================================================================

function api_agent_me()
{
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    api_success([
        'user' => [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'role' => $user['role'],
            'language' => $user['language'] ?? 'en',
            'is_ai_agent' => !empty($user['is_ai_agent']),
            'ai_model' => $user['ai_model'] ?? null,
        ],
    ]);
}

// =============================================================================
// LIST STATUSES
// =============================================================================

function api_agent_list_statuses()
{
    $statuses = get_statuses();
    $out = [];
    foreach ($statuses as $s) {
        $out[] = [
            'id' => (int) $s['id'],
            'name' => $s['name'],
            'color' => $s['color'] ?? null,
            'is_default' => !empty($s['is_default']),
        ];
    }
    api_success(['statuses' => $out]);
}

// =============================================================================
// LIST PRIORITIES
// =============================================================================

function api_agent_list_priorities()
{
    $priorities = get_priorities();
    $out = [];
    foreach ($priorities as $p) {
        $out[] = [
            'id' => (int) $p['id'],
            'name' => $p['name'],
            'color' => $p['color'] ?? null,
        ];
    }
    api_success(['priorities' => $out]);
}

// =============================================================================
// LIST USERS
// =============================================================================

function api_agent_list_users()
{
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $role_filter = $_GET['role'] ?? '';
    if ($role_filter === 'user') {
        $users = get_clients();
    } else {
        $users = get_all_users();
    }

    $exclude_ai = !empty($_GET['exclude_ai']);
    $out = [];
    foreach ($users as $u) {
        if (!empty($role_filter) && $role_filter !== 'user' && $u['role'] !== $role_filter) {
            continue;
        }
        $is_ai = !empty($u['is_ai_agent']);
        if ($exclude_ai && $is_ai) {
            continue;
        }
        $out[] = [
            'id' => (int) $u['id'],
            'email' => $u['email'],
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
            'role' => $u['role'],
            'organization_id' => $u['organization_id'] ? (int) $u['organization_id'] : null,
            'is_ai_agent' => $is_ai,
            'ai_model' => $u['ai_model'] ?? null,
        ];
    }
    api_success(['users' => $out]);
}

// =============================================================================
// CREATE TICKET
// =============================================================================

function api_agent_create_ticket()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $input = get_json_input();

    if (empty($input['title'])) {
        api_error('Field "title" is required', 422);
    }

    $user = current_user();
    $data = [
        'title' => trim($input['title']),
        'description' => $input['description'] ?? '',
        'user_id' => !empty($input['user_id']) ? (int) $input['user_id'] : $user['id'],
        'type' => $input['type'] ?? 'general',
    ];

    if (!empty($input['status_id'])) {
        $data['status_id'] = (int) $input['status_id'];
    }
    if (!empty($input['priority_id'])) {
        $data['priority_id'] = (int) $input['priority_id'];
    }
    if (array_key_exists('organization_id', $input)) {
        $data['organization_id'] = $input['organization_id'] ? (int) $input['organization_id'] : null;
    }
    if (!empty($input['due_date'])) {
        $data['due_date'] = $input['due_date'];
    }
    if (!empty($input['tags'])) {
        $data['tags'] = $input['tags'];
    }
    if (!empty($input['assignee_id'])) {
        $data['assignee_id'] = (int) $input['assignee_id'];
    }

    $ticket_id = create_ticket($data);
    if (!$ticket_id) {
        api_error('Failed to create ticket', 500);
    }

    // Fetch the created ticket for the response
    $ticket = db_fetch_one("SELECT id, hash, title FROM tickets WHERE id = ?", [$ticket_id]);

    $response = [
        'ticket_id' => (int) $ticket_id,
        'ticket_hash' => $ticket['hash'] ?? null,
        'ticket_code' => 'T-' . $ticket_id,
    ];

    // Auto-log time if duration_minutes provided
    $duration = (int) ($input['duration_minutes'] ?? 0);
    if ($duration > 0 && function_exists('add_manual_time_entry')) {
        $now = date('Y-m-d H:i:s');
        $source = (function_exists('is_ai_user') && is_ai_user($user['id'])) ? 'ai' : 'manual';
        $time_data = [
            'started_at' => $now,
            'ended_at' => date('Y-m-d H:i:s', strtotime($now) + ($duration * 60)),
            'duration_minutes' => $duration,
            'summary' => $input['time_summary'] ?? t('Ticket creation'),
            'is_billable' => 1,
            'source' => $source,
        ];
        $time_entry_id = add_manual_time_entry($ticket_id, $user['id'], $time_data);
        if ($time_entry_id) {
            $response['time_entry_id'] = (int) $time_entry_id;
            $response['duration_minutes'] = $duration;
        }
    }

    // In-app notifications
    if (function_exists('dispatch_ticket_notifications')) {
        $desc_text = strip_tags($input['description'] ?? '');
        $desc_preview = mb_strlen($desc_text) > 80 ? mb_substr($desc_text, 0, 77) . '...' : $desc_text;
        dispatch_ticket_notifications('new_ticket', $ticket_id, $user['id'], [
            'comment_preview' => $desc_preview,
        ]);
        if (!empty($input['assignee_id'])) {
            dispatch_ticket_notifications('assigned_to_you', $ticket_id, $user['id'], [
                'assignee_id' => (int) $input['assignee_id'],
            ]);
        }
    }

    api_success($response);
}

// =============================================================================
// LIST / SEARCH TICKETS
// =============================================================================

function api_agent_list_tickets()
{
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $filters = [];

    if (!empty($_GET['status'])) {
        // Accept status name or ID
        $status_val = $_GET['status'];
        if (is_numeric($status_val)) {
            $filters['status_id'] = (int) $status_val;
        } else {
            $status_row = db_fetch_one("SELECT id FROM statuses WHERE LOWER(name) = LOWER(?)", [$status_val]);
            if ($status_row) {
                $filters['status_id'] = (int) $status_row['id'];
            }
        }
    }
    if (!empty($_GET['priority'])) {
        $prio_val = $_GET['priority'];
        if (is_numeric($prio_val)) {
            $filters['priority_id'] = (int) $prio_val;
        } else {
            $prio_row = db_fetch_one("SELECT id FROM priorities WHERE LOWER(name) = LOWER(?)", [$prio_val]);
            if ($prio_row) {
                $filters['priority_id'] = (int) $prio_row['id'];
            }
        }
    }
    if (!empty($_GET['search'])) {
        $filters['search'] = $_GET['search'];
    }
    if (!empty($_GET['user_id'])) {
        $filters['user_id'] = (int) $_GET['user_id'];
    }
    if (!empty($_GET['assignee_id'])) {
        $filters['assignee_id'] = (int) $_GET['assignee_id'];
    }
    if (!empty($_GET['sort'])) {
        $filters['sort'] = $_GET['sort'];
    }

    $limit = min((int) ($_GET['limit'] ?? 50), 200);
    $offset = (int) ($_GET['offset'] ?? 0);

    // get_tickets returns all matching; we slice manually
    $all_tickets = get_tickets($filters);
    $total = count($all_tickets);
    $sliced = array_slice($all_tickets, $offset, $limit);

    $out = [];
    foreach ($sliced as $t) {
        $out[] = [
            'id' => (int) $t['id'],
            'hash' => $t['hash'] ?? null,
            'ticket_code' => 'T-' . $t['id'],
            'title' => $t['title'],
            'description' => mb_substr($t['description'] ?? '', 0, 300),
            'status' => $t['status_name'] ?? null,
            'status_color' => $t['status_color'] ?? null,
            'priority' => $t['priority_name'] ?? null,
            'priority_color' => $t['priority_color'] ?? null,
            'user' => trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')),
            'organization' => $t['organization_name'] ?? null,
            'created_at' => $t['created_at'] ?? null,
            'updated_at' => $t['updated_at'] ?? null,
            'due_date' => $t['due_date'] ?? null,
            'tags' => $t['tags'] ?? null,
        ];
    }

    api_success([
        'tickets' => $out,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

// =============================================================================
// GET SINGLE TICKET
// =============================================================================

function api_agent_get_ticket()
{
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $hash = $_GET['hash'] ?? '';
    $id = $_GET['id'] ?? '';

    $ticket = null;
    if (!empty($hash)) {
        $ticket = get_ticket_by_hash($hash);
    } elseif (!empty($id)) {
        $ticket = db_fetch_one(
            "SELECT t.*,
                    s.name as status_name, s.color as status_color,
                    u.first_name, u.last_name, u.email,
                    o.name as organization_name,
                    p.name as priority_name, p.color as priority_color,
                    a.first_name as assignee_first_name, a.last_name as assignee_last_name
             FROM tickets t
             LEFT JOIN statuses s ON t.status_id = s.id
             LEFT JOIN users u ON t.user_id = u.id
             LEFT JOIN organizations o ON t.organization_id = o.id
             LEFT JOIN priorities p ON t.priority_id = p.id
             LEFT JOIN users a ON t.assignee_id = a.id
             WHERE t.id = ?",
            [(int) $id]
        );
    } else {
        api_error('Provide "hash" or "id" parameter', 422);
    }

    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    // Get time breakdown
    $time_breakdown = ['total' => 0, 'human' => 0, 'ai' => 0];
    if (function_exists('get_ticket_time_breakdown')) {
        $time_breakdown = get_ticket_time_breakdown($ticket['id']);
    }

    // Get comments
    $comments = [];
    if (function_exists('get_comments')) {
        $raw_comments = get_comments($ticket['id']);
        foreach ($raw_comments as $c) {
            $comments[] = [
                'id' => (int) $c['id'],
                'content' => $c['content'],
                'is_internal' => !empty($c['is_internal']),
                'user' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
                'user_email' => $c['email'] ?? null,
                'is_ai_author' => function_exists('is_ai_user') ? is_ai_user((int) ($c['user_id'] ?? 0)) : false,
                'created_at' => $c['created_at'],
            ];
        }
    }

    // Get time entries
    $time_entries = [];
    if (function_exists('get_ticket_time_entries')) {
        $raw_time = get_ticket_time_entries($ticket['id']);
        foreach ($raw_time as $te) {
            $time_entries[] = [
                'id' => (int) $te['id'],
                'user' => trim(($te['first_name'] ?? '') . ' ' . ($te['last_name'] ?? '')),
                'started_at' => $te['started_at'],
                'ended_at' => $te['ended_at'] ?? null,
                'duration_minutes' => (int) ($te['duration_minutes'] ?? 0),
                'summary' => $te['summary'] ?? null,
                'is_billable' => !empty($te['is_billable']),
                'billable_rate' => (float) ($te['billable_rate'] ?? 0),
                'cost_rate' => (float) ($te['cost_rate'] ?? 0),
                'source' => function_exists('get_time_entry_source') ? get_time_entry_source($te) : (!empty($te['is_manual']) ? 'manual' : 'timer'),
                'is_ai_user' => function_exists('is_ai_user') ? is_ai_user((int) ($te['user_id'] ?? 0)) : false,
            ];
        }
    }

    api_success([
        'ticket' => [
            'id' => (int) $ticket['id'],
            'hash' => $ticket['hash'] ?? null,
            'ticket_code' => 'T-' . $ticket['id'],
            'title' => $ticket['title'],
            'description' => $ticket['description'] ?? '',
            'type' => $ticket['type'] ?? 'general',
            'status' => $ticket['status_name'] ?? null,
            'status_id' => (int) ($ticket['status_id'] ?? 0),
            'status_color' => $ticket['status_color'] ?? null,
            'priority' => $ticket['priority_name'] ?? null,
            'priority_id' => (int) ($ticket['priority_id'] ?? 0),
            'priority_color' => $ticket['priority_color'] ?? null,
            'user' => trim(($ticket['first_name'] ?? '') . ' ' . ($ticket['last_name'] ?? '')),
            'user_id' => (int) $ticket['user_id'],
            'assignee' => trim(($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? '')),
            'assignee_id' => $ticket['assignee_id'] ? (int) $ticket['assignee_id'] : null,
            'organization' => $ticket['organization_name'] ?? null,
            'organization_id' => $ticket['organization_id'] ? (int) $ticket['organization_id'] : null,
            'due_date' => $ticket['due_date'] ?? null,
            'tags' => $ticket['tags'] ?? null,
            'created_at' => $ticket['created_at'],
            'updated_at' => $ticket['updated_at'] ?? null,
            'total_time_minutes' => $time_breakdown['total'],
            'human_time_minutes' => $time_breakdown['human'],
            'ai_time_minutes' => $time_breakdown['ai'],
        ],
        'comments' => $comments,
        'time_entries' => $time_entries,
    ]);
}

// =============================================================================
// ADD COMMENT
// =============================================================================

function api_agent_add_comment()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $input = get_json_input();

    // Resolve ticket
    $ticket_id = null;
    if (!empty($input['ticket_hash'])) {
        $tid = null;
        if (function_exists('get_ticket_id_by_hash')) {
            $tid = get_ticket_id_by_hash($input['ticket_hash']);
        }
        $ticket_id = $tid;
    } elseif (!empty($input['ticket_id'])) {
        $ticket_id = (int) $input['ticket_id'];
    }

    if (!$ticket_id) {
        api_error('Provide "ticket_hash" or "ticket_id"', 422);
    }
    if (empty($input['content'])) {
        api_error('Field "content" is required', 422);
    }

    // Verify ticket exists
    $ticket = db_fetch_one("SELECT id FROM tickets WHERE id = ?", [$ticket_id]);
    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    $user = current_user();
    $is_internal = !empty($input['is_internal']) ? 1 : 0;

    $comment_id = add_comment($ticket_id, $user['id'], $input['content'], $is_internal);
    if (!$comment_id) {
        api_error('Failed to add comment', 500);
    }

    $response = ['comment_id' => (int) $comment_id];

    // Auto-log time if duration_minutes provided
    $duration = (int) ($input['duration_minutes'] ?? 0);
    if ($duration > 0 && function_exists('add_manual_time_entry')) {
        $now = date('Y-m-d H:i:s');
        $source = (function_exists('is_ai_user') && is_ai_user($user['id'])) ? 'ai' : 'manual';
        $time_data = [
            'started_at' => $now,
            'ended_at' => date('Y-m-d H:i:s', strtotime($now) + ($duration * 60)),
            'duration_minutes' => $duration,
            'summary' => $input['time_summary'] ?? null,
            'is_billable' => 1,
            'source' => $source,
        ];
        $time_entry_id = add_manual_time_entry($ticket_id, $user['id'], $time_data);
        if ($time_entry_id) {
            $response['time_entry_id'] = (int) $time_entry_id;
            $response['duration_minutes'] = $duration;
        }
    }

    // In-app notification for new comment (skip internal notes)
    if (!$is_internal && function_exists('dispatch_ticket_notifications')) {
        $preview = mb_strlen($input['content']) > 80 ? mb_substr($input['content'], 0, 77) . '...' : $input['content'];
        dispatch_ticket_notifications('new_comment', $ticket_id, $user['id'], [
            'comment_preview' => strip_tags($preview),
            'comment_id' => $comment_id,
        ]);
    }

    api_success($response);
}

// =============================================================================
// UPDATE STATUS
// =============================================================================

function api_agent_update_status()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $input = get_json_input();

    // Resolve ticket
    $ticket_id = null;
    if (!empty($input['ticket_hash'])) {
        if (function_exists('get_ticket_id_by_hash')) {
            $ticket_id = get_ticket_id_by_hash($input['ticket_hash']);
        }
    } elseif (!empty($input['ticket_id'])) {
        $ticket_id = (int) $input['ticket_id'];
    }

    if (!$ticket_id) {
        api_error('Provide "ticket_hash" or "ticket_id"', 422);
    }

    // Resolve status
    $status_id = null;
    if (!empty($input['status_id'])) {
        $status_id = (int) $input['status_id'];
    } elseif (!empty($input['status'])) {
        $status_row = db_fetch_one("SELECT id FROM statuses WHERE LOWER(name) = LOWER(?)", [$input['status']]);
        if ($status_row) {
            $status_id = (int) $status_row['id'];
        }
    }

    if (!$status_id) {
        api_error('Provide "status_id" or "status" (name)', 422);
    }

    // Verify ticket exists
    $ticket = db_fetch_one("SELECT id, status_id FROM tickets WHERE id = ?", [$ticket_id]);
    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    // Verify status exists
    $status = db_fetch_one("SELECT id, name FROM statuses WHERE id = ?", [$status_id]);
    if (!$status) {
        api_error('Status not found', 404);
    }

    update_ticket($ticket_id, ['status_id' => $status_id]);

    // Log activity
    if (function_exists('log_activity')) {
        $user = current_user();
        log_activity($ticket_id, $user['id'], 'status_changed', json_encode([
            'old_status_id' => (int) $ticket['status_id'],
            'new_status_id' => $status_id,
        ]));
    }

    // In-app notification for status change
    if (function_exists('dispatch_ticket_notifications')) {
        $user = $user ?? current_user();
        $old_status_row = db_fetch_one("SELECT name FROM statuses WHERE id = ?", [(int) $ticket['status_id']]);
        dispatch_ticket_notifications('status_changed', $ticket_id, $user['id'], [
            'old_status' => $old_status_row['name'] ?? '',
            'new_status' => $status['name'] ?? '',
        ]);
    }

    api_success([
        'ticket_id' => (int) $ticket_id,
        'status_id' => $status_id,
        'status' => $status['name'],
    ]);
}

// =============================================================================
// LOG TIME
// =============================================================================

function api_agent_log_time()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    if (!is_agent()) {
        api_error('Forbidden — agent or admin role required', 403);
    }

    $input = get_json_input();

    // Resolve ticket
    $ticket_id = null;
    if (!empty($input['ticket_hash'])) {
        if (function_exists('get_ticket_id_by_hash')) {
            $ticket_id = get_ticket_id_by_hash($input['ticket_hash']);
        }
    } elseif (!empty($input['ticket_id'])) {
        $ticket_id = (int) $input['ticket_id'];
    }

    if (!$ticket_id) {
        api_error('Provide "ticket_hash" or "ticket_id"', 422);
    }

    // Duration is required
    if (empty($input['duration_minutes']) || (int) $input['duration_minutes'] < 1) {
        api_error('Field "duration_minutes" is required (positive integer)', 422);
    }

    // Verify ticket exists
    $ticket = db_fetch_one("SELECT id FROM tickets WHERE id = ?", [$ticket_id]);
    if (!$ticket) {
        api_error('Ticket not found', 404);
    }

    $user = current_user();
    $duration = (int) $input['duration_minutes'];
    $now = date('Y-m-d H:i:s');
    $started_at = $input['started_at'] ?? $now;
    $ended_at = $input['ended_at'] ?? date('Y-m-d H:i:s', strtotime($started_at) + ($duration * 60));

    // Determine source: AI agent token defaults to 'ai', human token to 'manual'
    $default_source = (function_exists('is_ai_user') && is_ai_user($user['id'])) ? 'ai' : 'manual';
    $source = $input['source'] ?? $default_source;
    if (!in_array($source, ['timer', 'manual', 'ai'], true)) {
        $source = $default_source;
    }

    $data = [
        'started_at' => $started_at,
        'ended_at' => $ended_at,
        'duration_minutes' => $duration,
        'summary' => $input['summary'] ?? null,
        'is_billable' => isset($input['is_billable']) ? ($input['is_billable'] ? 1 : 0) : 1,
        'source' => $source,
    ];

    // Apply billable_rate: explicit input > AI setting > default
    if (isset($input['billable_rate'])) {
        $data['billable_rate'] = (float) $input['billable_rate'];
    }

    if (function_exists('add_manual_time_entry')) {
        $entry_id = add_manual_time_entry($ticket_id, $user['id'], $data);
    } else {
        // Fallback direct insert
        $entry_id = db_insert('ticket_time_entries', array_merge($data, [
            'ticket_id' => $ticket_id,
            'user_id' => $user['id'],
            'is_manual' => ($source === 'timer') ? 0 : 1,
            'created_at' => $now,
        ]));
    }

    if (!$entry_id) {
        api_error('Failed to log time entry', 500);
    }

    api_success([
        'time_entry_id' => (int) $entry_id,
        'duration_minutes' => $duration,
        'source' => $source,
    ]);
}

