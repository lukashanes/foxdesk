<?php
/**
 * Compatibility helpers for the classic dashboard.
 */

function dashboard_tags_from_query(array $query): array
{
    $tags = [];
    if (empty($query['tags'])) {
        return $tags;
    }

    $seen = [];
    foreach (explode(',', (string) $query['tags']) as $tag) {
        $tag = trim(ltrim(trim($tag), '#'));
        $tag = preg_replace('/\s+/', ' ', $tag);
        if ($tag === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $tags[] = $tag;
    }

    return $tags;
}

function dashboard_legacy_tenant_filter(string $table, string $alias, array &$params): string
{
    if (!function_exists('column_exists') || !column_exists($table, 'tenant_id') || !function_exists('current_tenant_id')) {
        return '';
    }

    $params[] = (int) current_tenant_id();
    $prefix = $alias !== '' ? $alias . '.' : '';
    return " AND ({$prefix}tenant_id = ? OR {$prefix}tenant_id IS NULL)";
}

function dashboard_empty_agent_activity(int $selected_agent_id = 0): array
{
    return [
        'selected_agent_id' => max(0, $selected_agent_id),
        'agent' => null,
        'totals' => ['today' => 0, 'week' => 0, 'month' => 0],
        'entries' => [],
    ];
}

function dashboard_selected_agent_activity(int $selected_agent_id, bool $can_view): array
{
    $selected_agent_id = max(0, $selected_agent_id);
    if (!$can_view || $selected_agent_id <= 0 || !function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return dashboard_empty_agent_activity($selected_agent_id);
    }

    $user_params = [$selected_agent_id];
    $user_deleted_filter = (function_exists('column_exists') && column_exists('users', 'deleted_at'))
        ? 'AND u.deleted_at IS NULL'
        : '';
    $user_tenant_filter = dashboard_legacy_tenant_filter('users', 'u', $user_params);
    $agent = db_fetch_one("
        SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.avatar
        FROM users u
        WHERE u.id = ?
          AND u.role IN ('agent', 'admin')
          AND u.is_active = 1
          {$user_deleted_filter}
          {$user_tenant_filter}
        LIMIT 1
    ", $user_params);

    if (!$agent) {
        return dashboard_empty_agent_activity($selected_agent_id);
    }

    $dur_expr = function_exists('sql_timer_duration_minutes') ? sql_timer_duration_minutes('tte.') : 'tte.duration_minutes';
    $ticket_params = [$selected_agent_id];
    $ticket_tenant_filter = dashboard_legacy_tenant_filter('tickets', 't', $ticket_params);
    $entries = db_fetch_all("
        SELECT
            tte.id,
            tte.ticket_id,
            tte.started_at,
            tte.ended_at,
            tte.duration_minutes,
            tte.summary,
            tte.is_billable,
            " . (function_exists('time_entry_source_column_exists') && time_entry_source_column_exists() ? 'tte.source' : "'manual' AS source") . ",
            {$dur_expr} AS actual_minutes,
            t.title AS ticket_title,
            t.hash AS ticket_hash,
            s.name AS status_name
        FROM ticket_time_entries tte
        JOIN tickets t ON t.id = tte.ticket_id
        LEFT JOIN statuses s ON s.id = t.status_id
        WHERE tte.user_id = ?
          AND tte.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          {$ticket_tenant_filter}
        ORDER BY tte.started_at DESC
        LIMIT 20
    ", $ticket_params);

    $week_start = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $week_end = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    $month_start = date('Y-m-01 00:00:00');
    $month_end = date('Y-m-t 23:59:59');
    $totals_params = [$week_start, $week_end, $month_start, $month_end, $selected_agent_id];
    $totals_tenant_filter = dashboard_legacy_tenant_filter('tickets', 't', $totals_params);
    $totals = db_fetch_one("
        SELECT
            SUM(CASE WHEN DATE(tte.started_at) = CURDATE() THEN ({$dur_expr}) ELSE 0 END) AS today,
            SUM(CASE WHEN tte.started_at >= ? AND tte.started_at <= ? THEN ({$dur_expr}) ELSE 0 END) AS week,
            SUM(CASE WHEN tte.started_at >= ? AND tte.started_at <= ? THEN ({$dur_expr}) ELSE 0 END) AS month
        FROM ticket_time_entries tte
        JOIN tickets t ON t.id = tte.ticket_id
        WHERE tte.user_id = ?
          {$totals_tenant_filter}
    ", $totals_params);

    return [
        'selected_agent_id' => $selected_agent_id,
        'agent' => $agent,
        'totals' => [
            'today' => (int) ($totals['today'] ?? 0),
            'week' => (int) ($totals['week'] ?? 0),
            'month' => (int) ($totals['month'] ?? 0),
        ],
        'entries' => $entries,
    ];
}

function dashboard_scale_class(string $prefix, $value, $max = 100, int $steps = 20): string
{
    $value = max(0, (int) $value);
    $max = max(1, (int) $max);
    $steps = max(1, $steps);
    $index = (int) round($steps * $value / $max);
    if ($value > 0) {
        $index = max(1, $index);
    }
    $index = min($steps, max(0, $index));
    return $prefix . $index;
}

function dashboard_width_class($percent): string
{
    return dashboard_scale_class('db-width--', $percent, 100, 20);
}

function dashboard_avatar_class(string $seed): string
{
    $seed = trim($seed) !== '' ? trim($seed) : 'user';
    return 'db-avatar-tone--' . (abs(crc32($seed)) % 12);
}

function dashboard_status_group(array $ticket): string
{
    $status = [
        'id' => $ticket['status_id'] ?? null,
        'name' => $ticket['status_name'] ?? '',
        'is_closed' => $ticket['is_closed'] ?? 0,
        'status_group' => $ticket['status_group'] ?? null,
    ];
    if (function_exists('ticket_status_group_from_status')) {
        return ticket_status_group_from_status($status);
    }

    $name = strtolower(trim((string) ($status['name'] ?? '')));
    if (!empty($status['is_closed']) || preg_match('/\b(done|closed|resolved|complete|completed|finished)\b/u', $name)) {
        return 'done';
    }
    if (preg_match('/\b(new|open|todo|to do|received|created)\b/u', $name)) {
        return 'new';
    }
    if (preg_match('/\b(wait|waiting|pending|hold|blocked|client|customer|vendor|third party)\b/u', $name)) {
        return 'waiting';
    }
    return 'active';
}

function dashboard_status_text_class(array $ticket): string
{
    return 'db-ticket-status db-ticket-status--' . dashboard_status_group($ticket);
}

function dashboard_status_dot_class(array $ticket): string
{
    return 'db-ticket-status-dot db-ticket-status-dot--' . dashboard_status_group($ticket);
}

function dashboard_priority_key(string $priority_name): string
{
    if (function_exists('ticket_detail_priority_key')) {
        return ticket_detail_priority_key($priority_name);
    }
    $text = function_exists('mb_strtolower') ? mb_strtolower(trim($priority_name), 'UTF-8') : strtolower(trim($priority_name));
    if (preg_match('/\b(urgent|critical|blocker|highest)\b/u', $text)) {
        return 'urgent';
    }
    if (preg_match('/\b(high|major)\b/u', $text)) {
        return 'high';
    }
    if (preg_match('/\b(low|minor)\b/u', $text)) {
        return 'low';
    }
    return 'medium';
}

function dashboard_priority_badge_class(string $priority_name): string
{
    return 'db-badge db-priority-badge db-priority-badge--' . dashboard_priority_key($priority_name);
}

function dashboard_notification_type_class(string $type): string
{
    $key = strtolower(trim($type));
    $key = preg_replace('/[^a-z0-9]+/', '-', $key);
    $key = trim((string) $key, '-');
    $allowed = ['new-ticket', 'new-comment', 'status-changed', 'assigned-to-you', 'priority-changed', 'ticket-updated', 'due-date-reminder'];
    return 'dbnotif-type-icon dbnotif-type-icon--' . (in_array($key, $allowed, true) ? $key : 'default');
}
