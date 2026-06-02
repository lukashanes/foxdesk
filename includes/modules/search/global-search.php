<?php
/**
 * Global search service.
 *
 * This is the Spotlight layer above specific pages. It should search across
 * open work, completed history, clients, and later reports without requiring
 * the user to guess the right filter first.
 */

function global_search_sections(): array
{
    return [
        'open_tickets' => ['label' => 'Open tickets', 'type' => 'ticket'],
        'done_tickets' => ['label' => 'Done tickets', 'type' => 'ticket'],
        'archived_tickets' => ['label' => 'Archived tickets', 'type' => 'ticket'],
        'clients' => ['label' => 'Clients', 'type' => 'client'],
        'contacts' => ['label' => 'Contacts', 'type' => 'contact'],
        'reports' => ['label' => 'Reports', 'type' => 'report'],
    ];
}

function global_search_normalize_query(string $query): string
{
    $query = trim(preg_replace('/\s+/', ' ', $query));
    return mb_substr($query, 0, 120);
}

function global_search_ticket_filters(string $query, array $user, string $section, int $limit): array
{
    $filters = [
        'search' => $query,
        'is_archived' => 0,
        'sort' => 'last_updated',
        'limit' => max(1, min(20, $limit)),
    ];

    if ($section === 'archived_tickets') {
        $filters['is_archived'] = 1;
        unset($filters['status_group_not']);
    } elseif ($section === 'done_tickets') {
        $filters['status_group'] = 'done';
    } else {
        $filters['status_group_not'] = ['done', 'archived'];
    }

    if (function_exists('build_ticket_visibility_filters_for_user')) {
        $filters = build_ticket_visibility_filters_for_user($user, $filters);
    }

    return $filters;
}

function global_search_ticket_section(string $query, array $user, string $section, int $limit): array
{
    if (!function_exists('get_tickets')) {
        return [];
    }

    $items = [];
    foreach (get_tickets(global_search_ticket_filters($query, $user, $section, $limit)) as $ticket) {
        $status_group = function_exists('ticket_status_group_from_status')
            ? ticket_status_group_from_status([
                'name' => $ticket['status_name'] ?? '',
                'is_closed' => $ticket['is_closed'] ?? 0,
            ])
            : '';

        $items[] = [
            'type' => 'ticket',
            'id' => (int) ($ticket['id'] ?? 0),
            'title' => (string) ($ticket['title'] ?? ''),
            'code' => function_exists('get_ticket_code') ? get_ticket_code((int) $ticket['id']) : ('#' . (int) $ticket['id']),
            'status' => (string) ($ticket['status_name'] ?? ''),
            'status_group' => $status_group,
            'client' => (string) ($ticket['organization_name'] ?? ''),
            'assignee' => trim((string) (($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? ''))),
            'url' => function_exists('ticket_url') ? ticket_url($ticket) : 'index.php?page=ticket&id=' . (int) $ticket['id'],
            'updated_at' => (string) ($ticket['updated_at'] ?? ''),
        ];
    }

    return $items;
}

function global_search_clients(string $query, array $user, int $limit): array
{
    if (($user['role'] ?? '') === 'user' || !function_exists('db_fetch_all')) {
        return [];
    }

    $params = ['%' . $query . '%', '%' . $query . '%'];
    $sql = "SELECT id, name, contact_email
            FROM organizations
            WHERE (name LIKE ? OR contact_email LIKE ?)";
    if (function_exists('tenant_sql_filter')) {
        $sql .= tenant_sql_filter('organizations', '', $params);
    }
    $sql .= " ORDER BY name ASC LIMIT " . max(1, min(20, $limit));

    $items = [];
    foreach (db_fetch_all($sql, $params) as $client) {
        $items[] = [
            'type' => 'client',
            'id' => (int) ($client['id'] ?? 0),
            'title' => (string) ($client['name'] ?? ''),
            'subtitle' => (string) ($client['contact_email'] ?? ''),
            'url' => function_exists('url')
                ? url('client', ['id' => (int) ($client['id'] ?? 0)])
                : 'index.php?page=client&id=' . (int) ($client['id'] ?? 0),
        ];
    }

    return $items;
}

function global_search_contacts(string $query, array $user, int $limit): array
{
    if (($user['role'] ?? '') === 'user' || !function_exists('db_fetch_all')) {
        return [];
    }

    $term = '%' . $query . '%';
    $params = [$term, $term, $term];
    $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.role, o.name AS organization_name
            FROM users u
            LEFT JOIN organizations o ON u.organization_id = o.id
            WHERE u.email NOT LIKE 'deleted-user-%@invalid.local'
              AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    if (function_exists('users_deleted_at_column_exists') && users_deleted_at_column_exists()) {
        $sql .= " AND u.deleted_at IS NULL";
    }
    if (function_exists('tenant_sql_filter')) {
        $sql .= tenant_sql_filter('users', 'u', $params);
    }
    $sql .= " ORDER BY u.role = 'user' DESC, u.last_name, u.first_name LIMIT " . max(1, min(20, $limit));

    $items = [];
    foreach (db_fetch_all($sql, $params) as $contact) {
        $name = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
        $items[] = [
            'type' => 'contact',
            'id' => (int) ($contact['id'] ?? 0),
            'title' => $name !== '' ? $name : (string) ($contact['email'] ?? ''),
            'subtitle' => trim((string) (($contact['email'] ?? '') . (!empty($contact['organization_name']) ? ' · ' . $contact['organization_name'] : ''))),
            'role' => (string) ($contact['role'] ?? ''),
            'url' => function_exists('url')
                ? url('user-profile', ['id' => (int) ($contact['id'] ?? 0)])
                : 'index.php?page=user-profile&id=' . (int) ($contact['id'] ?? 0),
        ];
    }

    return $items;
}

function global_search_reports(string $query, array $user, int $limit): array
{
    if (($user['role'] ?? '') === 'user' || !function_exists('db_fetch_all') || !table_exists('report_templates')) {
        return [];
    }

    $term = '%' . $query . '%';
    $params = [$term, $term, $term];
    $sql = "SELECT rt.id, rt.title, rt.date_from, rt.date_to, rt.is_draft, o.name AS organization_name
            FROM report_templates rt
            LEFT JOIN organizations o ON rt.organization_id = o.id
            WHERE (rt.title LIKE ? OR rt.executive_summary LIKE ? OR o.name LIKE ?)";
    if (function_exists('report_template_column_exists') && report_template_column_exists('is_archived')) {
        $sql .= " AND rt.is_archived = 0";
    }
    if (function_exists('tenant_sql_filter')) {
        $sql .= tenant_sql_filter('report_templates', 'rt', $params);
    }
    $sql .= " ORDER BY rt.updated_at DESC, rt.created_at DESC LIMIT " . max(1, min(20, $limit));

    $items = [];
    foreach (db_fetch_all($sql, $params) as $report) {
        $date_range = trim((string) (($report['date_from'] ?? '') . ' - ' . ($report['date_to'] ?? '')), ' -');
        $items[] = [
            'type' => 'report',
            'id' => (int) ($report['id'] ?? 0),
            'title' => (string) ($report['title'] ?? ''),
            'subtitle' => trim((string) (($report['organization_name'] ?? '') . ($date_range !== '' ? ' · ' . $date_range : ''))),
            'status' => !empty($report['is_draft']) ? 'Draft' : 'Published',
            'url' => function_exists('url')
                ? url('admin', ['section' => 'report-builder', 'id' => (int) ($report['id'] ?? 0)])
                : 'index.php?page=admin&section=report-builder&id=' . (int) ($report['id'] ?? 0),
        ];
    }

    return $items;
}

function global_search(string $query, array $user, int $limit_per_section = 6): array
{
    $query = global_search_normalize_query($query);
    $sections = global_search_sections();

    $result = [
        'query' => $query,
        'sections' => [],
        'total' => 0,
    ];

    if (mb_strlen($query) < 2) {
        return $result;
    }

    $result['sections']['open_tickets'] = [
        'definition' => $sections['open_tickets'],
        'items' => global_search_ticket_section($query, $user, 'open_tickets', $limit_per_section),
    ];
    $result['sections']['done_tickets'] = [
        'definition' => $sections['done_tickets'],
        'items' => global_search_ticket_section($query, $user, 'done_tickets', $limit_per_section),
    ];
    $result['sections']['archived_tickets'] = [
        'definition' => $sections['archived_tickets'],
        'items' => global_search_ticket_section($query, $user, 'archived_tickets', $limit_per_section),
    ];
    $result['sections']['clients'] = [
        'definition' => $sections['clients'],
        'items' => global_search_clients($query, $user, $limit_per_section),
    ];
    $result['sections']['contacts'] = [
        'definition' => $sections['contacts'],
        'items' => global_search_contacts($query, $user, $limit_per_section),
    ];
    $result['sections']['reports'] = [
        'definition' => $sections['reports'],
        'items' => global_search_reports($query, $user, $limit_per_section),
    ];

    foreach ($result['sections'] as $section) {
        $result['total'] += count($section['items'] ?? []);
    }

    return $result;
}
