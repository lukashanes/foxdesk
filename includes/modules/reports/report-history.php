<?php
/** Read-only self-hosted report history. The admin route enforces access. */
function report_history_model(array $request): array
{
    $params = [];
    $where = ' WHERE 1=1';
    if (report_template_column_exists('is_archived')) $where .= ' AND rt.is_archived = 0';
    $search = trim(is_scalar($request['search'] ?? '') ? (string) ($request['search'] ?? '') : '');
    if ($search !== '') {
        $where .= ' AND (rt.title LIKE ? OR o.name LIKE ?)';
        $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%';
    }
    if (in_array($request['draft'] ?? '', ['0','1'], true)) {
        $where .= ' AND rt.is_draft = ?'; $params[] = (int) $request['draft'];
    }
    $client = max(0, (int) ($request['client'] ?? 0));
    if ($client > 0) { $where .= ' AND rt.organization_id = ?'; $params[] = $client; }
    $from = ' FROM report_templates rt LEFT JOIN organizations o ON rt.organization_id = o.id';
    $total = db_fetch_one('SELECT COUNT(*) AS count' . $from . $where, $params);
    $paging = time_ledger_page($request, (int) ($total['count'] ?? 0));
    $limit = $paging['size'] === 'all' ? '' : ' LIMIT ' . (int) $paging['size'] . ' OFFSET ' . $paging['offset'];
    $rows = db_fetch_all('SELECT rt.*, o.name AS organization_name, u.first_name, u.last_name,
        (SELECT rs.kpi_data FROM report_snapshots rs WHERE rs.report_template_id = rt.id ORDER BY rs.id DESC LIMIT 1) AS snapshot_kpis' . $from .
        ' LEFT JOIN users u ON rt.created_by_user_id = u.id' . $where . ' ORDER BY rt.created_at DESC, rt.id DESC' . $limit, $params);
    foreach ($rows as &$row) {
        $share = empty($row['is_draft']) ? get_active_report_template_share((int) $row['id']) : null;
        $row['share_token'] = $share['token'] ?? null;
        $snapshot = json_decode((string) ($row['snapshot_kpis'] ?? ''), true);
        $row['saved_minutes'] = empty($row['is_draft']) && is_array($snapshot) && isset($snapshot['total_minutes'])
            ? max(0, (int) $snapshot['total_minutes']) : null;
    }
    unset($row);
    return ['rows' => $rows, 'paging' => $paging, 'search' => $search, 'client' => $client];
}
