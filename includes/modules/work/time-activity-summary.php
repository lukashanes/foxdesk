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

function time_activity_scope_from_request(array $request): array
{
    $scope = (string) ($request['activity'] ?? '1');
    if (!in_array($scope, ['1', '5', 'all'], true)) {
        $scope = '1';
    }

    return [
        'key' => $scope,
        'limit' => $scope === 'all' ? null : (int) $scope,
        'options' => [
            '1' => t('Last ticket'),
            '5' => t('Last 5 tickets'),
            'all' => t('All work'),
        ],
    ];
}

function time_activity_log_filter_options(): array
{
    return [
        'last3' => t('Last 3 tickets'),
        'last10' => t('Last 10 tickets'),
        'today' => t('Today'),
        'this_week' => t('This week'),
        'this_month' => t('This month'),
        'search' => t('Search'),
    ];
}

function time_activity_log_filter_from_request(array $request, string $param, string $session_key, string $default = 'last3'): array
{
    $options = time_activity_log_filter_options();
    $stored = '';
    if (session_status() === PHP_SESSION_ACTIVE) {
        $stored = (string) ($_SESSION[$session_key] ?? '');
    }

    $key = array_key_exists($param, $request)
        ? (string) $request[$param]
        : ($stored !== '' ? $stored : $default);

    if (!array_key_exists($key, $options)) {
        $key = $default;
    }

    if (array_key_exists($param, $request) && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[$session_key] = $key;
    }

    $limit = match ($key) {
        'last3' => 3,
        'last10' => 10,
        'today' => 80,
        'this_week' => 120,
        'this_month' => 180,
        'search' => 200,
        default => 3,
    };

    return [
        'key' => $key,
        'label' => $options[$key],
        'limit' => $limit,
        'is_search' => $key === 'search',
        'options' => $options,
    ];
}

function time_activity_period_for_log_filter(string $filter): array
{
    $period_key = match ($filter) {
        'today' => 'today',
        'this_week' => 'this_week',
        'this_month' => 'this_month',
        default => 'all',
    };

    if ($period_key !== 'all' && function_exists('get_time_range_bounds')) {
        $bounds = get_time_range_bounds($period_key, '', '');
        return [
            'period' => (string) ($bounds['range'] ?? $period_key),
            'start' => $bounds['start'] ?? date('Y-m-01 00:00:00'),
            'end' => $bounds['end'] ?? date('Y-m-d 23:59:59'),
        ];
    }

    return [
        'period' => 'all',
        'start' => '1970-01-01 00:00:00',
        'end' => date('Y-m-d 23:59:59'),
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

function time_activity_weekly_chart(int $viewer_user_id, bool $include_team = false): array
{
    $days = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime('-' . $i . ' days'));
        $days[$date] = [
            'key' => $date,
            'label' => function_exists('format_date_localized') ? format_date_localized($date, 'd.m.') : date('d.m.', strtotime($date)),
            'full_label' => function_exists('format_date_localized') ? format_date_localized($date, 'l, j. F') : date('l, M j', strtotime($date)),
            'minutes' => 0,
            'users' => [],
        ];
    }

    if (!function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return [
            'days' => array_values($days),
            'max_minutes' => 0,
            'total_minutes' => 0,
        ];
    }

    $start = date('Y-m-d 00:00:00', strtotime('-6 days'));
    $end = date('Y-m-d 23:59:59');
    $duration_sql = time_activity_duration_sql();
    $params = [$start, $end];
    $tenant_filter = time_activity_tenant_filter('tickets', 't', $params);
    $user_filter = '';

    if (!$include_team) {
        $params[] = max(0, $viewer_user_id);
        $user_filter = 'AND tte.user_id = ?';
    }

    $rows = db_fetch_all("
        SELECT
            DATE(tte.started_at) AS day_key,
            tte.user_id,
            u.first_name,
            u.last_name,
            u.email,
            SUM({$duration_sql}) AS minutes
        FROM ticket_time_entries tte
        JOIN tickets t ON t.id = tte.ticket_id
        LEFT JOIN users u ON u.id = tte.user_id
        WHERE tte.started_at >= ?
          AND tte.started_at <= ?
          {$tenant_filter}
          {$user_filter}
        GROUP BY DATE(tte.started_at), tte.user_id, u.first_name, u.last_name, u.email
        ORDER BY day_key ASC, minutes DESC
    ", $params);

    foreach ($rows as $row) {
        $day_key = (string) ($row['day_key'] ?? '');
        if (!isset($days[$day_key])) {
            continue;
        }

        $minutes = (int) round((float) ($row['minutes'] ?? 0));
        $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
        if ($name === '') {
            $name = (string) ($row['email'] ?? t('Agent'));
        }

        $days[$day_key]['minutes'] += $minutes;
        $days[$day_key]['users'][] = [
            'name' => $name,
            'minutes' => $minutes,
        ];
    }

    $max_minutes = 0;
    $total_minutes = 0;
    foreach ($days as $day) {
        $minutes = (int) ($day['minutes'] ?? 0);
        $max_minutes = max($max_minutes, $minutes);
        $total_minutes += $minutes;
    }

    return [
        'days' => array_values($days),
        'max_minutes' => $max_minutes,
        'total_minutes' => $total_minutes,
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

function time_activity_user_entries(int $user_id, array $period, ?int $limit = 8): array
{
    $user_id = max(0, $user_id);
    if ($user_id <= 0 || empty($period['start']) || empty($period['end']) || !function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return [];
    }

    $duration_sql = time_activity_duration_sql();
    $params = [$user_id, $period['start'], $period['end']];
    $tenant_filter = time_activity_tenant_filter('tickets', 't', $params);
    $limit_clause = '';
    if ($limit !== null) {
        $limit_clause = ' LIMIT ' . max(1, min(200, (int) $limit));
    }

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
        {$limit_clause}
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

function time_activity_team_summary(array $period, int $limit = 50, ?int $entry_limit = 1, ?array $entry_period = null): array
{
    $users = array_slice(time_activity_staff_users(), 0, max(1, min(200, $limit)));
    $rows = [];
    $entry_period = $entry_period ?: $period;

    foreach ($users as $staff_user) {
        $user_id = (int) ($staff_user['id'] ?? 0);
        $totals = time_activity_user_totals($user_id, $period);
        $entries = time_activity_user_entries($user_id, $entry_period, $entry_limit);
        $latest = $entries[0] ?? null;
        $name = trim((string) (($staff_user['first_name'] ?? '') . ' ' . ($staff_user['last_name'] ?? '')));
        $rows[] = [
            'user' => $staff_user,
            'name' => $name !== '' ? $name : (string) ($staff_user['email'] ?? t('Agent')),
            'totals' => $totals,
            'entries' => $entries,
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
    $my_activity_filter = time_activity_log_filter_from_request($request, 'my_activity', 'foxdesk_work_my_activity_filter');
    $team_activity_filter = time_activity_log_filter_from_request($request, 'team_activity', 'foxdesk_work_team_activity_filter');
    $my_activity_period = time_activity_period_for_log_filter($my_activity_filter['key']);
    $team_activity_period = time_activity_period_for_log_filter($team_activity_filter['key']);
    $user_id = (int) ($user['id'] ?? 0);
    $is_admin_user = function_exists('is_admin') ? is_admin() : (($user['role'] ?? '') === 'admin');

    return [
        'period' => $period,
        'period_options' => time_activity_period_options(),
        'activity_scope' => [
            'key' => $my_activity_filter['key'],
            'limit' => $my_activity_filter['limit'],
            'options' => $my_activity_filter['options'],
        ],
        'my_activity_filter' => $my_activity_filter,
        'team_activity_filter' => $team_activity_filter,
        'my_totals' => time_activity_user_totals($user_id, $period),
        'my_entries' => time_activity_user_entries($user_id, $my_activity_period, $my_activity_filter['limit']),
        'team' => $is_admin_user ? time_activity_team_summary($period, 80, $team_activity_filter['limit'], $team_activity_period) : [],
        'week_chart' => time_activity_weekly_chart($user_id, $is_admin_user),
    ];
}
