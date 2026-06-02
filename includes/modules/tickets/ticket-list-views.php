<?php
/**
 * Ticket list views.
 *
 * Stable English view keys keep the product model simple while custom workflow
 * statuses continue to live underneath as status groups.
 */

function ticket_list_view_keys(bool $include_archive = true): array
{
    $keys = ['open', 'waiting', 'done', 'all'];
    if ($include_archive) {
        $keys[] = 'archived';
    }
    return $keys;
}

function ticket_list_view_normalize(?string $view, bool $include_archive = true): string
{
    $view = strtolower(trim((string) $view));
    return in_array($view, ticket_list_view_keys($include_archive), true) ? $view : 'open';
}

function ticket_list_view_from_request(array $request, bool $is_archive = false, bool $include_archive = true): string
{
    if ($is_archive) {
        return 'archived';
    }
    return ticket_list_view_normalize($request['work_view'] ?? 'open', $include_archive);
}

function ticket_list_view_definitions(bool $include_archive = true): array
{
    $views = [
        'open' => [
            'key' => 'open',
            'label' => 'Open',
            'description' => 'New and active tickets',
            'filters' => ['status_group_not' => ['done']],
        ],
        'waiting' => [
            'key' => 'waiting',
            'label' => 'Waiting',
            'description' => 'Tickets waiting on somebody',
            'filters' => ['status_group' => 'waiting'],
        ],
        'done' => [
            'key' => 'done',
            'label' => 'Done',
            'description' => 'Resolved tickets',
            'filters' => ['status_group' => 'done'],
        ],
        'all' => [
            'key' => 'all',
            'label' => 'All',
            'description' => 'Every non-archived ticket',
            'filters' => [],
        ],
    ];

    if ($include_archive) {
        $views['archived'] = [
            'key' => 'archived',
            'label' => 'Archive',
            'description' => 'Archived tickets',
            'filters' => ['is_archived' => 1],
        ];
    }

    return $views;
}

function ticket_list_view_apply_filters(array $filters, string $view): array
{
    $definitions = ticket_list_view_definitions(true);
    $view = ticket_list_view_normalize($view, true);
    $view_filters = $definitions[$view]['filters'] ?? [];

    unset($filters['status_group'], $filters['status_group_not']);

    if ($view === 'archived') {
        $filters['is_archived'] = 1;
    } elseif (array_key_exists('is_archived', $filters)) {
        $filters['is_archived'] = 0;
    }

    foreach ($view_filters as $key => $value) {
        $filters[$key] = $value;
    }

    return $filters;
}

function ticket_list_view_url(string $view, array $request, bool $include_archive = true): string
{
    $view = ticket_list_view_normalize($view, $include_archive);
    $params = $request;
    unset($params['page'], $params['p'], $params['status_group'], $params['status_group_not']);

    if ($view === 'archived') {
        $params['archived'] = '1';
        unset($params['work_view']);
    } else {
        unset($params['archived']);
        if ($view === 'open') {
            unset($params['work_view']);
        } else {
            $params['work_view'] = $view;
        }
    }

    return url('tickets', $params);
}

function ticket_list_view_counts(array $base_filters, bool $include_archive = true): array
{
    $counts = [];
    if (!function_exists('get_tickets_count')) {
        return $counts;
    }

    foreach (ticket_list_view_definitions($include_archive) as $key => $definition) {
        $counts[$key] = get_tickets_count(ticket_list_view_apply_filters($base_filters, $key));
    }

    return $counts;
}
