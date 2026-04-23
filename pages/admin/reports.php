<?php
/**
 * Admin - Time Reports
 */

$page_title = t('Time report');
$page = 'admin';

$time_tracking_available = ticket_time_table_exists();
if (function_exists('ensure_ticket_custom_billable_rate_column')) {
    ensure_ticket_custom_billable_rate_column();
}
$tab = $_GET['tab'] ?? 'summary';
$allowed_tabs = ['summary', 'detailed', 'weekly', 'worklog', 'shared'];
if (!in_array($tab, $allowed_tabs, true)) {
    $tab = 'summary';
}

$time_range = $_GET['time_range'] ?? 'this_month';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$range_data = get_time_range_bounds($time_range, $from_date, $to_date);
$time_range = $range_data['range'];
$range_start = $range_data['start'];
$range_end = $range_data['end'];

$selected_orgs = array_map('intval', (array) ($_GET['organizations'] ?? []));
$selected_agents = array_map('intval', (array) ($_GET['agents'] ?? []));
$tags_supported = function_exists('ticket_tags_column_exists') && ticket_tags_column_exists();
$normalize_tag_filters = static function ($value) {
    $raw_tags = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string) $value));
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
$selected_tags = $normalize_tag_filters($_GET['tags'] ?? '');
$selected_tags_csv = implode(', ', $selected_tags);

// Determine if we should show amounts
// Default: show amounts on first visit (no filter applied yet)
// After filter applied: respect the checkbox state (checked=1 in URL, unchecked=not in URL)
if (isset($_GET['time_range']) || isset($_GET['organizations']) || isset($_GET['agents']) || isset($_GET['tags'])) {
    // Form has been submitted - use checkbox state
    // Checkbox sends show_money=1 when checked, nothing when unchecked
    $show_money = isset($_GET['show_money']) ? 1 : 0;
} else {
    // First visit, no filters applied yet
    $show_money = 1;
}

// Non-admin agents: hide money columns and agent filter
if (!is_admin()) {
    $show_money = 0;
}

$organizations = get_organizations(true);
$agents = db_fetch_all("SELECT id, first_name, last_name FROM users WHERE role IN ('agent', 'admin') AND is_active = 1 ORDER BY first_name, last_name");

$entries = [];
$totals = [
    'minutes' => 0,
    'billable_minutes' => 0,
    'billable_amount' => 0.0,
    'cost_amount' => 0.0,
    'profit' => 0.0
];
$by_org = [];
$by_agent = [];
$by_ticket = [];
$by_week = [];
$by_source = [];

$rounding = get_billing_rounding_increment();
// AI user IDs for human/AI breakdown (v0.3.1)
$_ai_user_ids = function_exists('get_ai_user_ids') ? get_ai_user_ids() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    require_csrf_token();

    if (isset($_POST['set_billable'])) {
        $entry_id = (int) ($_POST['entry_id'] ?? 0);
        $is_billable = isset($_POST['is_billable']) && $_POST['is_billable'] === '1' ? 1 : 0;
        if ($entry_id > 0) {
            $update_data = ['is_billable' => $is_billable];
            if ($is_billable && function_exists('get_ticket_effective_billable_rate')) {
                $entry_row = db_fetch_one("SELECT ticket_id FROM ticket_time_entries WHERE id = ?", [$entry_id]);
                if ($entry_row) {
                    $update_data['billable_rate'] = get_ticket_effective_billable_rate((int) $entry_row['ticket_id']);
                }
            }
            db_update('ticket_time_entries', $update_data, 'id = ?', [$entry_id]);
            flash(t('Settings saved.'), 'success');
        }
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports'])));
        exit;
    }

    if (isset($_POST['delete_entry'])) {
        $entry_id = (int) ($_POST['entry_id'] ?? 0);
        if ($entry_id > 0) {
            require_once BASE_PATH . '/includes/ticket-time-functions.php';
            if (delete_time_entry($entry_id)) {
                flash(t('Time entry deleted.'), 'success');
            } else {
                flash(t('Failed to delete time entry.'), 'error');
            }
        }
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports'])));
        exit;
    }

    // Inline time update from worklog
    if (isset($_POST['update_time_inline'])) {
        $entry_id = (int) ($_POST['entry_id'] ?? 0);
        $entry_date = $_POST['entry_date'] ?? date('Y-m-d');
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';

        if ($entry_id > 0 && $start_time && $end_time) {
            $start_dt = DateTime::createFromFormat('Y-m-d H:i', $entry_date . ' ' . $start_time);
            $end_dt = DateTime::createFromFormat('Y-m-d H:i', $entry_date . ' ' . $end_time);

            if (!$start_dt || !$end_dt) {
                flash(t('Invalid time format.'), 'error');
                header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports', 'tab' => 'worklog'])));
                exit;
            }

            // If end time is before start time, assume it's the next day
            if ($end_dt <= $start_dt) {
                $end_dt->modify('+1 day');
            }

            $duration = max(1, (int) floor(($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60));

            db_update('ticket_time_entries', [
                'started_at' => $start_dt->format('Y-m-d H:i:s'),
                'ended_at' => $end_dt->format('Y-m-d H:i:s'),
                'duration_minutes' => $duration
            ], 'id = ?', [$entry_id]);
        }
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports', 'tab' => 'worklog'])));
        exit;
    }

    if (isset($_POST['update_entry'])) {
        $entry_id = (int) ($_POST['entry_id'] ?? 0);
        $ticket_input = trim($_POST['ticket_id'] ?? '');
        $ticket_title = trim($_POST['ticket_title'] ?? '');
        $start_input = trim($_POST['started_at'] ?? '');
        $end_input = trim($_POST['ended_at'] ?? '');

        $ticket_id = null;
        if ($ticket_input !== '') {
            $parsed = parse_ticket_code($ticket_input);
            if ($parsed !== null) {
                $ticket_id = $parsed;
            } elseif (ctype_digit($ticket_input)) {
                $ticket_id = (int) $ticket_input;
            }
        }

        $ticket = $ticket_id ? get_ticket($ticket_id) : null;

        $start_dt = DateTime::createFromFormat('Y-m-d\\TH:i', $start_input);
        $end_dt = DateTime::createFromFormat('Y-m-d\\TH:i', $end_input);

        if (!$ticket || !$start_dt || !$end_dt || $end_dt <= $start_dt) {
            flash(t('Invalid time range.'), 'error');
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports'])));
            exit;
        }

        $duration = max(0, (int) floor(($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60));

        $update_data = [
            'ticket_id' => $ticket_id,
            'started_at' => $start_dt->format('Y-m-d H:i:s'),
            'ended_at' => $end_dt->format('Y-m-d H:i:s'),
            'duration_minutes' => $duration
        ];

        if (!empty($ticket_title) && $ticket['title'] !== $ticket_title) {
            db_update('tickets', ['title' => $ticket_title], 'id = ?', [$ticket_id]);
        }

        $current_entry = db_fetch_one("SELECT ticket_id FROM ticket_time_entries WHERE id = ?", [$entry_id]);
        if ($current_entry && (int) $current_entry['ticket_id'] !== $ticket_id) {
            $update_data['comment_id'] = null;
            $update_data['billable_rate'] = function_exists('get_ticket_effective_billable_rate')
                ? get_ticket_effective_billable_rate($ticket)
                : 0;
        }

        db_update('ticket_time_entries', $update_data, 'id = ?', [$entry_id]);
        flash(t('Settings saved.'), 'success');
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports'])));
        exit;
    }

    if (isset($_POST['create_report_share'])) {
        $org_id = (int) ($_POST['organization_id'] ?? 0);
        if ($org_id > 0) {
            $expires_input = trim($_POST['share_expires_at'] ?? '');
            $expires_at = null;
            if ($expires_input !== '') {
                $timestamp = strtotime($expires_input);
                if ($timestamp === false || $timestamp <= time()) {
                    flash(t('Expiration must be in the future.'), 'error');
                    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports', 'tab' => 'shared'])));
                    exit;
                }
                $expires_at = date('Y-m-d H:i:s', $timestamp);
            }

            $share = create_report_share($org_id, current_user()['id'], $expires_at);
            $_SESSION['report_share_token'] = $share['token'];
            $_SESSION['report_share_org_id'] = $org_id;
            flash(t('Share link created.'), 'success');
        }
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports', 'tab' => 'shared'])));
        exit;
    }

    if (isset($_POST['revoke_report_share'])) {
        $org_id = (int) ($_POST['organization_id'] ?? 0);
        if ($org_id > 0) {
            $revoked = revoke_report_shares($org_id);
            if ($revoked > 0) {
                flash(t('Share link revoked.'), 'success');
            } else {
                flash(t('No active share link to revoke.'), 'error');
            }
        }
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin', ['section' => 'reports', 'tab' => 'shared'])));
        exit;
    }
}

if ($time_tracking_available && $tab !== 'shared') {
    $ticket_tags_select = $tags_supported ? ', t.tags as ticket_tags' : ', NULL as ticket_tags';
    $ticket_custom_rate_select = (function_exists('column_exists') && column_exists('tickets', 'custom_billable_rate'))
        ? 't.custom_billable_rate as ticket_custom_billable_rate,'
        : 'NULL as ticket_custom_billable_rate,';
    $sql = "SELECT tte.*,
                   t.title as ticket_title,
                   t.organization_id,
                   {$ticket_custom_rate_select}
                   t.status_id as ticket_status_id,
                   s.is_closed as ticket_is_closed,
                   s.name as ticket_status_name,
                   o.name as organization_name,
                   o.billable_rate as org_billable_rate,
                   u.first_name,
                   u.last_name,
                   u.cost_rate as user_cost_rate
                   {$ticket_tags_select}
            FROM ticket_time_entries tte
            JOIN tickets t ON tte.ticket_id = t.id
            LEFT JOIN statuses s ON t.status_id = s.id
            LEFT JOIN organizations o ON t.organization_id = o.id
            LEFT JOIN users u ON tte.user_id = u.id
            WHERE 1=1";
    $params = [];

    if ($range_start && $range_end) {
        $sql .= " AND tte.started_at >= ? AND tte.started_at <= ?";
        $params[] = $range_start;
        $params[] = $range_end;
    }

    $org_ids = array_values(array_filter($selected_orgs, function ($id) {
        return $id > 0;
    }));
    $include_none_org = in_array(0, $selected_orgs, true);
    if (!empty($org_ids) || $include_none_org) {
        $conditions = [];
        if (!empty($org_ids)) {
            $placeholders = implode(',', array_fill(0, count($org_ids), '?'));
            $conditions[] = "t.organization_id IN ($placeholders)";
            foreach ($org_ids as $org_id) {
                $params[] = $org_id;
            }
        }
        if ($include_none_org) {
            $conditions[] = "t.organization_id IS NULL";
        }
        $sql .= " AND (" . implode(' OR ', $conditions) . ")";
    }

    $agent_ids = array_values(array_filter($selected_agents, function ($id) {
        return $id > 0;
    }));
    if (!empty($agent_ids)) {
        $placeholders = implode(',', array_fill(0, count($agent_ids), '?'));
        $sql .= " AND tte.user_id IN ($placeholders)";
        foreach ($agent_ids as $agent_id) {
            $params[] = $agent_id;
        }
    }

    // Non-admin agents can only see their own time entries
    if (!is_admin()) {
        $sql .= " AND tte.user_id = ?";
        $params[] = current_user()['id'];
    }

    if ($tags_supported && !empty($selected_tags)) {
        $tag_conditions = [];
        foreach ($selected_tags as $tag) {
            $tag_conditions[] = "FIND_IN_SET(?, REPLACE(IFNULL(t.tags, ''), ', ', ',')) > 0";
            $params[] = $tag;
        }
        $sql .= " AND (" . implode(' OR ', $tag_conditions) . ")";
    }

    $sql .= " ORDER BY tte.started_at DESC, tte.id DESC LIMIT 10000";
    $entries = db_fetch_all($sql, $params);

    foreach ($entries as &$entry) {
        if (empty($entry['ended_at']) && !empty($entry['started_at'])) {
            $actual_minutes = max(0, (int) floor(calculate_timer_elapsed($entry) / 60));
        } else {
            $actual_minutes = (int) $entry['duration_minutes'];
        }

        // Determine source
        $source = function_exists('get_time_entry_source') ? get_time_entry_source($entry) : (!empty($entry['is_manual']) ? 'manual' : 'timer');
        $entry['_source'] = $source;

        $ticket_custom_billable_rate = ($entry['ticket_custom_billable_rate'] ?? null);
        $has_ticket_custom_rate = $ticket_custom_billable_rate !== null && $ticket_custom_billable_rate !== '';

        $billable_rate = isset($entry['billable_rate']) ? (float) $entry['billable_rate'] : 0.0;
        if ($has_ticket_custom_rate) {
            $billable_rate = (float) $ticket_custom_billable_rate;
        } elseif ($billable_rate <= 0 && isset($entry['org_billable_rate'])) {
            $billable_rate = (float) $entry['org_billable_rate'];
        }

        $cost_rate = isset($entry['cost_rate']) ? (float) $entry['cost_rate'] : 0.0;
        if ($cost_rate <= 0 && isset($entry['user_cost_rate'])) {
            $cost_rate = (float) $entry['user_cost_rate'];
        }

        $billable_minutes = !empty($entry['is_billable']) ? round_minutes_nearest($actual_minutes, $rounding) : 0;
        $billable_amount = ($billable_minutes / 60) * $billable_rate;
        $cost_amount = ($actual_minutes / 60) * $cost_rate;
        $profit = $billable_amount - $cost_amount;

        $entry['actual_minutes'] = $actual_minutes;
        $entry['billable_minutes'] = $billable_minutes;
        $entry['billable_rate'] = $billable_rate;
        $entry['cost_rate'] = $cost_rate;
        $entry['billable_amount'] = $billable_amount;
        $entry['cost_amount'] = $cost_amount;
        $entry['profit'] = $profit;

        $totals['minutes'] += $actual_minutes;
        $totals['billable_minutes'] += $billable_minutes;
        $totals['billable_amount'] += $billable_amount;
        $totals['cost_amount'] += $cost_amount;
        $totals['profit'] += $profit;

        // Human vs AI breakdown (v0.3.1)
        if (in_array((int)$entry['user_id'], $_ai_user_ids, true)) {
            $totals['ai_minutes'] = ($totals['ai_minutes'] ?? 0) + $actual_minutes;
            $totals['ai_billable'] = ($totals['ai_billable'] ?? 0) + $billable_amount;
            $totals['ai_cost'] = ($totals['ai_cost'] ?? 0) + $cost_amount;
        } else {
            $totals['human_minutes'] = ($totals['human_minutes'] ?? 0) + $actual_minutes;
            $totals['human_billable'] = ($totals['human_billable'] ?? 0) + $billable_amount;
            $totals['human_cost'] = ($totals['human_cost'] ?? 0) + $cost_amount;
        }

        $org_id = $entry['organization_id'] ?? 0;
        $org_key = (string) $org_id;
        if (!isset($by_org[$org_key])) {
            $by_org[$org_key] = [
                'id' => $org_id,
                'name' => $entry['organization_name'] ?: t('-- No organization --'),
                'rate' => $billable_rate,
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0
            ];
        }
        $by_org[$org_key]['minutes'] += $actual_minutes;
        $by_org[$org_key]['billable_minutes'] += $billable_minutes;
        $by_org[$org_key]['billable_amount'] += $billable_amount;
        $by_org[$org_key]['cost_amount'] += $cost_amount;
        $by_org[$org_key]['profit'] += $profit;

        $agent_id = $entry['user_id'];
        $agent_key = (string) $agent_id;
        if (!isset($by_agent[$agent_key])) {
            $by_agent[$agent_key] = [
                'id' => $agent_id,
                'name' => trim($entry['first_name'] . ' ' . $entry['last_name']),
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0
            ];
        }
        $by_agent[$agent_key]['minutes'] += $actual_minutes;
        $by_agent[$agent_key]['billable_minutes'] += $billable_minutes;
        $by_agent[$agent_key]['billable_amount'] += $billable_amount;
        $by_agent[$agent_key]['cost_amount'] += $cost_amount;
        $by_agent[$agent_key]['profit'] += $profit;

        $ticket_key = (string) $entry['ticket_id'];
        if (!isset($by_ticket[$ticket_key])) {
            $by_ticket[$ticket_key] = [
                'id' => $entry['ticket_id'],
                'title' => $entry['ticket_title'],
                'organization_name' => $entry['organization_name'],
                'tags' => $entry['ticket_tags'] ?? '',
                'is_closed' => !empty($entry['ticket_is_closed']),
                'status_name' => $entry['ticket_status_name'] ?? '',
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0
            ];
        }
        $by_ticket[$ticket_key]['minutes'] += $actual_minutes;
        $by_ticket[$ticket_key]['billable_minutes'] += $billable_minutes;
        $by_ticket[$ticket_key]['billable_amount'] += $billable_amount;
        $by_ticket[$ticket_key]['cost_amount'] += $cost_amount;
        $by_ticket[$ticket_key]['profit'] += $profit;

        $week_key = date('o-W', strtotime($entry['started_at']));
        if (!isset($by_week[$week_key])) {
            $week_start = new DateTime($entry['started_at']);
            $week_start->setISODate((int) $week_start->format('o'), (int) $week_start->format('W'));
            $week_end = clone $week_start;
            $week_end->modify('+6 days');
            $by_week[$week_key] = [
                'label' => $week_start->format('Y-m-d'),
                'label_formatted' => $week_start->format('M j') . ' – ' . $week_end->format('M j, Y'),
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0,
                'agents' => [],
            ];
        }
        $by_week[$week_key]['minutes'] += $actual_minutes;
        $by_week[$week_key]['billable_minutes'] += $billable_minutes;
        $by_week[$week_key]['billable_amount'] += $billable_amount;
        $by_week[$week_key]['cost_amount'] += $cost_amount;
        $by_week[$week_key]['profit'] += $profit;
        // Per-agent breakdown within week
        $wa_key = (string) $entry['user_id'];
        if (!isset($by_week[$week_key]['agents'][$wa_key])) {
            $by_week[$week_key]['agents'][$wa_key] = [
                'name' => trim($entry['first_name'] . ' ' . $entry['last_name']),
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
            ];
        }
        $by_week[$week_key]['agents'][$wa_key]['minutes'] += $actual_minutes;
        $by_week[$week_key]['agents'][$wa_key]['billable_minutes'] += $billable_minutes;
        $by_week[$week_key]['agents'][$wa_key]['billable_amount'] += $billable_amount;

        // Aggregate by source
        if (!isset($by_source[$source])) {
            $source_labels = ['timer' => t('Timer'), 'manual' => t('Manual'), 'ai' => t('AI')];
            $by_source[$source] = [
                'source' => $source,
                'label' => $source_labels[$source] ?? ucfirst($source),
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0,
                'count' => 0
            ];
        }
        $by_source[$source]['minutes'] += $actual_minutes;
        $by_source[$source]['billable_minutes'] += $billable_minutes;
        $by_source[$source]['billable_amount'] += $billable_amount;
        $by_source[$source]['cost_amount'] += $cost_amount;
        $by_source[$source]['profit'] += $profit;
        $by_source[$source]['count']++;
    }
    unset($entry);
}

// Check if any cost data exists — if all cost_amount is 0, hide cost/profit columns
$has_cost_data = abs($totals['cost_amount']) > 0.001;

// ── CSV Export ──────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && in_array($tab, ['detailed', 'worklog', 'summary'], true)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $tab . '-' . date('Y-m-d') . '.csv"');
    $csv = fopen('php://output', 'w');
    // BOM for Excel UTF-8 compatibility
    fwrite($csv, "\xEF\xBB\xBF");

    if ($tab === 'detailed') {
        $h = [t('Ticket'), t('Company')];
        if ($tags_supported) $h[] = t('Tags');
        $h = array_merge($h, [t('Duration'), t('Duration (min)'), t('Billable'), t('Agent'), t('Source'), t('Start time'), t('End time')]);
        if ($show_money) $h[] = t('Amount');
        if ($show_money && $has_cost_data) $h = array_merge($h, [t('Cost'), t('Profit')]);
        fputcsv($csv, $h);

        foreach ($entries as $e) {
            $r = [$e['ticket_title'], $e['organization_name'] ?: ''];
            if ($tags_supported) $r[] = $e['ticket_tags'] ?? '';
            $r[] = format_duration_minutes($e['actual_minutes']);
            $r[] = $e['actual_minutes'];
            $r[] = !empty($e['is_billable']) ? t('Yes') : t('No');
            $r[] = trim($e['first_name'] . ' ' . $e['last_name']);
            $r[] = $e['_source'] ?? '';
            $r[] = $e['started_at'];
            $r[] = $e['ended_at'] ?: '';
            if ($show_money) {
                $r[] = number_format($e['billable_amount'], 2, '.', '');
            }
            if ($show_money && $has_cost_data) {
                $r[] = number_format($e['cost_amount'], 2, '.', '');
                $r[] = number_format($e['profit'], 2, '.', '');
            }
            fputcsv($csv, $r);
        }
    } elseif ($tab === 'worklog') {
        fputcsv($csv, [t('Date'), t('Ticket'), t('Subject'), t('Company'), t('User'), t('Billable'), t('Start'), t('End'), t('Duration'), t('Duration (min)')]);
        foreach ($entries as $e) {
            fputcsv($csv, [
                date('Y-m-d', strtotime($e['started_at'])),
                function_exists('get_ticket_code') ? get_ticket_code($e['ticket_id']) : $e['ticket_id'],
                $e['ticket_title'],
                $e['organization_name'] ?: '',
                trim($e['first_name'] . ' ' . $e['last_name']),
                !empty($e['is_billable']) ? t('Yes') : t('No'),
                date('H:i', strtotime($e['started_at'])),
                $e['ended_at'] ? date('H:i', strtotime($e['ended_at'])) : '',
                format_duration_minutes($e['actual_minutes']),
                $e['actual_minutes'],
            ]);
        }
    } elseif ($tab === 'summary') {
        $sh = [t('Company'), t('Time'), t('Time (min)'), t('Billable time'), t('Billable (min)'), t('Amount')];
        if ($has_cost_data) $sh = array_merge($sh, [t('Cost'), t('Profit')]);
        fputcsv($csv, $sh);
        foreach ($by_org as $org) {
            $sr = [
                $org['name'],
                format_duration_minutes($org['minutes']),
                $org['minutes'],
                format_duration_minutes($org['billable_minutes']),
                $org['billable_minutes'],
                number_format($org['billable_amount'], 2, '.', ''),
            ];
            if ($has_cost_data) {
                $sr[] = number_format($org['cost_amount'], 2, '.', '');
                $sr[] = number_format($org['profit'], 2, '.', '');
            }
            fputcsv($csv, $sr);
        }
    }

    fclose($csv);
    exit;
}

$base_params = $_GET;
$base_params['page'] = 'admin';
$base_params['section'] = 'reports';

require_once BASE_PATH . '/includes/header.php';
?>
<?php
$page_header_title = $page_title;
$page_header_subtitle = t('User activity and ticket history.');
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="space-y-3">
    <!-- Client Reports Module Access (admin only) -->
    <?php if (is_admin()): ?>
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.25rem; padding: 0;">
        <span style="font-size: 0.75rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.375rem;">
            <?php echo get_icon('file-text', 'w-3.5 h-3.5'); ?>
            <?php echo e(t('Client Reports')); ?>
        </span>
        <div style="display: flex; gap: 0.5rem;">
            <a href="<?php echo url('admin', ['section' => 'reports-list']); ?>"
                class="btn btn-ghost btn-sm" style="font-size: 0.75rem; padding: 4px 10px;">
                <?php echo get_icon('list', 'w-3.5 h-3.5 mr-1 inline-block'); ?><?php echo e(t('View Reports')); ?>
            </a>
            <a href="<?php echo url('admin', ['section' => 'report-builder']); ?>"
                class="btn btn-primary btn-sm" style="font-size: 0.75rem; padding: 4px 10px;">
                <?php echo get_icon('plus', 'w-3.5 h-3.5 mr-1 inline-block'); ?><?php echo e(t('Create New Report')); ?>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
        <div style="display: flex; gap: 2px; background: var(--surface-secondary); border-radius: 8px; padding: 3px; width: fit-content;">
            <?php
            $tab_labels = [
                'summary' => t('Summary'),
                'detailed' => t('Detailed'),
                'weekly' => t('Weekly'),
                'worklog' => t('Work Log'),
            ];
            if (is_admin()) {
                $tab_labels['shared'] = t('Shared');
            }
            foreach ($tab_labels as $tab_key => $label):
                $params = $base_params;
                $params['tab'] = $tab_key;
                $tab_url = 'index.php?' . http_build_query($params);
                ?>
                <a href="<?php echo e($tab_url); ?>"
                    style="padding: 4px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 500; text-decoration: none; transition: all 0.15s;
                           <?php echo $tab === $tab_key
                               ? 'background: var(--primary); color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.1);'
                               : 'color: var(--text-secondary); background: transparent;'; ?>">
                    <?php echo e($label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (in_array($tab, ['detailed', 'worklog', 'summary'], true) && !empty($entries)): ?>
        <div style="display: flex; align-items: center; gap: 0.375rem;">
            <?php if ($tab === 'detailed'): ?>
            <!-- Column Picker -->
            <div class="relative" id="col-picker-wrap">
                <button type="button" onclick="document.getElementById('col-picker-dropdown').classList.toggle('hidden')"
                    style="display: inline-flex; align-items: center; gap: 3px; padding: 3px 8px; font-size: 0.6875rem; border-radius: 6px; border: 1px solid var(--border-light); background: var(--surface-secondary); color: var(--text-secondary); cursor: pointer;"
                    title="<?php echo e(t('Columns')); ?>">
                    <?php echo get_icon('columns', 'w-3 h-3 inline-block'); ?><?php echo e(t('Columns')); ?>
                </button>
                <div id="col-picker-dropdown" class="hidden absolute right-0 mt-1 w-44 rounded-lg shadow-lg border z-50 p-1.5"
                     style="background: var(--bg-primary); border-color: var(--border-light);">
                    <?php
                    $col_defs = [
                        'ticket' => t('Ticket'),
                        'company' => t('Company'),
                    ];
                    if ($tags_supported) $col_defs['tags'] = t('Tags');
                    $col_defs += [
                        'duration' => t('Duration'),
                        'billable' => t('Billable'),
                        'agent' => t('Agent'),
                        'source' => t('Source'),
                        'start' => t('Start time'),
                        'end' => t('End time'),
                    ];
                    if ($show_money) {
                        $col_defs['amount'] = t('Amount');
                        $col_defs['cost'] = t('Cost');
                        $col_defs['profit'] = t('Profit');
                    }
                    foreach ($col_defs as $col_key => $col_label): ?>
                    <label class="flex items-center gap-2 px-2 py-1 text-xs rounded cursor-pointer" style="color: var(--text-primary);">
                        <input type="checkbox" class="rounded col-toggle" data-col="<?php echo e($col_key); ?>" checked>
                        <?php echo e($col_label); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Export CSV -->
            <?php
            $export_params = $base_params;
            $export_params['export'] = 'csv';
            ?>
            <a href="index.php?<?php echo http_build_query($export_params); ?>"
                style="display: inline-flex; align-items: center; gap: 3px; padding: 3px 8px; font-size: 0.6875rem; border-radius: 6px; border: 1px solid var(--border-light); background: var(--surface-secondary); color: var(--text-secondary); text-decoration: none;"
                title="<?php echo e(t('Export CSV')); ?>">
                <?php echo get_icon('download', 'w-3 h-3 inline-block'); ?><?php echo e(t('Export CSV')); ?>
            </a>

            <!-- Print -->
            <button type="button" onclick="window.print()"
                style="display: inline-flex; align-items: center; gap: 3px; padding: 3px 8px; font-size: 0.6875rem; border-radius: 6px; border: 1px solid var(--border-light); background: var(--surface-secondary); color: var(--text-secondary); cursor: pointer;"
                title="<?php echo e(t('Print')); ?>">
                <?php echo get_icon('print', 'w-3 h-3 inline-block'); ?><?php echo e(t('Print')); ?>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$time_tracking_available): ?>
        <div class="card card-body" style="color: var(--text-secondary);">
            <?php echo e(t('Time tracking is not available.')); ?>
        </div>
    <?php else: ?>
        <?php if ($tab !== 'shared'): ?>
            <?php
            // Compute active filters for pills display
            $active_filters = [];
            $time_range_labels = [
                'today' => t('Today'), 'yesterday' => t('Yesterday'),
                'last_7_days' => t('Last 7 days'), 'last_30_days' => t('Last 30 days'),
                'this_week' => t('This week'), 'last_week' => t('Last week'),
                'this_month' => t('This month'), 'last_month' => t('Last month'),
                'this_quarter' => t('This quarter'), 'last_quarter' => t('Last quarter'),
                'this_year' => t('This year'), 'last_year' => t('Last year'),
                'custom' => ($from_date && $to_date) ? $from_date . ' – ' . $to_date : t('Custom range'),
            ];
            if ($time_range !== 'all' && $time_range !== 'this_month') {
                $active_filters[] = ['type' => 'time_range', 'label' => $time_range_labels[$time_range] ?? $time_range, 'param' => 'time_range'];
            }
            foreach ($selected_orgs as $oid) {
                foreach ($organizations as $o) {
                    if ((int) $o['id'] === $oid) {
                        $active_filters[] = ['type' => 'org', 'label' => $o['name'], 'id' => $oid];
                        break;
                    }
                }
            }
            foreach ($selected_agents as $aid) {
                foreach ($agents as $a) {
                    if ((int) $a['id'] === $aid) {
                        $active_filters[] = ['type' => 'agent', 'label' => trim($a['first_name'] . ' ' . $a['last_name']), 'id' => $aid];
                        break;
                    }
                }
            }
            foreach ($selected_tags as $stag) {
                $active_filters[] = ['type' => 'tag', 'label' => '#' . $stag, 'value' => $stag];
            }
            // Non-admin agents: add implicit "my entries" filter indicator
            if (!is_admin()) {
                $cu = current_user();
                $active_filters[] = ['type' => 'my_entries', 'label' => trim($cu['first_name'] . ' ' . $cu['last_name'])];
            }
            $has_active_filters = !empty($active_filters);
            $filter_collapsed = $has_active_filters; // Start collapsed when filters are applied
            ?>
            <?php
            // Build filter summary text for collapsed header
            $filter_summary_parts = [];
            $filter_summary_parts[] = $time_range_labels[$time_range] ?? $time_range;
            if (!empty($selected_orgs)) $filter_summary_parts[] = count($selected_orgs) . ' ' . t('clients');
            if (!empty($selected_agents)) $filter_summary_parts[] = count($selected_agents) . ' ' . t('agents');
            if (!empty($selected_tags)) $filter_summary_parts[] = count($selected_tags) . ' ' . t('tags');
            $filter_summary_text = implode(' · ', $filter_summary_parts);
            ?>
            <?php if ($has_active_filters): ?>
            <div class="flex flex-wrap items-center gap-2 mb-1" id="report-filter-pills">
                <span style="font-size: 0.6875rem; font-weight: 500; color: var(--text-muted);"><?php echo e(t('Filters')); ?>:</span>
                <?php foreach ($active_filters as $af): ?>
                    <?php
                    $remove_params = $_GET;
                    if ($af['type'] === 'time_range') {
                        $remove_params['time_range'] = 'this_month';
                        unset($remove_params['from_date'], $remove_params['to_date']);
                    } elseif ($af['type'] === 'org') {
                        $remove_params['organizations'] = array_values(array_diff($selected_orgs, [$af['id']]));
                        if (empty($remove_params['organizations'])) unset($remove_params['organizations']);
                    } elseif ($af['type'] === 'agent') {
                        $remove_params['agents'] = array_values(array_diff($selected_agents, [$af['id']]));
                        if (empty($remove_params['agents'])) unset($remove_params['agents']);
                    } elseif ($af['type'] === 'tag') {
                        $remaining_tags = array_filter($selected_tags, fn($t) => $t !== $af['value']);
                        if (!empty($remaining_tags)) {
                            $remove_params['tags'] = implode(', ', $remaining_tags);
                        } else {
                            unset($remove_params['tags']);
                        }
                    }
                    $remove_url = 'index.php?' . http_build_query($remove_params);
                    ?>
                    <?php if ($af['type'] === 'my_entries'): ?>
                    <span style="display: inline-flex; align-items: center; gap: 3px; padding: 1px 8px; font-size: 0.6875rem; border-radius: 9999px; background: var(--primary-light, rgba(59,130,246,0.1)); color: var(--primary);">
                        <?php echo get_icon('user', 'w-3 h-3'); ?>
                        <?php echo e(t('My entries')); ?>: <?php echo e($af['label']); ?>
                    </span>
                    <?php else: ?>
                    <a href="<?php echo e($remove_url); ?>"
                       style="display: inline-flex; align-items: center; gap: 3px; padding: 1px 8px; font-size: 0.6875rem; border-radius: 9999px; background: var(--primary-light, rgba(59,130,246,0.1)); color: var(--primary); text-decoration: none;"
                       title="<?php echo e(t('Remove filter')); ?>">
                        <?php echo e($af['label']); ?>
                        <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <details class="card mb-2" id="report-filters" <?php echo !$filter_collapsed ? 'open' : ''; ?>>
                <summary class="card-header" style="cursor: pointer; list-style: none; display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0.75rem; user-select: none;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <?php echo get_icon('sliders-horizontal', 'w-3.5 h-3.5'); ?>
                        <span style="font-size: 0.8125rem; font-weight: 600;"><?php echo e(t('Filters')); ?></span>
                        <span style="font-size: 0.6875rem; color: var(--text-muted);"><?php echo e($filter_summary_text); ?></span>
                    </div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-muted); transition: transform 0.2s;" class="rpt-chevron"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </summary>
                <div style="padding: 0.5rem 0.75rem 0.75rem;">
                <form method="get">
                    <input type="hidden" name="page" value="admin">
                    <input type="hidden" name="section" value="reports">
                    <input type="hidden" name="tab" value="<?php echo e($tab); ?>">

                    <!-- Row 1: All filter fields on one horizontal line -->
                    <div style="display: flex; align-items: flex-end; gap: 0.75rem; flex-wrap: nowrap;">
                        <div style="flex: 1; min-width: 0;">
                            <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);"><?php echo e(t('Clients')); ?></label>
                            <div class="chip-select" id="cs-orgs">
                                <div class="chip-select__wrap" id="cs-orgs-wrap">
                                    <div class="chip-select__chips" id="cs-orgs-chips"></div>
                                    <input type="text" class="chip-select__input" id="cs-orgs-input"
                                           placeholder="<?php echo e(t('Type to filter...')); ?>" autocomplete="off">
                                </div>
                                <div class="chip-select__dropdown hidden" id="cs-orgs-dropdown"></div>
                                <div id="cs-orgs-hidden"></div>
                            </div>
                        </div>

                        <?php if (is_admin()): ?>
                        <div style="flex: 1; min-width: 0;">
                            <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);"><?php echo e(t('Agents')); ?></label>
                            <div class="chip-select" id="cs-agents">
                                <div class="chip-select__wrap" id="cs-agents-wrap">
                                    <div class="chip-select__chips" id="cs-agents-chips"></div>
                                    <input type="text" class="chip-select__input" id="cs-agents-input"
                                           placeholder="<?php echo e(t('Type to filter...')); ?>" autocomplete="off">
                                </div>
                                <div class="chip-select__dropdown hidden" id="cs-agents-dropdown"></div>
                                <div id="cs-agents-hidden"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($tags_supported): ?>
                        <div style="flex: 1; min-width: 0;">
                            <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);">
                                <?php echo e(t('Tags')); ?>
                                <span style="font-weight: 400; color: var(--text-muted); font-size: 0.625rem; margin-left: 4px;"><?php echo e(t('OR matching')); ?></span>
                            </label>
                            <input type="hidden" name="tags" id="rpt-tags-value" value="<?php echo e($selected_tags_csv); ?>">
                            <div class="chip-select" id="cs-tags">
                                <div class="chip-select__wrap" id="cs-tags-wrap">
                                    <div class="chip-select__chips" id="cs-tags-chips"></div>
                                    <input type="text" class="chip-select__input" id="cs-tags-input"
                                           placeholder="<?php echo e(t('Type to filter...')); ?>" autocomplete="off">
                                </div>
                                <div class="chip-select__dropdown hidden" id="cs-tags-dropdown"></div>
                                <div id="cs-tags-hidden"></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div style="flex: 1; min-width: 0;">
                            <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);"><?php echo e(t('Time range')); ?></label>
                            <select name="time_range" id="report-time-range" class="form-select" style="width: 100%;">
                                <option value="all" <?php echo $time_range === 'all' ? 'selected' : ''; ?>>
                                    <?php echo e(t('All time')); ?></option>
                                <option value="today" <?php echo $time_range === 'today' ? 'selected' : ''; ?>>
                                    <?php echo e(t('Today')); ?></option>
                                <option value="yesterday" <?php echo $time_range === 'yesterday' ? 'selected' : ''; ?>>
                                    <?php echo e(t('Yesterday')); ?></option>
                                <option value="last_7_days" <?php echo $time_range === 'last_7_days' ? 'selected' : ''; ?>>
                                    <?php echo e(t('Last 7 days')); ?></option>
                                <option value="last_30_days" <?php echo $time_range === 'last_30_days' ? 'selected' : ''; ?>>
                                    <?php echo e(t('Last 30 days')); ?></option>
                                <option value="this_week" <?php echo $time_range === 'this_week' ? 'selected' : ''; ?>>
                                    <?php echo e(t('This week')); ?></option>
                                <option value="last_week" <?php echo $time_range === 'last_week' ? 'selected' : ''; ?>>
                                    <?php echo e(t('Last week')); ?></option>
                                <option value="this_month" <?php echo $time_range === 'this_month' ? 'selected' : ''; ?>>
                                    <?php echo e(t('This month')); ?></option>
                                <option value="last_month" <?php echo $time_range === 'last_month' ? 'selected' : ''; ?>>
                                    <?php echo e(t('Last month')); ?></option>
                                <option value="this_quarter" <?php echo $time_range === 'this_quarter' ? 'selected' : ''; ?>>
                                    <?php echo e(t('This quarter')); ?></option>
                                <option value="last_quarter" <?php echo $time_range === 'last_quarter' ? 'selected' : ''; ?>>
                                    <?php echo e(t('Last quarter')); ?></option>
                                <option value="this_year" <?php echo $time_range === 'this_year' ? 'selected' : ''; ?>>
                                    <?php echo e(t('This year')); ?></option>
                                <option value="last_year" <?php echo $time_range === 'last_year' ? 'selected' : ''; ?>>
                                    <?php echo e(t('Last year')); ?></option>
                                <option value="custom" <?php echo $time_range === 'custom' ? 'selected' : ''; ?>>
                                    <?php echo e(t('Custom range')); ?></option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 2: Date hint, presets, show amounts, apply -->
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem; flex-wrap: wrap;">
                        <?php if ($range_start && $range_end && $time_range !== 'custom' && $time_range !== 'all'): ?>
                        <span id="report-range-hint" style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.6875rem; color: var(--text-muted);">
                            <?php echo get_icon('calendar', 'w-3 h-3 inline-block'); ?>
                            <?php echo date('M j', strtotime($range_start)); ?> – <?php echo date('M j, Y', strtotime($range_end)); ?>
                        </span>
                        <?php endif; ?>

                        <?php if (is_admin()): ?>
                        <label style="display: inline-flex; align-items: center; font-size: 0.75rem; color: var(--text-secondary); gap: 4px;">
                            <input type="checkbox" name="show_money" value="1" class="rounded" <?php echo $show_money ? 'checked' : ''; ?>>
                            <?php echo e(t('Show amounts')); ?>
                        </label>
                        <?php endif; ?>

                        <!-- Quick range presets -->
                        <div style="display: flex; gap: 3px; margin-left: auto;">
                            <?php
                            $quick_presets = [
                                'today' => t('Today'),
                                'this_week' => t('This week'),
                                'this_month' => t('This month'),
                                'last_month' => t('Last month'),
                                'this_quarter' => t('Q' . ceil(date('n') / 3)),
                            ];
                            foreach ($quick_presets as $preset_val => $preset_label): ?>
                            <button type="button"
                                class="range-preset-btn"
                                data-range="<?php echo e($preset_val); ?>"
                                style="padding: 2px 8px; font-size: 0.6875rem; border-radius: 9999px; border: none; cursor: pointer; transition: all 0.15s;
                                       <?php echo $time_range === $preset_val
                                           ? 'background: var(--primary); color: #fff;'
                                           : 'background: var(--surface-secondary); color: var(--text-muted);'; ?>"
                                onclick="setTimeRange('<?php echo e($preset_val); ?>')">
                                <?php echo e($preset_label); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" id="report-apply-btn" class="btn btn-primary btn-sm"><?php echo e(t('Apply')); ?></button>
                    </div>

                    <!-- Custom date range (shown only when "Custom range" selected) -->
                    <div id="report-custom-range"
                        style="display: flex; align-items: flex-end; gap: 0.75rem; margin-top: 0.5rem; <?php echo $time_range === 'custom' ? '' : 'display: none;'; ?>">
                        <div>
                            <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);"><?php echo e(t('From date')); ?></label>
                            <input type="date" name="from_date" value="<?php echo e($from_date); ?>" class="form-input">
                        </div>
                        <div>
                            <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);"><?php echo e(t('To date')); ?></label>
                            <input type="date" name="to_date" value="<?php echo e($to_date); ?>" class="form-input">
                        </div>
                    </div>

                    <!-- Confirmation overlay (hidden) -->
                    <div id="report-confirm" class="report-confirm hidden" style="flex-basis: 100%;">
                        <div class="report-confirm__title"><?php echo e(t('Generate report with these filters?')); ?></div>
                        <div id="report-confirm-body"></div>
                        <div class="report-confirm__actions">
                            <button type="button" id="report-confirm-back" class="btn btn-sm"
                                    style="background: var(--surface-tertiary); color: var(--text-primary);"><?php echo e(t('Back')); ?></button>
                            <button type="submit" class="btn btn-primary btn-sm"><?php echo e(t('Generate Report')); ?></button>
                        </div>
                    </div>
                </form>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($tab === 'summary'): ?>
            <div style="display: flex; border: 1px solid var(--border-light); border-radius: 8px; margin-bottom: 0.75rem; overflow: hidden; background: var(--surface-primary);">
                <div style="flex: 1; padding: 8px 14px;">
                    <div style="font-size: 0.5625rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 2px;"><?php echo e(is_admin() ? t('Total time') : t('My time')); ?></div>
                    <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.01em;"><?php echo e(format_duration_minutes($totals['minutes'])); ?></div>
                </div>
                <div style="flex: 1; padding: 8px 14px; border-left: 1px solid var(--border-light);">
                    <div style="font-size: 0.5625rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 2px;"><?php echo e(is_admin() ? t('Billable time') : t('My billable time')); ?></div>
                    <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.01em;"><?php echo e(format_duration_minutes($totals['billable_minutes'])); ?></div>
                </div>
                <?php if ($show_money): ?>
                <div style="flex: 1; padding: 8px 14px; border-left: 1px solid var(--border-light);">
                    <div style="font-size: 0.5625rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 2px;"><?php echo e(t('Billable amount')); ?></div>
                    <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.01em;"><?php echo e(format_money($totals['billable_amount'])); ?></div>
                </div>
                <?php if ($has_cost_data): ?>
                <div style="flex: 1; padding: 8px 14px; border-left: 1px solid var(--border-light);">
                    <div style="font-size: 0.5625rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 2px;"><?php echo e(t('Cost')); ?></div>
                    <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.01em;"><?php echo e(format_money($totals['cost_amount'])); ?></div>
                </div>
                <div style="flex: 1; padding: 8px 14px; border-left: 1px solid var(--border-light);">
                    <div style="font-size: 0.5625rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 2px;"><?php echo e(t('Profit')); ?></div>
                    <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.01em;"><?php echo e(format_money($totals['profit'])); ?></div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php
            $human_min = $totals['human_minutes'] ?? 0;
            $ai_min = $totals['ai_minutes'] ?? 0;
            if ($human_min > 0 && $ai_min > 0):
            ?>
            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.75rem;">
                <div style="flex: 1; padding: 6px 12px; border-left: 3px solid #60a5fa; border-radius: 6px; background: var(--surface-secondary);">
                    <div style="font-size: 0.6875rem; color: var(--text-muted); display: flex; align-items: center; gap: 4px;">
                        <?php echo get_icon('user', 'w-3 h-3'); ?>
                        <?php echo e(t('Human')); ?>
                    </div>
                    <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);"><?php echo e(format_duration_minutes($human_min)); ?></div>
                    <?php if ($show_money): ?>
                        <div style="font-size: 0.625rem; color: var(--text-muted); margin-top: 1px;">
                            <?php echo e(t('Billable')); ?>: <?php echo e(format_money($totals['human_billable'] ?? 0)); ?>
                            <?php if ($has_cost_data): ?>
                            · <?php echo e(t('Cost')); ?>: <?php echo e(format_money($totals['human_cost'] ?? 0)); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="flex: 1; padding: 6px 12px; border-left: 3px solid #a78bfa; border-radius: 6px; background: var(--surface-secondary);">
                    <div style="font-size: 0.6875rem; color: var(--text-muted); display: flex; align-items: center; gap: 4px;">
                        <?php echo get_icon('bot', 'w-3 h-3'); ?>
                        <?php echo e(t('AI')); ?>
                    </div>
                    <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);"><?php echo e(format_duration_minutes($ai_min)); ?></div>
                    <?php if ($show_money): ?>
                        <div style="font-size: 0.625rem; color: var(--text-muted); margin-top: 1px;">
                            <?php echo e(t('Billable')); ?>: <?php echo e(format_money($totals['ai_billable'] ?? 0)); ?>
                            <?php if ($has_cost_data): ?>
                            · <?php echo e(t('Cost')); ?>: <?php echo e(format_money($totals['ai_cost'] ?? 0)); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($entries)): ?>
                <div class="card card-body p-8 text-center">
                    <div class="text-4xl mb-3" style="color: var(--text-muted);">📊</div>
                    <div class="font-semibold mb-1" style="color: var(--text-primary);"><?php echo e(t('No time entries found')); ?></div>
                    <div class="text-sm" style="color: var(--text-muted);"><?php echo e(t('Try adjusting the time range or filters above.')); ?></div>
                </div>
            <?php else: ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
                <div class="card overflow-hidden">
                    <div class="card-header" style="border-color: var(--border-light); padding: 0.5rem 0.75rem;">
                        <h3 style="font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary);"><?php echo e(t('Company')); ?></h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full data-table">
                            <thead>
                                <tr>
                                    <th><?php echo e(t('Company')); ?></th>
                                    <th><?php echo e(t('Time')); ?></th>
                                    <th><?php echo e(t('Billable time')); ?></th>
                                    <?php if ($show_money): ?>
                                        <th><?php echo e(t('Billable rate')); ?></th>
                                        <th><?php echo e(t('Amount')); ?></th>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <th><?php echo e(t('Profit')); ?></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($by_org as $org):
                                    $org_pct = $totals['minutes'] > 0 ? round(($org['minutes'] / $totals['minutes']) * 100) : 0;
                                ?>
                                    <tr>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-primary);"><?php echo e($org['name']); ?></td>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                            <?php echo e(format_duration_minutes($org['minutes'])); ?>
                                            <div class="flex items-center gap-1.5 mt-1">
                                                <div style="width: 50px; height: 4px; background: var(--border-light); border-radius: 2px; flex-shrink: 0;">
                                                    <div style="width: <?php echo $org_pct; ?>%; height: 100%; background: var(--primary); border-radius: 2px;"></div>
                                                </div>
                                                <span class="text-xs" style="color: var(--text-muted);"><?php echo $org_pct; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                            <?php echo e(format_duration_minutes($org['billable_minutes'])); ?></td>
                                        <?php if ($show_money): ?>
                                            <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);"><?php echo e(format_money($org['rate'])); ?></td>
                                            <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                                <?php echo e(format_money($org['billable_amount'])); ?></td>
                                        <?php endif; ?>
                                        <?php if ($show_money && $has_cost_data): ?>
                                            <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);"><?php echo e(format_money($org['profit'])); ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (is_admin()): ?>
                <div class="card overflow-hidden">
                    <div class="card-header" style="padding: 0.5rem 0.75rem;">
                        <h3 style="font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary);"><?php echo e(t('Agents')); ?></h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead style="background: var(--surface-secondary);">
                                <tr>
                                    <th class="px-3 py-2 text-left th-label">
                                        <?php echo e(t('Agent')); ?></th>
                                    <th class="px-3 py-2 text-left th-label">
                                        <?php echo e(t('Time')); ?></th>
                                    <th class="px-3 py-2 text-left th-label">
                                        <?php echo e(t('Billable time')); ?></th>
                                    <?php if ($show_money): ?>
                                        <th class="px-3 py-2 text-left th-label">
                                            <?php echo e(t('Amount')); ?></th>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <th class="px-3 py-2 text-left th-label">
                                            <?php echo e(t('Profit')); ?></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($by_agent as $agent):
                                    $agent_pct = $totals['minutes'] > 0 ? round(($agent['minutes'] / $totals['minutes']) * 100) : 0;
                                ?>
                                    <tr>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-primary);"><?php echo e($agent['name']); ?></td>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                            <?php echo e(format_duration_minutes($agent['minutes'])); ?>
                                            <div class="flex items-center gap-1.5 mt-1">
                                                <div style="width: 50px; height: 4px; background: var(--border-light); border-radius: 2px; flex-shrink: 0;">
                                                    <div style="width: <?php echo $agent_pct; ?>%; height: 100%; background: #8b5cf6; border-radius: 2px;"></div>
                                                </div>
                                                <span class="text-xs" style="color: var(--text-muted);"><?php echo $agent_pct; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                            <?php echo e(format_duration_minutes($agent['billable_minutes'])); ?></td>
                                        <?php if ($show_money): ?>
                                            <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                                <?php echo e(format_money($agent['billable_amount'])); ?></td>
                                        <?php endif; ?>
                                        <?php if ($show_money && $has_cost_data): ?>
                                            <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);"><?php echo e(format_money($agent['profit'])); ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($by_source) && count($by_source) > 1): ?>
            <div class="card overflow-hidden">
                <div class="card-header" style="border-color: var(--border-light);">
                    <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Source')); ?></h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full data-table">
                        <thead>
                            <tr>
                                <th><?php echo e(t('Source')); ?></th>
                                <th><?php echo e(t('Entries')); ?></th>
                                <th><?php echo e(t('Time')); ?></th>
                                <th><?php echo e(t('Billable time')); ?></th>
                                <?php if ($show_money): ?>
                                    <th><?php echo e(t('Amount')); ?></th>
                                <?php endif; ?>
                                <?php if ($show_money && $has_cost_data): ?>
                                    <th><?php echo e(t('Profit')); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($by_source as $src):
                                $src_pct = $totals['minutes'] > 0 ? round(($src['minutes'] / $totals['minutes']) * 100) : 0;
                            ?>
                                <tr>
                                    <td class="px-3 py-1.5 text-xs"><?php echo function_exists('render_source_badge') ? render_source_badge($src['source']) : e($src['label']); ?></td>
                                    <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);"><?php echo (int) $src['count']; ?></td>
                                    <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                        <?php echo e(format_duration_minutes($src['minutes'])); ?>
                                        <div class="flex items-center gap-1.5 mt-1">
                                            <div style="width: 50px; height: 4px; background: var(--border-light); border-radius: 2px; flex-shrink: 0;">
                                                <div style="width: <?php echo $src_pct; ?>%; height: 100%; background: #10b981; border-radius: 2px;"></div>
                                            </div>
                                            <span class="text-xs" style="color: var(--text-muted);"><?php echo $src_pct; ?>%</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);"><?php echo e(format_duration_minutes($src['billable_minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);"><?php echo e(format_money($src['billable_amount'])); ?></td>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);"><?php echo e(format_money($src['profit'])); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // Separate open and closed tickets
            $open_tickets = array_filter($by_ticket, function ($t) { return empty($t['is_closed']); });
            $closed_tickets_report = array_filter($by_ticket, function ($t) { return !empty($t['is_closed']); });
            ?>
            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Tickets')); ?></h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead style="background: var(--surface-secondary);">
                            <tr>
                                <th class="px-3 py-2 text-left th-label">
                                    <?php echo e(t('Ticket')); ?></th>
                                <th class="px-3 py-2 text-left th-label">
                                    <?php echo e(t('Company')); ?></th>
                                <?php if ($tags_supported): ?>
                                    <th class="px-3 py-2 text-left th-label">
                                        <?php echo e(t('Tags')); ?></th>
                                <?php endif; ?>
                                <th class="px-3 py-2 text-left th-label">
                                    <?php echo e(t('Time')); ?></th>
                                <?php if ($show_money): ?>
                                    <th class="px-3 py-2 text-left th-label">
                                        <?php echo e(t('Amount')); ?></th>
                                <?php endif; ?>
                                <?php if ($show_money && $has_cost_data): ?>
                                    <th class="px-3 py-2 text-left th-label">
                                        <?php echo e(t('Profit')); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($open_tickets as $tid => $ticket): ?>
                                <tr>
                                    <td class="px-3 py-1.5 text-xs"><a href="<?php echo url('ticket', ['id' => $tid]); ?>" class="text-blue-600 hover:text-blue-800 hover:underline"><?php echo e($ticket['title']); ?></a></td>
                                    <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                        <?php echo e($ticket['organization_name'] ?: t('-- No organization --')); ?></td>
                                    <?php if ($tags_supported): ?>
                                        <td class="px-3 py-1.5 text-xs">
                                            <?php $row_tags = function_exists('get_ticket_tags_array') ? get_ticket_tags_array($ticket['tags'] ?? '') : []; ?>
                                            <?php if (!empty($row_tags)): ?>
                                                <div class="flex flex-wrap gap-1">
                                                    <?php foreach (array_slice($row_tags, 0, 4) as $tag): ?>
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded tag-badge text-xs">#<?php echo e($tag); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                        <?php echo e(format_duration_minutes($ticket['minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                            <?php echo e(format_money($ticket['billable_amount'])); ?></td>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);"><?php echo e(format_money($ticket['profit'])); ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($closed_tickets_report)): ?>
                        <tbody class="border-t-2" style="border-top-color: var(--border-light);">
                            <tr class="cursor-pointer" style="background: var(--surface-secondary);" onclick="document.getElementById('closed-tickets-report').classList.toggle('hidden')">
                                <?php $report_colspan = 3 + ($tags_supported ? 1 : 0) + ($show_money ? 1 : 0) + ($show_money && $has_cost_data ? 1 : 0); ?>
                                <td colspan="<?php echo $report_colspan; ?>" class="px-6 py-2 font-medium text-xs text-center text-gray-500 hover:text-gray-700">
                                    <?php echo e(t('Closed')); ?> (<?php echo count($closed_tickets_report); ?>)
                                </td>
                            </tr>
                        </tbody>
                        <tbody id="closed-tickets-report" class="hidden divide-y">
                            <?php foreach ($closed_tickets_report as $tid => $ticket): ?>
                                <tr style="opacity: 0.7;">
                                    <td class="px-3 py-1.5 text-xs"><a href="<?php echo url('ticket', ['id' => $tid]); ?>" class="text-blue-600 hover:text-blue-800 hover:underline"><?php echo e($ticket['title']); ?></a></td>
                                    <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                        <?php echo e($ticket['organization_name'] ?: t('-- No organization --')); ?></td>
                                    <?php if ($tags_supported): ?>
                                        <td class="px-3 py-1.5 text-xs">
                                            <?php $row_tags = function_exists('get_ticket_tags_array') ? get_ticket_tags_array($ticket['tags'] ?? '') : []; ?>
                                            <?php if (!empty($row_tags)): ?>
                                                <div class="flex flex-wrap gap-1">
                                                    <?php foreach (array_slice($row_tags, 0, 4) as $tag): ?>
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded tag-badge text-xs">#<?php echo e($tag); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                        <?php echo e(format_duration_minutes($ticket['minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                            <?php echo e(format_money($ticket['billable_amount'])); ?></td>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);"><?php echo e(format_money($ticket['profit'])); ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        <?php elseif ($tab === 'weekly'): ?>
            <?php if (empty($by_week)): ?>
                <div class="card card-body p-8 text-center">
                    <div class="text-4xl mb-3" style="color: var(--text-muted);">📅</div>
                    <div class="font-semibold mb-1" style="color: var(--text-primary);"><?php echo e(t('No time entries found')); ?></div>
                    <div class="text-sm" style="color: var(--text-muted);"><?php echo e(t('Try adjusting the time range or filters above.')); ?></div>
                </div>
            <?php else: ?>
            <?php
            // Compute max minutes across all weeks for bar scaling
            $weekly_max_minutes = 0;
            foreach ($by_week as $w) {
                if ($w['minutes'] > $weekly_max_minutes) $weekly_max_minutes = $w['minutes'];
            }
            // Assign consistent agent colors
            $weekly_agent_colors = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#ec4899','#06b6d4','#84cc16'];
            $weekly_agent_ids = [];
            foreach ($by_week as $w) {
                foreach (array_keys($w['agents']) as $aid) {
                    if (!in_array($aid, $weekly_agent_ids)) $weekly_agent_ids[] = $aid;
                }
            }
            $weekly_agent_color_map = [];
            foreach ($weekly_agent_ids as $ci => $aid) {
                $weekly_agent_color_map[$aid] = $weekly_agent_colors[$ci % count($weekly_agent_colors)];
            }
            $weekly_col_count = 3 + ($show_money ? 1 : 0) + ($show_money && $has_cost_data ? 1 : 0);
            ?>
            <div class="card overflow-hidden">
                <div class="card-header flex items-center justify-between">
                    <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Weekly')); ?></h3>
                    <?php if (count($weekly_agent_ids) > 1): ?>
                    <div class="flex flex-wrap items-center gap-3">
                        <?php foreach ($weekly_agent_ids as $aid): ?>
                            <?php $aname = ''; foreach ($by_week as $w) { if (isset($w['agents'][$aid])) { $aname = $w['agents'][$aid]['name']; break; } } ?>
                            <div class="flex items-center gap-1.5 text-xs" style="color: var(--text-secondary);">
                                <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?php echo $weekly_agent_color_map[$aid]; ?>;"></span>
                                <?php echo e($aname); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead style="background: var(--surface-secondary);">
                            <tr>
                                <th class="px-3 py-2 text-left th-label">
                                    <?php echo e(t('Week')); ?></th>
                                <th class="px-3 py-2 text-left th-label" style="min-width:180px;">
                                    <?php echo e(t('Time')); ?></th>
                                <th class="px-3 py-2 text-left th-label">
                                    <?php echo e(t('Billable time')); ?></th>
                                <?php if ($show_money): ?>
                                    <th class="px-3 py-2 text-left th-label">
                                        <?php echo e(t('Amount')); ?></th>
                                <?php endif; ?>
                                <?php if ($show_money && $has_cost_data): ?>
                                    <th class="px-3 py-2 text-left th-label">
                                        <?php echo e(t('Profit')); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php $wi = 0; foreach ($by_week as $wk => $week): $wi++; ?>
                                <tr class="cursor-pointer hover:bg-opacity-50" style="transition:background .15s;" onclick="toggleWeekAgents('week-agents-<?php echo $wi; ?>')">
                                    <td class="px-6 py-3">
                                        <div class="text-sm font-medium" style="color: var(--text-primary);"><?php echo e($week['label_formatted']); ?></div>
                                    </td>
                                    <td class="px-6 py-3">
                                        <div class="text-sm" style="color: var(--text-secondary);">
                                            <?php echo e(format_duration_minutes($week['minutes'])); ?>
                                        </div>
                                        <?php if ($weekly_max_minutes > 0): ?>
                                        <div style="display:flex;height:6px;border-radius:3px;overflow:hidden;background:var(--border-light);margin-top:4px;width:100%;max-width:160px;" title="<?php
                                            $parts = [];
                                            // Sort agents by minutes desc for this week
                                            $wa_sorted = $week['agents'];
                                            uasort($wa_sorted, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
                                            foreach ($wa_sorted as $aid => $ag) {
                                                $parts[] = e($ag['name']) . ': ' . format_duration_minutes($ag['minutes']);
                                            }
                                            echo implode(' | ', $parts);
                                        ?>">
                                            <?php foreach ($wa_sorted as $aid => $ag):
                                                $seg_pct = $weekly_max_minutes > 0 ? ($ag['minutes'] / $weekly_max_minutes) * 100 : 0;
                                            ?>
                                            <div style="width:<?php echo round($seg_pct, 1); ?>%;background:<?php echo $weekly_agent_color_map[$aid] ?? '#94a3b8'; ?>;"></div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                        <?php echo e(format_duration_minutes($week['billable_minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);">
                                            <?php echo e(format_money($week['billable_amount'])); ?></td>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <td class="px-3 py-1.5 text-xs" style="color: var(--text-secondary);"><?php echo e(format_money($week['profit'])); ?></td>
                                    <?php endif; ?>
                                </tr>
                                <?php if (count($week['agents']) > 0): ?>
                                <tr id="week-agents-<?php echo $wi; ?>" class="hidden">
                                    <td colspan="<?php echo $weekly_col_count; ?>" class="px-0 py-0">
                                        <div class="px-6 py-3" style="background: var(--surface-secondary);">
                                            <div class="grid gap-2" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));">
                                                <?php
                                                $wa_sorted2 = $week['agents'];
                                                uasort($wa_sorted2, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
                                                foreach ($wa_sorted2 as $aid => $ag):
                                                    $ag_pct = $week['minutes'] > 0 ? round(($ag['minutes'] / $week['minutes']) * 100) : 0;
                                                ?>
                                                <div class="flex items-center gap-2 px-3 py-2 rounded-lg" style="background: var(--surface-primary);">
                                                    <span style="display:inline-block;width:8px;height:8px;border-radius:2px;flex-shrink:0;background:<?php echo $weekly_agent_color_map[$aid] ?? '#94a3b8'; ?>;"></span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="text-xs font-medium truncate" style="color: var(--text-primary);"><?php echo e($ag['name']); ?></div>
                                                        <div class="text-xs" style="color: var(--text-muted);">
                                                            <?php echo e(format_duration_minutes($ag['minutes'])); ?>
                                                            <span class="ml-1">(<?php echo $ag_pct; ?>%)</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        <?php elseif ($tab === 'detailed'): ?>
            <?php if (empty($entries)): ?>
                <div class="card card-body p-8 text-center">
                    <div class="text-4xl mb-3" style="color: var(--text-muted);">📋</div>
                    <div class="font-semibold mb-1" style="color: var(--text-primary);"><?php echo e(t('No time entries found')); ?></div>
                    <div class="text-sm" style="color: var(--text-muted);"><?php echo e(t('Try adjusting the time range or filters above.')); ?></div>
                </div>
            <?php else: ?>
            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Detailed')); ?></h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead style="background: var(--surface-secondary);">
                            <tr>
                                <th class="px-3 py-2 text-left th-label" data-col="ticket">
                                    <?php echo e(t('Ticket')); ?></th>
                                <th class="px-3 py-2 text-left th-label" data-col="company">
                                    <?php echo e(t('Company')); ?></th>
                                <?php if ($tags_supported): ?>
                                    <th class="px-3 py-2 text-left th-label" data-col="tags">
                                        <?php echo e(t('Tags')); ?></th>
                                <?php endif; ?>
                                <th class="px-3 py-2 text-left th-label" data-col="duration">
                                    <?php echo e(t('Duration')); ?></th>
                                <th class="px-3 py-2 text-left th-label" data-col="billable">
                                    <?php echo e(t('Billable')); ?></th>
                                <th class="px-3 py-2 text-left th-label" data-col="agent">
                                    <?php echo e(t('Agent')); ?></th>
                                <th class="px-3 py-2 text-left th-label" data-col="source">
                                    <?php echo e(t('Source')); ?></th>
                                <th class="px-3 py-2 text-left th-label" data-col="start">
                                    <?php echo e(t('Start time')); ?></th>
                                <th class="px-3 py-2 text-left th-label" data-col="end">
                                    <?php echo e(t('End time')); ?></th>
                                <?php if ($show_money): ?>
                                    <th class="px-3 py-2 text-left th-label" data-col="amount">
                                        <?php echo e(t('Amount')); ?></th>
                                <?php endif; ?>
                                <?php if ($show_money && $has_cost_data): ?>
                                    <th class="px-3 py-2 text-left th-label" data-col="cost">
                                        <?php echo e(t('Cost')); ?></th>
                                    <th class="px-3 py-2 text-left th-label" data-col="profit">
                                        <?php echo e(t('Profit')); ?></th>
                                <?php endif; ?>
                                <?php if (is_admin()): ?>
                                <th class="px-6 py-3 text-right th-label">
                                    <?php echo e(t('Actions')); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($entries as $entry): ?>
                                <tr>
                                    <td class="px-3 py-1.5 text-xs" data-col="ticket"><a href="<?php echo url('ticket', ['id' => $entry['ticket_id']]); ?>" class="text-blue-600 hover:text-blue-800 hover:underline"><?php echo e($entry['ticket_title']); ?></a></td>
                                    <td class="px-3 py-1.5 text-xs" data-col="company" style="color: var(--text-secondary);">
                                        <?php echo e($entry['organization_name'] ?: t('-- No organization --')); ?></td>
                                    <?php if ($tags_supported): ?>
                                        <td class="px-3 py-1.5 text-xs" data-col="tags">
                                            <?php $entry_tags = function_exists('get_ticket_tags_array') ? get_ticket_tags_array($entry['ticket_tags'] ?? '') : []; ?>
                                            <?php if (!empty($entry_tags)): ?>
                                                <div class="flex flex-wrap gap-1">
                                                    <?php foreach (array_slice($entry_tags, 0, 4) as $tag): ?>
                                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded tag-badge text-xs">#<?php echo e($tag); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-3 py-1.5 text-xs" data-col="duration" style="color: var(--text-secondary);">
                                        <?php echo e(format_duration_minutes($entry['actual_minutes'])); ?></td>
                                    <td class="px-3 py-1.5 text-xs" data-col="billable" style="color: var(--text-secondary);">
                                        <?php if (is_admin()): ?>
                                        <form method="post">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                            <select name="is_billable" class="form-select text-xs" onchange="this.form.submit()">
                                                <option value="1" <?php echo !empty($entry['is_billable']) ? 'selected' : ''; ?>>
                                                    <?php echo e(t('Billable')); ?></option>
                                                <option value="0" <?php echo empty($entry['is_billable']) ? 'selected' : ''; ?>>
                                                    <?php echo e(t('Non-billable')); ?></option>
                                            </select>
                                            <input type="hidden" name="set_billable" value="1">
                                        </form>
                                        <?php else: ?>
                                            <span class="text-xs"><?php echo e(!empty($entry['is_billable']) ? t('Billable') : t('Non-billable')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs" data-col="agent" style="color: var(--text-secondary);">
                                        <?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?></td>
                                    <td class="px-3 py-1.5 text-xs" data-col="source">
                                        <?php echo function_exists('render_source_badge') ? render_source_badge($entry['_source'] ?? get_time_entry_source($entry)) : ''; ?></td>
                                    <td class="px-3 py-1.5 text-xs" data-col="start" style="color: var(--text-secondary);"><?php echo e(format_date($entry['started_at'])); ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs" data-col="end" style="color: var(--text-secondary);">
                                        <?php echo e($entry['ended_at'] ? format_date($entry['ended_at']) : '-'); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-3 py-1.5 text-xs" data-col="amount" style="color: var(--text-secondary);">
                                            <?php echo e(format_money($entry['billable_amount'])); ?></td>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <td class="px-3 py-1.5 text-xs" data-col="cost" style="color: var(--text-secondary);">
                                            <?php echo e(format_money($entry['cost_amount'])); ?></td>
                                        <td class="px-3 py-1.5 text-xs" data-col="profit" style="color: var(--text-secondary);"><?php echo e(format_money($entry['profit'])); ?>
                                        </td>
                                    <?php endif; ?>
                                    <?php if (is_admin()): ?>
                                    <td class="px-6 py-3 text-right">
                                        <?php
                                        $entry_data = [
                                            'id' => $entry['id'],
                                            'ticket_id' => $entry['ticket_id'],
                                            'ticket_code' => get_ticket_code($entry['ticket_id']),
                                            'ticket_title' => $entry['ticket_title'],
                                            'started_at' => date('Y-m-d\\TH:i', strtotime($entry['started_at'])),
                                            'ended_at' => $entry['ended_at'] ? date('Y-m-d\\TH:i', strtotime($entry['ended_at'])) : ''
                                        ];
                                        ?>
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button" class="text-blue-600 hover:text-blue-800"
                                                onclick='openEntryModal(<?php echo json_encode($entry_data, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                title="<?php echo e(t('Edit')); ?>">
                                                <?php echo get_icon('edit', 'w-4 h-4'); ?>
                                            </button>
                                            <form method="post" class="inline" onsubmit="return confirm('<?php echo e(t('Delete this time entry?')); ?>')">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                <button type="submit" name="delete_entry" class="hover:text-red-600" style="color: var(--text-muted);"
                                                    title="<?php echo e(t('Delete')); ?>">
                                                    <?php echo get_icon('trash', 'w-4 h-4'); ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        <?php elseif ($tab === 'worklog'): ?>
            <!-- Work Log Tab - Simple inline edit UI -->
            <?php
            // Group entries by day
            $entries_by_day = [];
            $day_totals = [];
            foreach ($entries as $entry) {
                $day_key = date('Y-m-d', strtotime($entry['started_at']));
                if (!isset($entries_by_day[$day_key])) {
                    $entries_by_day[$day_key] = [];
                    $day_totals[$day_key] = 0;
                }
                $entries_by_day[$day_key][] = $entry;
                $day_totals[$day_key] += $entry['actual_minutes'];
            }

            // Helper to get day label
            function get_day_label($date_str) {
                $date = new DateTime($date_str);
                $today = new DateTime('today');
                $yesterday = new DateTime('yesterday');

                if ($date->format('Y-m-d') === $today->format('Y-m-d')) {
                    return t('Today');
                } elseif ($date->format('Y-m-d') === $yesterday->format('Y-m-d')) {
                    return t('Yesterday');
                } else {
                    return $date->format('d.m.Y');
                }
            }
            ?>
            <?php if (empty($entries)): ?>
                <!-- Empty State -->
                <div class="worklog worklog--empty">
                    <?php echo get_icon('clock', 'worklog__empty-icon'); ?>
                    <p class="worklog__empty-text"><?php echo e(t('No time entries yet.')); ?></p>
                </div>
            <?php else: ?>
                <div class="worklog">
                    <!-- Sticky Column Headers -->
                    <div class="worklog__header">
                        <div><?php echo e(t('Ticket')); ?></div>
                        <div><?php echo e(t('Subject')); ?></div>
                        <div><?php echo e(t('Company')); ?></div>
                        <div><?php echo e(t('User')); ?></div>
                        <?php if (is_admin()): ?><div class="text-center">$</div><?php endif; ?>
                        <div class="text-center"><?php echo e(t('Time')); ?></div>
                        <div class="text-right"><?php echo e(t('Duration')); ?></div>
                        <?php if (is_admin()): ?><div></div><?php endif; ?>
                    </div>

                    <?php foreach ($entries_by_day as $day_key => $day_entries): ?>
                        <div class="worklog__day-group">
                            <!-- Day Header -->
                            <div class="worklog__day-header">
                                <span><?php echo get_day_label($day_key); ?></span>
                                <span class="worklog__day-total">
                                    <?php echo e(t('Total')); ?>: <strong><?php echo e(format_duration_minutes($day_totals[$day_key])); ?></strong>
                                </span>
                            </div>

                            <!-- Day Entries -->
                            <div class="worklog__entries">
                                <?php foreach ($day_entries as $entry): ?>
                                    <?php $is_running = empty($entry['ended_at']); ?>
                                    <div class="worklog__row <?php echo $is_running ? 'worklog__row--running' : ''; ?>" data-entry-id="<?php echo $entry['id']; ?>">
                                        <!-- Ticket ID -->
                                        <div class="worklog__cell worklog__cell--ticket">
                                            <a href="<?php echo url('ticket', ['id' => $entry['ticket_id']]); ?>">
                                                <?php echo e(get_ticket_code($entry['ticket_id'])); ?>
                                            </a>
                                        </div>

                                        <!-- Title -->
                                        <div class="worklog__cell worklog__cell--title" title="<?php echo e($entry['ticket_title']); ?>">
                                            <a href="<?php echo url('ticket', ['id' => $entry['ticket_id']]); ?>">
                                                <?php echo e($entry['ticket_title']); ?>
                                            </a>
                                            <?php if ($tags_supported): ?>
                                                <?php $entry_tags = function_exists('get_ticket_tags_array') ? get_ticket_tags_array($entry['ticket_tags'] ?? '') : []; ?>
                                                <?php if (!empty($entry_tags)): ?>
                                                    <div class="mt-1 flex flex-wrap gap-1">
                                                        <?php foreach (array_slice($entry_tags, 0, 4) as $tag): ?>
                                                            <span class="inline-flex items-center px-1 py-0.5 rounded tag-badge text-xs">#<?php echo e($tag); ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Client -->
                                        <div class="worklog__cell worklog__cell--client" title="<?php echo e($entry['organization_name'] ?: '-'); ?>">
                                            <?php if ($entry['organization_name']): ?>
                                                <span class="worklog__client-dot"></span><?php echo e($entry['organization_name']); ?>
                                            <?php else: ?>
                                                <span style="opacity: 0.3">—</span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- User -->
                                        <div class="worklog__cell worklog__cell--user" title="<?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?>">
                                            <?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?>
                                        </div>

                                        <!-- Billable -->
                                        <div class="worklog__cell worklog__cell--billable">
                                            <?php if (is_admin()): ?>
                                            <form method="post" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                <input type="hidden" name="is_billable" value="<?php echo $entry['is_billable'] ? '0' : '1'; ?>">
                                                <button type="submit" name="set_billable"
                                                    class="worklog__badge <?php echo $entry['is_billable'] ? 'worklog__badge--billable' : 'worklog__badge--non-billable'; ?>"
                                                    title="<?php echo $entry['is_billable'] ? t('Billable') : t('Non-billable'); ?>">
                                                    <?php echo get_icon('dollar-sign', 'w-4 h-4'); ?>
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <span class="worklog__badge <?php echo $entry['is_billable'] ? 'worklog__badge--billable' : 'worklog__badge--non-billable'; ?>"
                                                title="<?php echo $entry['is_billable'] ? t('Billable') : t('Non-billable'); ?>">
                                                <?php echo get_icon('dollar-sign', 'w-4 h-4'); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Time Range -->
                                        <div class="worklog__cell worklog__cell--time">
                                            <?php if (!$is_running): ?>
                                                <?php if (is_admin()): ?>
                                                <div class="worklog__time-form"
                                                     data-entry-id="<?php echo $entry['id']; ?>"
                                                     data-entry-date="<?php echo date('Y-m-d', strtotime($entry['started_at'])); ?>">
                                                    <input type="time" name="start_time"
                                                        value="<?php echo date('H:i', strtotime($entry['started_at'])); ?>"
                                                        class="worklog__time-input"
                                                        onchange="updateTimeInline(this)">
                                                    <span class="worklog__time-separator">–</span>
                                                    <input type="time" name="end_time"
                                                        value="<?php echo date('H:i', strtotime($entry['ended_at'])); ?>"
                                                        class="worklog__time-input"
                                                        onchange="updateTimeInline(this)">
                                                </div>
                                                <?php else: ?>
                                                <span class="text-sm">
                                                    <?php echo date('H:i', strtotime($entry['started_at'])); ?> – <?php echo date('H:i', strtotime($entry['ended_at'])); ?>
                                                </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="worklog__time-running">
                                                    <?php echo date('H:i', strtotime($entry['started_at'])); ?> – ...
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Duration -->
                                        <div class="worklog__cell worklog__cell--duration <?php echo $is_running ? 'text-green-600' : ''; ?>">
                                            <?php echo e(format_duration_minutes($entry['actual_minutes'])); ?>
                                        </div>

                                        <!-- Actions -->
                                        <?php if (is_admin()): ?>
                                        <div class="worklog__cell worklog__cell--actions">
                                            <form method="post" class="inline" onsubmit="return confirm('<?php echo e(t('Delete this time entry?')); ?>')">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                <button type="submit" name="delete_entry" class="worklog__delete-btn"
                                                    title="<?php echo e(t('Delete')); ?>">
                                                    <?php echo get_icon('trash', 'w-4 h-4'); ?>
                                                </button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php elseif ($tab === 'shared'): ?>
            <div class="card card-body space-y-4">
                <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Share link')); ?></h3>
                <form method="post" class="space-y-4">
                    <?php echo csrf_field(); ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium" class="mb-1" style="color: var(--text-secondary);"><?php echo e(t('Company')); ?></label>
                            <select name="organization_id" class="form-select">
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?php echo $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label
                                class="block text-sm font-medium" class="mb-1" style="color: var(--text-secondary);"><?php echo e(t('Expiry (optional)')); ?></label>
                            <input type="datetime-local" name="share_expires_at" class="form-input">
                        </div>
                    </div>
                    <button type="submit" name="create_report_share" class="btn btn-primary">
                        <?php echo e(t('Create share link')); ?>
                    </button>
                </form>

                <?php
                $share_org_id = (int) ($_GET['share_org_id'] ?? 0);
                if ($share_org_id <= 0 && !empty($organizations)) {
                    $share_org_id = (int) $organizations[0]['id'];
                }
                $active_share = $share_org_id ? get_active_report_share($share_org_id) : null;
                $share_token = null;
                if (!empty($_SESSION['report_share_token']) && (int) ($_SESSION['report_share_org_id'] ?? 0) === $share_org_id) {
                    $share_token = $_SESSION['report_share_token'];
                    unset($_SESSION['report_share_token'], $_SESSION['report_share_org_id']);
                }
                $share_url = $share_token ? get_report_share_url($share_token) : null;
                ?>

                <?php if ($share_url): ?>
                    <div class="border border-green-200 rounded-lg p-4" style="background: var(--surface-secondary);">
                        <div class="text-sm text-green-600 mb-2"><?php echo e(t('Share link created.')); ?></div>
                        <input type="text" readonly class="form-input" value="<?php echo e($share_url); ?>" onclick="this.select()">
                    </div>
                <?php elseif ($active_share): ?>
                    <div class="border border-yellow-200 rounded-lg p-4 text-sm text-yellow-600" style="background: var(--surface-secondary);">
                        <?php echo e(t('An active link exists but is hidden for security. Generate a new link to get a new URL.')); ?>
                    </div>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="organization_id" value="<?php echo $share_org_id; ?>">
                        <button type="submit" name="revoke_report_share" class="btn btn-warning">
                            <?php echo e(t('Revoke share link')); ?>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="text-sm" style="color: var(--text-muted);"><?php echo e(t('No active share link exists yet.')); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($tab === 'detailed' || $tab === 'worklog'): ?>
    <div id="entryModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="rounded-xl shadow-xl max-w-lg w-full mx-4 p-4" style="background: var(--bg-primary);">
            <h3 class="font-semibold mb-4" style="color: var(--text-primary);"><?php echo e(t('Edit time entry')); ?></h3>
            <form method="post" class="space-y-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="entry_id" id="edit_entry_id">

                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Ticket ID')); ?></label>
                    <input type="text" name="ticket_id" id="edit_ticket_id" class="form-input">
                    <p class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e(t('Ticket code (e.g., TK-0003)')); ?></p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--text-secondary);"><?php echo e(t('Ticket title')); ?></label>
                    <input type="text" name="ticket_title" id="edit_ticket_title" class="form-input">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label
                            class="block text-sm font-medium" class="mb-1" style="color: var(--text-secondary);"><?php echo e(t('Start time')); ?></label>
                        <input type="datetime-local" name="started_at" id="edit_started_at" class="form-input" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" class="mb-1" style="color: var(--text-secondary);"><?php echo e(t('End time')); ?></label>
                        <input type="datetime-local" name="ended_at" id="edit_ended_at" class="form-input" required>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" name="update_entry" class="btn btn-primary">
                        <?php echo e(t('Save changes')); ?>
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeEntryModal()">
                        <?php echo e(t('Cancel')); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script src="assets/js/chip-select.js"></script>
<script>
    /* ── Inline time update (AJAX, no page reload) ── */
    function updateTimeInline(input) {
        var wrap = input.closest('.worklog__time-form');
        if (!wrap) return;

        var entryId   = wrap.dataset.entryId;
        var entryDate = wrap.dataset.entryDate;
        var startTime = wrap.querySelector('[name="start_time"]').value;
        var endTime   = wrap.querySelector('[name="end_time"]').value;

        if (!startTime || !endTime) return;

        // Find the duration cell in the same row
        var row = wrap.closest('.worklog__row');
        var durationCell = row ? row.querySelector('.worklog__cell--duration') : null;

        // Visual feedback – dim duration while saving
        if (durationCell) durationCell.style.opacity = '0.4';

        // Grab CSRF token from any form on the page
        var csrfInput = document.querySelector('input[name="csrf_token"]');
        var csrfToken = csrfInput ? csrfInput.value : '';

        fetch('index.php?page=api&action=update-time-inline', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                entry_id:   entryId,
                entry_date: entryDate,
                start_time: startTime,
                end_time:   endTime
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && durationCell) {
                durationCell.textContent = data.duration_formatted;
                // Brief green flash to confirm save
                durationCell.style.opacity = '1';
                durationCell.style.transition = 'background .3s';
                durationCell.style.background = 'rgba(34,197,94,.15)';
                setTimeout(function () { durationCell.style.background = ''; }, 800);
            } else if (!data.success) {
                alert(data.error || <?php echo json_encode(t('Failed to save')); ?>);
                if (durationCell) durationCell.style.opacity = '1';
            }
        })
        .catch(function (err) {
            console.error('Time update failed:', err);
            if (durationCell) durationCell.style.opacity = '1';
        });
    }

    const reportRangeSelect = document.getElementById('report-time-range');
    const reportCustomRange = document.getElementById('report-custom-range');
    if (reportRangeSelect && reportCustomRange) {
        const toggleRange = () => {
            reportCustomRange.classList.toggle('hidden', reportRangeSelect.value !== 'custom');
            // Update preset button highlights
            document.querySelectorAll('.range-preset-btn').forEach(function(btn) {
                if (btn.dataset.range === reportRangeSelect.value) {
                    btn.style.background = 'var(--primary)';
                    btn.style.color = '#fff';
                } else {
                    btn.style.background = 'var(--surface-secondary)';
                    btn.style.color = 'var(--text-muted)';
                }
            });
        };
        reportRangeSelect.addEventListener('change', toggleRange);
        toggleRange();
    }

    /* ── Quick range preset click handler ── */
    window.setTimeRange = function(range) {
        var sel = document.getElementById('report-time-range');
        if (sel) {
            sel.value = range;
            sel.dispatchEvent(new Event('change'));
        }
    };

    /* ── Collapsible filter panel toggle (RP5) ── */
    window.toggleReportFilters = function() {
        var panel = document.getElementById('report-filter-panel');
        var label = document.getElementById('filter-toggle-label');
        if (!panel) return;
        var isHidden = panel.classList.contains('hidden');
        panel.classList.toggle('hidden');
        if (label) {
            label.textContent = isHidden ? <?php echo json_encode(t('Hide filters')); ?> : <?php echo json_encode(t('Edit filters')); ?>;
        }
    };

    /* ── Weekly tab: toggle per-agent breakdown (RP6) ── */
    window.toggleWeekAgents = function(id) {
        var row = document.getElementById(id);
        if (row) row.classList.toggle('hidden');
    };

    function openEntryModal(entry) {
        document.getElementById('edit_entry_id').value = entry.id;
        document.getElementById('edit_ticket_id').value = entry.ticket_code || entry.ticket_id;
        document.getElementById('edit_ticket_title').value = entry.ticket_title || '';
        document.getElementById('edit_started_at').value = entry.started_at || '';
        document.getElementById('edit_ended_at').value = entry.ended_at || '';
        const modal = document.getElementById('entryModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    }

    function closeEntryModal() {
        const modal = document.getElementById('entryModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    /* ── Initialize chip-selects ── */
    var csOrgs = null, csAgents = null, csTags = null;

    (function () {
        // Organization items
        var orgItems = <?php
            $org_items = array_map(function ($o) {
                return ['id' => (int) $o['id'], 'name' => $o['name']];
            }, $organizations);
            array_unshift($org_items, ['id' => 0, 'name' => t('-- No organization --')]);
            echo json_encode($org_items);
        ?>;
        var orgSelected = <?php echo json_encode(array_map('intval', $selected_orgs)); ?>;

        csOrgs = new ChipSelect({
            wrapId: 'cs-orgs-wrap',
            chipsId: 'cs-orgs-chips',
            inputId: 'cs-orgs-input',
            dropdownId: 'cs-orgs-dropdown',
            hiddenId: 'cs-orgs-hidden',
            items: orgItems,
            selected: orgSelected,
            name: 'organizations[]',
            noMatchText: <?php echo json_encode(t('No matches')); ?>
        });

        <?php if (is_admin()): ?>
        // Agent items
        var agentItems = <?php
            echo json_encode(array_map(function ($a) {
                return ['id' => (int) $a['id'], 'name' => trim($a['first_name'] . ' ' . $a['last_name'])];
            }, $agents));
        ?>;
        var agentSelected = <?php echo json_encode(array_map('intval', $selected_agents)); ?>;

        csAgents = new ChipSelect({
            wrapId: 'cs-agents-wrap',
            chipsId: 'cs-agents-chips',
            inputId: 'cs-agents-input',
            dropdownId: 'cs-agents-dropdown',
            hiddenId: 'cs-agents-hidden',
            items: agentItems,
            selected: agentSelected,
            name: 'agents[]',
            noMatchText: <?php echo json_encode(t('No matches')); ?>
        });
        <?php endif; ?>

        <?php if ($tags_supported): ?>
        // Tag items — fetch from API
        fetch('index.php?page=api&action=get-tags')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var preSelected = <?php echo json_encode($selected_tags); ?>;
                csTags = new ChipSelect({
                    wrapId:     'cs-tags-wrap',
                    chipsId:    'cs-tags-chips',
                    inputId:    'cs-tags-input',
                    dropdownId: 'cs-tags-dropdown',
                    hiddenId:   'cs-tags-hidden',
                    items:      data.tags || [],
                    selected:   preSelected,
                    name:       'tag_chips[]',
                    allowCreate: true,
                    noMatchText: <?php echo json_encode(t('No matches')); ?>
                });
            });
        <?php endif; ?>
    })();

    /* ── Report confirmation ── */
    (function () {
        var applyBtn    = document.getElementById('report-apply-btn');
        var confirmDiv  = document.getElementById('report-confirm');
        var confirmBody = document.getElementById('report-confirm-body');
        var backBtn     = document.getElementById('report-confirm-back');
        if (!applyBtn || !confirmDiv) return;

        applyBtn.addEventListener('click', function () {
            // Build summary
            var lines = [];

            // Clients
            var orgNames = csOrgs ? csOrgs.getSelectedNames() : [];
            lines.push(row(<?php echo json_encode(t('Clients')); ?>, orgNames.length ? orgNames.join(', ') : <?php echo json_encode(t('All clients')); ?>));

            // Agents
            <?php if (is_admin()): ?>
            var agentNames = csAgents ? csAgents.getSelectedNames() : [];
            lines.push(row(<?php echo json_encode(t('Agents')); ?>, agentNames.length ? agentNames.join(', ') : <?php echo json_encode(t('All agents')); ?>));
            <?php endif; ?>

            // Time range
            var rangeSelect = document.getElementById('report-time-range');
            var rangeLabel  = rangeSelect ? rangeSelect.options[rangeSelect.selectedIndex].text : '';
            if (rangeSelect && rangeSelect.value === 'custom') {
                var fd = document.querySelector('[name="from_date"]');
                var td = document.querySelector('[name="to_date"]');
                rangeLabel = (fd ? fd.value : '') + ' – ' + (td ? td.value : '');
            }
            lines.push(row(<?php echo json_encode(t('Range')); ?>, rangeLabel));

            // Tags
            var tagNames = csTags ? csTags.getSelectedNames() : [];
            if (tagNames.length) {
                lines.push(row(<?php echo json_encode(t('Tags')); ?>, tagNames.join(', ')));
            }

            // Sync chip values to hidden input before showing confirmation
            var tagsHidden = document.getElementById('rpt-tags-value');
            if (tagsHidden && csTags) {
                tagsHidden.value = csTags.getSelectedValues().join(', ');
            }

            confirmBody.innerHTML = lines.join('');
            confirmDiv.classList.remove('hidden');
            applyBtn.classList.add('hidden');
        });

        backBtn.addEventListener('click', function () {
            confirmDiv.classList.add('hidden');
            applyBtn.classList.remove('hidden');
        });

        function row(label, value) {
            return '<div class="report-confirm__row">' +
                '<span class="report-confirm__label">' + _escHtml(label) + '</span>' +
                '<span class="report-confirm__value">' + _escHtml(value) + '</span>' +
                '</div>';
        }
    })();

    /* ── Column picker (Detailed tab) ── */
    (function () {
        var toggles = document.querySelectorAll('.col-toggle');
        if (!toggles.length) return;
        var STORAGE_KEY = 'foxdesk_report_cols';

        // Restore saved state
        try {
            var saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            toggles.forEach(function (cb) {
                var col = cb.dataset.col;
                if (saved[col] === false) {
                    cb.checked = false;
                    applyCol(col, false);
                }
            });
        } catch (e) {}

        toggles.forEach(function (cb) {
            cb.addEventListener('change', function () {
                applyCol(cb.dataset.col, cb.checked);
                saveState();
            });
        });

        function applyCol(col, visible) {
            var cells = document.querySelectorAll('[data-col="' + col + '"]');
            cells.forEach(function (cell) {
                cell.style.display = visible ? '' : 'none';
            });
        }

        function saveState() {
            var state = {};
            toggles.forEach(function (cb) {
                state[cb.dataset.col] = cb.checked;
            });
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
        }

        // Close dropdown on outside click
        document.addEventListener('click', function (e) {
            var wrap = document.getElementById('col-picker-wrap');
            var dd = document.getElementById('col-picker-dropdown');
            if (wrap && dd && !wrap.contains(e.target)) {
                dd.classList.add('hidden');
            }
        });
    })();

    /* ── Filter persistence (localStorage) ── */
    (function () {
        var FILTER_KEY = 'foxdesk_report_filters';
        var form = document.querySelector('form[method="get"]');
        if (!form) return;
        var rangeSelect = document.getElementById('report-time-range');

        // On form submit (via Apply), save current filter state
        form.addEventListener('submit', function () {
            saveFilters();
        });

        // Also save when Apply button triggers confirmation
        var applyBtn = document.getElementById('report-apply-btn');
        if (applyBtn) {
            var origClick = applyBtn.onclick;
            applyBtn.addEventListener('click', function () {
                saveFilters();
            });
        }

        // Restore filters only on clean visit (no query params besides page/section)
        var urlParams = new URLSearchParams(window.location.search);
        var hasFilters = urlParams.has('time_range') || urlParams.has('organizations') || urlParams.has('agents');
        if (!hasFilters && rangeSelect) {
            try {
                var saved = JSON.parse(localStorage.getItem(FILTER_KEY) || '{}');
                if (saved.time_range && saved.time_range !== 'this_month') {
                    rangeSelect.value = saved.time_range;
                    if (saved.time_range === 'custom') {
                        var fd = document.querySelector('[name="from_date"]');
                        var td = document.querySelector('[name="to_date"]');
                        if (fd && saved.from_date) fd.value = saved.from_date;
                        if (td && saved.to_date) td.value = saved.to_date;
                        var customRange = document.getElementById('report-custom-range');
                        if (customRange) customRange.classList.remove('hidden');
                    }
                }
            } catch (e) {}
        }

        function saveFilters() {
            try {
                var state = {};
                if (rangeSelect) state.time_range = rangeSelect.value;
                var fd = document.querySelector('[name="from_date"]');
                var td = document.querySelector('[name="to_date"]');
                if (fd) state.from_date = fd.value;
                if (td) state.to_date = td.value;
                localStorage.setItem(FILTER_KEY, JSON.stringify(state));
            } catch (e) {}
        }
    })();
</script>

<!-- Print-friendly styles -->
<style>
@media print {
    /* Hide non-essential UI elements */
    aside, .sidebar,
    .mobile-header,
    header,
    .toast-stack,
    #notification-panel,
    .foxdesk-update-bar,
    #app-toast-stack,
    .flash-inline-wrapper,
    #col-picker-wrap,
    .report-confirm,
    #report-apply-btn,
    form[method="get"],
    .range-preset-btn,
    #report-range-hint,
    .btn-primary, .btn-secondary, .btn-sm,
    [onclick*="print"],
    [href*="export=csv"],
    .chip-select,
    #entryModal,
    footer,
    .help-panel {
        display: none !important;
    }

    /* Reset page layout */
    body {
        background: #fff !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }

    .space-y-3 > * {
        break-inside: avoid;
    }

    /* Card styling for print */
    .card {
        border: 1px solid #e5e7eb !important;
        box-shadow: none !important;
        break-inside: avoid;
    }

    .card-body {
        padding: 12px !important;
    }

    /* Table print styles */
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 11px !important;
    }

    th, td {
        padding: 6px 8px !important;
        border-bottom: 1px solid #e5e7eb !important;
    }

    th {
        background: #f9fafb !important;
        font-weight: 600 !important;
    }

    /* KPI cards grid - force horizontal layout */
    .grid {
        display: grid !important;
    }

    /* Summary card values */
    .text-xl {
        font-size: 16px !important;
    }

    /* Tab bar: show active tab name only */
    .flex.items-center.gap-2 a:not([style*="background: var(--primary)"]) {
        display: none !important;
    }

    /* Client Reports banner */
    [style*="linear-gradient"] {
        display: none !important;
    }

    /* Page header */
    .page-header {
        margin-bottom: 8px !important;
    }

    /* Add report title for print */
    .space-y-3::before {
        content: "Time Report";
        display: block;
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 8px;
        color: #000;
    }

    /* Remove links styling */
    a {
        color: #000 !important;
        text-decoration: none !important;
    }

    /* Page margins */
    @page {
        margin: 1.5cm;
        size: A4 landscape;
    }
}
</style>

<?php require_once BASE_PATH . '/includes/footer.php';
