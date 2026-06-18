<?php
/**
 * Team/users admin read model and form helpers.
 */

function team_users_table_capabilities(): array
{
    return [
        'ai_agent' => function_exists('column_exists') && column_exists('users', 'is_ai_agent'),
        'email_notifications' => function_exists('column_exists') && column_exists('users', 'email_notifications_enabled'),
        'in_app_notifications' => function_exists('column_exists') && column_exists('users', 'in_app_notifications_enabled'),
        'in_app_sound' => function_exists('column_exists') && column_exists('users', 'in_app_sound_enabled'),
        'contact_phone' => function_exists('column_exists') && column_exists('users', 'contact_phone'),
        'notes' => function_exists('column_exists') && column_exists('users', 'notes'),
        'deleted_at' => function_exists('column_exists') && column_exists('users', 'deleted_at'),
    ];
}

function team_users_filter_state(array $input): array
{
    return [
        'search' => trim((string) ($input['search'] ?? '')),
        'role' => in_array(($input['role'] ?? ''), ['user', 'agent', 'admin'], true) ? (string) $input['role'] : '',
        'status' => in_array(($input['status'] ?? ''), ['active', 'inactive'], true) ? (string) $input['status'] : '',
    ];
}

function team_users_tenant_filter(string $table, string $alias, array &$params): string
{
    if (function_exists('tenant_sql_filter')) {
        return tenant_sql_filter($table, $alias, $params);
    }

    if (function_exists('column_exists') && column_exists($table, 'tenant_id') && function_exists('current_tenant_id')) {
        $params[] = current_tenant_id();
        return ' AND ' . ($alias !== '' ? $alias . '.' : '') . 'tenant_id = ?';
    }

    return '';
}

function team_users_valid_organization_ids(array $organizations): array
{
    return array_map('intval', array_column($organizations, 'id'));
}

function team_users_filter_organization_ids($organization_ids, ?array $valid_organization_ids = null): array
{
    $ids = normalize_organization_ids($organization_ids);
    if ($valid_organization_ids === null) {
        return $ids;
    }

    $valid = array_map('intval', $valid_organization_ids);
    return array_values(array_intersect($ids, $valid));
}

function team_users_normalize_organization_assignment($organization_id, $membership_ids, ?array $valid_organization_ids = null): array
{
    $primary_id = !empty($organization_id) ? (int) $organization_id : null;
    $memberships = team_users_filter_organization_ids($membership_ids, $valid_organization_ids);

    if ($primary_id !== null && $valid_organization_ids !== null && !in_array($primary_id, $valid_organization_ids, true)) {
        $primary_id = null;
    }

    if ($primary_id !== null && !in_array($primary_id, $memberships, true)) {
        $memberships[] = $primary_id;
    }

    $memberships = team_users_filter_organization_ids($memberships, $valid_organization_ids);
    if ($primary_id === null && !empty($memberships)) {
        $primary_id = (int) $memberships[0];
    }

    return [
        'organization_id' => $primary_id,
        'organization_membership_ids' => $memberships,
    ];
}

function team_users_permission_payload(string $role, ?int $organization_id, array $organization_membership_ids, array $input, ?array $valid_organization_ids = null): ?array
{
    if ($role === 'admin') {
        return [
            'ticket_scope' => 'all',
            'organization_ids' => team_users_filter_organization_ids($organization_membership_ids, $valid_organization_ids),
            'can_archive' => true,
            'can_view_edit_history' => true,
            'can_import_md' => true,
            'can_view_time' => true,
            'can_view_timeline' => true,
        ];
    }

    if (!in_array($role, ['agent', 'user'], true)) {
        return null;
    }

    $ticket_scope = (string) ($input['ticket_scope'] ?? 'all');
    if (!in_array($ticket_scope, ['all', 'assigned', 'organization', 'own'], true)) {
        $ticket_scope = $role === 'user'
            ? ($organization_id !== null ? 'organization' : 'own')
            : 'all';
    }

    $scope_organization_ids = $ticket_scope === 'organization'
        ? team_users_filter_organization_ids($input['scope_organization_ids'] ?? [], $valid_organization_ids)
        : [];

    $effective_organization_ids = team_users_filter_organization_ids(array_merge(
        $organization_membership_ids,
        $scope_organization_ids
    ), $valid_organization_ids);

    return [
        'ticket_scope' => $ticket_scope,
        'organization_ids' => $effective_organization_ids,
        'can_archive' => $role === 'agent' && isset($input['can_archive']),
        'can_view_edit_history' => isset($input['can_view_edit_history']),
        'can_import_md' => $role === 'agent' && isset($input['can_import_md']),
        'can_view_time' => isset($input['can_view_time']),
        'can_view_timeline' => isset($input['can_view_timeline']),
    ];
}

function team_users_fetch(array $filters, array $capabilities): array
{
    $join = 'LEFT JOIN organizations o ON u.organization_id = o.id';
    if (function_exists('tenant_scoped_table_has_column')
        && tenant_scoped_table_has_column('users')
        && tenant_scoped_table_has_column('organizations')) {
        $join .= ' AND o.tenant_id = u.tenant_id';
    }

    $sql = "SELECT u.*, o.name as organization_name
            FROM users u
            {$join}
            WHERE 1=1";
    $params = [];
    $sql .= team_users_tenant_filter('users', 'u', $params);
    $sql .= " AND u.email NOT LIKE 'deleted-user-%@invalid.local'";

    if (!empty($capabilities['deleted_at'])) {
        $sql .= ' AND u.deleted_at IS NULL';
    }
    if (!empty($capabilities['ai_agent'])) {
        $sql .= ' AND (u.is_ai_agent = 0 OR u.is_ai_agent IS NULL)';
    }

    if (($filters['search'] ?? '') !== '') {
        $search_parts = [
            'u.first_name LIKE ?',
            'u.last_name LIKE ?',
            'u.email LIKE ?',
        ];
        if (!empty($capabilities['contact_phone'])) {
            $search_parts[] = 'u.contact_phone LIKE ?';
        }
        if (!empty($capabilities['notes'])) {
            $search_parts[] = 'u.notes LIKE ?';
        }
        $sql .= ' AND (' . implode(' OR ', $search_parts) . ')';
        $search_term = '%' . $filters['search'] . '%';
        foreach ($search_parts as $_) {
            $params[] = $search_term;
        }
    }

    if (($filters['role'] ?? '') !== '') {
        $sql .= ' AND u.role = ?';
        $params[] = $filters['role'];
    }
    if (($filters['status'] ?? '') !== '') {
        $sql .= ' AND u.is_active = ?';
        $params[] = $filters['status'] === 'active' ? 1 : 0;
    }

    $sql .= " ORDER BY FIELD(u.role, 'user', 'agent', 'admin'), u.first_name, u.last_name";
    return db_fetch_all($sql, $params);
}

function team_users_time_totals(?string $range_start, ?string $range_end): array
{
    if (!function_exists('ticket_time_table_exists') || !ticket_time_table_exists()) {
        return [];
    }

    $dur = sql_timer_duration_minutes();
    $sql = "SELECT tte.user_id, SUM({$dur}) as total_minutes
            FROM ticket_time_entries tte
            LEFT JOIN users u ON u.id = tte.user_id
            WHERE 1=1";
    $params = [];
    $sql .= team_users_tenant_filter('users', 'u', $params);

    if ($range_start && $range_end) {
        $sql .= ' AND tte.started_at >= ? AND tte.started_at <= ?';
        $params[] = $range_start;
        $params[] = $range_end;
    }

    $sql .= ' GROUP BY tte.user_id';
    $totals = [];
    foreach (db_fetch_all($sql, $params) as $row) {
        $totals[(int) $row['user_id']] = (int) $row['total_minutes'];
    }

    return $totals;
}

function team_ai_agents_fetch(bool $deleted_at_column_exists): array
{
    $sql = 'SELECT u.* FROM users u WHERE u.is_ai_agent = 1';
    $params = [];
    $sql .= team_users_tenant_filter('users', 'u', $params);
    if ($deleted_at_column_exists) {
        $sql .= ' AND u.deleted_at IS NULL';
    }
    $sql .= ' ORDER BY u.is_active DESC, u.first_name';

    return db_fetch_all($sql, $params);
}

function team_ai_agent_tokens_fetch(array $ai_agents): array
{
    if (empty($ai_agents)) {
        return [];
    }

    $ai_ids = array_map('intval', array_column($ai_agents, 'id'));
    $placeholders = implode(',', array_fill(0, count($ai_ids), '?'));
    $sql = 'SELECT * FROM api_tokens WHERE 1=1';
    $params = [];
    $sql .= team_users_tenant_filter('api_tokens', '', $params);
    $sql .= " AND user_id IN ({$placeholders}) ORDER BY created_at DESC";
    $params = array_merge($params, $ai_ids);

    try {
        return db_fetch_all($sql, $params);
    } catch (Throwable $e) {
        return [];
    }
}
