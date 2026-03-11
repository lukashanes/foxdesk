<?php
/**
 * Tickets List Page
 */

$user = current_user();
$is_archive = isset($_GET['archived']) && $_GET['archived'] === '1';

// Strict Access Control: Only admins can view Archive
if ($is_archive && !is_admin()) {
    flash(t('Access denied.'), 'error');
    redirect('tickets');
}

$page_title = $is_archive ? t('Archive') : t('Tickets');
$page = 'tickets';

// Bulk actions (archive/delete/update)
$collect_editable_tickets = function ($ticket_ids) use ($user) {
    $editable = [];
    $unique_ids = array_values(array_unique(array_filter(array_map('intval', (array) $ticket_ids))));
    foreach ($unique_ids as $ticket_id) {
        if ($ticket_id <= 0) {
            continue;
        }
        $ticket_item = get_ticket($ticket_id);
        if (!$ticket_item) {
            continue;
        }
        if (!can_see_ticket($ticket_item, $user) || !can_edit_ticket($ticket_item, $user)) {
            continue;
        }
        $editable[$ticket_id] = $ticket_item;
    }
    return $editable;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_agent()) {
    require_csrf_token();
    $ticket_ids = $_POST['ticket_ids'] ?? [];
    $editable_tickets = $collect_editable_tickets($ticket_ids);

    if (isset($_POST['bulk_delete']) && $is_archive) {
        $deleted_count = 0;
        $upload_dir = rtrim(BASE_PATH . '/' . (defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/'), '/\\') . DIRECTORY_SEPARATOR;
        foreach ($editable_tickets as $ticket_id => $ticket_item) {
            $attachments = get_ticket_attachments($ticket_id);
            foreach ($attachments as $attachment) {
                $path = $upload_dir . ($attachment['filename'] ?? '');
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            if (delete_ticket($ticket_id)) {
                $deleted_count++;
            }
        }

        if ($deleted_count > 0) {
            flash(t('Selected tickets were deleted.'), 'success');
        } else {
            flash(t('No tickets selected.'), 'error');
        }
        redirect('tickets', ['archived' => '1']);
    }

    if (isset($_POST['bulk_archive']) && !$is_archive) {
        $archived_count = 0;
        try {
            $archive_column_exists = (bool) db_fetch_one("SHOW COLUMNS FROM tickets LIKE 'is_archived'");
        } catch (Exception $e) {
            $archive_column_exists = false;
        }

        if (!$archive_column_exists) {
            flash(t('Archive is not available on this installation yet.'), 'error');
            redirect('tickets');
        }

        foreach ($editable_tickets as $ticket_id => $ticket_item) {
            if (db_update('tickets', ['is_archived' => 1], 'id = ?', [$ticket_id])) {
                log_activity($ticket_id, $user['id'], 'archived', 'Ticket archived via bulk action');
                $archived_count++;
            }
        }

        if ($archived_count > 0) {
            flash(t('{count} tickets moved to archive.', ['count' => $archived_count]), 'success');
        } else {
            flash(t('No tickets selected.'), 'error');
        }
        redirect('tickets');
    }

    if (isset($_POST['bulk_update']) && !$is_archive) {
        $organization_raw = (string) ($_POST['bulk_organization_id'] ?? '__keep__');
        $status_raw = (string) ($_POST['bulk_status_id'] ?? '');
        $priority_raw = (string) ($_POST['bulk_priority_id'] ?? '');
        $tags_mode = (string) ($_POST['bulk_tags_mode'] ?? 'keep');
        $tags_input = trim((string) ($_POST['bulk_tags'] ?? ''));

        $base_update_data = [];
        $has_update = false;

        if ($organization_raw !== '__keep__') {
            if ($organization_raw === '__none__') {
                $base_update_data['organization_id'] = null;
                $has_update = true;
            } else {
                $organization_id_candidate = (int) $organization_raw;
                $organization_exists = $organization_id_candidate > 0 && get_organization($organization_id_candidate);
                if (!$organization_exists) {
                    flash(t('Selected organization is not available.'), 'error');
                    redirect('tickets');
                }
                $base_update_data['organization_id'] = $organization_id_candidate;
                $has_update = true;
            }
        }

        if ($status_raw !== '') {
            $status_id_candidate = (int) $status_raw;
            if ($status_id_candidate > 0 && get_status($status_id_candidate)) {
                $base_update_data['status_id'] = $status_id_candidate;
                $has_update = true;
            }
        }

        if ($priority_raw !== '') {
            $priority_id_candidate = (int) $priority_raw;
            if ($priority_id_candidate > 0 && get_priority($priority_id_candidate)) {
                $base_update_data['priority_id'] = $priority_id_candidate;
                $has_update = true;
            }
        }

        if (!in_array($tags_mode, ['keep', 'replace', 'append', 'clear'], true)) {
            $tags_mode = 'keep';
        }
        $tags_supported = function_exists('ticket_tags_column_exists') && ticket_tags_column_exists();
        if (!$tags_supported) {
            $tags_mode = 'keep';
        }
        if ($tags_mode !== 'keep') {
            $has_update = true;
        }

        if (!$has_update) {
            flash(t('Select at least one field to update.'), 'error');
            redirect('tickets');
        }

        $updated_count = 0;
        foreach ($editable_tickets as $ticket_id => $ticket_item) {
            $update_data = $base_update_data;
            if ($tags_mode === 'replace') {
                $normalized = normalize_ticket_tags($tags_input);
                $update_data['tags'] = $normalized !== '' ? $normalized : null;
            } elseif ($tags_mode === 'append') {
                if ($tags_input !== '') {
                    $normalized = normalize_ticket_tags(($ticket_item['tags'] ?? '') . ', ' . $tags_input);
                    $update_data['tags'] = $normalized !== '' ? $normalized : null;
                }
            } elseif ($tags_mode === 'clear') {
                $update_data['tags'] = null;
            }

            if (!empty($update_data) && update_ticket_with_history($ticket_id, $update_data, $user['id'])) {
                log_activity($ticket_id, $user['id'], 'ticket_edited', 'Ticket updated via bulk action');
                $updated_count++;
            }
        }

        if ($updated_count > 0) {
            flash(t('{count} tickets updated.', ['count' => $updated_count]), 'success');
        } else {
            flash(t('No tickets selected.'), 'error');
        }
        redirect('tickets');
    }
}

// Get filters
$filters = [];
$status_id = isset($_GET['status']) ? (int) $_GET['status'] : null;
$organization_id = isset($_GET['organization']) ? (int) $_GET['organization'] : null;
$priority_id = isset($_GET['priority']) ? (int) $_GET['priority'] : null;
$search_query = trim($_GET['search'] ?? '');
$user_search = trim($_GET['user'] ?? '');
$created_date_input = trim($_GET['created_date'] ?? '');
$created_date_value = '';
$due_date_filter = trim($_GET['due_date'] ?? '');
$tags_supported = function_exists('ticket_tags_column_exists') && ticket_tags_column_exists();
$normalize_tag_filters = static function ($value) {
    $raw_tags = [];
    if (is_array($value)) {
        $raw_tags = $value;
    } else {
        $value = trim((string) $value);
        if ($value !== '') {
            $raw_tags = preg_split('/\s*,\s*/', $value);
        }
    }

    $tags = [];
    $seen = [];
    foreach ((array) $raw_tags as $raw_tag) {
        $tag = trim((string) $raw_tag);
        $tag = ltrim($tag, '#');
        $tag = preg_replace('/\s+/', ' ', $tag);
        if ($tag === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $tags[] = $tag;
    }
    return $tags;
};
$tag_filters = $normalize_tag_filters($_GET['tags'] ?? '');
if (empty($tag_filters)) {
    $tag_filters = $normalize_tag_filters($_GET['tag'] ?? '');
}
$tag_filter_csv = implode(', ', $tag_filters);
$assigned_to = isset($_GET['assigned_to']) ? (int) $_GET['assigned_to'] : null;
$sort = trim((string) ($_GET['sort'] ?? 'newest'));
$allowed_sorts = ['newest', 'oldest', 'priority', 'status', 'due_date'];
if ($tags_supported) {
    $allowed_sorts[] = 'tags';
}
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'newest';
}

if (!empty($assigned_to)) {
    $filters['assigned_to'] = $assigned_to;
}
if (!empty($status_id)) {
    $filters['status_id'] = $status_id;
}
if (!empty($organization_id)) {
    $filters['organization_id'] = $organization_id;
}
if (!empty($priority_id)) {
    $filters['priority_id'] = $priority_id;
}
if ($search_query !== '') {
    $filters['search'] = $search_query;
}
if ($user_search !== '') {
    $filters['user_search'] = $user_search;
}
if ($tags_supported && !empty($tag_filters)) {
    $filters['tags'] = $tag_filters;
}
if ($created_date_input !== '') {
    $created_dt = DateTime::createFromFormat('Y-m-d', $created_date_input);
    if ($created_dt) {
        $created_date_value = $created_dt->format('Y-m-d');
        $filters['created_from'] = $created_dt->format('Y-m-d');
        $created_dt->modify('+1 day');
        $filters['created_to'] = $created_dt->format('Y-m-d');
    }
}
if ($due_date_filter !== '') {
    if ($due_date_filter === 'overdue') {
        $filters['due_date_overdue'] = true;
    } elseif ($due_date_filter === 'today') {
        $filters['due_date_today'] = true;
    } elseif ($due_date_filter === 'week') {
        $filters['due_date_week'] = true;
    } else {
        // Specific date
        $due_dt = DateTime::createFromFormat('Y-m-d', $due_date_filter);
        if ($due_dt) {
            $filters['due_date_from'] = $due_dt->format('Y-m-d');
            $due_dt->modify('+1 day');
            $filters['due_date_to'] = $due_dt->format('Y-m-d');
        }
    }
}
if (!empty($sort) && $sort !== 'newest') {
    $filters['sort'] = $sort;
}
// Only set is_archived filter if we're specifically viewing archive or regular list
// This prevents errors if the column doesn't exist yet
try {
    $check = db_fetch_one("SHOW COLUMNS FROM tickets LIKE 'is_archived'");
    if ($check) {
        $filters['is_archived'] = $is_archive ? 1 : 0;
    }
} catch (Exception $e) {
    // Column check failed, don't filter by archive
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

$per_page = 20;
$page_num = max(1, (int) ($_GET['p'] ?? 1));
$filters['limit'] = $per_page;
$filters['offset'] = ($page_num - 1) * $per_page;

$count_filters = $filters;
unset($count_filters['limit'], $count_filters['offset']);
$total_tickets = get_tickets_count($count_filters);
$total_pages = max(1, (int) ceil($total_tickets / $per_page));
if ($page_num > $total_pages) {
    $page_num = $total_pages;
    $filters['offset'] = ($page_num - 1) * $per_page;
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

    $rows = db_fetch_all(
        "SELECT ticket_id,
                SUM(CASE WHEN ended_at IS NULL THEN TIMESTAMPDIFF(MINUTE, started_at, NOW()) ELSE duration_minutes END) as total_minutes
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
                TIMESTAMPDIFF(MINUTE, tte.started_at, NOW()) as elapsed_minutes
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
$priorities = get_priorities();
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
        $filter_notes[] = $status['name'];
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
$page_header_subtitle = t('{count} tickets', ['count' => $total_tickets]) . (!empty($filter_notes) ? ' | ' . implode(' | ', $filter_notes) : '');

$page_header_breadcrumbs = [
    ['label' => t('All tickets'), 'url' => url('tickets', $is_archive ? ['archived' => '1'] : [])]
];
if ($is_archive) {
    $page_header_breadcrumbs[] = ['label' => t('Archive')];
}
if (!empty($status_id) && !empty($status['name'])) {
    $page_header_breadcrumbs[] = ['label' => $status['name']];
}
if (!empty($organization_id) && !empty($org['name'])) {
    $page_header_breadcrumbs[] = ['label' => $org['name']];
}

$bulk_actions_enabled = is_agent() && !empty($tickets);
$bulk_archive_mode = $bulk_actions_enabled && !$is_archive;
$bulk_delete_mode = $bulk_actions_enabled && $is_archive;

$page_header_actions = '';
// User View Mode Toggle
if (!is_admin() && !is_agent() && isset($scope) && $scope === 'organization' && !empty($user['organization_id'])) {
    $current_view = $_GET['view_mode'] ?? 'company';
    // Remove p=page to reset pagination when switching
    $params_mine = $_GET; unset($params_mine['p']); $params_mine['view_mode'] = 'mine';
    $params_comp = $_GET; unset($params_comp['p']); $params_comp['view_mode'] = 'company';
    
    $mine_url = url('tickets', $params_mine);
    $company_url = url('tickets', $params_comp);

    $page_header_actions .= '<div class="inline-flex rounded-md shadow-sm mr-4" role="group">
        <a href="'.$mine_url.'" class="px-4 py-2 text-sm font-medium border rounded-l-lg '.($current_view === 'mine' ? 'bg-blue-600 text-white border-blue-600' : '').' " style="'.($current_view !== 'mine' ? 'background: var(--bg-primary); color: var(--text-secondary); border-color: var(--border-light);' : '').'">
            '.t('My Tickets').'
        </a>
        <a href="'.$company_url.'" class="px-4 py-2 text-sm font-medium border rounded-r-lg '.($current_view === 'company' ? 'bg-blue-600 text-white border-blue-600' : '').' " style="'.($current_view !== 'company' ? 'background: var(--bg-primary); color: var(--text-secondary); border-color: var(--border-light);' : '').'">
            '.t('Company Tickets').'
        </a>
    </div>';
}

// Sort dropdown in page header (syncs with hidden input in filter form via JS)
$sort_options = [
    'newest'   => t('Newest'),
    'oldest'   => t('Oldest'),
    'priority' => t('Priority'),
    'status'   => t('Status'),
    'due_date' => t('Due date'),
];
if ($tags_supported) {
    $sort_options['tags'] = t('Tags');
}
$sort_select = '<div class="inline-flex items-center btn btn-ghost gap-1.5">'
    . get_icon('arrow-up-down', 'w-3.5 h-3.5 opacity-50 flex-shrink-0')
    . '<select id="header-sort" class="appearance-none bg-transparent cursor-pointer text-sm font-semibold pr-5" onchange="applyHeaderSort(this.value)">';
foreach ($sort_options as $val => $label) {
    $sel = ($sort === $val) ? ' selected' : '';
    $sort_select .= '<option value="' . e($val) . '"' . $sel . '>' . e($label) . '</option>';
}
$sort_select .= '</select></div>';
$page_header_actions .= $sort_select;

if ($bulk_actions_enabled) {
    $page_header_actions .= '<button type="button" onclick="toggleBulkMode()" class="btn btn-ghost" id="bulk-toggle" aria-pressed="false">' . get_icon('check-square', 'mr-1') . e(t('Bulk select')) . '</button>';
}
if (!$is_archive) {
    $page_header_actions .= '<a href="' . url('new-ticket') . '" class="btn btn-primary">' . get_icon('plus', 'mr-1') . e(t('New ticket')) . '</a>';
}

$pagination_params = [];
if ($is_archive) {
    $pagination_params['archived'] = '1';
}
if (!empty($status_id)) {
    $pagination_params['status'] = $status_id;
}
if (!empty($priority_id)) {
    $pagination_params['priority'] = $priority_id;
}
if (!empty($organization_id) && is_admin()) {
    $pagination_params['organization'] = $organization_id;
}
if ($search_query !== '') {
    $pagination_params['search'] = $search_query;
}
if ($tags_supported && !empty($tag_filters)) {
    $pagination_params['tags'] = implode(',', $tag_filters);
}
if ($user_search !== '') {
    $pagination_params['user'] = $user_search;
}
if ($created_date_value !== '') {
    $pagination_params['created_date'] = $created_date_value;
}
if ($due_date_filter !== '') {
    $pagination_params['due_date'] = $due_date_filter;
}
if (!empty($sort) && $sort !== 'newest') {
    $pagination_params['sort'] = $sort;
}
if ($staff_scope) {
    $pagination_params['scope'] = 'staff';
}
if (!empty($assigned_to)) {
    $pagination_params['assigned_to'] = $assigned_to;
}

$build_tag_filter_url = function ($tag_value) use ($is_archive, $normalize_tag_filters, $tag_filters) {
    $params = $_GET;
    unset($params['page'], $params['p']);
    if ($is_archive) {
        $params['archived'] = '1';
    } else {
        unset($params['archived']);
    }
    $next_tags = $normalize_tag_filters(array_merge($tag_filters, [$tag_value]));
    if (!empty($next_tags)) {
        $params['tags'] = implode(',', $next_tags);
    } else {
        unset($params['tags']);
    }
    unset($params['tag']);
    return url('tickets', $params);
};

$build_remove_tag_filter_url = function ($tag_value) use ($is_archive, $normalize_tag_filters, $tag_filters) {
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
    $remaining = $normalize_tag_filters($remaining);
    if (!empty($remaining)) {
        $params['tags'] = implode(',', $remaining);
    } else {
        unset($params['tags']);
    }
    unset($params['tag']);

    return url('tickets', $params);
};

include BASE_PATH . '/includes/components/page-header.php';
?>

<style>
/* Ticket ID — plain text, no pill */
.ticket-code {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-decoration: none;
    white-space: nowrap;
    transition: color 0.15s ease;
}
.ticket-code:hover {
    color: var(--primary);
}
/* Subject link — plain text, underline on hover */
.ticket-subject-link {
    color: var(--text-primary);
    font-weight: 400;
    text-decoration: none;
    cursor: pointer;
    transition: color 0.15s ease;
}
.ticket-subject-link:hover {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}
/* Whole row clickable — pointer cursor */
.ticket-row {
    cursor: pointer;
}
.ticket-row:hover .ticket-subject-link {
    color: var(--primary);
}
/* Filter selects in header — clean minimal appearance */
.tickets-table .filter-select {
    appearance: none;
    -webkit-appearance: none;
    background: transparent;
    border: 1px solid var(--border-light);
    border-radius: var(--radius-sm);
    padding: 0.35rem 1.4rem 0.35rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2.5'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.35rem center;
    white-space: nowrap;
    width: 100%;
}
.tickets-table .filter-select:hover {
    border-color: var(--border-light);
    background-color: var(--surface-secondary);
}
.tickets-table .filter-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--primary-soft);
}
/* Filter text input — same subtle style as selects */
.tickets-table .filter-input {
    background: transparent;
    border: 1px solid var(--border-light);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem 0.35rem 0.5rem;
    font-size: 0.75rem;
    color: var(--text-secondary);
    transition: all 0.15s ease;
    width: 100%;
}
.tickets-table .filter-input::placeholder {
    color: var(--text-muted);
    font-weight: 400;
}
.tickets-table .filter-input:hover {
    border-color: var(--border-light);
    background-color: var(--surface-secondary);
}
.tickets-table .filter-input:focus {
    outline: none;
    border-color: var(--primary);
    background-color: var(--surface-primary);
    box-shadow: 0 0 0 2px var(--primary-soft);
}
/* Search input in subject header */
.ticket-search-wrap {
    position: relative;
    flex-shrink: 1;
    min-width: 0;
    overflow: visible;
}
/* Autosuggest dropdown */
.ticket-search-suggestions {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    width: 320px;
    max-height: 280px;
    overflow-y: auto;
    background: var(--surface-primary);
    border: 1px solid var(--border-light);
    border-radius: var(--radius-md, 8px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    z-index: 100;
    padding: 4px;
}
[data-theme="dark"] .ticket-search-suggestions {
    background: var(--corp-slate-800);
    border-color: var(--corp-slate-600);
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
}
.ticket-search-suggestions.active { display: block; }
.ticket-suggest-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-radius: var(--radius-sm, 6px);
    cursor: pointer;
    text-decoration: none;
    color: var(--text-primary);
    font-size: 0.8125rem;
    transition: background 0.1s;
}
.ticket-suggest-item:hover,
.ticket-suggest-item.active {
    background: var(--surface-secondary);
}
.ticket-suggest-item .suggest-code {
    font-size: 0.6875rem;
    color: var(--text-muted);
    white-space: nowrap;
    flex-shrink: 0;
}
.ticket-suggest-item .suggest-title {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ticket-suggest-item .suggest-status {
    font-size: 0.625rem;
    padding: 1px 6px;
    border-radius: 9999px;
    white-space: nowrap;
    flex-shrink: 0;
}
.ticket-suggest-hint {
    padding: 6px 10px;
    font-size: 0.6875rem;
    color: var(--text-muted);
    text-align: center;
}
.ticket-search-wrap .search-icon {
    position: absolute;
    left: 0.5rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    pointer-events: none;
    transition: color 0.15s;
}
.ticket-search-input {
    background: transparent;
    border: 1px solid var(--border-light);
    border-radius: var(--radius-sm);
    padding: 0.35rem 0.5rem 0.35rem 1.6rem;
    font-size: 0.75rem;
    color: var(--text-secondary);
    width: 2rem;
    min-width: 0;
    max-width: 100%;
    cursor: pointer;
    transition: all 0.2s ease;
}
.ticket-search-input::placeholder {
    color: transparent;
    font-weight: 400;
    font-size: 0.6875rem;
}
.ticket-search-input:hover {
    border-color: var(--border-light);
    background-color: var(--surface-secondary);
}
.ticket-search-input:focus {
    outline: none;
    width: 12rem;
    cursor: text;
    border-color: var(--primary);
    background-color: var(--surface-primary);
    box-shadow: 0 0 0 2px var(--primary-soft);
}
.ticket-search-input:focus::placeholder {
    color: var(--text-muted);
}
@media (min-width: 1280px) {
    .ticket-search-input { width: 6rem; }
    .ticket-search-input::placeholder { color: var(--text-muted); }
    .ticket-search-input:focus { width: 14rem; }
}
.ticket-search-input:focus + .search-icon,
.ticket-search-wrap:focus-within .search-icon {
    color: var(--primary);
}
/* Header sort select — icon is in wrapper div */
#header-sort {
    outline: none;
    border: none;
    color: inherit;
}
/* Dark mode overrides for ticket filters */
[data-theme="dark"] .tickets-table .filter-select {
    color: var(--corp-slate-200);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2.5'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
}
[data-theme="dark"] .tickets-table .filter-select:hover,
[data-theme="dark"] .tickets-table .filter-input:hover {
    border-color: var(--corp-slate-600);
    background-color: var(--corp-slate-700);
}
[data-theme="dark"] .tickets-table .filter-input,
[data-theme="dark"] .ticket-search-input {
    color: var(--corp-slate-200);
}
[data-theme="dark"] .tickets-table .filter-select:focus,
[data-theme="dark"] .tickets-table .filter-input:focus,
[data-theme="dark"] .ticket-search-input:focus {
    border-color: var(--corp-blue-500);
    background-color: var(--corp-slate-700);
}
[data-theme="dark"] .ticket-search-input:hover {
    border-color: var(--corp-slate-600);
    background-color: var(--corp-slate-700);
}
</style>

<!-- Tickets Table/List with Inline Filters -->
<div class="card overflow-hidden">
    <?php if (empty($tickets)): ?>
        <?php
        // Check if filters are active to show "Show all" button
        $empty_has_filters = !empty($search_query) || !empty($status_id) || !empty($priority_id) ||
                       !empty($organization_id) || !empty($due_date_filter) || !empty($created_date_value) ||
                       !empty($user_search) || !empty($assigned_to) || ($tags_supported && !empty($tag_filters));
        $empty_title = $is_archive ? t('Archive is empty') : t('No tickets found');
        $empty_message = $is_archive ? t('There are no archived tickets yet.') : t('Try adjusting filters or create a new ticket.');
        $empty_icon = $is_archive ? 'archive' : 'inbox';
        $empty_action_label = $is_archive ? null : t('Create ticket');
        $empty_action_url = $is_archive ? null : url('new-ticket');
        include BASE_PATH . '/includes/components/empty-state.php';
        ?>
        <?php if ($empty_has_filters): ?>
            <div class="text-center mt-4">
                <a href="<?php echo url('tickets', $is_archive ? ['archived' => '1'] : []); ?>"
                   class="btn btn-outline btn-sm inline-flex items-center gap-1.5">
                    <?php echo get_icon('list', 'w-4 h-4'); ?>
                    <?php echo e(t('Show all tickets')); ?>
                </a>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php
        // Check if any filters are active
        $has_filters = !empty($search_query) || !empty($status_id) || !empty($priority_id) ||
                       !empty($organization_id) || !empty($due_date_filter) || !empty($created_date_value) ||
                       !empty($user_search) || !empty($assigned_to) || $staff_scope || ($tags_supported && !empty($tag_filters)) || $sort !== 'newest';
        ?>

        <!-- Mobile Filter Bar -->
        <div class="block lg:hidden border-b px-4 py-3 glass" style="border-color: var(--border-light);">
            <form method="get" action="index.php" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="page" value="tickets">
                <?php if ($is_archive): ?>
                    <input type="hidden" name="archived" value="1">
                <?php endif; ?>
                <input type="text" name="search" value="<?php echo e($search_query); ?>"
                    placeholder="<?php echo e(t('Search...')); ?>"
                    class="form-input form-input-sm flex-1 min-w-[120px] text-xs">
                <?php if ($tags_supported): ?>
                    <input type="text" name="tags" value="<?php echo e($tag_filter_csv); ?>"
                        placeholder="#<?php echo e(t('Tags')); ?>"
                        class="form-input form-input-sm w-[140px] text-xs">
                <?php endif; ?>
                <select name="status" class="form-select form-select-sm text-xs" onchange="this.form.submit()">
                    <option value=""><?php echo e(t('Status')); ?></option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo $status['id']; ?>" <?php echo $status_id == $status['id'] ? 'selected' : ''; ?>>
                            <?php echo e($status['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="priority" class="form-select form-select-sm text-xs" onchange="this.form.submit()">
                    <option value=""><?php echo e(t('Priority')); ?></option>
                    <?php foreach ($priorities as $priority): ?>
                        <option value="<?php echo $priority['id']; ?>" <?php echo $priority_id == $priority['id'] ? 'selected' : ''; ?>>
                            <?php echo e($priority['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="sort" class="form-select form-select-sm text-xs" onchange="this.form.submit()">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>><?php echo e(t('Newest')); ?></option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>><?php echo e(t('Oldest')); ?></option>
                    <option value="priority" <?php echo $sort === 'priority' ? 'selected' : ''; ?>><?php echo e(t('Priority')); ?></option>
                    <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>><?php echo e(t('Status')); ?></option>
                    <option value="due_date" <?php echo $sort === 'due_date' ? 'selected' : ''; ?>><?php echo e(t('Due date')); ?></option>
                    <?php if ($tags_supported): ?>
                        <option value="tags" <?php echo $sort === 'tags' ? 'selected' : ''; ?>><?php echo e(t('Tags')); ?></option>
                    <?php endif; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-xs"><?php echo get_icon('search', 'w-3 h-3'); ?></button>
                <?php if ($has_filters): ?>
                <a href="<?php echo url('tickets', $is_archive ? ['archived' => '1'] : []); ?>" class="btn btn-secondary btn-xs">
                    <?php echo get_icon('x', 'w-3 h-3'); ?>
                </a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($tags_supported && !empty($tag_filters)): ?>
            <?php
            $clear_tags_params = $_GET;
            unset($clear_tags_params['page'], $clear_tags_params['p'], $clear_tags_params['tag'], $clear_tags_params['tags']);
            if ($is_archive) {
                $clear_tags_params['archived'] = '1';
            } else {
                unset($clear_tags_params['archived']);
            }
            $clear_tags_url = url('tickets', $clear_tags_params);
            ?>
            <div class="border-b px-4 py-2.5 flex flex-wrap items-center gap-2" style="border-color: var(--border-light); background: var(--surface-secondary);">
                <span class="text-xs font-medium" style="color: var(--text-secondary);"><?php echo e(t('Tags')); ?>:</span>
                <?php foreach ($tag_filters as $active_tag): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs" style="background: var(--primary-soft); color: var(--primary);">
                        #<?php echo e($active_tag); ?>
                        <a href="<?php echo e($build_remove_tag_filter_url($active_tag)); ?>" class="opacity-80 hover:opacity-100" aria-label="<?php echo e(t('Remove')); ?>">
                            &times;
                        </a>
                    </span>
                <?php endforeach; ?>
                <a href="<?php echo e($clear_tags_url); ?>" class="text-xs underline" style="color: var(--text-secondary);">
                    <?php echo e(t('Clear all tags')); ?>
                </a>
            </div>
        <?php endif; ?>

        <!-- Mobile List View -->
        <?php
        $active_tickets = [];
        $closed_tickets = [];
        $statuses_by_id = [];
        $is_closed_filter_active = false;
        foreach ($statuses as $s) {
            $statuses_by_id[$s['id']] = $s;
            if ($status_id == $s['id'] && !empty($s['is_closed'])) {
                $is_closed_filter_active = true;
            }
        }

        foreach ($tickets as $t) {
            if (!$is_closed_filter_active && !empty($statuses_by_id[$t['status_id']]['is_closed'])) {
                $closed_tickets[] = $t;
            } else {
                $active_tickets[] = $t;
            }
        }
        
        $ticket_groups = [
            ['name' => 'active', 'label' => '', 'tickets' => $active_tickets, 'hidden' => false],
        ];
        if (!empty($closed_tickets)) {
            $ticket_groups[] = ['name' => 'closed', 'label' => t('Closed') . ' (' . count($closed_tickets) . ')', 'tickets' => $closed_tickets, 'hidden' => true];
        }
        ?>
        <div class="block lg:hidden">
            <?php foreach ($ticket_groups as $group): ?>
                <?php if ($group['name'] === 'closed'): ?>
                    <div class="p-3 text-center border-t cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-700" style="background: var(--surface-secondary);" onclick="document.getElementById('closed-tickets-mobile').classList.toggle('hidden')">
                        <?php echo e($group['label']); ?>
                    </div>
                    <div id="closed-tickets-mobile" class="hidden divide-y">
                <?php else: ?>
                    <div class="divide-y">
                <?php endif; ?>
                <?php foreach ($group['tickets'] as $ticket):
                $priority_name = $ticket['priority_name'] ?? get_priority_label($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                $priority_color = $ticket['priority_color'] ?? get_priority_color($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                $is_overdue_mobile = !empty($ticket['due_date']) && empty($ticket['is_closed']) && strtotime($ticket['due_date']) < time();
                ?>
                <div class="p-4 ticket-list-item<?php echo $is_overdue_mobile ? ' ticket-overdue' : ''; ?>" style="border-left: 5px solid <?php echo e($ticket['status_color']); ?>;">
                    <div class="flex items-start gap-3">
                        <?php if ($bulk_actions_enabled): ?>
                            <input type="checkbox" name="ticket_ids[]" value="<?php echo $ticket['id']; ?>"
                                class="bulk-checkbox hidden mt-1 rounded" form="bulk-actions-form" onclick="event.stopPropagation()">
                        <?php endif; ?>
                            <a href="<?php echo ticket_url($ticket); ?>" class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 text-xs mb-1" style="color: var(--text-muted);">
                                    <span class="w-2 h-2 rounded-full"
                                        style="background-color: <?php echo e($ticket['status_color']); ?>"></span>
                                    <span><?php echo e($ticket['status_name']); ?></span>
                                    <span class="ticket-code-pill" title="<?php echo e('#' . (int) $ticket['id']); ?>">
                                        <?php echo e(get_ticket_code($ticket['id'])); ?>
                                    </span>
                                </div>
                                <div class="font-medium truncate" style="color: var(--text-primary);"><?php echo e($ticket['title']); ?></div>
                                <div class="text-sm mt-1 flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                                    <span><?php echo format_date($ticket['created_at'], 'd.m.Y'); ?></span>
                                    <?php if (!empty($ticket['due_date'])): ?>
                                        <?php
                                        $due_ts = strtotime($ticket['due_date']);
                                        $is_overdue = $due_ts < time() && empty($ticket['is_closed']);
                                        ?>
                                        <span
                                            class="<?php echo $is_overdue ? 'text-red-600 font-medium' : ''; ?> text-xs"
                                            <?php if (!$is_overdue): ?>style="color: var(--text-muted);"<?php endif; ?>
                                            title="<?php echo e(t('Due date')); ?>">
                                            <?php echo get_icon('calendar-alt', 'ml-1 mr-0.5 w-3 h-3 inline'); ?>
                                            <?php echo date('d.m.', $due_ts); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="badge"
                                        style="background-color: <?php echo e($priority_color); ?>20; color: <?php echo e($priority_color); ?>">
                                        <?php echo e($priority_name); ?>
                                    </span>
                                    <?php if (is_admin() && !empty($ticket['organization_name'])): ?>
                                        <span class="text-xs" style="color: var(--text-muted);"><?php echo e($ticket['organization_name']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($tags_supported && !empty($ticket['tags'])): ?>
                                        <?php foreach (array_slice(get_ticket_tags_array($ticket['tags']), 0, 3) as $tag): ?>
                                            <a href="<?php echo e($build_tag_filter_url($tag)); ?>"
                                               class="ticket-tag-pill"
                                               title="<?php echo e(t('Filter by this tag')); ?>">
                                                #<?php echo e($tag); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <?php if ($show_time): ?>
                                        <?php
                                        $ticket_total = $ticket_time_totals[$ticket['id']] ?? 0;
                                        $running_entries = $ticket_running_entries[$ticket['id']] ?? [];
                                        $running_label = '';
                                        $running_elapsed = 0;
                                        if (!empty($running_entries)) {
                                            $first = $running_entries[0];
                                            $name = trim(($first['first_name'] ?? '') . ' ' . ($first['last_name'] ?? ''));
                                            $name = $name !== '' ? $name : t('Unknown user');
                                            $extra = count($running_entries) - 1;
                                            $running_label = $name . ($extra > 0 ? ' +' . $extra : '');
                                            $running_elapsed = (int) ($first['elapsed_minutes'] ?? 0);
                                        }
                                        ?>
                                        <span class="text-xs" style="color: var(--text-muted);">
                                            <?php echo get_icon('clock', 'mr-1 w-3 h-3 inline'); ?><?php echo $ticket_total > 0 ? format_duration_minutes($ticket_total) : '-'; ?>
                                        </span>
                                        <?php if (!empty($running_label)): ?>
                                            <span class="text-xs text-green-600">
                                                <?php echo e(t('Running')); ?>: <?php echo e($running_label); ?> -
                                                <?php echo e(format_duration_minutes($running_elapsed)); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </a>
                        </div>
                    </div>
            <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Desktop Table View with Inline Filters -->
        <form method="get" action="index.php" id="filter-form">
                <input type="hidden" name="page" value="tickets">
                <?php if ($is_archive): ?>
                    <input type="hidden" name="archived" value="1">
                <?php endif; ?>
            <table class="w-full hidden lg:table tickets-table text-xs" style="table-layout: fixed;">
                <thead>
                    <tr class="border-b" style="border-color: var(--border-light);">
                        <th class="px-3 py-2.5 text-left" style="width: 80px;">
                            <div class="flex items-center gap-1">
                                <?php if ($bulk_actions_enabled): ?>
                                    <input type="checkbox" id="select-all" class="rounded hidden" onchange="toggleAll(this)">
                                <?php endif; ?>
                                <span class="text-[10px] font-medium uppercase tracking-wider" style="color: var(--text-muted);"><?php echo e(t('Date')); ?></span>
                            </div>
                        </th>
                        <th class="px-3 py-2.5 text-left" style="overflow:visible">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[10px] font-medium uppercase tracking-wider" style="color: var(--text-muted);"><?php echo e(t('Subject')); ?></span>
                                <div class="flex items-center gap-1.5">
                                    <div class="ticket-search-wrap">
                                        <input type="text" name="search" value="<?php echo e($search_query); ?>"
                                            placeholder="<?php echo e(t('Search...')); ?>"
                                            class="ticket-search-input"
                                            id="ticket-search-input"
                                            autocomplete="off">
                                        <span class="search-icon"><?php echo get_icon('search', 'w-3 h-3'); ?></span>
                                        <div class="ticket-search-suggestions" id="ticket-search-suggestions"></div>
                                    </div>
                                    <?php if ($has_filters): ?>
                                    <a href="<?php echo url('tickets', $is_archive ? ['archived' => '1'] : []); ?>"
                                       class="inline-flex items-center justify-center w-6 h-6 rounded hover:text-red-500 hover:bg-red-50 transition-colors" style="color: var(--text-muted);" title="<?php echo e(t('Clear')); ?>">
                                        <?php echo get_icon('x', 'w-3.5 h-3.5'); ?>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </th>
                        <th class="px-2 py-2.5" style="width: 140px;">
                            <select name="status" class="filter-select" onchange="this.form.submit()">
                                <option value=""><?php echo e(t('Status')); ?></option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo $status['id']; ?>" <?php echo $status_id == $status['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($status['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th class="px-2 py-2.5" style="width: 110px;">
                            <select name="priority" class="filter-select" onchange="this.form.submit()">
                                <option value=""><?php echo e(t('Priority')); ?></option>
                                <?php foreach ($priorities as $priority): ?>
                                    <option value="<?php echo $priority['id']; ?>" <?php echo $priority_id == $priority['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($priority['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th class="px-2 py-2.5" style="width: 90px;">
                            <select name="due_date" class="filter-select" onchange="this.form.submit()">
                                <option value=""><?php echo e(t('Due')); ?></option>
                                <option value="overdue" <?php echo $due_date_filter === 'overdue' ? 'selected' : ''; ?>>!</option>
                                <option value="today" <?php echo $due_date_filter === 'today' ? 'selected' : ''; ?>><?php echo e(t('Today')); ?></option>
                                <option value="week" <?php echo $due_date_filter === 'week' ? 'selected' : ''; ?>><?php echo e(t('Week')); ?></option>
                            </select>
                        </th>
                        <?php if (is_admin()): ?>
                            <th class="px-2 py-2.5" style="width: 120px;">
                                <?php if (!empty($organizations)): ?>
                                <select name="organization" class="filter-select" onchange="this.form.submit()">
                                    <option value=""><?php echo e(t('Company')); ?></option>
                                    <?php foreach ($organizations as $org): ?>
                                        <option value="<?php echo $org['id']; ?>" <?php echo $organization_id == $org['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($org['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </th>
                        <?php endif; ?>
                        <?php if (is_admin()): ?>
                            <th class="px-2 py-2.5" style="width: 110px;">
                                <input type="text" name="user" value="<?php echo e($user_search); ?>"
                                    placeholder="<?php echo e(t('User...')); ?>"
                                    class="filter-input">
                            </th>
                            <th class="px-3 py-2.5 text-left" style="width: 75px;">
                                <span class="text-[10px] font-medium uppercase tracking-wider" style="color: var(--text-muted);"><?php echo e(t('Time')); ?></span>
                            </th>
                        <?php elseif (is_agent()): ?>
                            <th class="px-2 py-2.5" style="width: 110px;">
                                <input type="text" name="user" value="<?php echo e($user_search); ?>"
                                    placeholder="<?php echo e(t('User...')); ?>"
                                    class="filter-input">
                            </th>
                        <?php endif; ?>
                        <input type="hidden" name="created_date" value="<?php echo e($created_date_value); ?>">
                        <input type="hidden" name="sort" value="<?php echo e($sort); ?>">
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ticket_groups as $group): ?>
                    <?php if ($group['name'] === 'closed'): ?>
                        </tbody>
                        <tbody class="border-t-2" style="border-top-color: var(--border-light)">
                            <tr class="cursor-pointer" style="background: var(--surface-secondary);" onclick="document.getElementById('closed-tickets-desktop').classList.toggle('hidden')">
                                <?php $colspan = is_admin() ? 8 : (is_agent() ? 6 : 5); ?>
                                <td colspan="<?php echo $colspan; ?>" class="px-3 py-2 font-medium text-xs text-center text-gray-500 hover:text-gray-700">
                                   <?php echo e($group['label']); ?>
                                </td>
                            </tr>
                        </tbody>
                        <tbody id="closed-tickets-desktop" class="hidden">
                    <?php endif; ?>
                    <?php foreach ($group['tickets'] as $ticket):
                        $priority_name = $ticket['priority_name'] ?? get_priority_label($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                        $priority_color = $ticket['priority_color'] ?? get_priority_color($ticket['priority_id'] ?? $ticket['priority'] ?? 'medium');
                        $is_overdue = !empty($ticket['due_date']) && empty($ticket['is_closed']) && strtotime($ticket['due_date']) < time();
                        ?>
                        <tr class="ticket-row<?php echo $is_overdue ? ' ticket-overdue' : ''; ?>" style="border-left: 5px solid <?php echo e($ticket['status_color']); ?>;" data-href="<?php echo e(ticket_url($ticket)); ?>">
                            <td class="px-3 py-2.5 whitespace-nowrap align-top">
                                <div class="flex items-center gap-1.5">
                                    <?php if ($bulk_actions_enabled): ?>
                                        <input type="checkbox" name="ticket_ids[]" value="<?php echo $ticket['id']; ?>"
                                            class="bulk-checkbox hidden rounded flex-shrink-0" form="bulk-actions-form">
                                    <?php endif; ?>
                                    <div>
                                        <a href="<?php echo ticket_url($ticket); ?>" class="font-medium text-xs" style="color: var(--text-primary);" title="<?php echo e(get_ticket_code($ticket['id'])); ?>">
                                            <?php echo date('d.m.', strtotime($ticket['created_at'])); ?>
                                        </a>
                                        <div class="text-[10px]" style="color: var(--text-muted);"><?php echo e(get_ticket_code($ticket['id'])); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 align-top">
                                <div class="flex items-center gap-1.5">
                                    <a href="<?php echo ticket_url($ticket); ?>" class="ticket-subject-link truncate">
                                        <?php echo e($ticket['title']); ?>
                                    </a>
                                    <?php if (!empty($ticket['attachment_count']) && $ticket['attachment_count'] > 0): ?>
                                        <span class="flex-shrink-0" style="color: var(--text-muted);" title="<?php echo e(t('Attachments')); ?>: <?php echo $ticket['attachment_count']; ?>">
                                            <?php echo get_icon('paperclip', 'w-3 h-3'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                                    <?php echo e(get_type_label($ticket['type'])); ?>
                                    <?php if (!is_admin() && !empty($ticket['organization_name'])): ?>
                                        <span class="ml-1"><?php echo e($ticket['organization_name']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($tags_supported && !empty($ticket['tags'])): ?>
                                        <?php foreach (array_slice(get_ticket_tags_array($ticket['tags']), 0, 3) as $tag): ?>
                                            <a href="<?php echo e($build_tag_filter_url($tag)); ?>"
                                               class="ticket-tag-pill ml-1"
                                               title="<?php echo e(t('Filter by this tag')); ?>">
                                                #<?php echo e($tag); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-2 py-2.5 whitespace-nowrap align-top">
                                <?php if (is_agent() || is_admin()): ?>
                                <div class="tl-inline-edit" style="position:relative;">
                                    <span class="badge-inline tl-edit-trigger" data-ticket="<?php echo (int)$ticket['id']; ?>" data-field="status"
                                        style="background-color: <?php echo e($ticket['status_color']); ?>20; color: <?php echo e($ticket['status_color']); ?>; cursor:pointer;"
                                        title="<?php echo e(t('Click to change')); ?>">
                                        <?php echo e($ticket['status_name']); ?>
                                    </span>
                                    <div class="tl-dropdown hidden" data-dropdown="status-<?php echo (int)$ticket['id']; ?>">
                                        <?php foreach ($statuses as $st): ?>
                                        <button type="button" class="tl-dropdown-item" onclick="inlineUpdate(<?php echo (int)$ticket['id']; ?>, 'status', <?php echo (int)$st['id']; ?>, this)"
                                            style="color: <?php echo e($st['color']); ?>;">
                                            <span class="w-2 h-2 rounded-full inline-block mr-1.5" style="background:<?php echo e($st['color']); ?>;"></span>
                                            <?php echo e($st['name']); ?>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="badge-inline"
                                    style="background-color: <?php echo e($ticket['status_color']); ?>20; color: <?php echo e($ticket['status_color']); ?>">
                                    <?php echo e($ticket['status_name']); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-2 py-2.5 whitespace-nowrap align-top">
                                <?php if (is_agent() || is_admin()): ?>
                                <div class="tl-inline-edit" style="position:relative;">
                                    <span class="badge-inline tl-edit-trigger" data-ticket="<?php echo (int)$ticket['id']; ?>" data-field="priority"
                                        style="background-color: <?php echo e($priority_color); ?>20; color: <?php echo e($priority_color); ?>; cursor:pointer;"
                                        title="<?php echo e(t('Click to change')); ?>">
                                        <?php echo e($priority_name); ?>
                                    </span>
                                    <div class="tl-dropdown hidden" data-dropdown="priority-<?php echo (int)$ticket['id']; ?>">
                                        <?php foreach ($priorities as $pr): ?>
                                        <button type="button" class="tl-dropdown-item" onclick="inlineUpdate(<?php echo (int)$ticket['id']; ?>, 'priority', <?php echo (int)$pr['id']; ?>, this)"
                                            style="color: <?php echo e($pr['color']); ?>;">
                                            <span class="w-2 h-2 rounded-full inline-block mr-1.5" style="background:<?php echo e($pr['color']); ?>;"></span>
                                            <?php echo e($pr['name']); ?>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="badge-inline"
                                    style="background-color: <?php echo e($priority_color); ?>20; color: <?php echo e($priority_color); ?>">
                                    <?php echo e($priority_name); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-2 py-2.5 whitespace-nowrap align-top text-xs" style="color: var(--text-muted);">
                                <?php if (!empty($ticket['due_date'])): ?>
                                    <?php
                                    $due_ts = strtotime($ticket['due_date']);
                                    $is_overdue = $due_ts < time() && empty($ticket['is_closed']);
                                    ?>
                                    <span class="<?php echo $is_overdue ? 'text-red-600 font-medium' : ''; ?>">
                                        <?php echo date('d.m', $due_ts); ?>
                                        <?php if ($is_overdue): ?>
                                            <?php echo get_icon('exclamation-circle', 'w-2.5 h-2.5 inline ml-0.5'); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <?php if (is_admin()): ?>
                                <td class="px-2 py-2.5 text-xs truncate align-top" style="color: var(--text-muted);" title="<?php echo e($ticket['organization_name'] ?? ''); ?>">
                                    <?php if (!empty($ticket['organization_name'])): ?>
                                        <?php echo e($ticket['organization_name']); ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <?php if (is_admin()): ?>
                                <td class="px-2 py-2.5 text-xs truncate align-top" style="color: var(--text-muted);" title="<?php echo e($ticket['first_name'] . ' ' . $ticket['last_name']); ?>">
                                    <?php echo e($ticket['first_name'] . ' ' . substr($ticket['last_name'] ?? '', 0, 1) . '.'); ?>
                                </td>
                                <td class="px-3 py-2.5 text-xs whitespace-nowrap align-top" style="color: var(--text-muted);">
                                    <?php
                                    $ticket_total = $ticket_time_totals[$ticket['id']] ?? 0;
                                    $running_entries = $ticket_running_entries[$ticket['id']] ?? [];
                                    ?>
                                    <?php if (!empty($running_entries)): ?>
                                        <span class="text-green-600"><?php echo get_icon('play', 'w-2.5 h-2.5 inline'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($ticket_total > 0): ?>
                                        <?php echo e(format_duration_minutes($ticket_total)); ?>
                                    <?php endif; ?>
                                </td>
                            <?php elseif (is_agent()): ?>
                                <td class="px-2 py-2.5 text-xs truncate align-top" style="color: var(--text-muted);" title="<?php echo e($ticket['first_name'] . ' ' . $ticket['last_name']); ?>">
                                    <?php echo e($ticket['first_name'] . ' ' . substr($ticket['last_name'] ?? '', 0, 1) . '.'); ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php endforeach; ?>
            </table>
        </form>

        <!-- Bulk Actions Bar -->
        <?php if ($bulk_actions_enabled): ?>
            <form method="post" id="bulk-actions-form">
                <?php echo csrf_field(); ?>
                <div id="bulk-actions"
                    class="hidden sticky bottom-0 border-t card-body space-y-3 <?php echo $bulk_delete_mode ? 'bg-red-50 border-red-200' : ''; ?>"
                    style="<?php echo $bulk_delete_mode ? '' : 'border-color: var(--border-light); background: var(--surface-secondary);'; ?>">
                    <div class="flex items-center justify-between">
                        <div class="inline-flex items-center gap-2 text-sm">
                            <span class="inline-flex items-center justify-center min-w-[1.75rem] h-7 px-2 rounded-full font-semibold"
                                style="background: var(--surface-tertiary); color: var(--text-secondary);">
                                <span id="selected-count">0</span>
                            </span>
                            <span style="color: var(--text-secondary);"><?php echo e(t('selected')); ?></span>
                        </div>
                    </div>

                    <?php if ($bulk_archive_mode): ?>
                        <div class="grid grid-cols-1 <?php echo $tags_supported ? 'lg:grid-cols-5' : 'lg:grid-cols-3'; ?> gap-2 lg:gap-3">
                            <select name="bulk_organization_id" class="form-select form-select-sm">
                                <option value="__keep__"><?php echo e(t('Keep company')); ?></option>
                                <option value="__none__"><?php echo e(t('No company')); ?></option>
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?php echo (int) $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="bulk_status_id" class="form-select form-select-sm">
                                <option value=""><?php echo e(t('Keep status')); ?></option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo (int) $status['id']; ?>"><?php echo e($status['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="bulk_priority_id" class="form-select form-select-sm">
                                <option value=""><?php echo e(t('Keep priority')); ?></option>
                                <?php foreach ($priorities as $priority): ?>
                                    <option value="<?php echo (int) $priority['id']; ?>"><?php echo e($priority['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($tags_supported): ?>
                                <input type="text" name="bulk_tags" class="form-input form-input-sm"
                                    placeholder="<?php echo e(t('Tags')); ?>">
                                <select name="bulk_tags_mode" class="form-select form-select-sm">
                                    <option value="keep"><?php echo e(t('Keep tags')); ?></option>
                                    <option value="replace"><?php echo e(t('Replace tags')); ?></option>
                                    <option value="append"><?php echo e(t('Append tags')); ?></option>
                                    <option value="clear"><?php echo e(t('Clear tags')); ?></option>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 justify-end flex-wrap">
                            <button type="submit" name="bulk_update" class="btn btn-primary btn-sm">
                                <?php echo get_icon('edit', 'mr-2'); ?><?php echo e(t('Apply bulk update')); ?>
                            </button>
                            <button type="submit" name="bulk_archive" class="btn btn-secondary btn-sm"
                                onclick="return confirm('<?php echo e(t('Move selected tickets to archive?')); ?>')">
                                <?php echo get_icon('archive', 'mr-2'); ?><?php echo e(t('Archive selected')); ?>
                            </button>
                        </div>
                    <?php elseif ($bulk_delete_mode): ?>
                        <div class="flex items-center justify-end">
                            <button type="submit" name="bulk_delete" class="btn btn-danger btn-sm"
                                onclick="return confirm('<?php echo e(t('Are you sure you want to permanently delete selected tickets? This action cannot be undone.')); ?>')">
                                <?php echo get_icon('trash', 'mr-2'); ?><?php echo e(t('Delete selected')); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($tickets) && $total_pages > 1): ?>
        <div class="border-t px-4 py-3 flex flex-wrap items-center justify-between gap-3 text-sm" style="background: var(--surface-secondary); color: var(--text-secondary); border-color: var(--border-light);">
            <div><?php echo e(t('Page {current} of {total}', ['current' => $page_num, 'total' => $total_pages])); ?></div>
            <div class="flex items-center gap-2">
                <?php if ($page_num > 1): ?>
                    <a href="<?php echo url('tickets', array_merge($pagination_params, ['p' => $page_num - 1])); ?>"
                        class="btn btn-secondary btn-sm"><?php echo e(t('Previous')); ?></a>
                <?php endif; ?>
                <?php if ($page_num < $total_pages): ?>
                    <a href="<?php echo url('tickets', array_merge($pagination_params, ['p' => $page_num + 1])); ?>"
                        class="btn btn-secondary btn-sm"><?php echo e(t('Next')); ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    let bulkMode = false;

    // Sync header sort dropdown → hidden input in filter form, then submit
    function applyHeaderSort(value) {
        const form = document.getElementById('filter-form');
        if (!form) return;
        let hidden = form.querySelector('input[name="sort"]');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'sort';
            form.appendChild(hidden);
        }
        hidden.value = value;
        form.submit();
    }

    function syncBulkHighlights() {
        document.querySelectorAll('.bulk-checkbox').forEach(cb => {
            const tableRow = cb.closest('tr');
            const mobileCard = cb.closest('.ticket-list-item');
            const isSelected = bulkMode && cb.checked;

            if (tableRow) {
                if (isSelected) { tableRow.style.background = 'var(--surface-secondary)'; } else { tableRow.style.background = ''; }
            }
            if (mobileCard) {
                if (isSelected) { mobileCard.style.background = 'var(--surface-secondary)'; } else { mobileCard.style.background = ''; }
            }
        });
    }

    function toggleBulkMode() {
        bulkMode = !bulkMode;
        const checkboxes = document.querySelectorAll('.bulk-checkbox');
        const selectAll = document.getElementById('select-all');
        const toggleBtn = document.getElementById('bulk-toggle');
        const bulkActions = document.getElementById('bulk-actions');

        checkboxes.forEach(cb => {
            cb.classList.toggle('hidden', !bulkMode);
            cb.checked = false;
        });

        if (selectAll) {
            selectAll.classList.toggle('hidden', !bulkMode);
            selectAll.checked = false;
        }

        if (toggleBtn) {
            if (bulkMode) {
                toggleBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="mr-1" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg><?php echo e(t('Cancel')); ?>';
                toggleBtn.classList.remove('btn-ghost');
                toggleBtn.classList.add('btn-secondary');
                toggleBtn.setAttribute('aria-pressed', 'true');
            } else {
                toggleBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="mr-1" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg><?php echo e(t('Bulk select')); ?>';
                toggleBtn.classList.add('btn-ghost');
                toggleBtn.classList.remove('btn-secondary');
                toggleBtn.setAttribute('aria-pressed', 'false');
                if (bulkActions) bulkActions.classList.add('hidden');
            }
        }

        syncBulkHighlights();
        updateSelectedCount();
    }

    function toggleAll(source) {
        const checkboxes = document.querySelectorAll('.bulk-checkbox');
        checkboxes.forEach(cb => cb.checked = source.checked);
        syncBulkHighlights();
        updateSelectedCount();
    }

    function updateSelectedCount() {
        const checked = document.querySelectorAll('.bulk-checkbox:checked').length;
        const countSpan = document.getElementById('selected-count');
        const bulkActions = document.getElementById('bulk-actions');

        if (countSpan) countSpan.textContent = checked;
        if (bulkActions) {
            if (checked > 0) {
                bulkActions.classList.remove('hidden');
            } else {
                bulkActions.classList.add('hidden');
            }
        }
        syncBulkHighlights();
    }

    // Add event listeners to checkboxes
    document.querySelectorAll('.bulk-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });

    // Clickable rows handled by app-footer.js global tr[data-href] handler

    // Autosuggest search — shows suggestions, Enter or click to search/navigate
    (function() {
        const searchInput = document.getElementById('ticket-search-input');
        const suggestBox = document.getElementById('ticket-search-suggestions');
        if (!searchInput || !suggestBox) return;

        let debounceTimer;
        let activeIdx = -1;
        let items = [];

        function closeSuggestions() {
            suggestBox.classList.remove('active');
            while (suggestBox.firstChild) suggestBox.removeChild(suggestBox.firstChild);
            activeIdx = -1;
            items = [];
        }

        function highlightItem(idx) {
            items.forEach(function(el, i) { el.classList.toggle('active', i === idx); });
            activeIdx = idx;
        }

        function createSuggestionItem(t) {
            const a = document.createElement('a');
            a.className = 'ticket-suggest-item';
            a.href = t.url;
            a.setAttribute('data-url', t.url);

            const code = document.createElement('span');
            code.className = 'suggest-code';
            code.textContent = t.ticket_code;

            const title = document.createElement('span');
            title.className = 'suggest-title';
            title.textContent = t.title;

            const status = document.createElement('span');
            status.className = 'suggest-status';
            status.style.background = t.status_color ? t.status_color + '20' : 'transparent';
            status.style.color = t.status_color || 'inherit';
            status.textContent = t.status_name;

            a.appendChild(code);
            a.appendChild(title);
            a.appendChild(status);
            return a;
        }

        function renderSuggestions(tickets) {
            while (suggestBox.firstChild) suggestBox.removeChild(suggestBox.firstChild);
            if (!tickets.length) {
                const hint = document.createElement('div');
                hint.className = 'ticket-suggest-hint';
                hint.textContent = '<?php echo e(t('No tickets found')); ?> — Enter <?php echo e(t('to filter list')); ?>';
                suggestBox.appendChild(hint);
                suggestBox.classList.add('active');
                items = [];
                activeIdx = -1;
                return;
            }
            tickets.forEach(function(t) {
                suggestBox.appendChild(createSuggestionItem(t));
            });
            const hint = document.createElement('div');
            hint.className = 'ticket-suggest-hint';
            hint.textContent = 'Enter <?php echo e(t('to filter list')); ?>';
            suggestBox.appendChild(hint);
            suggestBox.classList.add('active');
            items = Array.from(suggestBox.querySelectorAll('.ticket-suggest-item'));
            activeIdx = -1;
        }

        // Fetch suggestions on input (debounced)
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const val = this.value.trim();
            if (val.length < 2) {
                closeSuggestions();
                return;
            }
            debounceTimer = setTimeout(function() {
                fetch('index.php?page=api&action=search-tickets&q=' + encodeURIComponent(val))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.tickets) {
                            renderSuggestions(data.tickets);
                        } else {
                            closeSuggestions();
                        }
                    })
                    .catch(function() { closeSuggestions(); });
            }, 300);
        });

        // Keyboard navigation
        searchInput.addEventListener('keydown', function(e) {
            if (!suggestBox.classList.contains('active') || !items.length) {
                if (e.key === 'Escape') { closeSuggestions(); }
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlightItem(Math.min(activeIdx + 1, items.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlightItem(Math.max(activeIdx - 1, 0));
            } else if (e.key === 'Enter') {
                if (activeIdx >= 0 && items[activeIdx]) {
                    e.preventDefault();
                    window.location.href = items[activeIdx].getAttribute('data-url');
                }
                // else: let the form submit normally (Enter without selection = filter)
                closeSuggestions();
            } else if (e.key === 'Escape') {
                closeSuggestions();
                searchInput.blur();
            }
        });

        // Close on click outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !suggestBox.contains(e.target)) {
                closeSuggestions();
            }
        });

        // Close on focus out
        searchInput.addEventListener('blur', function() {
            setTimeout(closeSuggestions, 200);
        });
    })();

    // ─── Inline status/priority editing ─────────────────────
    (function() {
        var openDd = null;

        function closeAll() {
            if (openDd) { openDd.classList.add('hidden'); openDd = null; }
        }

        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('.tl-edit-trigger');
            if (trigger) {
                e.preventDefault();
                e.stopImmediatePropagation();
                var tid = trigger.dataset.ticket;
                var field = trigger.dataset.field;
                var dd = document.querySelector('[data-dropdown="' + field + '-' + tid + '"]');
                if (!dd) return;
                if (openDd === dd) { closeAll(); return; }
                closeAll();
                dd.classList.remove('hidden');
                openDd = dd;
                return;
            }
            if (!e.target.closest('.tl-dropdown')) closeAll();
        });

        window.inlineUpdate = function(ticketId, field, valueId, btn) {
            closeAll();
            var action = field === 'status' ? 'agent-update-status' : 'quick-priority';
            var body = new FormData();
            body.append('ticket_id', ticketId);
            if (field === 'status') body.append('status_id', valueId);
            else body.append('priority_id', valueId);

            var opts = { method: 'POST', body: body };
            if (field === 'status') {
                opts.headers = { 'Content-Type': 'application/json' };
                opts.body = JSON.stringify({ ticket_id: ticketId, status_id: valueId });
            } else {
                opts.headers = { 'X-CSRF-TOKEN': window.csrfToken };
            }

            fetch(window.appConfig.apiUrl + '&action=' + action, opts)
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    var row = btn.closest('tr');
                    if (!row) { location.reload(); return; }
                    var color = btn.querySelector('.rounded-full')?.style.background || '';
                    var name = btn.textContent.trim();
                    var container = row.querySelector('.tl-edit-trigger[data-field="' + field + '"]');
                    if (container) {
                        container.textContent = name;
                        container.style.backgroundColor = color + '20';
                        container.style.color = color;
                    }
                    if (field === 'status') {
                        row.style.borderLeftColor = color;
                    }
                    if (window.showAppToast) window.showAppToast(res.message || '<?php echo e(t('Saved')); ?>', 'success');
                } else {
                    if (window.showAppToast) window.showAppToast(res.error || '<?php echo e(t('Error')); ?>', 'error');
                }
            })
            .catch(function() {
                if (window.showAppToast) window.showAppToast('<?php echo e(t('Error')); ?>', 'error');
            });
        };
    })();
</script>

<style>
.tl-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    z-index: 50;
    min-width: 160px;
    padding: 4px 0;
    margin-top: 4px;
    background: var(--surface-primary);
    border: 1px solid var(--border-light);
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
}
[data-theme="dark"] .tl-dropdown {
    box-shadow: 0 4px 16px rgba(0,0,0,0.4);
}
.tl-dropdown-item {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 6px 12px;
    font-size: 13px;
    border: none;
    background: none;
    cursor: pointer;
    text-align: left;
    white-space: nowrap;
}
.tl-dropdown-item:hover {
    background: var(--surface-secondary);
}
.tl-edit-trigger:hover {
    opacity: 0.8;
    outline: 2px solid currentColor;
    outline-offset: 1px;
    border-radius: 4px;
}
</style>

<?php require_once BASE_PATH . '/includes/footer.php';
