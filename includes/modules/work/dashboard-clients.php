<?php
/** Dashboard uses saved records; running timers are presented separately. */
function dashboard_clients_model(array $user, array $period, bool $team = false): array
{
    $today = date('Y-m-d');
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $week_end = date('Y-m-d', strtotime('sunday this week'));
    $from = substr((string) ($period['start'] ?? $month_start), 0, 10);
    $to = substr((string) ($period['end'] ?? $month_end), 0, 10);
    $totals = ['today' => 0, 'week' => 0, 'month' => 0, 'selected' => 0];
    $days = [];
    $from_ts = strtotime($from);
    $to_ts = strtotime($to);
    if ($from_ts === false || $to_ts === false || $from_ts > $to_ts) {
        return ['totals' => $totals, 'clients' => [], 'chart' => ['days' => [], 'max_minutes' => 0, 'total_minutes' => 0]];
    }
    // Keep every date in the explicit range; do not silently truncate chart totals.
    for ($cursor = $from_ts; $cursor <= $to_ts; $cursor = strtotime('+1 day', $cursor)) {
        $key = date('Y-m-d', $cursor);
        $days[$key] = ['key' => $key, 'label' => date('j.n.', $cursor), 'full_label' => format_date_localized($key, 'l, j. F'), 'minutes' => 0, 'users' => []];
    }
    $clients = [];
    if (ticket_time_table_exists() && (int) ($user['id'] ?? 0) > 0) {
        $params = [];
        $visibility = build_ticket_where_clause(build_ticket_visibility_filters_for_user($user), $params);
        $params[] = min($from, $month_start, $week_start) . ' 00:00:00';
        $params[] = max($to, $month_end, $week_end) . ' 23:59:59';
        $scope = '';
        // Only administrators may request the team aggregate, including direct calls.
        if (!$team || ($user['role'] ?? '') !== 'admin') {
            $scope = ' AND tte.user_id = ?';
            $params[] = (int) $user['id'];
        }
        $rows = db_fetch_all("SELECT DATE(tte.started_at) AS day_key,
                COALESCE(t.organization_id, 0) AS client_id, o.name AS client_name,
                SUM(tte.duration_minutes) AS minutes, COUNT(*) AS record_count
            FROM ticket_time_entries tte JOIN tickets t ON t.id = tte.ticket_id
            LEFT JOIN organizations o ON o.id = t.organization_id
            {$visibility} AND tte.started_at >= ? AND tte.started_at <= ?
                AND tte.ended_at IS NOT NULL AND tte.duration_minutes > 0 {$scope}
            GROUP BY DATE(tte.started_at), t.organization_id, o.name
            ORDER BY day_key", $params);
        foreach ($rows as $row) {
            $date = (string) $row['day_key'];
            $id = (int) $row['client_id'];
            $minutes = max(0, (int) $row['minutes']);
            $clients[$id] ??= ['id' => $id, 'name' => (string) ($row['client_name'] ?: '—'), 'today' => 0, 'month' => 0, 'selected' => 0, 'count' => 0];
            if ($date === $today) { $totals['today'] += $minutes; $clients[$id]['today'] += $minutes; }
            if ($date >= $week_start && $date <= $week_end) $totals['week'] += $minutes;
            if ($date >= $month_start && $date <= $month_end) { $totals['month'] += $minutes; $clients[$id]['month'] += $minutes; }
            if (!isset($days[$date])) continue;
            $clients[$id]['selected'] += $minutes;
            $clients[$id]['count'] += (int) $row['record_count'];
            $totals['selected'] += $minutes;
            $days[$date]['minutes'] += $minutes;
            // Same series interface as the existing graph renderer, keyed by client.
            $days[$date]['users'][] = ['user_id' => $id, 'name' => $clients[$id]['name'], 'minutes' => $minutes];
        }
    }
    $clients = array_values(array_filter($clients, static fn($client) => $client['selected'] > 0));
    usort($clients, static fn($a, $b) => ($b['count'] <=> $a['count']) ?: ($b['selected'] <=> $a['selected']) ?: strnatcasecmp($a['name'], $b['name']));
    return ['totals' => $totals, 'clients' => $clients, 'chart' => ['days' => array_values($days), 'max_minutes' => $days ? max(array_column($days, 'minutes')) : 0, 'total_minutes' => $totals['selected']]];
}

/** A presentation projection only: every aggregate still comes from the same read model. */
function dashboard_clients_projection(array $model, ?int $client_id = null, int $page = 1): array
{
    $clients = $model['clients'];
    if ($client_id !== null) {
        $clients = array_values(array_filter($clients, static fn($client) => $client['id'] === $client_id));
    }
    $allowed = array_fill_keys(array_column($clients, 'id'), true);
    $days = [];
    foreach ($model['chart']['days'] as $day) {
        $day['users'] = array_values(array_filter($day['users'], static fn($entry) => isset($allowed[$entry['user_id']])));
        $day['minutes'] = array_sum(array_column($day['users'], 'minutes'));
        $days[] = $day;
    }
    $pages = max(1, (int) ceil(count($clients) / 4));
    $page = max(1, min($pages, $page));
    $palette = ['#97b7ef', '#98ccbb', '#c7b3e6', '#e6b4bc', '#b6cddd', '#bfc7d5'];
    $visible = count($clients) > 6 ? array_slice($clients, 0, 5) : $clients;
    $series = [];
    foreach ($visible as $index => $client) {
        $series[(string) $client['id']] = ['label' => $client['name'], 'data' => array_fill(0, count($days), 0), 'backgroundColor' => $palette[$index]];
    }
    if (count($clients) > 6) {
        $series['other'] = ['label' => t('Clients') . ' +' . (count($clients) - 5), 'data' => array_fill(0, count($days), 0), 'backgroundColor' => $palette[5]];
    }
    foreach ($days as $index => $day) {
        foreach ($day['users'] as $entry) {
            $key = isset($series[(string) $entry['user_id']]) ? (string) $entry['user_id'] : 'other';
            $series[$key]['data'][$index] += $entry['minutes'];
        }
    }
    return ['clients' => $clients, 'columns' => array_slice($clients, ($page - 1) * 4, 4), 'page' => $page, 'pages' => $pages,
        'days' => $days, 'datasets' => array_values($series), 'total' => array_sum(array_column($days, 'minutes'))];
}
