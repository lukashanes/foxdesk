<?php
/**
 * API Handler: User Operations
 *
 * Handles user-related API actions like search.
 */

/**
 * Handle user search (for CC autocomplete)
 */
function api_search_users() {
    // Only admins and agents can search users
    if (!is_admin() && !is_agent()) {
        api_error('Forbidden', 403);
    }

    $query = trim($_GET['q'] ?? '');

    if (strlen($query) < 2) {
        // Return flat array (consumed directly by CC autocomplete)
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    // Search in first_name, last_name, and email
    $search_term = '%' . $query . '%';
    $users = db_fetch_all("
        SELECT id, first_name, last_name, email
        FROM users
        WHERE is_active = 1
          AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
        ORDER BY first_name, last_name
        LIMIT 10
    ", [$search_term, $search_term, $search_term]);

    // Format results — flat array format expected by CC autocomplete
    $results = [];
    foreach ($users as $u) {
        $results[] = [
            'id' => $u['id'],
            'name' => $u['first_name'] . ' ' . $u['last_name'],
            'email' => $u['email']
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($results);
    exit;
}

/**
 * Get tickets for the current user (for new ticket page sidebar)
 */
function api_get_user_tickets() {
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }
    $limit = min((int)($_GET['limit'] ?? 20), 50);

    // Build query based on user role and permissions
    $sql = "SELECT t.id, t.title, t.created_at, s.name as status_name, s.slug as status_slug
            FROM tickets t
            LEFT JOIN statuses s ON t.status_id = s.id
            WHERE t.is_archived = 0";
    $params = [];

    // Filter based on user permissions
    if ($user['role'] === 'user') {
        // Users see only their own tickets or organization tickets
        $permissions = json_decode($user['permissions'] ?? '{}', true) ?? [];
        $ticket_scope = $permissions['ticket_scope'] ?? 'own';

        if ($ticket_scope === 'organization') {
            $org_ids = get_user_organization_ids($user['id']);
            if (!empty($org_ids)) {
                $org_placeholders = implode(',', array_fill(0, count($org_ids), '?'));
                $sql .= " AND (t.user_id = ? OR t.organization_id IN ($org_placeholders))";
                $params[] = $user['id'];
                $params = array_merge($params, $org_ids);
            } else {
                $sql .= " AND t.user_id = ?";
                $params[] = $user['id'];
            }
        } else {
            $sql .= " AND t.user_id = ?";
            $params[] = $user['id'];
        }
    } elseif ($user['role'] === 'agent') {
        $permissions = json_decode($user['permissions'] ?? '{}', true) ?? [];
        $ticket_scope = $permissions['ticket_scope'] ?? 'all';

        if ($ticket_scope === 'assigned') {
            $sql .= " AND t.assignee_id = ?";
            $params[] = $user['id'];
        } elseif ($ticket_scope === 'organization') {
            $org_ids = get_user_organization_ids($user['id']);
            if (!empty($org_ids)) {
                $org_placeholders = implode(',', array_fill(0, count($org_ids), '?'));
                $sql .= " AND t.organization_id IN ($org_placeholders)";
                $params = array_merge($params, $org_ids);
            } else {
                $sql .= " AND 0=1";
            }
        }
        // 'all' scope sees all tickets
    }
    // Admins see all tickets

    $sql .= " ORDER BY t.created_at DESC LIMIT ?";
    $params[] = $limit;

    $tickets = db_fetch_all($sql, $params);

    // Format created_at for display
    foreach ($tickets as &$ticket) {
        $created = strtotime($ticket['created_at']);
        $now = time();
        $diff = $now - $created;

        if ($diff < 60) {
            $ticket['created_at_human'] = t('Just now');
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            $ticket['created_at_human'] = $mins . ' ' . t('min ago');
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            $ticket['created_at_human'] = $hours . ' ' . t('hours ago');
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            $ticket['created_at_human'] = $days . ' ' . t('days ago');
        } else {
            $ticket['created_at_human'] = date('M j', $created);
        }
    }

    api_success(['tickets' => $tickets]);
}

/**
 * Get active timer for the current user (for browser tab indicator)
 */
function api_get_active_timer() {
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    // Check if time tracking is available
    if (!ticket_time_table_exists()) {
        api_success(['active' => false]);
    }

    // Find active timer for this user
    $active = db_fetch_one("
        SELECT tte.id, tte.ticket_id, tte.started_at, tte.paused_at, tte.paused_seconds, t.title as ticket_title
        FROM ticket_time_entries tte
        LEFT JOIN tickets t ON tte.ticket_id = t.id
        WHERE tte.user_id = ? AND tte.ended_at IS NULL
        ORDER BY tte.started_at DESC
        LIMIT 1
    ", [$user['id']]);

    if ($active) {
        $started_ts = strtotime($active['started_at']);
        $paused_seconds = (int)($active['paused_seconds'] ?? 0);
        $is_paused = !empty($active['paused_at']);

        // If paused, add time since pause started to paused_seconds for display
        $display_paused = $paused_seconds;
        if ($is_paused && $active['paused_at']) {
            $display_paused += time() - strtotime($active['paused_at']);
        }

        $elapsed_seconds = max(0, time() - $started_ts - $display_paused);
        $elapsed_minutes = (int) floor($elapsed_seconds / 60);
        $hours = floor($elapsed_minutes / 60);
        $mins = $elapsed_minutes % 60;
        $elapsed_str = $hours > 0 ? sprintf('%d:%02d', $hours, $mins) : sprintf('%d min', $mins);

        api_success([
            'active' => true,
            'ticket_id' => (int)$active['ticket_id'],
            'ticket_title' => $active['ticket_title'],
            'started_at' => $started_ts,
            'paused_seconds' => $display_paused,
            'is_paused' => $is_paused,
            'elapsed_minutes' => $elapsed_minutes,
            'elapsed_str' => $elapsed_str
        ]);
    } else {
        api_success(['active' => false]);
    }
}

/**
 * Get ALL active timers for the current user (for sidebar widget)
 */
function api_get_active_timers() {
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    if (!ticket_time_table_exists()) {
        api_success(['timers' => []]);
    }

    $timers = get_user_all_active_timers($user['id']);
    $result = [];

    foreach ($timers as $timer) {
        $started_ts = strtotime($timer['started_at']);
        $paused_seconds = (int)($timer['paused_seconds'] ?? 0);
        $is_paused = !empty($timer['paused_at']);

        $display_paused = $paused_seconds;
        if ($is_paused && $timer['paused_at']) {
            $display_paused += time() - strtotime($timer['paused_at']);
        }

        $elapsed_seconds = max(0, time() - $started_ts - $display_paused);
        $elapsed_minutes = (int) floor($elapsed_seconds / 60);
        $hours = floor($elapsed_minutes / 60);
        $mins = $elapsed_minutes % 60;
        $elapsed_str = $hours > 0 ? sprintf('%dh %dmin', $hours, $mins) : sprintf('%d min', $mins);

        $result[] = [
            'ticket_id'      => (int)$timer['ticket_id'],
            'ticket_hash'    => $timer['ticket_hash'] ?? null,
            'ticket_title'   => $timer['ticket_title'],
            'started_at'     => $started_ts,
            'paused_seconds' => $display_paused,
            'is_paused'      => $is_paused,
            'elapsed_minutes'=> $elapsed_minutes,
            'elapsed_str'    => $elapsed_str,
        ];
    }

    api_success(['timers' => $result]);
}

/**
 * Add user to organization (AJAX)
 */
function api_org_add_user() {
    if (!is_admin()) {
        api_error('Forbidden', 403);
    }

    require_csrf_token(true);

    $input = get_json_input();
    $org_id = (int)($input['org_id'] ?? 0);
    $user_id = (int)($input['user_id'] ?? 0);

    if ($org_id <= 0 || $user_id <= 0) {
        api_error('Invalid data');
    }

    // Verify organization exists
    $org = get_organization($org_id);
    if (!$org) {
        api_error('Organization not found');
    }

    // Verify user exists
    $user = db_fetch_one("SELECT id, first_name, last_name, email, role FROM users WHERE id = ?", [$user_id]);
    if (!$user) {
        api_error('User not found');
    }

    if (!add_user_organization_membership($user_id, $org_id)) {
        api_error('Failed to update user organization');
    }

    api_success([
        'user' => [
            'id' => $user['id'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'organization_ids' => get_user_organization_ids($user_id)
        ]
    ]);
}

/**
 * Remove user from organization (AJAX)
 */
function api_org_remove_user() {
    if (!is_admin()) {
        api_error('Forbidden', 403);
    }

    require_csrf_token(true);

    $input = get_json_input();
    $user_id = (int)($input['user_id'] ?? 0);
    $org_id = (int)($input['org_id'] ?? 0);

    if ($user_id <= 0 || $org_id <= 0) {
        api_error('Invalid data');
    }

    // Verify user exists
    $user = db_fetch_one("SELECT id FROM users WHERE id = ?", [$user_id]);
    if (!$user) {
        api_error('User not found');
    }

    if (!remove_user_organization_membership($user_id, $org_id)) {
        api_error('Failed to update user organization');
    }

    api_success();
}

/**
 * Save dashboard layout (order + hidden sections) for the current user
 */
function api_save_dashboard_layout() {
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    $input = get_json_input();
    $layout = $input['layout'] ?? null;

    if (!is_array($layout)) {
        api_error('Invalid layout', 400);
    }

    // Sanitize: only allow known widget IDs
    $allowed = [
        'overview', 'new_tickets', 'deadlines', 'my_time', 'team_time',
        'status_chart', 'due_week', 'timers', 'focus', 'recent',
        'completed', 'notifications',
        // Legacy IDs for backwards compatibility
        'time', 'charts',
    ];

    // Support both new format {order, hidden, sizes} and old flat array
    if (isset($layout['order'])) {
        $order = array_values(array_filter(
            (array)($layout['order'] ?? []),
            fn($s) => in_array($s, $allowed, true)
        ));
        $hidden = array_values(array_filter(
            (array)($layout['hidden'] ?? []),
            fn($s) => in_array($s, $allowed, true)
        ));
        // Persist widget sizes (half/full)
        $sizes = [];
        if (is_array($layout['sizes'] ?? null)) {
            $valid_sizes = ['half', 'full'];
            foreach ($layout['sizes'] as $wid => $sz) {
                if (in_array($wid, $allowed, true) && in_array($sz, $valid_sizes, true)) {
                    $sizes[$wid] = $sz;
                }
            }
        }
        $save = json_encode(['order' => $order, 'hidden' => $hidden, 'sizes' => $sizes]);
    } else {
        // Old format: flat array → convert to new format
        $order = array_values(array_filter($layout, fn($s) => in_array($s, $allowed, true)));
        $save = json_encode(['order' => $order, 'hidden' => [], 'sizes' => []]);
    }

    db_query("UPDATE users SET dashboard_layout = ? WHERE id = ?", [
        $save,
        $user['id']
    ]);

    api_success(['saved' => true]);
}


