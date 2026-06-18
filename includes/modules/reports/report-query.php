<?php
/**
 * Report query read model.
 */

function report_query_empty_result(): array
{
    return [
        'entries' => [],
        'totals' => report_totals_empty(),
        'by_org' => [],
        'by_agent' => [],
        'by_ticket' => [],
        'by_week' => [],
        'by_source' => [],
    ];
}

function report_query_tenant_ticket_filter(array &$params): string
{
    if (function_exists('tenant_sql_filter')) {
        return tenant_sql_filter('tickets', 't', $params);
    }

    if (function_exists('column_exists') && column_exists('tickets', 'tenant_id') && function_exists('current_tenant_id')) {
        $params[] = current_tenant_id();
        return ' AND t.tenant_id = ?';
    }

    return '';
}

function report_query_time_entries(array $filter_state, array $current_user, bool $tags_supported, int $rounding, array $ai_user_ids = []): array
{
    if (!function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return report_query_empty_result();
    }

    $tab = (string) ($filter_state['tab'] ?? 'summary');
    if (in_array($tab, ['shared', 'rates'], true)) {
        return report_query_empty_result();
    }

    $ticket_tags_select = $tags_supported ? ', t.tags as ticket_tags' : ', NULL as ticket_tags';
    $ticket_custom_rate_select = (function_exists('column_exists') && column_exists('tickets', 'custom_billable_rate'))
        ? 't.custom_billable_rate as ticket_custom_billable_rate,'
        : 'NULL as ticket_custom_billable_rate,';
    $user_billable_rate_select = (function_exists('column_exists') && column_exists('users', 'billable_rate'))
        ? 'u.billable_rate as user_billable_rate,'
        : 'NULL as user_billable_rate,';

    $sql = "SELECT tte.*,
                   t.title as ticket_title,
                   t.organization_id,
                   {$ticket_custom_rate_select}
                   t.status_id as ticket_status_id,
                   s.is_closed as ticket_is_closed,
                   s.name as ticket_status_name,
                   o.name as organization_name,
                   o.billable_rate as org_billable_rate,
                   u.first_name,
                   u.last_name,
                   {$user_billable_rate_select}
                   u.cost_rate as user_cost_rate
                   {$ticket_tags_select}
            FROM ticket_time_entries tte
            JOIN tickets t ON tte.ticket_id = t.id
            LEFT JOIN statuses s ON t.status_id = s.id
            LEFT JOIN organizations o ON t.organization_id = o.id
            LEFT JOIN users u ON tte.user_id = u.id
            WHERE 1=1";
    $params = [];
    $sql .= report_query_tenant_ticket_filter($params);

    if (!empty($filter_state['range_start']) && !empty($filter_state['range_end'])) {
        $sql .= " AND tte.started_at >= ? AND tte.started_at <= ?";
        $params[] = $filter_state['range_start'];
        $params[] = $filter_state['range_end'];
    }

    $selected_orgs = array_map('intval', (array) ($filter_state['selected_orgs'] ?? []));
    $org_ids = array_values(array_filter($selected_orgs, static fn (int $id): bool => $id > 0));
    $include_none_org = in_array(0, $selected_orgs, true);
    if (!empty($org_ids) || $include_none_org) {
        $conditions = [];
        if (!empty($org_ids)) {
            $conditions[] = "t.organization_id IN (" . implode(',', array_fill(0, count($org_ids), '?')) . ")";
            foreach ($org_ids as $org_id) {
                $params[] = $org_id;
            }
        }
        if ($include_none_org) {
            $conditions[] = "t.organization_id IS NULL";
        }
        $sql .= " AND (" . implode(' OR ', $conditions) . ")";
    }

    $selected_agents = array_map('intval', (array) ($filter_state['selected_agents'] ?? []));
    $agent_ids = array_values(array_filter($selected_agents, static fn (int $id): bool => $id > 0));
    if (!empty($agent_ids)) {
        $sql .= " AND tte.user_id IN (" . implode(',', array_fill(0, count($agent_ids), '?')) . ")";
        foreach ($agent_ids as $agent_id) {
            $params[] = $agent_id;
        }
    }

    $is_admin_user = function_exists('is_admin') ? is_admin() : (($current_user['role'] ?? '') === 'admin');
    if (!$is_admin_user) {
        $sql .= " AND tte.user_id = ?";
        $params[] = (int) ($current_user['id'] ?? 0);
    }

    $selected_tags = (array) ($filter_state['selected_tags'] ?? []);
    if ($tags_supported && !empty($selected_tags)) {
        $tag_conditions = [];
        foreach ($selected_tags as $tag) {
            $tag_conditions[] = "FIND_IN_SET(?, REPLACE(IFNULL(t.tags, ''), ', ', ',')) > 0";
            $params[] = $tag;
        }
        $sql .= " AND (" . implode(' OR ', $tag_conditions) . ")";
    }

    $sql .= " ORDER BY tte.started_at DESC, tte.id DESC LIMIT 10000";
    $rows = db_fetch_all($sql, $params);

    $entries = [];
    foreach ($rows as $row) {
        $entries[] = report_entry_enrich($row, $rounding);
    }

    return report_group_enriched_entries($entries, $ai_user_ids);
}
