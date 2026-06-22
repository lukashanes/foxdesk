<?php
/**
 * Exact worked-time read models for Work and Reports.
 *
 * These helpers deliberately use actual tracked minutes. Invoice rounding lives
 * in billing review/export only, so agents never see rounded-up work time.
 */

function time_activity_period_options(): array
{
    return [
        'today' => t('Today'),
        'this_week' => t('This week'),
        'this_month' => t('This month'),
        'last_month' => t('Last month'),
        'custom' => t('Custom month'),
    ];
}

function time_activity_period_from_request(array $request): array
{
    $period = (string) ($request['period'] ?? 'this_month');
    if (!array_key_exists($period, time_activity_period_options())) {
        $period = 'this_month';
    }

    $from_date = trim((string) ($request['from_date'] ?? ''));
    $to_date = trim((string) ($request['to_date'] ?? ''));
    $bounds = function_exists('get_time_range_bounds')
        ? get_time_range_bounds($period, $from_date, $to_date)
        : ['range' => $period, 'start' => date('Y-m-01 00:00:00'), 'end' => date('Y-m-t 23:59:59')];

    return [
        'period' => (string) ($bounds['range'] ?? $period),
        'label' => time_activity_period_options()[$period] ?? t('This month'),
        'start' => $bounds['start'] ?? null,
        'end' => $bounds['end'] ?? null,
        'from_date' => $from_date,
        'to_date' => $to_date,
    ];
}

function time_activity_tenant_filter(string $table, string $alias, array &$params): string
{
    if (!function_exists('column_exists') || !column_exists($table, 'tenant_id') || !function_exists('current_tenant_id')) {
        return '';
    }

    $params[] = (int) current_tenant_id();
    $prefix = $alias !== '' ? $alias . '.' : '';
    return " AND ({$prefix}tenant_id = ? OR {$prefix}tenant_id IS NULL)";
}

function time_activity_duration_sql(): string
{
    return function_exists('sql_timer_duration_minutes') ? sql_timer_duration_minutes('tte.') : 'tte.duration_minutes';
}

function time_activity_empty_totals(): array
{
    return [
        'today' => 0,
        'week' => 0,
        'month' => 0,
        'selected' => 0,
    ];
}

function time_activity_user_totals(int $user_id, array $period): array
{
    $user_id = max(0, $user_id);
    if ($user_id <= 0 || !function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return time_activity_empty_totals();
    }

    $today_start = date('Y-m-d 00:00:00');
    $today_end = date('Y-m-d 23:59:59');
    $week_start = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $week_end = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    $month_start = date('Y-m-01 00:00:00');
    $month_end = date('Y-m-t 23:59:59');
    $selected_start = $period['start'] ?? $month_start;
    $selected_end = $period['end'] ?? $month_end;

    $duration_sql = time_activity_duration_sql();
    $params = [
        $today_start,
        $today_end,
        $week_start,
        $week_end,
        $month_start,
        $month_end,
        $selected_start,
        $selected_end,
        $user_id,
    ];
    $tenant_filter = time_activity_tenant_filter('tickets', 't', $params);

    $row = db_fetch_one("
        SELECT
            SUM(CASE WHEN tte.started_at >= ? AND tte.started_at <= ? THEN ({$duration_sql}) ELSE 0 END) AS today,
            SUM(CASE WHEN tte.started_at >= ? AND tte.started_at <= ? THEN ({$duration_sql}) ELSE 0 END) AS week,
            SUM(CASE WHEN tte.started_at >= ? AND tte.started_at <= ? THEN ({$duration_sql}) ELSE 0 END) AS month,
            SUM(CASE WHEN tte.started_at >= ? AND tte.started_at <= ? THEN ({$duration_sql}) ELSE 0 END) AS selected
        FROM ticket_time_entries tte
        JOIN tickets t ON t.id = tte.ticket_id
        WHERE tte.user_id = ?
          {$tenant_filter}
    ", $params);

    return [
        'today' => (int) ($row['today'] ?? 0),
        'week' => (int) ($row['week'] ?? 0),
        'month' => (int) ($row['month'] ?? 0),
        'selected' => (int) ($row['selected'] ?? 0),
    ];
}

function time_activity_user_entries(int $user_id, array $period, int $limit = 8): array
{
    $user_id = max(0, $user_id);
    $limit = max(1, min(50, $limit));
    if ($user_id <= 0 || empty($period['start']) || empty($period['end']) || !function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return [];
    }

    $duration_sql = time_activity_duration_sql();
    $params = [$user_id, $period['start'], $period['end']];
    $tenant_filter = time_activity_tenant_filter('tickets', 't', $params);

    return db_fetch_all("
        SELECT
            tte.id,
            tte.ticket_id,
            tte.started_at,
            tte.ended_at,
            tte.duration_minutes,
            tte.summary,
            {$duration_sql} AS actual_minutes,
            t.title AS ticket_title,
            t.hash AS ticket_hash,
            o.name AS organization_name,
            s.name AS status_name
        FROM ticket_time_entries tte
        JOIN tickets t ON t.id = tte.ticket_id
        LEFT JOIN organizations o ON o.id = t.organization_id
        LEFT JOIN statuses s ON s.id = t.status_id
        WHERE tte.user_id = ?
          AND tte.started_at >= ?
          AND tte.started_at <= ?
          {$tenant_filter}
        ORDER BY tte.started_at DESC, tte.id DESC
        LIMIT {$limit}
    ", $params);
}

function time_activity_staff_users(): array
{
    $params = [];
    $deleted_filter = (function_exists('column_exists') && column_exists('users', 'deleted_at')) ? 'AND u.deleted_at IS NULL' : '';
    $tenant_filter = time_activity_tenant_filter('users', 'u', $params);

    return db_fetch_all("
        SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.avatar
        FROM users u
        WHERE u.role IN ('admin', 'agent')
          AND u.is_active = 1
          {$deleted_filter}
          {$tenant_filter}
        ORDER BY u.first_name, u.last_name, u.email
    ", $params);
}

function time_activity_team_summary(array $period, int $limit = 50): array
{
    $users = array_slice(time_activity_staff_users(), 0, max(1, min(200, $limit)));
    $rows = [];

    foreach ($users as $staff_user) {
        $user_id = (int) ($staff_user['id'] ?? 0);
        $totals = time_activity_user_totals($user_id, $period);
        $entries = time_activity_user_entries($user_id, $period, 1);
        $latest = $entries[0] ?? null;
        $name = trim((string) (($staff_user['first_name'] ?? '') . ' ' . ($staff_user['last_name'] ?? '')));
        $rows[] = [
            'user' => $staff_user,
            'name' => $name !== '' ? $name : (string) ($staff_user['email'] ?? t('Agent')),
            'totals' => $totals,
            'latest_entry' => $latest,
            'is_running' => $latest && empty($latest['ended_at']),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return ($b['totals']['selected'] ?? 0) <=> ($a['totals']['selected'] ?? 0);
    });

    return $rows;
}

function time_activity_work_model(array $user, array $request): array
{
    $period = time_activity_period_from_request($request);
    $user_id = (int) ($user['id'] ?? 0);
    $is_admin_user = function_exists('is_admin') ? is_admin() : (($user['role'] ?? '') === 'admin');

    return [
        'period' => $period,
        'period_options' => time_activity_period_options(),
        'my_totals' => time_activity_user_totals($user_id, $period),
        'my_entries' => time_activity_user_entries($user_id, $period, 6),
        'team' => $is_admin_user ? time_activity_team_summary($period, 80) : [],
    ];
}
