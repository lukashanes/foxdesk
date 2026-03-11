<?php
/**
 * Report Generation Functions
 *
 * Core business logic for client-facing time tracking reports
 * Version: 1.3.0
 */

/**
 * Create a new report template
 *
 * @param array $data Report configuration data
 * @return int|false Report template ID or false on failure
 */
function create_report_template($data) {
    $uuid = generate_uuid();

    $insert_data = [
        'uuid' => $uuid,
        'organization_id' => $data['organization_id'],
        'created_by_user_id' => $data['created_by_user_id'],
        'title' => $data['title'],
        'report_language' => $data['report_language'] ?? 'en',
        'date_from' => $data['date_from'],
        'date_to' => $data['date_to'],
        'executive_summary' => $data['executive_summary'] ?? '',
        'show_financials' => $data['show_financials'] ?? 1,
        'show_team_attribution' => $data['show_team_attribution'] ?? 1,
        'show_cost_breakdown' => $data['show_cost_breakdown'] ?? 0,
        'group_by' => $data['group_by'] ?? 'none',
        'rounding_minutes' => $data['rounding_minutes'] ?? 15,
        'theme_color' => $data['theme_color'] ?? null,
        'hide_branding' => $data['hide_branding'] ?? 0,
        'is_draft' => $data['is_draft'] ?? 1
    ];

    $insert_data = report_filter_template_data($insert_data);
    $id = db_insert('report_templates', $insert_data);

    if ($id) {
        // Create initial snapshot
        generate_report_snapshot($id);
    }

    return $id;
}

/**
 * Check if organizations table contains a specific column.
 */
function report_organization_column_exists($column_name) {
    $column_name = preg_replace('/[^a-z0-9_]/i', '', (string) $column_name);
    if ($column_name === '') {
        return false;
    }
    return column_exists('organizations', $column_name);
}

/**
 * Check if report_templates contains a specific column.
 */
function report_template_column_exists($column_name) {
    $column_name = preg_replace('/[^a-z0-9_]/i', '', (string) $column_name);
    if ($column_name === '') {
        return false;
    }
    return column_exists('report_templates', $column_name);
}

/**
 * Filter report_templates data against currently available columns.
 */
function report_filter_template_data(array $data): array {
    $filtered = [];
    foreach ($data as $key => $value) {
        if (report_template_column_exists((string) $key)) {
            $filtered[$key] = $value;
        }
    }
    return $filtered;
}

/**
 * Check if tickets table has a tags column without depending on ticket modules.
 */
function report_ticket_tags_column_exists(): bool {
    return column_exists('tickets', 'tags');
}

/**
 * Get report template by ID
 *
 * @param int $id Report template ID
 * @return array|null Report template data
 */
function get_report_template($id) {
    $organization_logo_select = report_organization_column_exists('logo_url') ? 'o.logo_url' : 'NULL';
    $organization_theme_select = report_organization_column_exists('theme_color') ? 'o.theme_color' : 'NULL';

    return db_fetch_one("
        SELECT rt.*,
               o.name as organization_name,
               {$organization_logo_select} as organization_logo,
               {$organization_theme_select} as organization_theme_color,
               u.first_name, u.last_name, u.email
        FROM report_templates rt
        LEFT JOIN organizations o ON rt.organization_id = o.id
        LEFT JOIN users u ON rt.created_by_user_id = u.id
        WHERE rt.id = ?
    ", [$id]);
}

/**
 * Get report template by UUID (for public access)
 *
 * @param string $uuid Report UUID
 * @return array|null Report template data
 */
function get_report_template_by_uuid($uuid) {
    $organization_logo_select = report_organization_column_exists('logo_url') ? 'o.logo_url' : 'NULL';
    $organization_theme_select = report_organization_column_exists('theme_color') ? 'o.theme_color' : 'NULL';
    $where_parts = ['rt.uuid = ?'];
    if (report_template_column_exists('is_draft')) {
        $where_parts[] = 'rt.is_draft = 0';
    }
    if (report_template_column_exists('is_archived')) {
        $where_parts[] = 'rt.is_archived = 0';
    }

    return db_fetch_one("
        SELECT rt.*,
               o.name as organization_name,
               {$organization_logo_select} as organization_logo,
               {$organization_theme_select} as organization_theme_color
        FROM report_templates rt
        LEFT JOIN organizations o ON rt.organization_id = o.id
        WHERE " . implode(' AND ', $where_parts) . "
    ", [$uuid]);
}

/**
 * Update report template
 *
 * @param int $id Report template ID
 * @param array $data Updated data
 * @return bool Success status
 */
function update_report_template($id, $data) {
    $update_data = [];

    $allowed_fields = [
        'title', 'report_language', 'date_from', 'date_to',
        'executive_summary', 'show_financials', 'show_team_attribution',
        'show_cost_breakdown', 'group_by', 'rounding_minutes',
        'theme_color', 'hide_branding', 'is_draft', 'is_archived', 'expires_at'
    ];

    foreach ($allowed_fields as $field) {
        if (array_key_exists($field, $data)) {
            $update_data[$field] = $data[$field];
        }
    }
    $update_data = report_filter_template_data($update_data);

    if (empty($update_data)) {
        return false;
    }

    $result = db_update('report_templates', $update_data, 'id = ?', [$id]);

    // Regenerate snapshot if report is published
    $template = get_report_template($id);
    if ($template && !$template['is_draft']) {
        generate_report_snapshot($id);
    }

    return $result;
}

/**
 * Delete report template
 *
 * @param int $id Report template ID
 * @return bool Success status
 */
function delete_report_template($id) {
    return db_delete('report_templates', 'id = ?', [$id]);
}

/**
 * Get time entries for report
 *
 * @param array $template Report template configuration
 * @return array Time entries with ticket and user details
 */
function get_report_time_entries($template) {
    $has_ticket_tags = report_ticket_tags_column_exists();
    $ticket_tags_select = $has_ticket_tags
        ? 't.tags as ticket_tags,'
        : 'NULL as ticket_tags,';

    $sql = "
        SELECT
            te.id,
            te.ticket_id,
            te.user_id,
            te.started_at,
            te.ended_at,
            te.duration_minutes,
            te.is_billable,
            te.billable_rate,
            te.cost_rate,
            te.is_manual,
            t.id as ticket_id,
            t.hash as ticket_number,
            t.title as ticket_title,
            {$ticket_tags_select}
            tt.name as ticket_type,
            u.first_name,
            u.last_name,
            u.cost_rate as user_cost_rate,
            DATE(te.started_at) as entry_date
        FROM ticket_time_entries te
        INNER JOIN tickets t ON te.ticket_id = t.id
        LEFT JOIN ticket_types tt ON t.type = tt.id
        LEFT JOIN users u ON te.user_id = u.id
        WHERE t.organization_id = ?
          AND DATE(te.started_at) >= ?
          AND DATE(te.started_at) <= ?
    ";
    $params = [
        $template['organization_id'],
        $template['date_from'],
        $template['date_to']
    ];

    if (!empty($template['tags']) && $has_ticket_tags) {
        $raw_tags = is_array($template['tags']) ? $template['tags'] : preg_split('/\s*,\s*/', trim((string) $template['tags']));
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

        if (!empty($tags)) {
            $conditions = [];
            foreach ($tags as $tag) {
                $conditions[] = "FIND_IN_SET(?, REPLACE(IFNULL(t.tags, ''), ', ', ',')) > 0";
                $params[] = $tag;
            }
            $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
        }
    }

    $sql .= ' ORDER BY te.started_at ASC';

    return db_fetch_all($sql, $params);
}

/**
 * Calculate report KPIs
 *
 * @param array $time_entries Time entries data
 * @param array $template Report template configuration
 * @return array KPI data (total_hours, total_tasks, total_cost, team_members)
 */
function calculate_report_kpis($time_entries, $template) {
    $total_minutes = 0;
    $total_tasks = 0;
    $total_cost = 0;
    $team_members = [];
    $tasks_seen = [];

    foreach ($time_entries as $entry) {
        // Apply rounding if configured
        $minutes = $entry['duration_minutes'];
        if ($template['rounding_minutes'] > 0) {
            $minutes = round_minutes_nearest($minutes, $template['rounding_minutes']);
        }

        $total_minutes += $minutes;

        // Count unique tasks
        if (!in_array($entry['ticket_id'], $tasks_seen)) {
            $tasks_seen[] = $entry['ticket_id'];
            $total_tasks++;
        }

        // Calculate billable amount if financials are enabled (use billable_rate for client reports)
        if ($template['show_financials']) {
            $rate = $entry['billable_rate'] ?: 0;
            $total_cost += ($minutes / 60) * $rate;
        }

        // Track team members
        if ($template['show_team_attribution'] && $entry['user_id']) {
            $team_members[$entry['user_id']] = trim($entry['first_name'] . ' ' . $entry['last_name']);
        }
    }

    return [
        'total_hours' => round($total_minutes / 60, 2),
        'total_minutes' => $total_minutes,
        'total_tasks' => $total_tasks,
        'total_cost' => round($total_cost, 2),
        'team_members' => array_values($team_members),
        'team_member_count' => count($team_members)
    ];
}

/**
 * Generate chart data for daily time distribution
 *
 * @param array $time_entries Time entries data
 * @param array $template Report template configuration
 * @return array Chart.js compatible data structure
 */
function generate_report_chart_data($time_entries, $template) {
    $date_from = new DateTime($template['date_from']);
    $date_to = new DateTime($template['date_to']);

    // Initialize daily buckets
    $daily_totals = [];
    $current_date = clone $date_from;

    while ($current_date <= $date_to) {
        $date_key = $current_date->format('Y-m-d');
        $daily_totals[$date_key] = 0;
        $current_date->modify('+1 day');
    }

    // Aggregate time by date
    foreach ($time_entries as $entry) {
        $date_key = $entry['entry_date'];
        if (isset($daily_totals[$date_key])) {
            $minutes = $entry['duration_minutes'];
            if ($template['rounding_minutes'] > 0) {
                $minutes = round_minutes_nearest($minutes, $template['rounding_minutes']);
            }
            $daily_totals[$date_key] += $minutes;
        }
    }

    // Format for Chart.js
    $labels = [];
    $data = [];

    foreach ($daily_totals as $date => $minutes) {
        $dt = new DateTime($date);
        $labels[] = format_date_localized($dt->format('Y-m-d'), 'M j');
        $data[] = round($minutes / 60, 2); // Convert to hours
    }

    return [
        'labels' => $labels,
        'datasets' => [[
            'label' => t('Hours Worked'),
            'data' => $data,
            'backgroundColor' => $template['theme_color'] ?: '#3B82F6',
            'borderColor' => $template['theme_color'] ?: '#2563EB',
            'borderWidth' => 2
        ]]
    ];
}

/**
 * Group time entries by day or task
 *
 * @param array $time_entries Time entries data
 * @param string $group_by Grouping mode ('none', 'day', 'task')
 * @param array $template Report template configuration
 * @return array Grouped entries with totals
 */
function group_report_entries($time_entries, $group_by, $template) {
    if ($group_by === 'none') {
        return $time_entries;
    }

    $grouped = [];

    if ($group_by === 'day') {
        foreach ($time_entries as $entry) {
            $key = $entry['entry_date'];

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'group_key' => $key,
                    'group_label' => format_date_localized($key, 'l, j. F Y'),
                    'total_minutes' => 0,
                    'total_cost' => 0,
                    'entries' => []
                ];
            }

            $minutes = $entry['duration_minutes'];
            if ($template['rounding_minutes'] > 0) {
                $minutes = round_minutes_nearest($minutes, $template['rounding_minutes']);
            }

            $grouped[$key]['total_minutes'] += $minutes;
            $grouped[$key]['entries'][] = $entry;

            if ($template['show_financials']) {
                $rate = $entry['billable_rate'] ?: 0;
                $grouped[$key]['total_cost'] += ($minutes / 60) * $rate;
            }
        }
    } elseif ($group_by === 'task') {
        foreach ($time_entries as $entry) {
            $key = $entry['ticket_id'];

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'group_key' => $key,
                    'group_label' => '#' . $entry['ticket_number'] . ' - ' . $entry['ticket_title'],
                    'total_minutes' => 0,
                    'total_cost' => 0,
                    'entries' => []
                ];
            }

            $minutes = $entry['duration_minutes'];
            if ($template['rounding_minutes'] > 0) {
                $minutes = round_minutes_nearest($minutes, $template['rounding_minutes']);
            }

            $grouped[$key]['total_minutes'] += $minutes;
            $grouped[$key]['entries'][] = $entry;

            if ($template['show_financials']) {
                $rate = $entry['billable_rate'] ?: 0;
                $grouped[$key]['total_cost'] += ($minutes / 60) * $rate;
            }
        }
    }

    return array_values($grouped);
}

/**
 * Format time range for display
 *
 * @param array $entry Time entry with started_at and ended_at
 * @return string Formatted time range (e.g., "09:00 - 11:30") or duration
 */
function format_time_range($entry) {
    if ($entry['is_manual'] || empty($entry['started_at']) || empty($entry['ended_at'])) {
        // Manual entry - show duration only
        return format_duration_minutes($entry['duration_minutes']);
    }

    // Show clock times
    $start = new DateTime($entry['started_at']);
    $end = new DateTime($entry['ended_at']);

    return $start->format('H:i') . ' - ' . $end->format('H:i');
}

/**
 * Generate UUID for report template
 *
 * @return string UUID v4
 */
function generate_uuid() {
    $data = random_bytes(16);
    // Set version to 0100 (UUID v4)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10 (RFC 4122 variant)
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Generate or regenerate report snapshot (cached computed data)
 *
 * @param int $report_template_id Report template ID
 * @return int|false Snapshot ID or false on failure
 */
function generate_report_snapshot($report_template_id) {
    $start_time = microtime(true);

    $template = get_report_template($report_template_id);
    if (!$template) {
        return false;
    }

    $time_entries = get_report_time_entries($template);
    $kpis = calculate_report_kpis($time_entries, $template);
    $chart_data = generate_report_chart_data($time_entries, $template);

    $generation_time_ms = round((microtime(true) - $start_time) * 1000);

    $snapshot_data = [
        'report_template_id' => $report_template_id,
        'kpi_data' => json_encode($kpis),
        'chart_data' => json_encode($chart_data),
        'generation_time_ms' => $generation_time_ms,
        'generated_by_user_id' => current_user()['id']
    ];

    $snapshot_id = db_insert('report_snapshots', $snapshot_data);

    // Update template's last_generated_at timestamp
    db_update('report_templates', [
        'last_generated_at' => date('Y-m-d H:i:s')
    ], 'id = ?', [$report_template_id]);

    return $snapshot_id;
}


/**
 * Create public share for report template
 *
 * @param int $report_template_id Report template ID
 * @param int $organization_id Organization ID
 * @param int|null $expires_days Days until expiration (null = never)
 * @return string|false Share token or false on failure
 */
function create_report_template_share($report_template_id, $organization_id, $expires_days = null) {
    $template = get_report_template($report_template_id);
    if (!$template) {
        return false;
    }

    // Use report UUID directly as the public share token
    // No need to create a report_shares record - the UUID is already unique and secure
    // The public page uses get_report_template_by_uuid() to fetch the report

    return $template['uuid'];
}

