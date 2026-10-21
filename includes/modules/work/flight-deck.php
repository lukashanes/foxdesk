<?php
/**
 * Flight deck dashboard read models.
 *
 * Money, people, clients, the 14-day chart, attention chips and the activity
 * feed all come from existing tables (saved time entries, tickets, activity
 * log, report templates). Every ticket query goes through the shared ticket
 * visibility predicate, and the Me/Team scope is enforced here, not in the
 * template.
 */

/** Saved time entries for a period, with the joins the billing rate resolver needs. */
function work_flight_deck_entries(array $user, bool $team, string $start, string $end): array
{
    $user_id = (int) ($user['id'] ?? 0);
    if ($user_id <= 0 || !function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return [];
    }

    $params = [];
    $where = build_ticket_where_clause(build_ticket_visibility_filters_for_user($user), $params);
    $params[] = $start;
    $params[] = $end;
    $scope = '';
    // Only administrators may aggregate the whole team, even on direct calls.
    if (!$team || ($user['role'] ?? '') !== 'admin') {
        $scope = ' AND tte.user_id = ?';
        $params[] = $user_id;
    }

    $ticket_rate = column_exists('tickets', 'custom_billable_rate') ? 't.custom_billable_rate' : 'NULL';
    $user_rate = column_exists('users', 'billable_rate') ? 'u.billable_rate' : 'NULL';
    $ticket_tags = (function_exists('ticket_tags_column_exists') && ticket_tags_column_exists()) ? 't.tags' : 'NULL';

    return db_fetch_all("SELECT tte.id, tte.ticket_id, tte.user_id, tte.started_at, tte.ended_at,
            tte.duration_minutes, tte.is_billable, tte.billable_rate,
            t.organization_id, t.status_id, {$ticket_rate} AS ticket_custom_billable_rate, {$ticket_tags} AS ticket_tags,
            o.name AS organization_name, o.billable_rate AS org_billable_rate,
            u.first_name, u.last_name, u.email, u.avatar, {$user_rate} AS user_billable_rate
        FROM ticket_time_entries tte
        JOIN tickets t ON t.id = tte.ticket_id
        LEFT JOIN organizations o ON o.id = t.organization_id
        LEFT JOIN users u ON u.id = tte.user_id
        {$where} AND tte.started_at >= ? AND tte.started_at <= ?
            AND tte.ended_at IS NOT NULL AND tte.duration_minutes > 0 {$scope}
        ORDER BY tte.started_at ASC, tte.id ASC", $params);
}

/**
 * Billable amount of one saved entry.
 *
 * Rule: actual saved minutes (no report rounding, like the billing review) multiplied by the
 * same effective rate the billing review resolves (entry → ticket → agent/client → user → client).
 * The rate lookup hits the database, so it is memoised per rate context.
 */
function work_flight_deck_entry_amount(array $entry): float
{
    static $rates = [];
    if (!function_exists('billing_review_entry_rate') || empty($entry['is_billable'])) {
        return 0.0;
    }

    $key = implode('|', [
        (int) ($entry['organization_id'] ?? 0),
        (int) ($entry['user_id'] ?? 0),
        (string) ($entry['billable_rate'] ?? ''),
        (string) ($entry['ticket_custom_billable_rate'] ?? ''),
        (string) ($entry['user_billable_rate'] ?? ''),
        (string) ($entry['org_billable_rate'] ?? ''),
    ]);
    if (!array_key_exists($key, $rates)) {
        $rates[$key] = billing_review_entry_rate($entry);
    }

    return billing_review_amount_from_rate(billing_review_entry_billable_minutes($entry, 1), $rates[$key]);
}

/**
 * Which entries are already covered by a published client report.
 *
 * A report template selects entries by client, date range and optional agent/tag filters, so an
 * entry counts as reported when a non-draft report matches all of them. Returns null when the
 * workspace has no report tables, so callers never fabricate an "invoiced" split.
 */
function work_flight_deck_report_coverage(array $entries, string $start, string $end): ?array
{
    if (!function_exists('table_exists') || !table_exists('report_templates') || !column_exists('report_templates', 'is_draft')) {
        return null;
    }

    $params = [];
    $archived = column_exists('report_templates', 'is_archived') ? ' AND rt.is_archived = 0' : '';
    $agent_select = column_exists('report_templates', 'agent_ids') ? 'rt.agent_ids' : 'NULL';
    $tags_select = column_exists('report_templates', 'tags') ? 'rt.tags' : 'NULL';
    $params[] = substr($end, 0, 10);
    $params[] = substr($start, 0, 10);
    $templates = db_fetch_all("SELECT rt.id, rt.organization_id, rt.date_from, rt.date_to,
            {$agent_select} AS agent_ids, {$tags_select} AS tags
        FROM report_templates rt
        WHERE rt.is_draft = 0{$archived} AND rt.date_from <= ? AND rt.date_to >= ?", $params);

    $normalized = [];
    foreach ($templates as $template) {
        $agent_ids = function_exists('report_normalize_agent_ids')
            ? array_map('intval', (array) report_normalize_agent_ids($template['agent_ids'] ?? '', true))
            : work_flight_deck_int_list((string) ($template['agent_ids'] ?? ''));
        $normalized[] = [
            'organization_id' => (int) ($template['organization_id'] ?? 0),
            'from' => (string) ($template['date_from'] ?? ''),
            'to' => (string) ($template['date_to'] ?? ''),
            'agent_ids' => $agent_ids,
            'tags' => work_flight_deck_tag_list((string) ($template['tags'] ?? '')),
        ];
    }

    $coverage = [];
    foreach ($entries as $entry) {
        $date = substr((string) ($entry['started_at'] ?? ''), 0, 10);
        $organization_id = (int) ($entry['organization_id'] ?? 0);
        $entry_user = (int) ($entry['user_id'] ?? 0);
        $entry_tags = work_flight_deck_tag_list((string) ($entry['ticket_tags'] ?? ''));
        $reported = false;
        foreach ($normalized as $template) {
            if ($template['organization_id'] !== $organization_id || $date < $template['from'] || $date > $template['to']) {
                continue;
            }
            if (!empty($template['agent_ids']) && !in_array($entry_user, $template['agent_ids'], true)) {
                continue;
            }
            if (!empty($template['tags']) && empty(array_intersect($template['tags'], $entry_tags))) {
                continue;
            }
            $reported = true;
            break;
        }
        $coverage[(int) $entry['id']] = $reported;
    }

    return $coverage;
}

function work_flight_deck_int_list(string $value): array
{
    $ids = [];
    foreach (explode(',', $value) as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part)) {
            $ids[] = (int) $part;
        }
    }
    return array_values(array_unique($ids));
}

function work_flight_deck_tag_list(string $value): array
{
    $tags = [];
    foreach (explode(',', $value) as $part) {
        $part = trim(ltrim(trim($part), '#'));
        if ($part !== '') {
            $tags[] = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
        }
    }
    return array_values(array_unique($tags));
}

/** The comparable window before the selected period (month-to-date compares to the same day of the previous month). */
function work_flight_deck_previous_period(array $period): array
{
    $start = !empty($period['start']) ? strtotime((string) $period['start']) : false;
    $end = !empty($period['end']) ? strtotime((string) $period['end']) : false;
    if ($start === false || $end === false || $end < $start) {
        return ['start' => null, 'end' => null, 'label' => t('work.money.previous_period')];
    }

    $key = (string) ($period['period'] ?? '');
    if ($key === 'this_month' || $key === 'last_month') {
        $previous_month = strtotime('-1 month', strtotime(date('Y-m-01 00:00:00', $start)));
        $day = min((int) date('j', $end), (int) date('t', $previous_month));
        $previous_end = strtotime(date('Y-m-', $previous_month) . sprintf('%02d', $day) . ' ' . date('H:i:s', $end));
        return [
            'start' => date('Y-m-d 00:00:00', $previous_month),
            'end' => date('Y-m-d H:i:s', $previous_end),
            'label' => format_date_localized(date('Y-m-d', $previous_month), 'F'),
        ];
    }

    $previous_end = $start - 1;
    $previous_start = $previous_end - ($end - $start);
    $label = match ($key) {
        'today' => t('Yesterday'),
        'this_week' => t('Last week'),
        default => t('work.money.previous_period'),
    };

    return [
        'start' => date('Y-m-d H:i:s', $previous_start),
        'end' => date('Y-m-d H:i:s', $previous_end),
        'label' => $label,
    ];
}

/** Billable money, people and clients for the selected period and scope. */
function work_flight_deck_money(array $user, array $period, bool $team): array
{
    $start = (string) ($period['start'] ?? date('Y-m-01 00:00:00'));
    $end = (string) ($period['end'] ?? date('Y-m-d 23:59:59'));
    $entries = work_flight_deck_entries($user, $team, $start, $end);
    $coverage = $entries ? work_flight_deck_report_coverage($entries, $start, $end) : (function_exists('table_exists') && table_exists('report_templates') ? [] : null);

    $status_groups = [];
    if (function_exists('get_statuses') && function_exists('ticket_status_group_from_status')) {
        foreach (get_statuses() as $status) {
            $status_groups[(int) ($status['id'] ?? 0)] = ticket_status_group_from_status($status);
        }
    }

    $model = [
        'minutes' => 0,
        'amount' => 0.0,
        'reported_amount' => 0.0,
        'unreported_amount' => 0.0,
        'reports_supported' => $coverage !== null,
        'to_report' => ['tickets' => 0, 'amount' => 0.0],
        'people' => [],
        'clients' => [],
        'client_count' => 0,
        'has_time' => false,
        'previous_amount' => 0.0,
        'change_percent' => null,
        'previous_label' => '',
    ];

    $to_report_tickets = [];
    foreach ($entries as $entry) {
        $minutes = max(0, (int) ($entry['duration_minutes'] ?? 0));
        $amount = work_flight_deck_entry_amount($entry);
        $model['minutes'] += $minutes;
        $model['amount'] += $amount;

        $reported = $coverage !== null && !empty($coverage[(int) $entry['id']]);
        if ($coverage !== null) {
            if ($reported) {
                $model['reported_amount'] += $amount;
            } else {
                $model['unreported_amount'] += $amount;
                $group = $status_groups[(int) ($entry['status_id'] ?? 0)] ?? 'active';
                if ($group === 'done' && $amount > 0) {
                    $to_report_tickets[(int) $entry['ticket_id']] = true;
                    $model['to_report']['amount'] += $amount;
                }
            }
        }

        $person_id = (int) ($entry['user_id'] ?? 0);
        if (!isset($model['people'][$person_id])) {
            $person = [
                'id' => $person_id,
                'first_name' => (string) ($entry['first_name'] ?? ''),
                'last_name' => (string) ($entry['last_name'] ?? ''),
                'email' => (string) ($entry['email'] ?? ''),
                'avatar' => (string) ($entry['avatar'] ?? ''),
            ];
            $model['people'][$person_id] = $person + [
                'name' => function_exists('user_avatar_display_name') ? user_avatar_display_name($person) : trim($person['first_name'] . ' ' . $person['last_name']),
                'minutes' => 0,
                'amount' => 0.0,
            ];
        }
        $model['people'][$person_id]['minutes'] += $minutes;
        $model['people'][$person_id]['amount'] += $amount;

        $client_id = (int) ($entry['organization_id'] ?? 0);
        if (!isset($model['clients'][$client_id])) {
            $client_name = trim((string) ($entry['organization_name'] ?? ''));
            $model['clients'][$client_id] = [
                'id' => $client_id,
                'name' => $client_name !== '' ? $client_name : t('work.clients.no_client'),
                'minutes' => 0,
                'amount' => 0.0,
            ];
        }
        $model['clients'][$client_id]['minutes'] += $minutes;
        $model['clients'][$client_id]['amount'] += $amount;
    }

    $model['to_report']['tickets'] = count($to_report_tickets);
    $model['has_time'] = $model['minutes'] > 0;
    $by_amount = static fn(array $a, array $b): int => ($b['amount'] <=> $a['amount']) ?: ($b['minutes'] <=> $a['minutes']) ?: strnatcasecmp($a['name'], $b['name']);
    usort($model['people'], $by_amount);
    usort($model['clients'], $by_amount);
    $model['client_count'] = count($model['clients']);

    $previous = work_flight_deck_previous_period($period);
    $model['previous_label'] = (string) $previous['label'];
    if (!empty($previous['start']) && !empty($previous['end'])) {
        foreach (work_flight_deck_entries($user, $team, (string) $previous['start'], (string) $previous['end']) as $entry) {
            $model['previous_amount'] += work_flight_deck_entry_amount($entry);
        }
        if ($model['previous_amount'] > 0) {
            $model['change_percent'] = (int) round((($model['amount'] - $model['previous_amount']) / $model['previous_amount']) * 100);
        }
    }

    return $model;
}

/** Worked minutes per day for the last N days (today included), honouring scope and visibility. */
function work_flight_deck_days(array $user, bool $team, int $count = 14): array
{
    $count = max(1, min(62, $count));
    $today = date('Y-m-d');
    $days = [];
    for ($offset = $count - 1; $offset >= 0; $offset--) {
        $timestamp = strtotime('-' . $offset . ' days');
        $key = date('Y-m-d', $timestamp);
        $days[$key] = [
            'key' => $key,
            'label' => date('j.', $timestamp),
            'short_label' => format_date_localized($key, 'j. n.'),
            'full_label' => format_date_localized($key, 'l, j. F'),
            'minutes' => 0,
            'is_today' => $key === $today,
            'is_weekend' => (int) date('N', $timestamp) >= 6,
        ];
    }

    $user_id = (int) ($user['id'] ?? 0);
    if ($user_id > 0 && function_exists('ticket_time_table_exists') && ticket_time_table_exists()) {
        $params = [];
        $where = build_ticket_where_clause(build_ticket_visibility_filters_for_user($user), $params);
        $params[] = date('Y-m-d 00:00:00', strtotime('-' . ($count - 1) . ' days'));
        $params[] = date('Y-m-d 23:59:59');
        $scope = '';
        if (!$team || ($user['role'] ?? '') !== 'admin') {
            $scope = ' AND tte.user_id = ?';
            $params[] = $user_id;
        }
        $rows = db_fetch_all("SELECT DATE(tte.started_at) AS day_key, SUM(tte.duration_minutes) AS minutes
            FROM ticket_time_entries tte JOIN tickets t ON t.id = tte.ticket_id
            {$where} AND tte.started_at >= ? AND tte.started_at <= ?
                AND tte.ended_at IS NOT NULL AND tte.duration_minutes > 0 {$scope}
            GROUP BY DATE(tte.started_at)", $params);
        foreach ($rows as $row) {
            $key = (string) ($row['day_key'] ?? '');
            if (isset($days[$key])) {
                $days[$key]['minutes'] = max(0, (int) ($row['minutes'] ?? 0));
            }
        }
    }

    $total = 0;
    $max = 0;
    $working_days = 0;
    foreach ($days as $day) {
        $total += $day['minutes'];
        $max = max($max, $day['minutes']);
        if (!$day['is_weekend']) {
            $working_days++;
        }
    }

    return [
        'days' => array_values($days),
        'total_minutes' => $total,
        'max_minutes' => $max,
        'working_days' => $working_days,
        'average_minutes' => $working_days > 0 ? (int) round($total / $working_days) : 0,
    ];
}

/** "Waiting for you" counts. Overdue and unanswered follow the Me/Team scope; triage is team-wide by nature. */
function work_flight_deck_attention(array $user, bool $team, array $money): array
{
    $user_id = (int) ($user['id'] ?? 0);
    $mine = $team ? [] : ['assigned_to' => $user_id];
    $base = ['is_archived' => 0];
    $count = static function (array $filters) use ($user): int {
        return function_exists('get_tickets_count') ? get_tickets_count(build_ticket_visibility_filters_for_user($user, $filters)) : 0;
    };

    $overdue = $count($base + $mine + ['due_date_overdue' => true, 'status_group_not' => ['done', 'archived']]);
    // Same definition as the ticket list "New" view, so the chip and the list agree.
    $triage = $count($base + ['status_group_not' => ['waiting', 'done', 'archived'], 'triage_state' => 'new']);

    $unanswered = 0;
    if ($user_id > 0) {
        $params = [];
        $where = build_ticket_where_clause(
            build_ticket_visibility_filters_for_user($user, $base + $mine + ['status_group_not' => ['done', 'archived']]),
            $params
        );
        $row = db_fetch_one("SELECT COUNT(*) AS c FROM tickets t {$where} AND (
                EXISTS (
                    SELECT 1 FROM comments lc JOIN users lcu ON lcu.id = lc.user_id
                    WHERE lc.ticket_id = t.id AND lc.is_internal = 0 AND lcu.role = 'user'
                      AND lc.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                      AND lc.created_at = (SELECT MAX(lc2.created_at) FROM comments lc2 WHERE lc2.ticket_id = t.id AND lc2.is_internal = 0)
                )
                OR (
                    NOT EXISTS (SELECT 1 FROM comments lc3 WHERE lc3.ticket_id = t.id AND lc3.is_internal = 0)
                    AND t.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    AND EXISTS (SELECT 1 FROM users cu WHERE cu.id = t.user_id AND cu.role = 'user')
                )
            )", $params);
        $unanswered = (int) ($row['c'] ?? 0);
    }

    $chips = [
        'overdue' => [
            'count' => $overdue,
            'tone' => 'red',
            'label' => t('work.attention.overdue'),
            'url' => url('tickets', ['due_date' => 'overdue'] + $mine),
        ],
        'triage' => [
            'count' => $triage,
            'tone' => 'blue',
            'label' => t('work.attention.triage'),
            'url' => url('tickets', ['work_view' => 'open', 'sort' => 'newest']),
        ],
        'unanswered' => [
            'count' => $unanswered,
            'tone' => 'amber',
            'label' => t('work.attention.unanswered'),
            'url' => url('tickets', ['work_view' => 'open', 'sort' => 'last_updated'] + $mine),
        ],
    ];
    if (!empty($money['reports_supported'])) {
        $chips['to_report'] = [
            'count' => (int) ($money['to_report']['tickets'] ?? 0),
            'tone' => 'green',
            'label' => t('work.attention.to_report'),
            'detail' => format_money((float) ($money['to_report']['amount'] ?? 0)),
            'url' => url('admin', ['section' => 'reports']),
        ];
    }

    $chips = array_filter($chips, static fn(array $chip): bool => $chip['count'] > 0);
    return ['chips' => array_values($chips), 'calm' => empty($chips)];
}

function work_flight_deck_actor_name(array $row): string
{
    $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
    if ($name === '') {
        $name = trim((string) ($row['email'] ?? ''));
    }
    return $name !== '' ? $name : t('System');
}

function work_flight_deck_time_label(int $timestamp): string
{
    $twelve_hour = function_exists('get_setting') && (string) get_setting('time_format', '24') === '12';
    return date($twelve_hour ? 'g:i A' : 'H:i', $timestamp);
}

/** Recent ticket activity (and published reports for admins), scoped like the ticket list. */
function work_flight_deck_feed(array $user, bool $team, int $limit = 25): array
{
    $user_id = (int) ($user['id'] ?? 0);
    $limit = max(1, min(100, $limit));
    if ($user_id <= 0 || !function_exists('table_exists') || !table_exists('activity_log')) {
        return [];
    }

    $status_by_name = [];
    if (function_exists('get_statuses') && function_exists('ticket_status_group_from_status')) {
        foreach (get_statuses() as $status) {
            $status_by_name[(string) ($status['name'] ?? '')] = ticket_status_group_from_status($status);
        }
    }

    $params = [];
    $where = build_ticket_where_clause(build_ticket_visibility_filters_for_user($user, ['is_archived' => 0]), $params);
    $scope = '';
    if (!$team) {
        $scope = ' AND (al.user_id = ? OR t.assignee_id = ?)';
        $params[] = $user_id;
        $params[] = $user_id;
    }
    $rows = db_fetch_all("SELECT al.id, al.ticket_id, al.user_id, al.action, al.details, al.created_at,
            t.hash, t.title, t.organization_id, o.name AS organization_name,
            au.first_name, au.last_name, au.email, au.role AS actor_role
        FROM activity_log al
        JOIN tickets t ON t.id = al.ticket_id
        LEFT JOIN organizations o ON o.id = t.organization_id
        LEFT JOIN users au ON au.id = al.user_id
        {$where} AND al.action IN ('created', 'commented', 'status_changed', 'assigned', 'time_manual', 'time_stopped') {$scope}
        ORDER BY al.created_at DESC, al.id DESC
        LIMIT " . ($limit * 6), $params);

    $items = [];
    foreach ($rows as $row) {
        $timestamp = strtotime((string) ($row['created_at'] ?? '')) ?: time();
        $actor_is_client = (string) ($row['actor_role'] ?? '') === 'user';
        $details = (string) ($row['details'] ?? '');
        $type = 'status';
        $verb = '';
        $minutes = 0;
        switch ((string) $row['action']) {
            case 'created':
                $type = 'new';
                $verb = $actor_is_client ? t('work.feed.requested') : t('work.feed.created');
                break;
            case 'commented':
                $type = 'reply';
                $verb = $actor_is_client ? t('work.feed.commented') : t('work.feed.replied');
                break;
            case 'assigned':
                $type = 'assign';
                $verb = t('work.feed.assigned');
                break;
            case 'time_manual':
            case 'time_stopped':
                $type = 'time';
                $minutes = preg_match('/(\d+)\s*min/', $details, $match) ? (int) $match[1] : 0;
                $verb = $minutes > 0
                    ? t('work.feed.logged', ['duration' => format_duration_minutes($minutes)])
                    : t('work.feed.logged_time');
                break;
            case 'status_changed':
                $status_name = preg_match("/to '([^']*)'$/u", $details, $match) ? (string) $match[1] : '';
                $group = $status_by_name[$status_name] ?? 'active';
                if ($group === 'done') {
                    $type = 'done';
                    $verb = t('work.feed.closed');
                } elseif ($group === 'waiting') {
                    $type = 'wait';
                    $verb = t('work.feed.waiting');
                } else {
                    $type = 'status';
                    $verb = $status_name !== '' ? t('work.feed.moved', ['status' => $status_name]) : t('work.feed.updated');
                }
                break;
        }

        $items[] = [
            'timestamp' => $timestamp,
            'time' => work_flight_deck_time_label($timestamp),
            'type' => $type,
            'minutes' => $minutes,
            'count' => 1,
            'actor_id' => (int) ($row['user_id'] ?? 0),
            'actor' => work_flight_deck_actor_name($row),
            'verb' => $verb,
            'code' => get_ticket_code((int) $row['ticket_id']),
            'title' => (string) ($row['title'] ?? ''),
            'meta' => (string) ($row['organization_name'] ?? ''),
            'url' => !empty($row['hash']) ? url('ticket', ['t' => (string) $row['hash']]) : url('ticket', ['id' => (int) $row['ticket_id']]),
        ];
    }

    if (function_exists('is_admin') && is_admin() && table_exists('report_templates') && column_exists('report_templates', 'is_draft')) {
        $report_params = [];
        $archived = column_exists('report_templates', 'is_archived') ? ' AND rt.is_archived = 0' : '';
        $sent_select = column_exists('report_templates', 'schedule_last_sent') ? 'rt.schedule_last_sent' : 'NULL';
        $owner = '';
        if (!$team && column_exists('report_templates', 'created_by_user_id')) {
            $owner = ' AND rt.created_by_user_id = ?';
            $report_params[] = $user_id;
        }
        $reports = db_fetch_all("SELECT rt.id, rt.title, rt.created_at, {$sent_select} AS sent_at, o.name AS organization_name
            FROM report_templates rt LEFT JOIN organizations o ON o.id = rt.organization_id
            WHERE rt.is_draft = 0{$archived}{$owner}
            ORDER BY rt.created_at DESC, rt.id DESC LIMIT 10", $report_params);
        foreach ($reports as $report) {
            $report_url = url('admin', ['section' => 'report-builder', 'edit' => (int) ($report['id'] ?? 0)]);
            $client = trim((string) ($report['organization_name'] ?? ''));
            foreach (['created_at' => t('work.feed.report_ready'), 'sent_at' => t('work.feed.report_sent')] as $column => $verb) {
                if (empty($report[$column])) {
                    continue;
                }
                $timestamp = strtotime((string) $report[$column]) ?: 0;
                if ($timestamp <= 0) {
                    continue;
                }
                $items[] = [
                    'timestamp' => $timestamp,
                    'time' => work_flight_deck_time_label($timestamp),
                    'type' => 'bill',
                    'minutes' => 0,
                    'count' => 1,
                    'actor_id' => 0,
                    'actor' => $client !== '' ? $client : t('Report'),
                    'verb' => $verb,
                    'code' => '',
                    'title' => (string) ($report['title'] ?? ''),
                    'meta' => '',
                    'url' => $report_url,
                ];
            }
        }
    }

    usort($items, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
    return array_slice(work_flight_deck_feed_collapse($items), 0, $limit);
}

/**
 * One line per burst of the same action: repeated replies on one ticket within
 * a few hours become a single row with a count, logged time on one ticket is
 * summed, and intermediate status moves are dropped when the same person did
 * something more meaningful on that ticket in the same window.
 */
function work_flight_deck_feed_collapse(array $items): array
{
    $window = 3 * 3600;
    $kept = [];
    foreach ($items as $item) {
        $key = $item['actor_id'] . '|' . $item['type'] . '|' . $item['code'];
        $merged = false;
        foreach ($kept as &$existing) {
            if ($existing['_key'] !== $key || abs((int) $existing['timestamp'] - (int) $item['timestamp']) > $window) {
                continue;
            }
            if (in_array($item['type'], ['reply', 'time', 'status', 'assign', 'done', 'wait', 'new'], true)) {
                $existing['count']++;
                $existing['minutes'] += (int) $item['minutes'];
                $existing['first_timestamp'] = min((int) $existing['first_timestamp'], (int) $item['timestamp']);
                $merged = true;
            }
            break;
        }
        unset($existing);
        if ($merged) {
            continue;
        }
        $item['_key'] = $key;
        $item['first_timestamp'] = (int) $item['timestamp'];
        $kept[] = $item;
    }
    // Drop plain status moves and assignments when the same person replied, logged time or closed the ticket nearby.
    $result = [];
    foreach ($kept as $index => $item) {
        if (in_array($item['type'], ['status', 'assign'], true)) {
            $noise = false;
            foreach ($kept as $other_index => $other) {
                if ($other_index === $index || (int) $other['actor_id'] !== (int) $item['actor_id'] || $other['code'] !== $item['code']) {
                    continue;
                }
                if (in_array($other['type'], ['reply', 'time', 'done', 'wait', 'new'], true) && abs((int) $other['timestamp'] - (int) $item['timestamp']) <= $window) {
                    $noise = true;
                    break;
                }
            }
            if ($noise) {
                continue;
            }
        }
        if ($item['type'] === 'time' && (int) $item['minutes'] > 0) {
            $item['verb'] = t('work.feed.logged', ['duration' => format_duration_minutes((int) $item['minutes'])]);
        }
        unset($item['_key']);
        $result[] = $item;
    }
    return $result;
}

/** Tickets most recently touched, in the same visibility scope as the queues. */
function work_flight_deck_recent_tickets(array $user, bool $team, int $limit = 8): array
{
    if (!function_exists('work_queue_items')) {
        return [];
    }
    $items = work_queue_items($team ? 'all' : 'mine', $user, $limit, 0, 'last_updated');
    $recent = [];
    foreach ($items as $ticket) {
        $status_group = 'active';
        if (function_exists('ticket_registry_status_group_from_ticket') && function_exists('get_statuses')) {
            $status_group = (string) ticket_registry_status_group_from_ticket($ticket, get_statuses());
        }
        if (function_exists('ticket_status_group_normalize')) {
            $status_group = ticket_status_group_normalize($status_group);
        }
        $updated = (string) ($ticket['updated_at'] ?? $ticket['created_at'] ?? '');
        $timestamp = $updated !== '' ? (strtotime($updated) ?: 0) : 0;
        $recent[] = [
            'id' => (int) ($ticket['id'] ?? 0),
            'code' => get_ticket_code((int) ($ticket['id'] ?? 0)),
            'title' => (string) ($ticket['title'] ?? ''),
            'client' => trim((string) ($ticket['organization_name'] ?? '')),
            'status' => function_exists('ticket_status_group_display_name')
                ? ticket_status_group_display_name(['name' => (string) ($ticket['status_name'] ?? ''), 'is_closed' => $ticket['is_closed'] ?? 0])
                : trim((string) ($ticket['status_name'] ?? '')),
            'status_group' => $status_group,
            'updated' => $timestamp > 0 ? work_flight_deck_time_label($timestamp) : '',
            'updated_day' => $timestamp > 0 ? date('Y-m-d', $timestamp) : '',
            'url' => ticket_url($ticket),
        ];
    }
    return $recent;
}

/** Group feed items under Today / Yesterday / date headings. */
function work_flight_deck_feed_groups(array $items): array
{
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $groups = [];
    foreach ($items as $item) {
        $day = date('Y-m-d', (int) $item['timestamp']);
        if (!isset($groups[$day])) {
            $groups[$day] = [
                'label' => $day === $today ? t('Today') : ($day === $yesterday ? t('Yesterday') : format_date_localized($day, 'l, j. F')),
                'items' => [],
            ];
        }
        $groups[$day]['items'][] = $item;
    }
    return array_values($groups);
}

/** Two-letter mark for a client name (first letters of the first two words). */
function work_flight_deck_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
        if (mb_strlen($initials, 'UTF-8') >= 2) {
            break;
        }
    }
    if (mb_strlen($initials, 'UTF-8') < 2 && count($parts) === 1 && mb_strlen((string) $parts[0], 'UTF-8') >= 2) {
        $initials = mb_strtoupper(mb_substr((string) $parts[0], 0, 2, 'UTF-8'), 'UTF-8');
    }
    return $initials !== '' ? $initials : '?';
}
