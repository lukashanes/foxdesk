<?php
/**
 * Ticket-list request controller.
 *
 * Resolves access, filters, query data, header actions, and view models. The
 * component renderer consumes the prepared variables without running queries.
 */
$user = current_user();
$is_archive = (isset($_GET['archived']) && $_GET['archived'] === '1') || (($_GET['work_view'] ?? '') === 'archived');
// Strict Access Control: Only admins can view Archive
if ($is_archive && !is_admin()) {
    flash(t('Access denied.'), 'error');
    redirect('tickets');
}
$page_title = $is_archive ? t('Archive') : t('Tickets');
$page = 'tickets';
$ticket_list_asset_version = static function (string $path): string {
    $file = BASE_PATH . '/' . ltrim($path, '/');
    $base = defined('APP_VERSION') ? APP_VERSION : '1';
    return $base . '-' . (is_file($file) ? (string) filemtime($file) : '0');
};
// Preserve current filter params for redirects after bulk actions
$_redirect_params = ticket_bulk_action_redirect_params($_GET);
ticket_handle_bulk_actions($_SERVER['REQUEST_METHOD'] ?? 'GET', $_POST, $user, $is_archive, $_redirect_params);
// Get filters
$ticket_filter_state = ticket_list_filter_state_from_request($_GET, $_COOKIE, $is_archive);
$filters = $ticket_filter_state['filters'];
$status_id = $ticket_filter_state['status_id'];
$organization_id = $ticket_filter_state['organization_id'];
$priority_id = $ticket_filter_state['priority_id'];
$search_query = $ticket_filter_state['search_query'];
$user_search = $ticket_filter_state['user_search'];
$created_date_input = $ticket_filter_state['created_date_input'];
$created_date_value = $ticket_filter_state['created_date_value'];
$due_date_filter = $ticket_filter_state['due_date_filter'];
$tags_supported = $ticket_filter_state['tags_supported'];
$tag_filters = $ticket_filter_state['tag_filters'];
$tag_filter_csv = $ticket_filter_state['tag_filter_csv'];
$assigned_to = $ticket_filter_state['assigned_to'];
$sort = $ticket_filter_state['sort'];
$ticket_view = $ticket_filter_state['ticket_view'];
if ($ticket_filter_state['ticket_view_should_persist']) {
    setcookie('foxdesk_ticket_view', $ticket_view, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
}
// VISIBILITY CONTROL
if (!is_admin()) {
    $permissions = get_user_permissions($user['id']) ?? [];
    $scope = $permissions['ticket_scope'] ?? 'own'; // Default fallback
    // 1. AGENTS
    if (is_agent()) {
        switch ($scope) {
            case 'assigned':
                // Strict: Only assigned to me (or created by me/shared)
                $filters['agent_id'] = $user['id'];
                break;
            case 'organization':
                // Agents can see tickets from specific organizations
                // The query builder handles 'organization' scope for agents by looking up permissions
                // We just need to signal the scope.
                $filters['current_user'] = $user;
                $filters['scope'] = 'organization';
                // Note: ticket-query-functions needs to handle this correctly for agents again
                break;
            case 'all':
                // See EVERYTHING (Super Agent / Manager)
                break;
            default:
                $filters['agent_id'] = $user['id'];
                break;
        }
    }
    // 2. REGULAR USERS
    else {
        switch ($scope) {
            case 'all':
                // User sees ALL tickets (rare, but possible for special users)
                break;
            case 'organization':
                // User sees tickets from their organizations
                // Allow toggling between "My Tickets" and "Company Tickets"
                $view_mode = $_GET['view_mode'] ?? 'company';
                if ($view_mode === 'company') {
                    // Check if user has multiple organizations in permissions
                    $org_ids = $permissions['organization_ids'] ?? [];
                    if (!empty($org_ids)) {
                        // Multi-organization user
                        $filters['current_user'] = $user;
                        $filters['scope'] = 'organization';
                    } elseif (!empty($user['organization_id'])) {
                        // Single organization from user profile
                        $filters['organization_id'] = $user['organization_id'];
                    } else {
                        // Fallback to own tickets
                        $filters['viewer_user_id'] = $user['id'];
                    }
                } else {
                    // User explicitly filtered for 'mine'
                    $filters['viewer_user_id'] = $user['id'];
                }
                break;
            case 'own':
            default:
                // User sees ONLY their own tickets
                $filters['viewer_user_id'] = $user['id'];
                unset($filters['organization_id']); // Ensure strictly own
                $filters['current_user'] = $user;
                $filters['scope'] = 'own';
                break;
        }
    }
}
// Admin staff scope filter (from dashboard links)
$staff_scope = is_admin() && (($_GET['scope'] ?? '') === 'staff');
if ($staff_scope) {
    $filters['assigned_to_staff'] = true;
}
$ticket_list_include_archive = is_admin();
$ticket_list_view = ticket_list_view_from_request($_GET, $is_archive, $ticket_list_include_archive);
$sort = ticket_list_view_effective_sort($ticket_list_view, $sort, (bool) ($ticket_filter_state['sort_is_explicit'] ?? false));
if ($sort !== 'newest') {
    $filters['sort'] = $sort;
} elseif (($filters['sort'] ?? '') === 'newest') {
    unset($filters['sort']);
}
$ticket_list_view_definitions = ticket_list_view_definitions($ticket_list_include_archive);
$ticket_show_all_url = $is_archive
    ? url('tickets', ['archived' => '1'])
    : ticket_list_view_url('all', [], $ticket_list_include_archive);
$ticket_clear_url = $is_archive
    ? url('tickets', ['archived' => '1'])
    : ticket_list_view_url($ticket_list_view, [], $ticket_list_include_archive);
$ticket_list_count_filters = $filters;
$ticket_list_view_counts = ticket_list_view_counts($ticket_list_count_filters, $ticket_list_include_archive);
$filters = ticket_list_view_apply_filters($filters, $ticket_list_view);
$total_tickets = get_tickets_count($filters);
$ticket_page_size = (string) ($_GET['page_size'] ?? '20');
if (!in_array($ticket_page_size, ['10', '20', '50', 'all'], true)) $ticket_page_size = '20';
$ticket_page_count = $ticket_page_size === 'all' ? 1 : max(1, (int) ceil($total_tickets / (int) $ticket_page_size));
$ticket_page_number = max(1, min($ticket_page_count, (int) ($_GET['p'] ?? 1)));
// Board columns retain their complete status populations. List paging stays server-owned.
if ($ticket_view === 'list' && $ticket_page_size !== 'all') {
    $filters['limit'] = (int) $ticket_page_size;
    $filters['offset'] = ($ticket_page_number - 1) * (int) $ticket_page_size;
}
$tickets = get_tickets($filters);
// Time tracking totals (admins only)
$show_time = is_admin() && ticket_time_table_exists();
$ticket_time_totals = [];
$ticket_running_entries = [];
if ($show_time && !empty($tickets)) {
    $ticket_ids = array_map(function ($t) {
        return (int) $t['id'];
    }, $tickets);
    $placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
    $dur = sql_timer_duration_minutes();
    $rows = db_fetch_all(
        "SELECT ticket_id, SUM({$dur}) as total_minutes
         FROM ticket_time_entries
         WHERE ticket_id IN ($placeholders)
         GROUP BY ticket_id",
        $ticket_ids
    );
    foreach ($rows as $row) {
        $ticket_time_totals[(int) $row['ticket_id']] = (int) $row['total_minutes'];
    }
    $running_rows = db_fetch_all(
        "SELECT tte.ticket_id, tte.user_id, u.first_name, u.last_name, tte.started_at,
                " . sql_timer_duration_minutes('tte.') . " as elapsed_minutes
         FROM ticket_time_entries tte
         LEFT JOIN users u ON tte.user_id = u.id
         WHERE tte.ticket_id IN ($placeholders) AND tte.ended_at IS NULL
         ORDER BY tte.started_at ASC",
        $ticket_ids
    );
    foreach ($running_rows as $row) {
        $ticket_id = (int) $row['ticket_id'];
        if (!isset($ticket_running_entries[$ticket_id])) {
            $ticket_running_entries[$ticket_id] = [];
        }
        $ticket_running_entries[$ticket_id][] = $row;
    }
}
require_once BASE_PATH . '/includes/header.php';
?>
<?php
$statuses = get_statuses();
$workflow_statuses = function_exists('ticket_status_group_visible_workflow_statuses')
    ? ticket_status_group_visible_workflow_statuses($statuses)
    : $statuses;
$filter_statuses = function_exists('ticket_status_group_visible_workflow_statuses')
    ? ticket_status_group_visible_workflow_statuses($statuses)
    : $statuses;
$status_display_name = static function (array $status): string {
    return function_exists('ticket_status_group_display_name')
        ? ticket_status_group_display_name($status)
        : (string) ($status['name'] ?? '');
};
$ticket_status_display_name = static function (array $ticket) use ($statuses): string {
    return function_exists('ticket_registry_status_label_from_ticket')
        ? ticket_registry_status_label_from_ticket($ticket, $statuses)
        : (string) ($ticket['status_name'] ?? '');
};
$priorities = get_priorities();
$filter_users = [];
if (is_agent()) {
    $filter_users = get_all_users();
}
// Inline-edit data: assignable agents + ticket types
$assignable_agents = [];
if (is_agent()) {
    try {
        $assignable_agents = db_fetch_all(
            "SELECT id, first_name, last_name FROM users WHERE role IN ('agent', 'admin') AND is_active = 1 ORDER BY first_name",
            []
        ) ?: [];
    } catch (Exception $e) {
        $assignable_agents = [];
    }
}
$ticket_types_list = function_exists('get_ticket_types') ? get_ticket_types() : [];
$organizations = [];
if (is_agent()) {
    try {
        $organizations = get_organizations(true);
        if (!is_admin()) {
            $allowed_org_ids = get_user_organization_ids($user['id']);
            if (!empty($allowed_org_ids)) {
                $lookup = array_flip($allowed_org_ids);
                $organizations = array_values(array_filter($organizations, function ($org) use ($lookup) {
                    return isset($lookup[(int) ($org['id'] ?? 0)]);
                }));
            }
        }
    } catch (Exception $e) {
        $organizations = [];
    }
}
$page_header_title = $is_archive ? t('Archive') : t('Tickets');
$filter_notes = [];
if (!empty($status_id)) {
    $status = get_status($status_id);
    if ($status)
        $filter_notes[] = $status_display_name($status);
}
if (!empty($priority_id)) {
    $priority = get_priority($priority_id);
    if ($priority)
        $filter_notes[] = $priority['name'];
}
if (!empty($organization_id) && is_admin()) {
    $org = get_organization($organization_id);
    if ($org)
        $filter_notes[] = $org['name'];
}
if ($search_query !== '') {
    $filter_notes[] = t('Search: {query}', ['query' => $search_query]);
}
$filter_tag_label = '';
if ($tags_supported && !empty($tag_filters)) {
    $tag_labels = array_map(static function ($tag) {
        return '#' . $tag;
    }, $tag_filters);
    $filter_tag_label = implode(', ', $tag_labels);
    $filter_notes[] = t('Tags') . ': ' . $filter_tag_label;
}
$filter_user_label = '';
if ($user_search !== '') {
    $filter_user_label = t('User') . ': ' . $user_search;
    $filter_notes[] = $filter_user_label;
}
if ($created_date_value !== '') {
    $filter_notes[] = t('Created') . ': ' . $created_date_value;
}
$has_filters = !empty($search_query) || !empty($status_id) || !empty($priority_id) ||
               !empty($organization_id) || !empty($due_date_filter) || !empty($created_date_value) ||
               !empty($user_search) || !empty($assigned_to) || $staff_scope || ($tags_supported && !empty($tag_filters)) || $sort !== ticket_list_view_default_sort($ticket_list_view);
$page_header_subtitle = '';
$page_header_breadcrumbs = [
    ['label' => t('All tickets'), 'url' => url('tickets', $is_archive ? ['archived' => '1'] : [])]
];
if ($is_archive) {
    $page_header_breadcrumbs[] = ['label' => t('Archive')];
}
if (!empty($status_id) && !empty($status['name'])) {
    $page_header_breadcrumbs[] = ['label' => $status_display_name($status)];
}
if (!empty($organization_id) && !empty($org['name'])) {
    $page_header_breadcrumbs[] = ['label' => $org['name']];
}
$bulk_actions_enabled = is_agent() && !empty($tickets) && !$is_archive;
$bulk_archive_mode = $bulk_actions_enabled && !$is_archive;
$bulk_delete_mode = false;
$page_header_actions = '';
// User View Mode Toggle
if (!is_admin() && !is_agent() && isset($scope) && $scope === 'organization' && !empty($user['organization_id'])) {
    $current_view = $_GET['view_mode'] ?? 'company';
    // Remove p=page to reset pagination when switching
    $params_mine = $_GET; unset($params_mine['p']); $params_mine['view_mode'] = 'mine';
    $params_comp = $_GET; unset($params_comp['p']); $params_comp['view_mode'] = 'company';
    $mine_url = url('tickets', $params_mine);
    $company_url = url('tickets', $params_comp);
    $page_header_actions .= '<div class="ticket-segmented-control" role="group" aria-label="' . e(t('Ticket scope')) . '">
        <a href="'.$mine_url.'" class="ticket-segmented-item '.($current_view === 'mine' ? 'is-active' : '').'">
            '.t('My Tickets').'
        </a>
        <a href="'.$company_url.'" class="ticket-segmented-item '.($current_view === 'company' ? 'is-active' : '').'">
            '.t('Company Tickets').'
        </a>
    </div>';
}
// Board/List view toggle
if (!$is_archive) {
    $view_params_list = $_GET; unset($view_params_list['p'], $view_params_list['page']); $view_params_list['view'] = 'list';
    $view_params_board = $_GET; unset($view_params_board['p'], $view_params_board['page']); $view_params_board['view'] = 'board';
    $list_url = url('tickets', $view_params_list);
    $board_url = url('tickets', $view_params_board);
    $page_header_actions .= '<div class="ticket-segmented-control ticket-segmented-control--icon" role="group" aria-label="' . e(t('View')) . '">'
        . '<a href="' . $list_url . '" class="ticket-segmented-item ' . ($ticket_view === 'list' ? 'is-active' : '') . '" title="' . e(t('List')) . '">'
        . get_icon('list', 'w-4 h-4')
        . '</a>'
        . '<a href="' . $board_url . '" class="ticket-segmented-item ' . ($ticket_view === 'board' ? 'is-active' : '') . '" title="' . e(t('Board')) . '">'
        . get_icon('columns', 'w-4 h-4')
        . '</a>'
        . '</div>';
}
// Sort dropdown in page header (syncs with hidden input in filter form via JS)
$sort_options = [
    'newest'           => t('Newest'),
    'oldest'           => t('Oldest'),
    'completed'        => t('Completed'),
    'last_updated'     => t('Last updated'),
    'ticket_number'    => t('Ticket # (newest)'),
    'ticket_number_asc'=> t('Ticket # (oldest)'),
    'priority'         => t('Priority'),
    'status'           => t('Status'),
    'due_date'         => t('Due date'),
];
if ($tags_supported) {
    $sort_options['tags'] = t('Tags');
}
$sort_select = '<div class="inline-flex items-center btn btn-ghost gap-1.5">'
    . get_icon('arrow-up-down', 'w-3.5 h-3.5 opacity-50 flex-shrink-0')
    . '<select id="header-sort" aria-label="' . e(t('Sort')) . '" class="appearance-none bg-transparent cursor-pointer text-sm font-semibold pr-5" onchange="applyHeaderSort(this.value)">';
foreach ($sort_options as $val => $label) {
    $sel = ($sort === $val) ? ' selected' : '';
    $sort_select .= '<option value="' . e($val) . '"' . $sel . '>' . e($label) . '</option>';
}
$sort_select .= '</select></div>';
$page_header_actions .= $sort_select;
if ($bulk_actions_enabled && $ticket_view !== 'board') {
    $page_header_actions .= '<button type="button" onclick="toggleBulkMode()" class="hdr-icon-btn" id="bulk-toggle" aria-pressed="false" title="' . e(t('Bulk select')) . '" aria-label="' . e(t('Bulk select')) . '">' . get_icon('check-square', 'w-4 h-4') . '</button>';
}
if (!$is_archive) {
    if (is_agent() || is_admin()) {
        $page_header_actions .= '<button type="button" id="quick-add-toggle-btn" class="hdr-icon-btn hdr-icon-btn--quick" onclick="window.toggleNewTicketRow && window.toggleNewTicketRow()" title="' . e(t('Quick add')) . '" aria-label="' . e(t('Quick add')) . '">' . get_icon('bolt', 'w-4 h-4') . '</button>';
    }
    $page_header_actions .= '<a href="' . url('new-ticket') . '" class="btn btn-primary btn-sm" title="' . e(t('New ticket')) . '">' . get_icon('plus', 'mr-1 w-4 h-4') . e(t('New ticket')) . '</a>';
}
$build_tag_filter_url = function ($tag_value) use ($is_archive, $tag_filters) {
    $params = $_GET;
    unset($params['page'], $params['p']);
    if ($is_archive) {
        $params['archived'] = '1';
    } else {
        unset($params['archived']);
    }
    $next_tags = ticket_list_normalize_tag_filters(array_merge($tag_filters, [$tag_value]));
    if (!empty($next_tags)) {
        $params['tags'] = implode(',', $next_tags);
    } else {
        unset($params['tags']);
    }
    unset($params['tag']);
    return url('tickets', $params);
};
$build_remove_tag_filter_url = function ($tag_value) use ($is_archive, $tag_filters) {
    $params = $_GET;
    unset($params['page'], $params['p']);
    if ($is_archive) {
        $params['archived'] = '1';
    } else {
        unset($params['archived']);
    }
    $remaining = [];
    $remove_key = function_exists('mb_strtolower') ? mb_strtolower($tag_value, 'UTF-8') : strtolower($tag_value);
    foreach ($tag_filters as $tag) {
        $tag_key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
        if ($tag_key === $remove_key) {
            continue;
        }
        $remaining[] = $tag;
    }
    $remaining = ticket_list_normalize_tag_filters($remaining);
    if (!empty($remaining)) {
        $params['tags'] = implode(',', $remaining);
    } else {
        unset($params['tags']);
    }
    unset($params['tag']);
    return url('tickets', $params);
};
$date_sort_next = $sort === 'oldest' ? 'newest' : 'oldest';
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $date_sort_next = 'newest';
}
$date_sort_params = $_GET;
unset($date_sort_params['page'], $date_sort_params['p']);
$date_sort_params['sort'] = $date_sort_next;
if ($is_archive) {
    $date_sort_params['archived'] = '1';
} else {
    unset($date_sort_params['archived']);
}
$date_sort_url = url('tickets', $date_sort_params);
$date_sort_icon = $sort === 'oldest' ? 'arrow-up' : ($sort === 'newest' ? 'arrow-down' : 'arrow-up-down');
$date_sort_is_active = in_array($sort, ['newest', 'oldest'], true);
$date_sort_title = t('Date') . ': ' . t($date_sort_next === 'oldest' ? 'Oldest' : 'Newest');
include BASE_PATH . '/includes/components/page-header.php';
?>
