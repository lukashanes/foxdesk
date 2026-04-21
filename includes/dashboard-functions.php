<?php
/**
 * Dashboard Functions
 * Handles data aggregation and logic for the dashboard view.
 */

function get_dashboard_data($user, $tags = [])
{
    $is_admin = $user['role'] === 'admin';
    $is_agent = in_array($user['role'], ['admin', 'agent'], true);
    $is_staff = $is_agent;

    // ─── Tag filter (reusable SQL fragment) ──────────────────
    $tag_where = '';
    $tag_params = [];
    if (!empty($tags) && function_exists('ticket_tags_column_exists') && ticket_tags_column_exists()) {
        $tag_clauses = [];
        foreach ($tags as $tag) {
            $tag_clauses[] = "FIND_IN_SET(?, REPLACE(IFNULL(t.tags, ''), ', ', ',')) > 0";
            $tag_params[] = $tag;
        }
        $tag_where = ' AND (' . implode(' OR ', $tag_clauses) . ')';
    }

    // ─── Base Data ───────────────────────────────────────────
    $closed_statuses = db_fetch_all("SELECT id FROM statuses WHERE is_closed = 1");
    $closed_ids = array_map('intval', array_column($closed_statuses, 'id'));
    $closed_placeholder = !empty($closed_ids) ? implode(',', array_fill(0, count($closed_ids), '?')) : '0';
    $closed_lookup = array_flip($closed_ids);

    $scope_where = '';
    $scope_params = [];
    if ($is_admin) {
        $scope_where = "t.assignee_id IN (SELECT id FROM users WHERE role IN ('agent', 'admin'))";
    } elseif ($is_agent) {
        $scope_where = 't.assignee_id = ?';
        $scope_params[] = $user['id'];
    } else {
        $scope_where = 't.user_id = ?';
        $scope_params[] = $user['id'];
    }

    $week_start = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $week_end = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    $month_start = date('Y-m-01 00:00:00');
    $month_end = date('Y-m-t 23:59:59');

    // ─── Workload Stats (open tickets + due dates) ──────────
    $workload_sql = "
        SELECT
            COUNT(*) as open_count,
            SUM(CASE WHEN t.due_date IS NOT NULL AND t.due_date < NOW() THEN 1 ELSE 0 END) as overdue_count,
            SUM(CASE WHEN t.due_date IS NOT NULL AND DATE(t.due_date) = CURDATE() AND t.due_date >= NOW() THEN 1 ELSE 0 END) as due_today_count,
            SUM(CASE WHEN t.due_date >= ? AND t.due_date <= ? THEN 1 ELSE 0 END) as due_week_count,
            SUM(CASE WHEN t.due_date >= ? AND t.due_date <= ? THEN 1 ELSE 0 END) as due_month_count
        FROM tickets t
        WHERE {$scope_where}
          AND (t.is_archived IS NULL OR t.is_archived = 0)
          AND t.status_id NOT IN ($closed_placeholder)
          {$tag_where}
    ";
    $workload_params = array_merge([$week_start, $week_end, $month_start, $month_end], $scope_params, $closed_ids, $tag_params);
    $workload_result = db_fetch_one($workload_sql, $workload_params);

    $workload_stats = [
        'open' => (int) ($workload_result['open_count'] ?? 0),
        'overdue' => (int) ($workload_result['overdue_count'] ?? 0),
        'due_today' => (int) ($workload_result['due_today_count'] ?? 0),
        'due_this_week' => (int) ($workload_result['due_week_count'] ?? 0),
        'due_this_month' => (int) ($workload_result['due_month_count'] ?? 0),
    ];

    // ─── New Ticket Stats (created recently) ─────────────────
    $new_sql = "
        SELECT
            SUM(CASE WHEN DATE(t.created_at) = CURDATE() THEN 1 ELSE 0 END) as new_today,
            SUM(CASE WHEN t.created_at >= ? AND t.created_at <= ? THEN 1 ELSE 0 END) as new_week,
            SUM(CASE WHEN t.created_at >= ? AND t.created_at <= ? THEN 1 ELSE 0 END) as new_month
        FROM tickets t
        WHERE {$scope_where}
          AND (t.is_archived IS NULL OR t.is_archived = 0)
          {$tag_where}
    ";
    $new_params = array_merge([$week_start, $week_end, $month_start, $month_end], $scope_params, $tag_params);
    $new_result = db_fetch_one($new_sql, $new_params);

    $new_ticket_stats = [
        'today' => (int) ($new_result['new_today'] ?? 0),
        'week' => (int) ($new_result['new_week'] ?? 0),
        'month' => (int) ($new_result['new_month'] ?? 0),
    ];

    // ─── Total visible tickets ──────────────────────────────
    // Always use $scope_where so counts match the dashboard context exactly
    $status_counts = db_fetch_all("
        SELECT s.id, s.name, s.color, s.is_closed, COUNT(t.id) as count
        FROM statuses s
        LEFT JOIN tickets t ON t.status_id = s.id
            AND {$scope_where}
            AND (t.is_archived IS NULL OR t.is_archived = 0)
            {$tag_where}
        GROUP BY s.id
        ORDER BY s.sort_order ASC
    ", array_merge($scope_params, $tag_params));
    $total_visible_tickets = 0;
    $closed_visible_tickets = 0;
    $status_chart_ids = [];
    $status_chart_labels = [];
    $status_chart_values = [];
    $status_chart_colors = [];

    foreach ($status_counts as $status) {
        $count = (int) ($status['count'] ?? 0);
        $status_id = (int) ($status['id'] ?? 0);
        $total_visible_tickets += $count;
        if (isset($closed_lookup[$status_id])) {
            $closed_visible_tickets += $count;
        }
        if ($count > 0) {
            $status_chart_ids[] = $status_id;
            $status_chart_labels[] = (string) $status['name'];
            $status_chart_values[] = $count;
            $status_chart_colors[] = (string) ($status['color'] ?? '#94a3b8');
        }
    }

    // ─── Priority data ──────────────────────────────────────
    $priority_scope_where = $is_admin
        ? "t.assignee_id IN (SELECT id FROM users WHERE role IN ('agent', 'admin'))"
        : ($is_agent ? 't.assignee_id = ?' : 't.user_id = ?');
    $priority_scope_params = $is_admin ? [] : [$user['id']];

    $priority_counts = db_fetch_all("
        SELECT p.id, p.name, p.color, COUNT(t.id) as count
        FROM priorities p
        LEFT JOIN tickets t ON t.priority_id = p.id
            AND {$priority_scope_where}
            AND (t.is_archived IS NULL OR t.is_archived = 0)
            AND t.status_id NOT IN ($closed_placeholder)
            {$tag_where}
        GROUP BY p.id
        ORDER BY p.sort_order ASC
    ", array_merge($priority_scope_params, $closed_ids, $tag_params));

    $priority_chart_ids = [];
    $priority_chart_labels = [];
    $priority_chart_values = [];
    $priority_chart_colors = [];
    foreach ($priority_counts as $priority) {
        $count = (int) ($priority['count'] ?? 0);
        if ($count <= 0)
            continue;
        $priority_chart_ids[] = (int) $priority['id'];
        $priority_chart_labels[] = (string) $priority['name'];
        $priority_chart_values[] = $count;
        $priority_chart_colors[] = (string) ($priority['color'] ?? '#3b82f6');
    }

    // ─── Time Tracking ──────────────────────────────────────
    $my_time_today = 0;
    $my_time_week = 0;
    $my_time_month = 0;
    $team_time_today = 0;
    $team_time_week = 0;
    $team_time_month = 0;
    $team_members_time = [];

    if (function_exists('ticket_time_table_exists') && ticket_time_table_exists() && $is_staff) {
        $dur = function_exists('sql_timer_duration_minutes') ? sql_timer_duration_minutes() : 'duration_minutes';
        $my_time = db_fetch_one("
            SELECT
                SUM(CASE WHEN DATE(started_at) = CURDATE() THEN ({$dur}) ELSE 0 END) as today,
                SUM(CASE WHEN started_at >= ? AND started_at <= ? THEN ({$dur}) ELSE 0 END) as week,
                SUM(CASE WHEN started_at >= ? AND started_at <= ? THEN ({$dur}) ELSE 0 END) as month
            FROM ticket_time_entries
            WHERE user_id = ?
        ", [$week_start, $week_end, $month_start, $month_end, $user['id']]);

        $my_time_today = (int) ($my_time['today'] ?? 0);
        $my_time_week = (int) ($my_time['week'] ?? 0);
        $my_time_month = (int) ($my_time['month'] ?? 0);

        if ($is_admin) {
            $dur_tte = function_exists('sql_timer_duration_minutes') ? sql_timer_duration_minutes('tte.') : 'tte.duration_minutes';
            $team_time = db_fetch_one("
                SELECT
                    SUM(CASE WHEN DATE(tte.started_at) = CURDATE() THEN ({$dur_tte}) ELSE 0 END) as today,
                    SUM(CASE WHEN tte.started_at >= ? AND tte.started_at <= ? THEN ({$dur_tte}) ELSE 0 END) as week,
                    SUM(CASE WHEN tte.started_at >= ? AND tte.started_at <= ? THEN ({$dur_tte}) ELSE 0 END) as month
                FROM ticket_time_entries tte
                JOIN users u ON u.id = tte.user_id
                WHERE u.role IN ('agent', 'admin')
            ", [$week_start, $week_end, $month_start, $month_end]);

            $team_time_today = (int) ($team_time['today'] ?? 0);
            $team_time_week = (int) ($team_time['week'] ?? 0);
            $team_time_month = (int) ($team_time['month'] ?? 0);

            // Per-member breakdown
            $team_members_time = db_fetch_all("
                SELECT
                    u.id, u.first_name, u.last_name, u.role, u.avatar,
                    COALESCE(SUM(CASE WHEN DATE(tte.started_at) = CURDATE() THEN ({$dur_tte}) ELSE 0 END), 0) as today_mins,
                    COALESCE(SUM(CASE WHEN tte.started_at >= ? AND tte.started_at <= ? THEN ({$dur_tte}) ELSE 0 END), 0) as week_mins,
                    COALESCE(SUM(CASE WHEN tte.started_at >= ? AND tte.started_at <= ? THEN ({$dur_tte}) ELSE 0 END), 0) as month_mins
                FROM users u
                LEFT JOIN ticket_time_entries tte ON tte.user_id = u.id
                WHERE u.role IN ('agent', 'admin') AND u.is_active = 1
                  AND (u.deleted_at IS NULL)
                GROUP BY u.id
                ORDER BY month_mins DESC
            ", [$week_start, $week_end, $month_start, $month_end]);
        }
    }

    // ─── Link Parameters ────────────────────────────────────
    if ($is_agent && !$is_admin) {
        $scope_link_params = ['assigned_to' => $user['id']];
    } elseif ($is_admin) {
        $scope_link_params = ['scope' => 'staff'];
    } else {
        $scope_link_params = [];
    }
    // Carry tag filter through to ticket list links
    if (!empty($tags)) {
        $scope_link_params['tags'] = implode(',', $tags);
    }

    // Pre-compute interactive stat URLs
    $link_new_today = url('tickets', array_merge($scope_link_params, ['created_from' => date('Y-m-d')]));
    $link_new_week = url('tickets', array_merge($scope_link_params, ['created_from' => date('Y-m-d', strtotime('monday this week'))]));
    $link_new_month = url('tickets', array_merge($scope_link_params, ['created_from' => date('Y-m-01')]));
    $link_overdue = url('tickets', array_merge($scope_link_params, ['due_date' => 'overdue']));
    $link_due_today = url('tickets', array_merge($scope_link_params, ['due_date' => 'today']));
    $link_due_week = url('tickets', array_merge($scope_link_params, ['due_date' => 'week']));
    $link_due_month = url('tickets', array_merge($scope_link_params, ['due_date' => 'month']));
    $link_reports = url('admin', ['section' => 'reports']);

    // ─── Ticket Queries ─────────────────────────────────────
    $ticket_scope_where = $is_admin
        ? "t.assignee_id IN (SELECT id FROM users WHERE role IN ('agent', 'admin'))"
        : ($is_agent ? 't.assignee_id = ?' : 't.user_id = ?');
    $ticket_scope_params = ($is_admin) ? [] : [$user['id']];

    $recent_tickets = db_fetch_all("
        SELECT t.*,
               s.name as status_name, s.color as status_color, s.is_closed,
               p.name as priority_name, p.color as priority_color,
               u.first_name, u.last_name,
               a.first_name as assignee_first_name, a.last_name as assignee_last_name,
               (SELECT COUNT(*) FROM comments WHERE ticket_id = t.id) as comment_count,
               (SELECT COUNT(*) FROM attachments WHERE ticket_id = t.id) as attachment_count
        FROM tickets t
        LEFT JOIN statuses s ON t.status_id = s.id
        LEFT JOIN priorities p ON t.priority_id = p.id
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN users a ON t.assignee_id = a.id
        WHERE {$ticket_scope_where}
          AND (t.is_archived IS NULL OR t.is_archived = 0)
          {$tag_where}
        ORDER BY
          CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END,
          t.due_date ASC,
          t.created_at DESC
        LIMIT 8
    ", array_merge($ticket_scope_params, $tag_params));

    // Due this week tickets
    $due_week_tickets = [];
    if ($is_staff) {
        $due_week_tickets = db_fetch_all("
            SELECT t.*,
                   s.name as status_name, s.color as status_color,
                   p.name as priority_name, p.color as priority_color,
                   u.first_name, u.last_name,
                   a.first_name as assignee_first_name, a.last_name as assignee_last_name
            FROM tickets t
            LEFT JOIN statuses s ON t.status_id = s.id
            LEFT JOIN priorities p ON t.priority_id = p.id
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN users a ON t.assignee_id = a.id
            WHERE {$ticket_scope_where}
              AND (t.is_archived IS NULL OR t.is_archived = 0)
              AND t.status_id NOT IN ($closed_placeholder)
              AND t.due_date >= ?
              AND t.due_date <= ?
              {$tag_where}
            ORDER BY t.due_date ASC
            LIMIT 6
        ", array_merge($ticket_scope_params, $closed_ids, [$week_start, $week_end], $tag_params));
    }

    // Completed tickets (resolved/closed this month — staff only)
    $completed_tickets = [];
    if ($is_staff) {
        $completed_tickets = db_fetch_all("
            SELECT t.*,
                   s.name as status_name, s.color as status_color,
                   p.name as priority_name, p.color as priority_color,
                   u.first_name, u.last_name,
                   a.first_name as assignee_first_name, a.last_name as assignee_last_name
            FROM tickets t
            LEFT JOIN statuses s ON t.status_id = s.id
            LEFT JOIN priorities p ON t.priority_id = p.id
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN users a ON t.assignee_id = a.id
            WHERE {$ticket_scope_where}
              AND (t.is_archived IS NULL OR t.is_archived = 0)
              AND t.status_id IN ($closed_placeholder)
              AND t.updated_at >= ?
              {$tag_where}
            ORDER BY t.updated_at DESC
            LIMIT 20
        ", array_merge($ticket_scope_params, $closed_ids, [$month_start], $tag_params));
    }

    // Focus tickets (non-admin)
    $focus_tickets = [];
    if (!$is_admin && !empty($recent_tickets)) {
        $today_start = strtotime(date('Y-m-d 00:00:00'));
        $today_end = strtotime(date('Y-m-d 23:59:59'));

        foreach ($recent_tickets as $ticket) {
            $status_id_current = (int) ($ticket['status_id'] ?? 0);
            if (isset($closed_lookup[$status_id_current]))
                continue;

            $due_ts = !empty($ticket['due_date']) ? strtotime((string) $ticket['due_date']) : false;
            $urgency_rank = 3;
            if ($due_ts !== false) {
                $is_overdue = is_due_date_overdue($ticket['due_date'] ?? null, !empty($ticket['is_closed']));
                if ($is_overdue)
                    $urgency_rank = 0;
                elseif ($due_ts <= $today_end)
                    $urgency_rank = 1;
                else
                    $urgency_rank = 2;
            }

            $priority_label = strtolower((string) ($ticket['priority_name'] ?? ''));
            $priority_boost = (strpos($priority_label, 'critical') !== false
                || strpos($priority_label, 'urgent') !== false
                || strpos($priority_label, 'high') !== false
                || strpos($priority_label, 'vysok') !== false
                || strpos($priority_label, 'krit') !== false) ? 1 : 0;

            $focus_tickets[] = [
                'ticket' => $ticket,
                'urgency_rank' => $urgency_rank,
                'priority_boost' => $priority_boost,
                'due_ts' => $due_ts !== false ? (int) $due_ts : PHP_INT_MAX,
                'created_ts' => strtotime((string) ($ticket['created_at'] ?? '')) ?: 0,
            ];
        }

        usort($focus_tickets, static function (array $a, array $b): int {
            if ($a['urgency_rank'] !== $b['urgency_rank'])
                return $a['urgency_rank'] <=> $b['urgency_rank'];
            if ($a['priority_boost'] !== $b['priority_boost'])
                return $b['priority_boost'] <=> $a['priority_boost'];
            if ($a['due_ts'] !== $b['due_ts'])
                return $a['due_ts'] <=> $b['due_ts'];
            return $b['created_ts'] <=> $a['created_ts'];
        });
        $focus_tickets = array_slice($focus_tickets, 0, 5);
    }

    // Active timers
    $active_timers = [];
    if ($is_staff && function_exists('ticket_time_table_exists') && ticket_time_table_exists()) {
        $active_timers = get_user_all_active_timers($user['id']);
    }

    // ─── Dashboard Layout Config (v2 — Grid Based) ─────────
    $dashboard_layout = null;
    try {
        $col_check = db_fetch_one("SHOW COLUMNS FROM users LIKE 'dashboard_layout'");
        if ($col_check && !empty($user['dashboard_layout'])) {
            $dashboard_layout = json_decode($user['dashboard_layout'], true);
        }
    } catch (Exception $e) {
    }

    // Clean default layout: essential widgets first, secondary available via Customize
    //   Visible: overview → focus → my_time → due_week
    //   Hidden (toggle via Customize): notifications, new_tickets, deadlines, team_time, completed, status_chart, timers
    if ($is_admin) {
        $default_order = ['overview', 'focus', 'my_time', 'due_week', 'notifications', 'new_tickets', 'deadlines', 'team_time', 'completed', 'status_chart', 'timers'];
    } elseif ($is_agent) {
        $default_order = ['overview', 'focus', 'my_time', 'due_week', 'notifications', 'new_tickets', 'deadlines', 'completed', 'timers'];
    } else {
        $default_order = ['overview', 'notifications', 'recent'];
    }

    // Default sizes per widget (3-col grid: "half" = 1 col, "full" = 3 cols)
    $default_sizes = [
        'overview'     => 'full',
        'my_time'      => 'half',
        'new_tickets'  => 'half',
        'deadlines'    => 'half',
        'team_time'    => 'half',
        'due_week'     => 'half',
        'focus'        => 'full',
        'status_chart' => 'half',
        'completed'    => 'half',
        'timers'       => 'full',
        'recent'       => 'full',
        'notifications' => 'half',
    ];

    $hidden_sections = [];
    $widget_sizes = $default_sizes;

    if (is_array($dashboard_layout) && isset($dashboard_layout['order'])) {
        $section_order = $dashboard_layout['order'];
        $hidden_sections = $dashboard_layout['hidden'] ?? [];
        if (isset($dashboard_layout['sizes']) && is_array($dashboard_layout['sizes'])) {
            $widget_sizes = array_merge($default_sizes, $dashboard_layout['sizes']);
        }
    } elseif (is_array($dashboard_layout)) {
        $section_order = $dashboard_layout;
    } else {
        $section_order = $default_order;
        // Clean default: hide secondary widgets for first-time users
        // They see only: Overview → Focus (assigned tickets) → My Time → Due this week
        if ($is_staff) {
            $hidden_sections = ['notifications', 'new_tickets', 'deadlines', 'team_time', 'completed', 'status_chart', 'timers'];
        }
    }

    // ─── Migrate old layout
    $pulse_pos = array_search('pulse', $section_order, true);
    if ($pulse_pos !== false) {
        array_splice($section_order, $pulse_pos, 1, ['new_tickets', 'deadlines']);
        if (in_array('pulse', $hidden_sections, true)) {
            $hidden_sections = array_diff($hidden_sections, ['pulse']);
            $hidden_sections[] = 'new_tickets';
            $hidden_sections[] = 'deadlines';
        }
    }
    $time_pos = array_search('time', $section_order, true);
    if ($time_pos !== false) {
        array_splice($section_order, $time_pos, 1, ['my_time', 'team_time']);
        if (in_array('time', $hidden_sections, true)) {
            $hidden_sections = array_diff($hidden_sections, ['time']);
            $hidden_sections[] = 'my_time';
            $hidden_sections[] = 'team_time';
        }
    }
    $charts_pos = array_search('charts', $section_order, true);
    if ($charts_pos !== false) {
        array_splice($section_order, $charts_pos, 1, ['status_chart', 'due_week']);
    }
    $section_order = array_values(array_filter($section_order, fn($s) => !in_array($s, ['charts', 'pulse', 'time'], true)));
    $section_order = array_values(array_unique($section_order));

    foreach ($default_order as $s) {
        if (!in_array($s, $section_order, true))
            $section_order[] = $s;
    }

    $section_labels = [
        'overview' => t('Overview'),
        'new_tickets' => t('New tickets'),
        'deadlines' => t('Deadlines'),
        'my_time' => t('My Time'),
        'team_time' => t('Team'),
        'focus' => $is_admin ? t('Recent tickets') : ($is_staff ? t('Assigned to you') : t('Your recent tickets')),
        'timers' => t('Active Timers'),
        'recent' => t('Your recent tickets'),
        'status_chart' => t('By status'),
        'due_week' => t('Due this week'),
        'completed' => t('Completed tickets'),
        'notifications' => t('Notifications'),
    ];

    $tags_supported = function_exists('ticket_tags_column_exists') && ticket_tags_column_exists();

    // ─── Notifications (for dashboard widget) ─────────────
    $dashboard_notifications = [];
    $dashboard_unread_count = 0;
    if (function_exists('notifications_table_exists') && notifications_table_exists()) {
        $notif_result = get_user_notifications((int) $user['id'], 15, 0);
        $dashboard_notifications = $notif_result['notifications'];
        $dashboard_unread_count = $notif_result['unread_count'];
    }

    return [
        'tags_supported' => $tags_supported,
        'active_tags' => $tags,
        'workload_stats' => $workload_stats,
        'new_ticket_stats' => $new_ticket_stats,
        'total_visible_tickets' => $total_visible_tickets,
        'closed_visible_tickets' => $closed_visible_tickets,
        'status_chart_ids' => $status_chart_ids,
        'status_chart_labels' => $status_chart_labels,
        'status_chart_values' => $status_chart_values,
        'status_chart_colors' => $status_chart_colors,
        'priority_chart_ids' => $priority_chart_ids,
        'priority_chart_labels' => $priority_chart_labels,
        'priority_chart_values' => $priority_chart_values,
        'priority_chart_colors' => $priority_chart_colors,
        'my_time_today' => $my_time_today,
        'my_time_week' => $my_time_week,
        'my_time_month' => $my_time_month,
        'team_time_today' => $team_time_today,
        'team_time_week' => $team_time_week,
        'team_time_month' => $team_time_month,
        'team_members_time' => $team_members_time,
        'scope_link_params' => $scope_link_params,
        'link_new_today' => $link_new_today,
        'link_new_week' => $link_new_week,
        'link_new_month' => $link_new_month,
        'link_overdue' => $link_overdue,
        'link_due_today' => $link_due_today,
        'link_due_week' => $link_due_week,
        'link_due_month' => $link_due_month,
        'link_reports' => $link_reports,
        'recent_tickets' => $recent_tickets,
        'due_week_tickets' => $due_week_tickets,
        'completed_tickets' => $completed_tickets,
        'focus_tickets' => $focus_tickets,
        'active_timers' => $active_timers,
        'dashboard_notifications' => $dashboard_notifications,
        'dashboard_unread_count' => $dashboard_unread_count,
        'section_order' => $section_order,
        'hidden_sections' => $hidden_sections,
        'widget_sizes' => $widget_sizes,
        'default_order' => $default_order,
        'section_labels' => $section_labels,
    ];
}
