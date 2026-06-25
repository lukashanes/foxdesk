<?php
/**
 * Ticket list filter state.
 *
 * Keep request parsing deterministic so the ticket registry route can stay
 * focused on authorization, data loading, and rendering.
 */

function ticket_list_normalize_tag_filters($value): array
{
    $raw_tags = [];
    if (is_array($value)) {
        $raw_tags = $value;
    } else {
        $value = trim((string) $value);
        if ($value !== '') {
            $raw_tags = preg_split('/\s*,\s*/', $value) ?: [];
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
}

function ticket_list_allowed_sorts(bool $tags_supported): array
{
    $sorts = ['newest', 'oldest', 'completed', 'last_updated', 'ticket_number', 'ticket_number_asc', 'priority', 'status', 'due_date'];
    if ($tags_supported) {
        $sorts[] = 'tags';
    }
    return $sorts;
}

function ticket_list_visual_view_from_request(array $request, array $cookies = []): array
{
    $requested = (string) ($request['view'] ?? '');
    if (in_array($requested, ['list', 'board'], true)) {
        return [
            'view' => $requested,
            'persist' => true,
        ];
    }

    return [
        'view' => (($cookies['foxdesk_ticket_view'] ?? '') === 'board') ? 'board' : 'list',
        'persist' => false,
    ];
}

function ticket_list_filter_state_from_request(array $request, array $cookies = [], bool $is_archive = false, array $options = []): array
{
    $tags_supported = array_key_exists('tags_supported', $options)
        ? (bool) $options['tags_supported']
        : (function_exists('ticket_tags_column_exists') && ticket_tags_column_exists());
    $archive_supported = array_key_exists('archive_supported', $options)
        ? (bool) $options['archive_supported']
        : (function_exists('column_exists') && column_exists('tickets', 'is_archived'));

    $status_id = isset($request['status']) ? (int) $request['status'] : null;
    $organization_id = isset($request['organization']) ? (int) $request['organization'] : null;
    $priority_id = isset($request['priority']) ? (int) $request['priority'] : null;
    $assigned_to = isset($request['assigned_to']) ? (int) $request['assigned_to'] : null;
    $search_query = trim((string) ($request['search'] ?? ''));
    $user_search = trim((string) ($request['user'] ?? ''));
    $created_date_input = trim((string) ($request['created_date'] ?? ''));
    $created_date_value = '';
    $due_date_filter = trim((string) ($request['due_date'] ?? ''));

    $tag_filters = ticket_list_normalize_tag_filters($request['tags'] ?? '');
    if (empty($tag_filters)) {
        $tag_filters = ticket_list_normalize_tag_filters($request['tag'] ?? '');
    }

    $sort_is_explicit = array_key_exists('sort', $request);
    $sort = trim((string) ($request['sort'] ?? 'newest'));
    if (!in_array($sort, ticket_list_allowed_sorts($tags_supported), true)) {
        $sort = 'newest';
    }

    $view_state = ticket_list_visual_view_from_request($request, $cookies);
    $filters = [];

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
            $due_dt = DateTime::createFromFormat('Y-m-d', $due_date_filter);
            if ($due_dt) {
                $filters['due_date_from'] = $due_dt->format('Y-m-d');
                $due_dt->modify('+1 day');
                $filters['due_date_to'] = $due_dt->format('Y-m-d');
            }
        }
    }

    if ($sort_is_explicit || $sort !== 'newest') {
        $filters['sort'] = $sort;
    }
    if ($archive_supported) {
        $filters['is_archived'] = $is_archive ? 1 : 0;
    }

    return [
        'filters' => $filters,
        'status_id' => $status_id,
        'organization_id' => $organization_id,
        'priority_id' => $priority_id,
        'assigned_to' => $assigned_to,
        'search_query' => $search_query,
        'user_search' => $user_search,
        'created_date_input' => $created_date_input,
        'created_date_value' => $created_date_value,
        'due_date_filter' => $due_date_filter,
        'tags_supported' => $tags_supported,
        'tag_filters' => $tag_filters,
        'tag_filter_csv' => implode(', ', $tag_filters),
        'sort' => $sort,
        'sort_is_explicit' => $sort_is_explicit,
        'ticket_view' => $view_state['view'],
        'ticket_view_should_persist' => $view_state['persist'],
    ];
}
