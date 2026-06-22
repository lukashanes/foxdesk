<?php
/**
 * Report page filter read model.
 */

function report_filter_allowed_tabs(): array
{
    return ['time', 'billing', 'published', 'summary', 'detailed', 'weekly', 'worklog', 'rates', 'shared'];
}

function report_filter_normalize_tab(string $tab): string
{
    $tab = trim($tab);
    $aliases = [
        'summary' => 'time',
        'weekly' => 'time',
        'worklog' => 'time',
        'detailed' => 'billing',
        'rates' => 'billing',
        'shared' => 'published',
    ];

    return $aliases[$tab] ?? $tab;
}

function report_filter_state_from_request(array $request, bool $is_admin_user): array
{
    $tab = report_filter_normalize_tab((string) ($request['tab'] ?? 'time'));
    if (!in_array($tab, report_filter_allowed_tabs(), true)) {
        $tab = 'time';
    }
    if (!$is_admin_user && $tab !== 'time') {
        $tab = 'time';
    }

    $range_data = get_time_range_bounds(
        (string) ($request['time_range'] ?? $request['period'] ?? 'this_month'),
        (string) ($request['from_date'] ?? ''),
        (string) ($request['to_date'] ?? '')
    );

    $selected_orgs = array_map('intval', (array) ($request['organizations'] ?? []));
    $selected_agents = array_map('intval', (array) ($request['agents'] ?? []));
    $selected_tags = normalize_ticket_tags($request['tags'] ?? '', true);

    $has_filters = isset($request['time_range'])
        || isset($request['organizations'])
        || isset($request['agents'])
        || isset($request['tags']);
    $show_money = $has_filters ? (isset($request['show_money']) ? 1 : 0) : 1;
    if (!$is_admin_user) {
        $show_money = 0;
    }
    if ($tab === 'time') {
        $show_money = 0;
    }

    return [
        'tab' => $tab,
        'time_range' => $range_data['range'],
        'range_start' => $range_data['start'],
        'range_end' => $range_data['end'],
        'selected_orgs' => $selected_orgs,
        'selected_agents' => $selected_agents,
        'selected_tags' => $selected_tags,
        'selected_tags_csv' => implode(', ', $selected_tags),
        'show_money' => $show_money,
    ];
}
