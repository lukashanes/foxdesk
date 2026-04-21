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
    if (
        array_key_exists('schedule_enabled', $data) ||
        array_key_exists('schedule_interval', $data) ||
        array_key_exists('schedule_day', $data) ||
        array_key_exists('schedule_recipients', $data) ||
        array_key_exists('schedule_next_due', $data)
    ) {
        ensure_report_schedule_columns();
    }

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
        'is_draft' => $data['is_draft'] ?? 1,
        'schedule_enabled' => $data['schedule_enabled'] ?? 0,
        'schedule_interval' => $data['schedule_interval'] ?? 'monthly',
        'schedule_day' => $data['schedule_day'] ?? 1,
        'schedule_recipients' => $data['schedule_recipients'] ?? null,
        'schedule_next_due' => $data['schedule_next_due'] ?? null,
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

    if (
        array_key_exists('schedule_enabled', $data) ||
        array_key_exists('schedule_interval', $data) ||
        array_key_exists('schedule_day', $data) ||
        array_key_exists('schedule_recipients', $data) ||
        array_key_exists('schedule_next_due', $data)
    ) {
        ensure_report_schedule_columns();
    }

    $allowed_fields = [
        'organization_id', 'title', 'report_language', 'date_from', 'date_to',
        'executive_summary', 'show_financials', 'show_team_attribution',
        'show_cost_breakdown', 'group_by', 'rounding_minutes',
        'theme_color', 'hide_branding', 'is_draft', 'is_archived', 'expires_at',
        'schedule_enabled', 'schedule_interval', 'schedule_day', 'schedule_recipients', 'schedule_next_due'
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
                    'group_label' => $entry['ticket_title'],
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

// ── RP10/RP11: Scheduled Reports & Email Delivery ───────────────────────────

/**
 * Auto-migrate: add schedule columns to report_templates.
 */
function ensure_report_schedule_columns(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [
        'schedule_enabled'    => "TINYINT(1) NOT NULL DEFAULT 0",
        'schedule_interval'   => "VARCHAR(20) NOT NULL DEFAULT 'monthly'",
        'schedule_day'        => "INT NOT NULL DEFAULT 1",
        'schedule_recipients' => "TEXT NULL",
        'schedule_last_sent'  => "DATETIME NULL",
        'schedule_next_due'   => "DATE NULL",
    ];

    foreach ($cols as $col => $def) {
        if (!report_template_column_exists($col)) {
            try {
                db_query("ALTER TABLE report_templates ADD COLUMN {$col} {$def}");
            } catch (Throwable $e) { /* ignore */ }
        }
    }
}

/**
 * Get scheduled reports that are due for generation.
 *
 * @return array List of report templates due for scheduled generation
 */
function get_due_scheduled_reports(): array
{
    ensure_report_schedule_columns();
    $today = date('Y-m-d');

    return db_fetch_all("
        SELECT rt.*, o.name as organization_name
        FROM report_templates rt
        LEFT JOIN organizations o ON rt.organization_id = o.id
        WHERE rt.schedule_enabled = 1
          AND rt.is_draft = 0
          AND (rt.schedule_next_due IS NULL OR rt.schedule_next_due <= ?)
    ", [$today]);
}

/**
 * Calculate the next due date for a scheduled report.
 *
 * @param string $interval  'weekly', 'monthly', or 'quarterly'
 * @param int    $day       Day of week (1=Mon for weekly) or day of month
 * @param string|null $from Base date (default: today)
 * @return string Next due date (Y-m-d)
 */
function report_days_in_month(int $year, int $month): int
{
    $month = max(1, min(12, $month));
    $date = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-1');
    if (!$date) {
        return 31;
    }

    return (int) $date->format('t');
}

function calculate_next_report_due(string $interval, int $day, ?string $from = null): string
{
    $base = new DateTime($from ?? 'today');

    switch ($interval) {
        case 'weekly':
            // $day = 1 (Monday) to 7 (Sunday)
            $current_dow = (int) $base->format('N'); // 1=Mon to 7=Sun
            $diff = $day - $current_dow;
            if ($diff <= 0) $diff += 7;
            $base->modify("+{$diff} days");
            break;

        case 'quarterly':
            // Move to first day of next quarter, then set day
            $month = (int) $base->format('n');
            $quarter_start = (int) (ceil($month / 3) * 3) + 1;
            if ($quarter_start > 12) {
                $quarter_start = 1;
                $base->modify('+1 year');
            }
            $year = (int) $base->format('Y');
            $max_day = report_days_in_month($year, $quarter_start);
            $actual_day = min($day, $max_day);
            $base->setDate($year, $quarter_start, $actual_day);
            break;

        default: // monthly
            $base->modify('+1 month');
            $year = (int) $base->format('Y');
            $month = (int) $base->format('n');
            $max_day = report_days_in_month($year, $month);
            $actual_day = min($day, $max_day);
            $base->setDate($year, $month, $actual_day);
            break;
    }

    return $base->format('Y-m-d');
}

/**
 * Process all due scheduled reports: regenerate snapshots, advance dates, send emails.
 */
function process_scheduled_reports(): void
{
    ensure_report_schedule_columns();
    $due_reports = get_due_scheduled_reports();

    foreach ($due_reports as $report) {
        try {
            // Calculate new date range: previous interval up to yesterday
            $today = new DateTime();
            $interval = $report['schedule_interval'] ?? 'monthly';
            $date_to = (clone $today)->modify('-1 day')->format('Y-m-d');

            switch ($interval) {
                case 'weekly':
                    $date_from = (clone $today)->modify('-7 days')->format('Y-m-d');
                    break;
                case 'quarterly':
                    $date_from = (clone $today)->modify('-3 months')->format('Y-m-d');
                    break;
                default:
                    $date_from = (clone $today)->modify('-1 month')->format('Y-m-d');
                    break;
            }

            // Update the report template date range and regenerate
            db_update('report_templates', [
                'date_from' => $date_from,
                'date_to' => $date_to,
            ], 'id = ?', [$report['id']]);

            // Regenerate snapshot with new date range
            generate_report_snapshot_cron($report['id']);

            // Calculate next due date
            $next_due = calculate_next_report_due(
                $interval,
                (int) ($report['schedule_day'] ?? 1)
            );

            db_update('report_templates', [
                'schedule_last_sent' => date('Y-m-d H:i:s'),
                'schedule_next_due' => $next_due,
            ], 'id = ?', [$report['id']]);

            // Send email to recipients (RP11)
            $recipients = trim($report['schedule_recipients'] ?? '');
            if ($recipients !== '') {
                send_scheduled_report_email($report, $date_from, $date_to);
            }
        } catch (Throwable $e) {
            error_log('[scheduled-reports] Error processing report #' . $report['id'] . ': ' . $e->getMessage());
        }
    }
}

/**
 * Generate report snapshot in cron context (no current_user).
 */
function generate_report_snapshot_cron(int $report_template_id)
{
    $start_time = microtime(true);
    $template = get_report_template($report_template_id);
    if (!$template) return false;

    $time_entries = get_report_time_entries($template);
    $kpis = calculate_report_kpis($time_entries, $template);
    $chart_data = generate_report_chart_data($time_entries, $template);

    $generation_time_ms = round((microtime(true) - $start_time) * 1000);

    $snapshot_data = [
        'report_template_id' => $report_template_id,
        'kpi_data' => json_encode($kpis),
        'chart_data' => json_encode($chart_data),
        'generation_time_ms' => $generation_time_ms,
        'generated_by_user_id' => $template['created_by_user_id'] ?? null,
    ];

    $snapshot_id = db_insert('report_snapshots', $snapshot_data);
    db_update('report_templates', [
        'last_generated_at' => date('Y-m-d H:i:s')
    ], 'id = ?', [$report_template_id]);

    return $snapshot_id;
}

/**
 * Send a scheduled report email to all configured recipients (RP11).
 *
 * @param array  $report    Report template data
 * @param string $date_from Report period start
 * @param string $date_to   Report period end
 */
function send_scheduled_report_email(array $report, string $date_from, string $date_to): void
{
    $recipients_str = $report['schedule_recipients'] ?? '';
    $emails = array_filter(array_map('trim', explode(',', $recipients_str)));
    if (empty($emails)) return;

    $app_name = defined('APP_NAME') ? APP_NAME : 'FoxDesk';
    $app_url = function_exists('get_app_url') ? get_app_url() : '';
    $org_name = $report['organization_name'] ?? 'Client';
    $report_title = $report['title'] ?? 'Report';
    $uuid = $report['uuid'] ?? '';
    $report_link = $app_url . '/index.php?page=report-public&uuid=' . urlencode($uuid);

    // Fetch latest snapshot KPIs
    $kpi_html = '';
    try {
        $snapshot = db_fetch_one(
            "SELECT kpi_data FROM report_snapshots WHERE report_template_id = ? ORDER BY id DESC LIMIT 1",
            [$report['id']]
        );
        if ($snapshot && !empty($snapshot['kpi_data'])) {
            $kpis = json_decode($snapshot['kpi_data'], true);
            if ($kpis) {
                $total_hours = round(($kpis['total_minutes'] ?? 0) / 60, 1);
                $total_tasks = $kpis['total_tasks'] ?? 0;
                $team_size = $kpis['team_members'] ?? 0;
                $kpi_html = "
                    <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                        <tr>
                            <td style='padding:12px;text-align:center;background:#f3f4f6;border-radius:8px 0 0 8px;'>
                                <div style='font-size:24px;font-weight:bold;color:#1f2937;'>{$total_hours}h</div>
                                <div style='font-size:12px;color:#6b7280;'>Total Hours</div>
                            </td>
                            <td style='padding:12px;text-align:center;background:#f3f4f6;'>
                                <div style='font-size:24px;font-weight:bold;color:#1f2937;'>{$total_tasks}</div>
                                <div style='font-size:12px;color:#6b7280;'>Tasks</div>
                            </td>
                            <td style='padding:12px;text-align:center;background:#f3f4f6;border-radius:0 8px 8px 0;'>
                                <div style='font-size:24px;font-weight:bold;color:#1f2937;'>{$team_size}</div>
                                <div style='font-size:12px;color:#6b7280;'>Team Members</div>
                            </td>
                        </tr>
                    </table>";
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    $from_formatted = date('M j, Y', strtotime($date_from));
    $to_formatted = date('M j, Y', strtotime($date_to));

    $subject = "{$report_title} — {$from_formatted} to {$to_formatted}";
    $body = "
    <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;max-width:600px;margin:0 auto;'>
        <div style='padding:24px 0;border-bottom:2px solid #e5e7eb;margin-bottom:24px;'>
            <h1 style='margin:0;font-size:22px;color:#111827;'>{$report_title}</h1>
            <p style='margin:8px 0 0;color:#6b7280;font-size:14px;'>
                {$org_name} &mdash; {$from_formatted} to {$to_formatted}
            </p>
        </div>

        {$kpi_html}

        <p style='color:#374151;font-size:14px;line-height:1.6;'>
            Your scheduled report has been generated and is ready for review.
            Click the button below to view the full report with detailed breakdowns.
        </p>

        <div style='text-align:center;margin:24px 0;'>
            <a href='{$report_link}' style='display:inline-block;padding:12px 32px;background:#3b82f6;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;'>
                View Full Report
            </a>
        </div>

        <p style='color:#9ca3af;font-size:12px;text-align:center;margin-top:32px;border-top:1px solid #e5e7eb;padding-top:16px;'>
            Sent automatically by {$app_name}. To stop receiving these emails, ask your administrator to update the report schedule.
        </p>
    </div>";

    foreach ($emails as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                send_email($email, $subject, $body, true, true);
            } catch (Throwable $e) {
                error_log("[scheduled-reports] Failed to email {$email}: " . $e->getMessage());
            }
        }
    }
}
