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

    $rows = db_fetch_all(
        "SELECT id, status_id, is_archived FROM tickets WHERE tenant_id = ? AND organization_id = ?",
        [current_tenant_id(), $organization_id]
    );

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

function client_overview_recent_tickets(int $organization_id, string $view = 'open', int $limit = 8): array
{
    $view = ticket_list_view_normalize($view, true);
    $rows = db_fetch_all(
        "SELECT t.id, t.hash, t.title, t.status_id, t.is_archived, t.updated_at, t.created_at,
                s.name AS status_name, s.color AS status_color,
                a.first_name AS assignee_first_name, a.last_name AS assignee_last_name
         FROM tickets t
         LEFT JOIN statuses s ON t.status_id = s.id
         LEFT JOIN users a ON t.assignee_id = a.id
         WHERE t.tenant_id = ? AND t.organization_id = ?
         ORDER BY t.updated_at DESC, t.created_at DESC
         LIMIT 80",
        [current_tenant_id(), $organization_id]
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
        if (count($filtered) >= $limit) {
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
         WHERE tenant_id = ?
           AND email NOT LIKE 'deleted-user-%@invalid.local'
         ORDER BY role = 'user' DESC, last_name, first_name",
        [current_tenant_id()]
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
    $empty = ['minutes' => 0, 'billable_minutes' => 0, 'billable_amount' => 0.0];
    if ($organization_id <= 0 || !function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return $empty;
    }

    [$start, $end] = client_overview_month_bounds();
    $dur = sql_timer_duration_minutes('tte.');
    $row = db_fetch_one(
        "SELECT
            COALESCE(SUM({$dur}), 0) AS minutes,
            COALESCE(SUM(CASE WHEN tte.is_billable = 1 THEN {$dur} ELSE 0 END), 0) AS billable_minutes,
            COALESCE(SUM(CASE WHEN tte.is_billable = 1 THEN ({$dur} * COALESCE(tte.billable_rate, o.billable_rate, 0) / 60) ELSE 0 END), 0) AS billable_amount
         FROM ticket_time_entries tte
         JOIN tickets t ON tte.ticket_id = t.id
         LEFT JOIN organizations o ON t.organization_id = o.id
         WHERE t.tenant_id = ?
           AND t.organization_id = ?
           AND tte.started_at >= ?
           AND tte.started_at < ?",
        [current_tenant_id(), $organization_id, $start, $end]
    );

    if (!$row) {
        return $empty;
    }

    return [
        'minutes' => (int) ($row['minutes'] ?? 0),
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
