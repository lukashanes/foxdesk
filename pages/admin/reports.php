<?php
/**
 * Admin - Time Reports
 */

$page_title = t('Time report');
$page = 'admin';

$time_tracking_available = ticket_time_table_exists();
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
            db_update('ticket_time_entries', ['is_billable' => $is_billable], 'id = ?', [$entry_id]);
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
            $org = get_organization($ticket['organization_id'] ?? 0);
            $update_data['comment_id'] = null;
            $update_data['billable_rate'] = $org ? (float) $org['billable_rate'] : 0;
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
    $sql = "SELECT tte.*,
                   t.title as ticket_title,
                   t.organization_id,
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

    $sql .= " ORDER BY tte.started_at DESC, tte.id DESC";
    $entries = db_fetch_all($sql, $params);

    foreach ($entries as &$entry) {
        $actual_minutes = (int) $entry['duration_minutes'];
        if (empty($entry['ended_at']) && !empty($entry['started_at'])) {
            $actual_minutes = max(0, (int) floor((time() - strtotime($entry['started_at'])) / 60));
        }

        // Determine source
        $source = function_exists('get_time_entry_source') ? get_time_entry_source($entry) : (!empty($entry['is_manual']) ? 'manual' : 'timer');
        $entry['_source'] = $source;

        $billable_rate = isset($entry['billable_rate']) ? (float) $entry['billable_rate'] : 0.0;
        if ($billable_rate <= 0 && isset($entry['org_billable_rate'])) {
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
            $by_week[$week_key] = [
                'label' => $week_start->format('Y-m-d'),
                'minutes' => 0,
                'billable_minutes' => 0,
                'billable_amount' => 0.0,
                'cost_amount' => 0.0,
                'profit' => 0.0
            ];
        }
        $by_week[$week_key]['minutes'] += $actual_minutes;
        $by_week[$week_key]['billable_minutes'] += $billable_minutes;
        $by_week[$week_key]['billable_amount'] += $billable_amount;
        $by_week[$week_key]['cost_amount'] += $cost_amount;
        $by_week[$week_key]['profit'] += $profit;

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
    <div class="card card-body rounded-2xl mb-2" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark, #1d4ed8) 100%);">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
                <h3 class="text-xl font-semibold text-white">
                    <?php echo get_icon('file-invoice', 'mr-2 inline-block'); ?><?php echo e(t('Client Reports')); ?>
                </h3>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="<?php echo url('admin', ['section' => 'reports-list']); ?>"
                    class="btn btn-secondary px-5 py-2.5 rounded-lg font-medium shadow-sm">
                    <?php echo get_icon('list', 'mr-2 inline-block'); ?><?php echo e(t('View Reports')); ?>
                </a>
                <a href="<?php echo url('admin', ['section' => 'report-builder']); ?>"
                    class="btn btn-primary px-5 py-2.5 rounded-lg font-medium shadow-sm" style="background: rgba(255,255,255,0.2); border-color: rgba(255,255,255,0.3);">
                    <?php echo get_icon('plus', 'mr-2 inline-block'); ?><?php echo e(t('Create New Report')); ?>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-4 mb-4">
        <div class="flex items-center gap-2">
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
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                    style="<?php echo $tab === $tab_key
                        ? 'background: var(--primary); color: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.1);'
                        : 'color: var(--text-primary); background: transparent;'; ?>">
                    <?php echo e($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$time_tracking_available): ?>
        <div class="card card-body" style="color: var(--text-secondary);">
            <?php echo e(t('Time tracking is not available.')); ?>
        </div>
    <?php else: ?>
        <?php if ($tab !== 'shared'): ?>
            <div class="card card-body mb-2">
                <form method="get" class="flex flex-wrap items-end gap-4">
                    <input type="hidden" name="page" value="admin">
                    <input type="hidden" name="section" value="reports">
                    <input type="hidden" name="tab" value="<?php echo e($tab); ?>">

                    <div class="min-w-[220px]">
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
                    <div class="min-w-[220px]">
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
                        <div class="min-w-[220px]">
                            <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);"><?php echo e(t('Tags')); ?></label>
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
                            <p class="text-xs mt-1" style="color: var(--text-muted);"><?php echo e(t('Multiple tags use OR matching.')); ?></p>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);"><?php echo e(t('Time range')); ?></label>
                        <select name="time_range" id="report-time-range" class="form-select">
                            <option value="all" <?php echo $time_range === 'all' ? 'selected' : ''; ?>>
                                <?php echo e(t('All time')); ?></option>
                            <option value="yesterday" <?php echo $time_range === 'yesterday' ? 'selected' : ''; ?>>
                                <?php echo e(t('Yesterday')); ?></option>
                            <option value="this_week" <?php echo $time_range === 'this_week' ? 'selected' : ''; ?>>
                                <?php echo e(t('This week')); ?></option>
                            <option value="last_week" <?php echo $time_range === 'last_week' ? 'selected' : ''; ?>>
                                <?php echo e(t('Last week')); ?></option>
                            <option value="this_month" <?php echo $time_range === 'this_month' ? 'selected' : ''; ?>>
                                <?php echo e(t('This month')); ?></option>
                            <option value="last_month" <?php echo $time_range === 'last_month' ? 'selected' : ''; ?>>
                                <?php echo e(t('Last month')); ?></option>
                            <option value="this_year" <?php echo $time_range === 'this_year' ? 'selected' : ''; ?>>
                                <?php echo e(t('This year')); ?></option>
                            <option value="last_year" <?php echo $time_range === 'last_year' ? 'selected' : ''; ?>>
                                <?php echo e(t('Last year')); ?></option>
                            <option value="custom" <?php echo $time_range === 'custom' ? 'selected' : ''; ?>>
                                <?php echo e(t('Custom range')); ?></option>
                        </select>
                    </div>

                    <div id="report-custom-range"
                        class="flex items-end gap-3 <?php echo $time_range === 'custom' ? '' : 'hidden'; ?>">
                        <div>
                            <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);"><?php echo e(t('From date')); ?></label>
                            <input type="date" name="from_date" value="<?php echo e($from_date); ?>" class="form-input">
                        </div>
                        <div>
                            <label class="block text-xs mb-1 font-medium" style="color: var(--text-secondary);"><?php echo e(t('To date')); ?></label>
                            <input type="date" name="to_date" value="<?php echo e($to_date); ?>" class="form-input">
                        </div>
                    </div>

                    <?php if (is_admin()): ?>
                    <div class="flex items-center mt-3">
                        <label class="flex items-center text-sm" style="color: var(--text-secondary);">
                            <input type="checkbox" name="show_money" value="1" class="mr-2 rounded" <?php echo $show_money ? 'checked' : ''; ?>>
                            <?php echo e(t('Show amounts')); ?>
                        </label>
                    </div>
                    <?php endif; ?>

                    <button type="button" id="report-apply-btn" class="btn btn-primary btn-sm ml-auto"><?php echo e(t('Apply')); ?></button>

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
        <?php endif; ?>

        <?php if ($tab === 'summary'): ?>
            <div class="grid grid-cols-1 md:grid-cols-<?php echo $show_money ? '3' : '2'; ?> lg:grid-cols-<?php echo $show_money ? '5' : '2'; ?> gap-4 mb-2">
                <div class="card card-body">
                    <div class="text-xs uppercase tracking-wider mb-1" style="color: var(--text-secondary);"><?php echo e(t('Total time')); ?></div>
                    <div class="text-xl font-bold" style="color: var(--text-primary);"><?php echo e(format_duration_minutes($totals['minutes'])); ?></div>
                </div>
                <div class="card card-body">
                    <div class="text-xs uppercase tracking-wider mb-1" style="color: var(--text-secondary);"><?php echo e(t('Billable time')); ?></div>
                    <div class="text-xl font-bold" style="color: var(--text-primary);"><?php echo e(format_duration_minutes($totals['billable_minutes'])); ?></div>
                </div>
                <?php if ($show_money): ?>
                <div class="card card-body">
                    <div class="text-xs uppercase tracking-wider mb-1" style="color: var(--text-secondary);"><?php echo e(t('Billable amount')); ?></div>
                    <div class="text-xl font-bold" style="color: var(--text-primary);"><?php echo e(format_money($totals['billable_amount'])); ?></div>
                </div>
                <div class="card card-body">
                    <div class="text-xs uppercase tracking-wider mb-1" style="color: var(--text-secondary);"><?php echo e(t('Cost')); ?></div>
                    <div class="text-xl font-bold" style="color: var(--text-primary);"><?php echo e(format_money($totals['cost_amount'])); ?></div>
                </div>
                <div class="card card-body">
                    <div class="text-xs uppercase tracking-wider mb-1" style="color: var(--text-secondary);"><?php echo e(t('Profit')); ?></div>
                    <div class="text-xl font-bold" style="color: var(--text-primary);"><?php echo e(format_money($totals['profit'])); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php
            $human_min = $totals['human_minutes'] ?? 0;
            $ai_min = $totals['ai_minutes'] ?? 0;
            if ($human_min > 0 && $ai_min > 0):
            ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-2">
                <div class="card card-body border-l-4 border-blue-400">
                    <div class="flex items-center gap-2 text-xs uppercase tracking-wider mb-1" style="color: var(--text-secondary);">
                        <?php echo get_icon('user', 'w-4 h-4'); ?>
                        <?php echo e(t('Human time')); ?>
                    </div>
                    <div class="text-xl font-bold" style="color: var(--text-primary);"><?php echo e(format_duration_minutes($human_min)); ?></div>
                    <?php if ($show_money): ?>
                        <div class="text-xs mt-1" style="color: var(--text-muted);">
                            <?php echo e(t('Billable')); ?>: <?php echo e(format_money($totals['human_billable'] ?? 0)); ?>
                            · <?php echo e(t('Cost')); ?>: <?php echo e(format_money($totals['human_cost'] ?? 0)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card card-body border-l-4 border-purple-400">
                    <div class="flex items-center gap-2 text-xs uppercase tracking-wider mb-1" style="color: var(--text-secondary);">
                        <?php echo get_icon('bot', 'w-4 h-4'); ?>
                        <?php echo e(t('AI time')); ?>
                    </div>
                    <div class="text-xl font-bold" style="color: var(--text-primary);"><?php echo e(format_duration_minutes($ai_min)); ?></div>
                    <?php if ($show_money): ?>
                        <div class="text-xs mt-1" style="color: var(--text-muted);">
                            <?php echo e(t('Billable')); ?>: <?php echo e(format_money($totals['ai_billable'] ?? 0)); ?>
                            · <?php echo e(t('Cost')); ?>: <?php echo e(format_money($totals['ai_cost'] ?? 0)); ?>
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

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div class="card overflow-hidden">
                    <div class="card-header" style="border-color: var(--border-light);">
                        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Company')); ?></h3>
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
                                        <th><?php echo e(t('Profit')); ?></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($by_org as $org): ?>
                                    <tr>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-primary);"><?php echo e($org['name']); ?></td>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                            <?php echo e(format_duration_minutes($org['minutes'])); ?></td>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                            <?php echo e(format_duration_minutes($org['billable_minutes'])); ?></td>
                                        <?php if ($show_money): ?>
                                            <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_money($org['rate'])); ?></td>
                                            <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                                <?php echo e(format_money($org['billable_amount'])); ?></td>
                                            <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_money($org['profit'])); ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card overflow-hidden">
                    <div class="card-header">
                        <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Agents')); ?></h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead style="background: var(--surface-secondary);">
                                <tr>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Agent')); ?></th>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Time')); ?></th>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Billable time')); ?></th>
                                    <?php if ($show_money): ?>
                                        <th class="px-6 py-3 text-left th-label">
                                            <?php echo e(t('Amount')); ?></th>
                                        <th class="px-6 py-3 text-left th-label">
                                            <?php echo e(t('Profit')); ?></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($by_agent as $agent): ?>
                                    <tr>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-primary);"><?php echo e($agent['name']); ?></td>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                            <?php echo e(format_duration_minutes($agent['minutes'])); ?></td>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                            <?php echo e(format_duration_minutes($agent['billable_minutes'])); ?></td>
                                        <?php if ($show_money): ?>
                                            <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                                <?php echo e(format_money($agent['billable_amount'])); ?></td>
                                            <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_money($agent['profit'])); ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
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
                                    <th><?php echo e(t('Profit')); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($by_source as $src): ?>
                                <tr>
                                    <td class="px-6 py-3 text-sm"><?php echo function_exists('render_source_badge') ? render_source_badge($src['source']) : e($src['label']); ?></td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo (int) $src['count']; ?></td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_duration_minutes($src['minutes'])); ?></td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_duration_minutes($src['billable_minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_money($src['billable_amount'])); ?></td>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_money($src['profit'])); ?></td>
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
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Ticket')); ?></th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Company')); ?></th>
                                <?php if ($tags_supported): ?>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Tags')); ?></th>
                                <?php endif; ?>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Time')); ?></th>
                                <?php if ($show_money): ?>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Amount')); ?></th>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Profit')); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($open_tickets as $tid => $ticket): ?>
                                <tr>
                                    <td class="px-6 py-3 text-sm"><a href="<?php echo url('ticket', ['id' => $tid]); ?>" class="text-blue-600 hover:text-blue-800 hover:underline"><?php echo e($ticket['title']); ?></a></td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                        <?php echo e($ticket['organization_name'] ?: t('-- No organization --')); ?></td>
                                    <?php if ($tags_supported): ?>
                                        <td class="px-6 py-3 text-sm">
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
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                        <?php echo e(format_duration_minutes($ticket['minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                            <?php echo e(format_money($ticket['billable_amount'])); ?></td>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_money($ticket['profit'])); ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($closed_tickets_report)): ?>
                        <tbody class="border-t-2" style="border-top-color: var(--border-light);">
                            <tr class="cursor-pointer" style="background: var(--surface-secondary);" onclick="document.getElementById('closed-tickets-report').classList.toggle('hidden')">
                                <?php $report_colspan = 3 + ($tags_supported ? 1 : 0) + ($show_money ? 2 : 0); ?>
                                <td colspan="<?php echo $report_colspan; ?>" class="px-6 py-2 font-medium text-xs text-center text-gray-500 hover:text-gray-700">
                                    <?php echo e(t('Closed')); ?> (<?php echo count($closed_tickets_report); ?>)
                                </td>
                            </tr>
                        </tbody>
                        <tbody id="closed-tickets-report" class="hidden divide-y">
                            <?php foreach ($closed_tickets_report as $tid => $ticket): ?>
                                <tr style="opacity: 0.7;">
                                    <td class="px-6 py-3 text-sm"><a href="<?php echo url('ticket', ['id' => $tid]); ?>" class="text-blue-600 hover:text-blue-800 hover:underline"><?php echo e($ticket['title']); ?></a></td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                        <?php echo e($ticket['organization_name'] ?: t('-- No organization --')); ?></td>
                                    <?php if ($tags_supported): ?>
                                        <td class="px-6 py-3 text-sm">
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
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                        <?php echo e(format_duration_minutes($ticket['minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                            <?php echo e(format_money($ticket['billable_amount'])); ?></td>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_money($ticket['profit'])); ?>
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
            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Weekly')); ?></h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead style="background: var(--surface-secondary);">
                            <tr>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('From date')); ?></th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Time')); ?></th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Billable time')); ?></th>
                                <?php if ($show_money): ?>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Amount')); ?></th>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Profit')); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($by_week as $week): ?>
                                <tr>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-primary);"><?php echo e($week['label']); ?></td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                        <?php echo e(format_duration_minutes($week['minutes'])); ?></td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                        <?php echo e(format_duration_minutes($week['billable_minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                            <?php echo e(format_money($week['billable_amount'])); ?></td>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_money($week['profit'])); ?></td>
                                    <?php endif; ?>
                                </tr>
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
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Ticket')); ?></th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Company')); ?></th>
                                <?php if ($tags_supported): ?>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Tags')); ?></th>
                                <?php endif; ?>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Duration')); ?></th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Billable')); ?></th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Agent')); ?></th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Source')); ?></th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('Start time')); ?></th>
                                <th class="px-6 py-3 text-left th-label">
                                    <?php echo e(t('End time')); ?></th>
                                <?php if ($show_money): ?>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Amount')); ?></th>
                                    <th class="px-6 py-3 text-left th-label">
                                        <?php echo e(t('Cost')); ?></th>
                                    <th class="px-6 py-3 text-left th-label">
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
                                    <td class="px-6 py-3 text-sm"><a href="<?php echo url('ticket', ['id' => $entry['ticket_id']]); ?>" class="text-blue-600 hover:text-blue-800 hover:underline"><?php echo e($entry['ticket_title']); ?></a></td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                        <?php echo e($entry['organization_name'] ?: t('-- No organization --')); ?></td>
                                    <?php if ($tags_supported): ?>
                                        <td class="px-6 py-3 text-sm">
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
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                        <?php echo e(format_duration_minutes($entry['actual_minutes'])); ?></td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
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
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                        <?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?></td>
                                    <td class="px-6 py-3 text-sm">
                                        <?php echo function_exists('render_source_badge') ? render_source_badge($entry['_source'] ?? get_time_entry_source($entry)) : ''; ?></td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_date($entry['started_at'])); ?>
                                    </td>
                                    <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                        <?php echo e($entry['ended_at'] ? format_date($entry['ended_at']) : '-'); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                            <?php echo e(format_money($entry['billable_amount'])); ?></td>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);">
                                            <?php echo e(format_money($entry['cost_amount'])); ?></td>
                                        <td class="px-6 py-3 text-sm" style="color: var(--text-secondary);"><?php echo e(format_money($entry['profit'])); ?>
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
                alert(data.error || 'Failed to save');
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
        };
        reportRangeSelect.addEventListener('change', toggleRange);
        toggleRange();
    }

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
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; 
