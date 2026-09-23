<?php
/**
 * Client overview service.
 *
 * This is the single read model for the client detail page: tickets, contacts,
 * current-month time, and billing context in one place.
 */

function client_overview_month_bounds(?DateTimeImmutable $now = null): array
{
    $now = $now ?: new DateTimeImmutable('now');
    $start = $now->modify('first day of this month')->setTime(0, 0, 0);
    $end = $start->modify('first day of next month');
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function client_overview_ticket_group(array $ticket): string
{
    if (!empty($ticket['is_archived'])) {
        return 'archived';
    }

    return ticket_status_group_for_status_id(isset($ticket['status_id']) ? (int) $ticket['status_id'] : null);
}

function client_overview_ticket_counts(int $organization_id): array
{
    $counts = ['open' => 0, 'waiting' => 0, 'done' => 0, 'archived' => 0, 'all' => 0];
    if ($organization_id <= 0) {
        return $counts;
    }

    $params = [];
    $where = build_ticket_where_clause(build_ticket_visibility_filters_for_user(current_user(), ['organization_id' => $organization_id]), $params);
    $rows = db_fetch_all("SELECT t.id, t.status_id, t.is_archived FROM tickets t" . $where, $params);

    foreach ($rows as $row) {
        $counts['all']++;
        $group = client_overview_ticket_group($row);
        if ($group === 'done') {
            $counts['done']++;
        } elseif ($group === 'waiting') {
            $counts['waiting']++;
            $counts['open']++;
        } elseif ($group === 'archived') {
            $counts['archived']++;
        } else {
            $counts['open']++;
        }
    }

    return $counts;
}

function client_overview_recent_tickets(int $organization_id, string $view = 'open', int $limit = 8, int $offset = 0): array
{
    $view = ticket_list_view_normalize($view, true);
    $filters = ['organization_id' => $organization_id];
    if ($view !== 'all') $filters['is_archived'] = $view === 'archived' ? 1 : 0;
    if ($view === 'open') $filters['status_group_not'] = ['done', 'archived'];
    elseif (in_array($view, ['done', 'waiting'], true)) $filters['status_group'] = $view;
    $params = [];
    $where = build_ticket_where_clause(build_ticket_visibility_filters_for_user(current_user(), $filters), $params);
    $rows = db_fetch_all(
        "SELECT t.id, t.hash, t.title, t.status_id, t.is_archived, t.updated_at, t.created_at,
                s.name AS status_name, s.color AS status_color,
                a.first_name AS assignee_first_name, a.last_name AS assignee_last_name,
                (SELECT COALESCE(SUM(te.duration_minutes), 0) FROM ticket_time_entries te
                 WHERE te.ticket_id = t.id AND te.ended_at IS NOT NULL AND te.duration_minutes > 0) AS saved_minutes
         FROM tickets t
         LEFT JOIN statuses s ON t.status_id = s.id
         LEFT JOIN users a ON t.assignee_id = a.id
         " . $where . " ORDER BY t.updated_at DESC, t.id DESC" .
         ($limit > 0 ? ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset) : ''),
        $params
    );

    $filtered = [];
    foreach ($rows as $ticket) {
        $group = client_overview_ticket_group($ticket);
        $include = $view === 'all'
            || ($view === 'archived' && $group === 'archived')
            || ($view === 'done' && $group === 'done')
            || ($view === 'waiting' && $group === 'waiting')
            || ($view === 'open' && !in_array($group, ['done', 'archived'], true));

        if ($include) {
            $filtered[] = $ticket;
        }
        if ($limit > 0 && count($filtered) >= $limit) {
            break;
        }
    }

    return $filtered;
}

function client_overview_contacts(int $organization_id): array
{
    $contacts = [];
    $users = db_fetch_all(
        "SELECT id, first_name, last_name, email, role, is_active, organization_id, permissions, avatar
         FROM users
         WHERE email NOT LIKE 'deleted-user-%@invalid.local'
         ORDER BY role = 'user' DESC, last_name, first_name",
        []
    );

    foreach ($users as $user) {
        $ids = [];
        if (!empty($user['organization_id'])) {
            $ids[] = (int) $user['organization_id'];
        }
        if (function_exists('get_user_organization_ids')) {
            $ids = array_merge($ids, get_user_organization_ids((int) $user['id']));
        }
        $ids = normalize_organization_ids($ids);
        if (in_array($organization_id, $ids, true)) {
            $contacts[] = $user;
        }
    }

    return $contacts;
}

function client_overview_time_summary(int $organization_id): array
{
    $empty = ['minutes' => 0, 'today' => 0, 'week' => 0, 'billable_minutes' => 0, 'billable_amount' => 0.0];
    if ($organization_id <= 0 || !function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return $empty;
    }

    [$start, $end] = client_overview_month_bounds();
    $dur = 'tte.duration_minutes';
    $params = [];
    $where = build_ticket_where_clause(build_ticket_visibility_filters_for_user(current_user(), ['organization_id' => $organization_id]), $params);
    if ((current_user()['role'] ?? '') !== 'admin') {
        $where .= ' AND tte.user_id = ?';
        $params[] = (int) current_user()['id'];
    }
    $week_start = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $week_end = date('Y-m-d 00:00:00', strtotime('monday next week'));
    $params = array_merge([$start, $end, date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'), $week_start, $week_end, $start, $end, $start, $end], $params, [min($start, $week_start), max($end, $week_end)]);
    $row = db_fetch_one(
        "SELECT
            COALESCE(SUM(CASE WHEN tte.started_at >= ? AND tte.started_at < ? THEN {$dur} ELSE 0 END), 0) AS minutes,
            COALESCE(SUM(CASE WHEN tte.started_at BETWEEN ? AND ? THEN {$dur} ELSE 0 END),0) AS today,
            COALESCE(SUM(CASE WHEN tte.started_at >= ? AND tte.started_at < ? THEN {$dur} ELSE 0 END),0) AS week,
            COALESCE(SUM(CASE WHEN tte.started_at >= ? AND tte.started_at < ? AND tte.is_billable = 1 THEN {$dur} ELSE 0 END), 0) AS billable_minutes,
            COALESCE(SUM(CASE WHEN tte.started_at >= ? AND tte.started_at < ? AND tte.is_billable = 1 THEN ({$dur} * COALESCE(tte.billable_rate, o.billable_rate, 0) / 60) ELSE 0 END), 0) AS billable_amount
         FROM ticket_time_entries tte
         JOIN tickets t ON tte.ticket_id = t.id
         LEFT JOIN organizations o ON t.organization_id = o.id
         " . $where . "
           AND tte.ended_at IS NOT NULL AND tte.duration_minutes > 0
           AND tte.started_at >= ?
           AND tte.started_at < ?",
        $params
    );

    if (!$row) {
        return $empty;
    }

    return [
        'minutes' => (int) ($row['minutes'] ?? 0),
        'today' => (int) ($row['today'] ?? 0),
        'week' => (int) ($row['week'] ?? 0),
        'billable_minutes' => (int) ($row['billable_minutes'] ?? 0),
        'billable_amount' => (float) ($row['billable_amount'] ?? 0),
    ];
}

function client_overview(int $organization_id, string $ticket_view = 'open'): ?array
{
    $organization = get_organization($organization_id);
    if (!$organization) {
        return null;
    }

    return [
        'organization' => $organization,
        'counts' => client_overview_ticket_counts($organization_id),
        'tickets' => client_overview_recent_tickets($organization_id, $ticket_view),
        'contacts' => client_overview_contacts($organization_id),
        'time' => client_overview_time_summary($organization_id),
    ];
}
