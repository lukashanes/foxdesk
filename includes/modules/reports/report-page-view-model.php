<?php
/**
 * Presentation-only read models for the unified reports page.
 */

function report_page_time_range_labels(?string $range_start, ?string $range_end): array
{
    $from_date = $range_start ? substr($range_start, 0, 10) : '';
    $to_date = $range_end ? substr($range_end, 0, 10) : '';

    return [
        'all' => t('All time'),
        'today' => t('Today'),
        'yesterday' => t('Yesterday'),
        'last_7_days' => t('Last 7 days'),
        'last_30_days' => t('Last 30 days'),
        'this_week' => t('This week'),
        'last_week' => t('Last week'),
        'this_month' => t('This month'),
        'last_month' => t('Last month'),
        'this_quarter' => t('This quarter'),
        'last_quarter' => t('Last quarter'),
        'this_year' => t('This year'),
        'last_year' => t('Last year'),
        'custom' => ($from_date && $to_date) ? $from_date . ' – ' . $to_date : t('Custom range'),
    ];
}

function report_page_selected_client(array $selected_orgs, array $organizations): array
{
    $positive_ids = array_values(array_filter(
        array_map('intval', $selected_orgs),
        static fn (int $id): bool => $id > 0
    ));
    $organization_id = count($positive_ids) === 1 ? $positive_ids[0] : null;
    $organization_name = null;

    if ($organization_id !== null) {
        foreach ($organizations as $organization) {
            if ((int) ($organization['id'] ?? 0) === $organization_id) {
                $organization_name = (string) ($organization['name'] ?? '');
                break;
            }
        }
    }

    return [
        'id' => $organization_id,
        'name' => $organization_name,
    ];
}

function report_page_selected_agent(array $selected_agents, array $agents): array
{
    $positive_ids = array_values(array_unique(array_filter(
        array_map('intval', $selected_agents),
        static fn (int $id): bool => $id > 0
    )));
    $agent_id = count($positive_ids) === 1 ? $positive_ids[0] : null;
    $agent_name = null;

    if ($agent_id !== null) {
        foreach ($agents as $agent) {
            if ((int) ($agent['id'] ?? 0) === $agent_id) {
                $agent_name = trim((string) ($agent['first_name'] ?? '') . ' ' . (string) ($agent['last_name'] ?? ''));
                break;
            }
        }
    }

    return [
        'id' => $agent_id,
        'name' => $agent_name,
    ];
}

function report_page_billing_columns(bool $is_admin_user, bool $tags_supported, bool $show_money, bool $has_cost_data): array
{
    if (!$is_admin_user) {
        return [];
    }

    $columns = [
        'ticket' => t('Ticket'),
        'company' => t('Company'),
    ];
    if ($tags_supported) {
        $columns['tags'] = t('Tags');
    }
    $columns += [
        'duration' => t('Duration'),
        'billable' => t('Billable'),
        'agent' => t('Agent'),
        'source' => t('Source'),
        'start' => t('Start time'),
        'end' => t('End time'),
    ];
    if ($show_money) {
        $columns['amount'] = t('Amount');
    }
    if ($show_money && $has_cost_data) {
        $columns['cost'] = t('Cost');
        $columns['profit'] = t('Profit');
    }

    return $columns;
}

function report_page_active_filters(array $filter_state, array $organizations, array $agents, array $current_user): array
{
    $time_range = (string) ($filter_state['time_range'] ?? 'this_month');
    $labels = report_page_time_range_labels(
        $filter_state['range_start'] ?? null,
        $filter_state['range_end'] ?? null
    );
    $active = [];

    if ($time_range !== 'all' && $time_range !== 'this_month') {
        $active[] = ['type' => 'time_range', 'label' => $labels[$time_range] ?? $time_range, 'param' => 'time_range'];
    }
    $organizations_by_id = [];
    foreach ($organizations as $organization) {
        $organizations_by_id[(int) ($organization['id'] ?? 0)] = (string) ($organization['name'] ?? '');
    }
    foreach ((array) ($filter_state['selected_orgs'] ?? []) as $organization_id) {
        $organization_id = (int) $organization_id;
        if (isset($organizations_by_id[$organization_id])) {
            $active[] = ['type' => 'org', 'label' => $organizations_by_id[$organization_id], 'id' => $organization_id];
        }
    }

    $agents_by_id = [];
    foreach ($agents as $agent) {
        $agents_by_id[(int) ($agent['id'] ?? 0)] = trim((string) ($agent['first_name'] ?? '') . ' ' . (string) ($agent['last_name'] ?? ''));
    }
    foreach ((array) ($filter_state['selected_agents'] ?? []) as $agent_id) {
        $agent_id = (int) $agent_id;
        if (isset($agents_by_id[$agent_id])) {
            $active[] = ['type' => 'agent', 'label' => $agents_by_id[$agent_id], 'id' => $agent_id];
        }
    }
    foreach ((array) ($filter_state['selected_tags'] ?? []) as $tag) {
        $active[] = ['type' => 'tag', 'label' => '#' . $tag, 'value' => $tag];
    }
    if (!is_admin()) {
        $active[] = [
            'type' => 'my_entries',
            'label' => trim((string) ($current_user['first_name'] ?? '') . ' ' . (string) ($current_user['last_name'] ?? '')),
        ];
    }

    $summary = [$labels[$time_range] ?? $time_range];
    $selected_orgs = (array) ($filter_state['selected_orgs'] ?? []);
    $selected_agents = (array) ($filter_state['selected_agents'] ?? []);
    $selected_tags = (array) ($filter_state['selected_tags'] ?? []);
    if ($selected_orgs) {
        $summary[] = count($selected_orgs) . ' ' . t('clients');
    }
    if ($selected_agents) {
        $summary[] = count($selected_agents) . ' ' . t('agents');
    }
    if ($selected_tags) {
        $summary[] = count($selected_tags) . ' ' . t('tags');
    }

    return [
        'items' => $active,
        'has_items' => $active !== [],
        'collapsed' => $active !== [],
        'summary' => implode(' · ', $summary),
    ];
}

function report_page_worklog_model(array $entries): array
{
    $days = [];
    foreach ($entries as $entry) {
        $day_key = date('Y-m-d', strtotime((string) $entry['started_at']));
        if (!isset($days[$day_key])) {
            $days[$day_key] = ['entries' => [], 'minutes' => 0];
        }
        $days[$day_key]['entries'][] = $entry;
        $days[$day_key]['minutes'] += (int) ($entry['actual_minutes'] ?? 0);
    }

    return $days;
}

function report_page_day_label(string $date_string): string
{
    $date = new DateTime($date_string);
    if ($date->format('Y-m-d') === (new DateTime('today'))->format('Y-m-d')) {
        return t('Today');
    }
    if ($date->format('Y-m-d') === (new DateTime('yesterday'))->format('Y-m-d')) {
        return t('Yesterday');
    }

    return $date->format('d.m.Y');
}

function report_page_weekly_model(array $by_week): array
{
    $max_minutes = 0;
    $agent_ids = [];
    foreach ($by_week as $week) {
        $max_minutes = max($max_minutes, (int) ($week['minutes'] ?? 0));
        foreach (array_keys((array) ($week['agents'] ?? [])) as $agent_id) {
            $agent_id = (int) $agent_id;
            if (!in_array($agent_id, $agent_ids, true)) {
                $agent_ids[] = $agent_id;
            }
        }
    }
    $tone_map = [];
    foreach ($agent_ids as $index => $agent_id) {
        $tone_map[$agent_id] = $index % 8;
    }

    return [
        'max_minutes' => $max_minutes,
        'agent_ids' => $agent_ids,
        'agent_tone_map' => $tone_map,
    ];
}

function report_page_client_script_config(array $organizations, array $agents, array $filter_state, bool $tags_supported): array
{
    $organization_items = array_map(static fn (array $organization): array => [
        'id' => (int) ($organization['id'] ?? 0),
        'name' => (string) ($organization['name'] ?? ''),
    ], $organizations);
    array_unshift($organization_items, ['id' => 0, 'name' => t('-- No organization --')]);

    return [
        'isAdmin' => is_admin(),
        'tagsSupported' => $tags_supported,
        'organizations' => $organization_items,
        'selectedOrganizations' => array_map('intval', (array) ($filter_state['selected_orgs'] ?? [])),
        'agents' => array_map(static fn (array $agent): array => [
            'id' => (int) ($agent['id'] ?? 0),
            'name' => trim((string) ($agent['first_name'] ?? '') . ' ' . (string) ($agent['last_name'] ?? '')),
        ], $agents),
        'selectedAgents' => array_map('intval', (array) ($filter_state['selected_agents'] ?? [])),
        'selectedTags' => array_values((array) ($filter_state['selected_tags'] ?? [])),
        'labels' => [
            'failedSave' => t('Failed to save'),
            'noMatches' => t('No matches'),
        ],
    ];
}
