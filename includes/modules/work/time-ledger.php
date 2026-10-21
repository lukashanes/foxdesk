<?php
/** Saved work ledger. Aggregates, exports and rows share one permission/filter predicate. */
function time_ledger_page(array $request, int $count): array
{
    $size = is_scalar($request['page_size'] ?? null) ? (string) $request['page_size'] : '20';
    if (!in_array($size, ['10', '20', '50', 'all'], true)) $size = '20';
    $pages = $size === 'all' ? 1 : max(1, (int) ceil($count / (int) $size));
    $page = max(1, min($pages, (int) ($request['p'] ?? 1)));
    return ['size' => $size, 'page' => $page, 'pages' => $pages, 'count' => $count,
        'offset' => $size === 'all' ? 0 : ($page - 1) * (int) $size];
}

function time_ledger_where(array $user, array $period, array $request, array &$params): string
{
    if (empty($user['id']) || !in_array($user['role'] ?? '', ['admin', 'agent'], true)) return ' WHERE 1=0';
    $where = build_ticket_where_clause(build_ticket_visibility_filters_for_user($user), $params);
    $where .= ' AND tte.ended_at IS NOT NULL AND tte.duration_minutes > 0 AND tte.started_at >= ? AND tte.started_at <= ?';
    $params[] = $period['start'];
    $params[] = $period['end'];
    if (($user['role'] ?? '') !== 'admin' || ($request['time_scope'] ?? 'mine') !== 'team') {
        $where .= ' AND tte.user_id = ?'; $params[] = (int) $user['id'];
    } elseif (ctype_digit((string) ($request['agent'] ?? '')) && (int) $request['agent'] > 0) {
        $where .= ' AND tte.user_id = ?'; $params[] = (int) $request['agent'];
    }
    if (ctype_digit((string) ($request['client'] ?? ''))) {
        $where .= ' AND COALESCE(t.organization_id, 0) = ?'; $params[] = (int) $request['client'];
    }
    if (in_array($request['billable'] ?? '', ['1', '0'], true)) {
        $where .= ' AND tte.is_billable = ?'; $params[] = (int) $request['billable'];
    }
    $search = trim(is_scalar($request['search'] ?? null) ? (string) $request['search'] : '');
    if ($search !== '') {
        $where .= ' AND (t.title LIKE ? OR tte.summary LIKE ? OR CAST(t.id AS CHAR) = ?)';
        $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; $params[] = $search;
    }
    return $where;
}

function time_ledger_model(array $user, array $period, array $request): array
{
    $params = [];
    $where = time_ledger_where($user, $period, $request, $params);
    $from = ' FROM ticket_time_entries tte JOIN tickets t ON t.id = tte.ticket_id';
    $totals = db_fetch_one('SELECT COUNT(*) AS count, COALESCE(SUM(tte.duration_minutes),0) AS minutes,
        COALESCE(SUM(CASE WHEN tte.is_billable = 1 THEN tte.duration_minutes ELSE 0 END),0) AS billable,
        COUNT(DISTINCT COALESCE(t.organization_id,0)) AS clients' . $from . $where, $params);
    $paging = time_ledger_page($request, (int) $totals['count']);
    $limit = $paging['size'] === 'all' ? '' : ' LIMIT ' . (int) $paging['size'] . ' OFFSET ' . $paging['offset'];
    $select = 'SELECT tte.*, t.title AS ticket_title, t.hash AS ticket_hash, o.name AS client_name, u.first_name, u.last_name';
    $joins = ' LEFT JOIN organizations o ON o.id = t.organization_id LEFT JOIN users u ON u.id = tte.user_id';
    $rows = db_fetch_all($select . $from . $joins . $where . ' ORDER BY tte.started_at DESC, tte.id DESC' . $limit, $params);
    $choice_request = $request;
    unset($choice_request['client'], $choice_request['agent'], $choice_request['search'], $choice_request['billable']);
    $choice_params = [];
    $choice_where = time_ledger_where($user, $period, $choice_request, $choice_params);
    $clients = db_fetch_all('SELECT COALESCE(t.organization_id,0) AS id, o.name, COUNT(*) AS count' . $from .
        ' LEFT JOIN organizations o ON o.id = t.organization_id' . $choice_where . ' GROUP BY t.organization_id, o.name ORDER BY count DESC, o.name', $choice_params);
    $agents = ($user['role'] ?? '') === 'admin' && ($request['time_scope'] ?? 'mine') === 'team'
        ? db_fetch_all('SELECT tte.user_id AS id, u.first_name, u.last_name, COUNT(*) AS count' . $from .
            ' JOIN users u ON u.id = tte.user_id' . $choice_where . ' GROUP BY tte.user_id, u.first_name, u.last_name ORDER BY count DESC, u.last_name', $choice_params) : [];
    return compact('rows', 'totals', 'paging', 'clients', 'agents', 'where', 'params', 'from', 'select', 'joins');
}

function time_ledger_csv_value($value): string
{
    $value = (string) $value;
    return preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
}
