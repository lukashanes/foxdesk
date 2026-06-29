<?php
/**
 * Admin - Time Reports
 */

$page_title = t('Time report');
$page = 'admin';
$current_user = current_user();

if (!is_admin() && (!function_exists('can_view_time') || !can_view_time($current_user))) {
    flash(t('Access denied.'), 'error');
    redirect(function_exists('foxdesk_authenticated_home_page') ? foxdesk_authenticated_home_page() : 'dashboard');
}

$time_tracking_available = ticket_time_table_exists();
if (function_exists('ensure_ticket_custom_billable_rate_column')) {
    ensure_ticket_custom_billable_rate_column();
}
if (function_exists('ensure_user_billable_rate_column')) {
    ensure_user_billable_rate_column();
}
if (function_exists('ensure_agent_client_billable_rates_table')) {
    ensure_agent_client_billable_rates_table();
}
$report_filter_state = report_filter_state_from_request($_GET, is_admin());
$tab = $report_filter_state['tab'];
$time_range = $report_filter_state['time_range'];
$range_start = $report_filter_state['range_start'];
$range_end = $report_filter_state['range_end'];
$selected_orgs = $report_filter_state['selected_orgs'];
$selected_agents = $report_filter_state['selected_agents'];
$tags_supported = function_exists('ticket_tags_column_exists') && ticket_tags_column_exists();
$selected_tags = $report_filter_state['selected_tags'];
$selected_tags_csv = $report_filter_state['selected_tags_csv'];
$show_money = $report_filter_state['show_money'];

$organizations = get_organizations(true);
$agents = db_fetch_all("SELECT id, first_name, last_name FROM users WHERE role IN ('agent', 'admin') AND is_active = 1 ORDER BY first_name, last_name");

$rounding = get_billing_rounding_increment();
$_ai_user_ids = function_exists('get_ai_user_ids') ? get_ai_user_ids() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    require_csrf_token();
    report_handle_admin_post_actions($_POST, $rounding);
}

$from_date = $range_start ? substr((string) $range_start, 0, 10) : '';
$to_date = $range_end ? substr((string) $range_end, 0, 10) : '';

$report_data = report_query_time_entries($report_filter_state, $current_user, $tags_supported, $rounding, $_ai_user_ids);
$entries = $report_data['entries'];
$totals = $report_data['totals'];
$by_org = $report_data['by_org'];
$by_agent = $report_data['by_agent'];
$by_ticket = $report_data['by_ticket'];
$by_week = $report_data['by_week'];
$by_source = $report_data['by_source'];
$billable_time_notice = $tab === 'billing' ? report_billable_time_notice($totals, $rounding) : null;

$has_cost_data = abs((float) ($totals['cost_amount'] ?? 0)) > 0.001;
report_export_csv_if_requested($_GET, $tab, $entries, $by_org, $tags_supported, (bool) $show_money, $has_cost_data);

$base_params = $_GET;
$base_params['page'] = 'admin';
$base_params['section'] = 'reports';

require_once BASE_PATH . '/includes/header.php';
?>
<?php
$page_header_title = $page_title;
$page_header_suppressed = true;
include BASE_PATH . '/includes/components/page-header.php';
?>

<div class="workflow-surface workflow-surface--reports admin-legacy-page" data-core-workflow-surface="reports">
    <?php if (is_admin() && $tab === 'billing'): ?>
    <section class="reporting-flow-card">
        <div class="reporting-flow-main">
            <div class="reporting-flow-heading">
                <p class="admin-eyebrow"><?php echo e(t('Billing review')); ?></p>
                <h2><?php echo e(t('Review client work before publishing')); ?></h2>
            </div>
            <form method="GET" action="index.php" class="reporting-flow-form">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="section" value="reports">
                <input type="hidden" name="tab" value="billing">
                <input type="hidden" name="show_money" value="1">
                <label>
                    <span><?php echo e(t('Client')); ?></span>
                    <select name="organizations[]" class="form-select" required>
                        <option value="" disabled <?php echo empty($selected_orgs) ? 'selected' : ''; ?>>
                            <?php echo e(t('Choose client')); ?>
                        </option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo (int) $org['id']; ?>"
                                <?php echo in_array((int) $org['id'], $selected_orgs, true) ? 'selected' : ''; ?>>
                                <?php echo e($org['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php echo e(t('Period')); ?></span>
                    <select name="time_range" class="form-select">
                        <?php foreach (reporting_flow_time_presets() as $preset => $label): ?>
                            <option value="<?php echo e($preset); ?>" <?php echo $time_range === $preset ? 'selected' : ''; ?>>
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary btn-sm">
                    <?php echo get_icon('search', 'w-3.5 h-3.5'); ?><?php echo e(t('Review items')); ?>
                </button>
            </form>
        </div>
        <div class="reporting-flow-side">
            <div class="reporting-flow-steps">
                <?php foreach (reporting_flow_steps() as $index => $step): ?>
                    <div class="reporting-flow-step">
                        <span><?php echo (int) $index + 1; ?></span>
                        <strong><?php echo e($step['label']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
            $selected_positive_orgs = array_values(array_filter($selected_orgs, static function ($id) {
                return (int) $id > 0;
            }));
            $selected_flow_org = count($selected_positive_orgs) === 1 ? (int) $selected_positive_orgs[0] : null;
            ?>
            <div class="admin-hero-actions">
            <a href="<?php echo url('admin', ['section' => 'reports-list']); ?>"
                class="btn btn-secondary btn-sm">
                <?php echo get_icon('list', 'w-3.5 h-3.5'); ?><?php echo e(t('Client reports')); ?>
            </a>
            <a href="<?php echo reporting_flow_builder_url($selected_flow_org, $time_range); ?>"
                class="btn btn-primary btn-sm">
                <?php echo get_icon('plus', 'w-3.5 h-3.5'); ?><?php echo e(t('Create report')); ?>
            </a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <div class="report-page-toolbar report-page-toolbar--modes">
        <div class="report-mode-switch" aria-label="<?php echo e(t('Report mode')); ?>">
            <?php
            $tab_labels = [
                'time' => t('Time overview'),
            ];
            if (is_admin()) {
                $tab_labels['billing'] = t('Billing review');
                $tab_labels['published'] = t('Published reports');
            }
            foreach ($tab_labels as $tab_key => $label):
                $params = $base_params;
                $params['tab'] = $tab_key;
                $tab_url = 'index.php?' . http_build_query($params);
                ?>
                <a href="<?php echo e($tab_url); ?>"
                    class="report-mode-link <?php echo $tab === $tab_key ? 'is-active' : ''; ?>">
                    <?php echo e($label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (in_array($tab, ['billing', 'time'], true) && !empty($entries)): ?>
        <div class="report-actions">
            <?php if ($tab === 'billing'): ?>
            <!-- Column Picker -->
            <div class="relative" id="col-picker-wrap">
                <button type="button" onclick="document.getElementById('col-picker-dropdown').classList.toggle('hidden')"
                    class="report-mini-action"
                    title="<?php echo e(t('Columns')); ?>">
                    <?php echo get_icon('columns', 'w-3 h-3 inline-block'); ?><?php echo e(t('Columns')); ?>
                </button>
                <div id="col-picker-dropdown" class="report-col-picker-dropdown hidden absolute right-0 mt-1 w-44 rounded-lg shadow-lg border z-50 p-1.5">
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
                    <label class="flex items-center gap-2 px-2 py-1 text-xs rounded cursor-pointer text-theme-primary">
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
                class="report-mini-action"
                title="<?php echo e(t('Export CSV')); ?>">
                <?php echo get_icon('download', 'w-3 h-3 inline-block'); ?><?php echo e(t('Export CSV')); ?>
            </a>

            <!-- Print -->
            <button type="button" onclick="window.print()"
                class="report-mini-action"
                title="<?php echo e(t('Print')); ?>">
                <?php echo get_icon('print', 'w-3 h-3 inline-block'); ?><?php echo e(t('Print')); ?>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$time_tracking_available): ?>
        <div class="card card-body text-theme-secondary">
            <?php echo e(t('Time tracking is not available.')); ?>
        </div>
    <?php else: ?>
        <?php if ($tab !== 'published'): ?>
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
            <div class="report-filter-pills" id="report-filter-pills">
                <span class="report-filter-pills__label"><?php echo e(t('Filters')); ?>:</span>
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
                    <span class="report-filter-pill">
                        <?php echo get_icon('user', 'w-3 h-3'); ?>
                        <?php echo e(t('My entries')); ?>: <?php echo e($af['label']); ?>
                    </span>
                    <?php else: ?>
                    <a href="<?php echo e($remove_url); ?>"
                       class="report-filter-pill"
                       title="<?php echo e(t('Remove filter')); ?>">
                        <?php echo e($af['label']); ?>
                        <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <details class="card mb-2" id="report-filters" <?php echo !$filter_collapsed ? 'open' : ''; ?>>
                <summary class="card-header report-filter-summary">
                    <div class="report-filter-summary__main">
                        <?php echo get_icon('sliders-horizontal', 'w-3.5 h-3.5'); ?>
                        <span class="report-filter-summary__title"><?php echo e(t('Filters')); ?></span>
                        <span class="report-filter-summary__text"><?php echo e($filter_summary_text); ?></span>
                    </div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="rpt-chevron report-filter-summary__chevron"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </summary>
                <div class="report-filter-body">
                <form method="get">
                    <input type="hidden" name="page" value="admin">
                    <input type="hidden" name="section" value="reports">
                    <input type="hidden" name="tab" value="<?php echo e($tab); ?>">

                    <!-- Row 1: All filter fields on one horizontal line -->
                    <div class="report-filter-grid">
                        <div class="report-filter-field">
                            <label class="block text-xs mb-1 font-medium text-theme-secondary"><?php echo e(t('Clients')); ?></label>
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
                        <div class="report-filter-field">
                            <label class="block text-xs mb-1 font-medium text-theme-secondary"><?php echo e(t('Agents')); ?></label>
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
                        <div class="report-filter-field">
                            <label class="block text-xs mb-1 font-medium text-theme-secondary">
                                <?php echo e(t('Tags')); ?>
                                <span class="report-filter-field__hint"><?php echo e(t('OR matching')); ?></span>
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

                        <div class="report-filter-field">
                            <label class="block text-xs mb-1 font-medium text-theme-secondary"><?php echo e(t('Time range')); ?></label>
                            <select name="time_range" id="report-time-range" class="form-select w-full">
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
                    <div class="report-filter-actions">
                        <?php if ($range_start && $range_end && $time_range !== 'custom' && $time_range !== 'all'): ?>
                        <span id="report-range-hint" class="report-range-hint">
                            <?php echo get_icon('calendar', 'w-3 h-3 inline-block'); ?>
                            <?php echo date('M j', strtotime($range_start)); ?> – <?php echo date('M j, Y', strtotime($range_end)); ?>
                        </span>
                        <?php endif; ?>

                        <?php if (is_admin()): ?>
                        <label class="report-toggle-label">
                            <input type="checkbox" name="show_money" value="1" class="rounded" <?php echo $show_money ? 'checked' : ''; ?>>
                            <?php echo e(t('Show amounts')); ?>
                        </label>
                        <?php endif; ?>

                        <!-- Quick range presets -->
                        <div class="report-preset-list">
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
                                class="range-preset-btn <?php echo $time_range === $preset_val ? 'is-active' : ''; ?>"
                                data-range="<?php echo e($preset_val); ?>"
                                onclick="setTimeRange('<?php echo e($preset_val); ?>')">
                                <?php echo e($preset_label); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" id="report-apply-btn" class="btn btn-primary btn-sm"><?php echo e(t('Apply')); ?></button>
                    </div>

                    <!-- Custom date range (shown only when "Custom range" selected) -->
                    <div id="report-custom-range" class="report-custom-range <?php echo $time_range === 'custom' ? 'is-open' : ''; ?>">
                        <div>
                            <label class="block text-xs mb-1 font-medium text-theme-secondary"><?php echo e(t('From date')); ?></label>
                            <input type="date" name="from_date" value="<?php echo e($from_date); ?>" class="form-input">
                        </div>
                        <div>
                            <label class="block text-xs mb-1 font-medium text-theme-secondary"><?php echo e(t('To date')); ?></label>
                            <input type="date" name="to_date" value="<?php echo e($to_date); ?>" class="form-input">
                        </div>
                    </div>

                    <!-- Confirmation overlay (hidden) -->
                    <div id="report-confirm" class="report-confirm hidden flex-basis-full">
                        <div class="report-confirm__title"><?php echo e(t('Generate report with these filters?')); ?></div>
                        <div id="report-confirm-body"></div>
                        <div class="report-confirm__actions">
                            <button type="button" id="report-confirm-back" class="btn btn-sm report-confirm-back"><?php echo e(t('Back')); ?></button>
                            <button type="submit" class="btn btn-primary btn-sm"><?php echo e(t('Generate Report')); ?></button>
                        </div>
                    </div>
                </form>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($tab === 'time'): ?>
            <?php
            $work_log_rows = report_time_overview_work_log_rows($entries, 120);
            $work_log_total = count($entries);
            ?>
            <div class="report-summary-strip">
                <div class="report-metric">
                    <div class="report-metric__label"><?php echo e(is_admin() ? t('Total time') : t('My time')); ?></div>
                    <div class="report-metric__value"><?php echo e(format_duration_minutes($totals['minutes'])); ?></div>
                </div>
                <div class="report-metric">
                    <div class="report-metric__label"><?php echo e(t('Entries')); ?></div>
                    <div class="report-metric__value"><?php echo e((string) count($entries)); ?></div>
                </div>
                <div class="report-metric">
                    <div class="report-metric__label"><?php echo e(t('Clients')); ?></div>
                    <div class="report-metric__value"><?php echo e((string) count($by_org)); ?></div>
                </div>
                <?php if (is_admin()): ?>
                <div class="report-metric">
                    <div class="report-metric__label"><?php echo e(t('Agents')); ?></div>
                    <div class="report-metric__value"><?php echo e((string) count($by_agent)); ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($work_log_rows)): ?>
            <section class="card report-worklog-card" data-report-time-overview-log>
                <div class="card-header report-worklog-card__header">
                    <div>
                        <p class="admin-eyebrow"><?php echo e(t('Work log')); ?></p>
                        <h3 class="report-section-title"><?php echo e(t('What was done')); ?></h3>
                    </div>
                    <?php if ($work_log_total > count($work_log_rows)): ?>
                    <span class="report-worklog-card__count">
                        <?php echo e(t('Showing latest {shown} of {total} entries.', ['shown' => count($work_log_rows), 'total' => $work_log_total])); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="overflow-x-auto">
                    <table class="data-table report-worklog-table">
                        <thead>
                            <tr>
                                <th><?php echo e(t('Started')); ?></th>
                                <th><?php echo e(t('Agent')); ?></th>
                                <th><?php echo e(t('Ticket')); ?></th>
                                <th><?php echo e(t('Client')); ?></th>
                                <th><?php echo e(t('Time')); ?></th>
                                <th><?php echo e(t('Note')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($work_log_rows as $row): ?>
                            <tr>
                                <td>
                                    <span class="report-worklog-table__date"><?php echo e($row['started_label']); ?></span>
                                    <?php if ($row['ended_label'] !== ''): ?>
                                    <span class="report-worklog-table__muted">– <?php echo e($row['ended_label']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($row['agent']); ?></td>
                                <td>
                                    <a href="<?php echo url('ticket', ['id' => $row['ticket_id']]); ?>" class="report-worklog-table__ticket">
                                        <?php if ($row['ticket_code'] !== ''): ?>
                                            <span><?php echo e($row['ticket_code']); ?></span>
                                        <?php endif; ?>
                                        <?php echo e($row['ticket_title']); ?>
                                    </a>
                                </td>
                                <td><?php echo e($row['client']); ?></td>
                                <td>
                                    <span class="report-worklog-table__duration"><?php echo e(format_duration_minutes($row['minutes'])); ?></span>
                                </td>
                                <td class="report-worklog-table__note"><?php echo e($row['summary']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>
            <?php if ($billable_time_notice): ?>
            <div class="report-billing-note report-billing-note--<?php echo e($billable_time_notice['tone']); ?>">
                <div class="report-billing-note__head">
                    <?php echo get_icon('info', 'w-3.5 h-3.5'); ?>
                    <strong><?php echo e($billable_time_notice['title']); ?></strong>
                </div>
                <div class="report-billing-note__body">
                    <span><?php echo e($billable_time_notice['text']); ?></span>
                    <?php if (!empty($billable_time_notice['delta'])): ?>
                        <span class="report-billing-note__delta"><?php echo e($billable_time_notice['delta']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php
            $human_min = $totals['human_minutes'] ?? 0;
            $ai_min = $totals['ai_minutes'] ?? 0;
            if ($human_min > 0 && $ai_min > 0):
            ?>
            <div class="report-source-strip">
                <div class="report-source-card report-source-card--human">
                    <div class="report-source-card__label">
                        <?php echo get_icon('user', 'w-3 h-3'); ?>
                        <?php echo e(t('Human')); ?>
                    </div>
                    <div class="report-source-card__value"><?php echo e(format_duration_minutes($human_min)); ?></div>
                    <?php if ($show_money): ?>
                        <div class="report-source-card__meta">
                            <?php echo e(t('Billable')); ?>: <?php echo e(format_money($totals['human_billable'] ?? 0)); ?>
                            <?php if ($has_cost_data): ?>
                            · <?php echo e(t('Cost')); ?>: <?php echo e(format_money($totals['human_cost'] ?? 0)); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="report-source-card report-source-card--ai">
                    <div class="report-source-card__label">
                        <?php echo get_icon('bot', 'w-3 h-3'); ?>
                        <?php echo e(t('AI')); ?>
                    </div>
                    <div class="report-source-card__value"><?php echo e(format_duration_minutes($ai_min)); ?></div>
                    <?php if ($show_money): ?>
                        <div class="report-source-card__meta">
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
                    <div class="text-4xl mb-3 text-theme-muted">📊</div>
                    <div class="font-semibold mb-1 text-theme-primary"><?php echo e(t('No time entries found')); ?></div>
                    <div class="text-sm text-theme-muted"><?php echo e(t('Try adjusting the time range or filters above.')); ?></div>
                </div>
            <?php else: ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-2">
                <div class="card overflow-hidden">
                    <div class="card-header report-card-header--compact">
                        <h3 class="report-section-title"><?php echo e(t('Company')); ?></h3>
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
                                        <td class="px-3 py-1.5 text-xs text-theme-primary"><?php echo e($org['name']); ?></td>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                            <?php echo e(format_duration_minutes($org['minutes'])); ?>
                                            <div class="flex items-center gap-1.5 mt-1">
                                                <div class="report-mini-progress">
                                                    <div class="report-mini-progress__bar report-mini-progress__bar--org <?php echo e(report_width_class($org_pct)); ?>"></div>
                                                </div>
                                                <span class="text-xs text-theme-muted"><?php echo $org_pct; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                            <?php echo e(format_duration_minutes($org['billable_minutes'])); ?></td>
                                        <?php if ($show_money): ?>
                                            <td class="px-3 py-1.5 text-xs text-theme-secondary"><?php echo e(format_money($org['rate'])); ?></td>
                                            <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                                <?php echo e(format_money($org['billable_amount'])); ?></td>
                                        <?php endif; ?>
                                        <?php if ($show_money && $has_cost_data): ?>
                                            <td class="px-3 py-1.5 text-xs text-theme-secondary"><?php echo e(format_money($org['profit'])); ?>
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
                    <div class="card-header report-card-header--compact">
                        <h3 class="report-section-title"><?php echo e(t('Agents')); ?></h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-theme-secondary">
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
                                        <td class="px-3 py-1.5 text-xs text-theme-primary"><?php echo e($agent['name']); ?></td>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                            <?php echo e(format_duration_minutes($agent['minutes'])); ?>
                                            <div class="flex items-center gap-1.5 mt-1">
                                                <div class="report-mini-progress">
                                                    <div class="report-mini-progress__bar report-mini-progress__bar--agent <?php echo e(report_width_class($agent_pct)); ?>"></div>
                                                </div>
                                                <span class="text-xs text-theme-muted"><?php echo $agent_pct; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                            <?php echo e(format_duration_minutes($agent['billable_minutes'])); ?></td>
                                        <?php if ($show_money): ?>
                                            <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                                <?php echo e(format_money($agent['billable_amount'])); ?></td>
                                        <?php endif; ?>
                                        <?php if ($show_money && $has_cost_data): ?>
                                            <td class="px-3 py-1.5 text-xs text-theme-secondary"><?php echo e(format_money($agent['profit'])); ?>
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
                <div class="card-header border-theme-light">
                    <h3 class="font-semibold text-theme-primary"><?php echo e(t('Source')); ?></h3>
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
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary"><?php echo (int) $src['count']; ?></td>
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                        <?php echo e(format_duration_minutes($src['minutes'])); ?>
                                        <div class="flex items-center gap-1.5 mt-1">
                                            <div class="report-mini-progress">
                                                <div class="report-mini-progress__bar report-mini-progress__bar--source <?php echo e(report_width_class($src_pct)); ?>"></div>
                                            </div>
                                            <span class="text-xs text-theme-muted"><?php echo $src_pct; ?>%</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary"><?php echo e(format_duration_minutes($src['billable_minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary"><?php echo e(format_money($src['billable_amount'])); ?></td>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary"><?php echo e(format_money($src['profit'])); ?></td>
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
                    <h3 class="font-semibold text-theme-primary"><?php echo e(t('Tickets')); ?></h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-theme-secondary">
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
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary">
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
                                                <span class="text-theme-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                        <?php echo e(format_duration_minutes($ticket['minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                            <?php echo e(format_money($ticket['billable_amount'])); ?></td>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary"><?php echo e(format_money($ticket['profit'])); ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if (!empty($closed_tickets_report)): ?>
                        <tbody class="border-t-2" style="border-top-color: var(--border-light);">
                            <tr class="cursor-pointer bg-theme-secondary" onclick="document.getElementById('closed-tickets-report').classList.toggle('hidden')">
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
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary">
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
                                                <span class="text-theme-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                        <?php echo e(format_duration_minutes($ticket['minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                            <?php echo e(format_money($ticket['billable_amount'])); ?></td>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary"><?php echo e(format_money($ticket['profit'])); ?>
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
                    <div class="text-4xl mb-3 text-theme-muted">📅</div>
                    <div class="font-semibold mb-1 text-theme-primary"><?php echo e(t('No time entries found')); ?></div>
                    <div class="text-sm text-theme-muted"><?php echo e(t('Try adjusting the time range or filters above.')); ?></div>
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
                    <h3 class="font-semibold text-theme-primary"><?php echo e(t('Weekly')); ?></h3>
                    <?php if (count($weekly_agent_ids) > 1): ?>
                    <div class="flex flex-wrap items-center gap-3">
                        <?php foreach ($weekly_agent_ids as $aid): ?>
                            <?php $aname = ''; foreach ($by_week as $w) { if (isset($w['agents'][$aid])) { $aname = $w['agents'][$aid]['name']; break; } } ?>
                            <div class="flex items-center gap-1.5 text-xs text-theme-secondary">
                                <span style="display:inline-block;width:10px;height:10px;border-radius: var(--fd-radius-control);background:<?php echo $weekly_agent_color_map[$aid]; ?>;"></span>
                                <?php echo e($aname); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-theme-secondary">
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
                                        <div class="text-sm font-medium text-theme-primary"><?php echo e($week['label_formatted']); ?></div>
                                    </td>
                                    <td class="px-6 py-3">
                                        <div class="text-sm text-theme-secondary">
                                            <?php echo e(format_duration_minutes($week['minutes'])); ?>
                                        </div>
                                        <?php if ($weekly_max_minutes > 0): ?>
                                        <div style="display:flex;height:6px;border-radius: var(--fd-radius-control);overflow:hidden;background:var(--border-light);margin-top:4px;width:100%;max-width:160px;" title="<?php
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
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                        <?php echo e(format_duration_minutes($week['billable_minutes'])); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary">
                                            <?php echo e(format_money($week['billable_amount'])); ?></td>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary"><?php echo e(format_money($week['profit'])); ?></td>
                                    <?php endif; ?>
                                </tr>
                                <?php if (count($week['agents']) > 0): ?>
                                <tr id="week-agents-<?php echo $wi; ?>" class="hidden">
                                    <td colspan="<?php echo $weekly_col_count; ?>" class="px-0 py-0">
                                        <div class="px-6 py-3 bg-theme-secondary">
                                            <div class="grid gap-2" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));">
                                                <?php
                                                $wa_sorted2 = $week['agents'];
                                                uasort($wa_sorted2, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
                                                foreach ($wa_sorted2 as $aid => $ag):
                                                    $ag_pct = $week['minutes'] > 0 ? round(($ag['minutes'] / $week['minutes']) * 100) : 0;
                                                ?>
                                                <div class="flex items-center gap-2 px-3 py-2 rounded-lg" style="background: var(--surface-primary);">
                                                    <span style="display:inline-block;width:8px;height:8px;border-radius: var(--fd-radius-control);flex-shrink:0;background:<?php echo $weekly_agent_color_map[$aid] ?? '#94a3b8'; ?>;"></span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="text-xs font-medium truncate text-theme-primary"><?php echo e($ag['name']); ?></div>
                                                        <div class="text-xs text-theme-muted">
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
        <?php elseif ($tab === 'billing'): ?>
            <?php if (empty($entries)): ?>
                <div class="card card-body p-8 text-center">
                    <div class="text-4xl mb-3 text-theme-muted">📋</div>
                    <div class="font-semibold mb-1 text-theme-primary"><?php echo e(t('No time entries found')); ?></div>
                    <div class="text-sm text-theme-muted"><?php echo e(t('Try adjusting the time range or filters above.')); ?></div>
                </div>
            <?php else: ?>
            <div class="reporting-review-surface"
                data-app-contract-surface="reporting-review"
                data-app-contract-action="app-reporting-review"
                data-report-currency="<?php echo e(function_exists('get_currency_label') ? get_currency_label() : 'CZK'); ?>">
            <div class="report-detail-totals" id="report-detail-totals">
                <div class="report-metric">
                    <div class="report-metric__label"><?php echo e(t('Total time')); ?></div>
                    <div id="detail-total-time" class="report-metric__value" data-report-total="minutes"><?php echo e(format_duration_minutes($totals['minutes'])); ?></div>
                </div>
                <div class="report-metric">
                    <div class="report-metric__label"><?php echo e(t('Billable time')); ?></div>
                    <div id="detail-billable-time" class="report-metric__value" data-report-total="billable_minutes"><?php echo e(format_duration_minutes($totals['billable_minutes'])); ?></div>
                </div>
                <?php if ($show_money): ?>
                <div class="report-metric">
                    <div class="report-metric__label"><?php echo e(t('Billable amount')); ?></div>
                    <div id="detail-billable-amount" class="report-metric__value" data-report-total="billable_amount"><?php echo e(format_money($totals['billable_amount'])); ?></div>
                </div>
                <?php if ($has_cost_data): ?>
                <div class="report-metric">
                    <div class="report-metric__label"><?php echo e(t('Profit')); ?></div>
                    <div id="detail-profit" class="report-metric__value" data-report-total="profit"><?php echo e(format_money($totals['profit'])); ?></div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($billable_time_notice): ?>
            <div class="report-billing-note report-billing-note--<?php echo e($billable_time_notice['tone']); ?>">
                <div class="report-billing-note__head">
                    <?php echo get_icon('info', 'w-3.5 h-3.5'); ?>
                    <strong><?php echo e($billable_time_notice['title']); ?></strong>
                </div>
                <div class="report-billing-note__body">
                    <span><?php echo e($billable_time_notice['text']); ?></span>
                    <?php if (!empty($billable_time_notice['delta'])): ?>
                        <span class="report-billing-note__delta"><?php echo e($billable_time_notice['delta']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="font-semibold text-theme-primary"><?php echo e(t('Detailed')); ?></h3>
                </div>
                <?php if (is_admin()): ?>
                <form id="bulk-billing-form" method="post" class="report-bulk-billing px-4 py-3 border-b">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="bulk_update_billable_entries" value="1">
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="min-w-[180px]">
                            <label class="block text-xs font-medium mb-1 text-theme-secondary"><?php echo e(t('Bulk billing adjustments')); ?></label>
                            <select name="bulk_action" class="form-select text-sm">
                                <?php foreach (billing_review_bulk_adjustment_actions() as $action_key => $action_label): ?>
                                    <option value="<?php echo e($action_key); ?>"><?php echo e($action_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="w-32">
                            <label class="block text-xs font-medium mb-1 text-theme-secondary"><?php echo e(t('Hourly rate')); ?></label>
                            <input type="number" step="0.01" min="0" name="bulk_rate" class="form-input text-sm" placeholder="1000">
                        </div>
                        <div class="w-32">
                            <label class="block text-xs font-medium mb-1 text-theme-secondary"><?php echo e(t('Discount (%)')); ?></label>
                            <input type="number" step="0.01" min="0" max="100" name="bulk_discount_percent" class="form-input text-sm" placeholder="10">
                        </div>
                        <div class="w-36">
                            <label class="block text-xs font-medium mb-1 text-theme-secondary"><?php echo e(t('Discount amount')); ?></label>
                            <input type="number" step="0.01" min="0" name="bulk_discount_amount" class="form-input text-sm" placeholder="500">
                        </div>
                        <div class="w-36">
                            <label class="block text-xs font-medium mb-1 text-theme-secondary"><?php echo e(t('Target total')); ?></label>
                            <input type="number" step="0.01" min="0" name="bulk_target_total" class="form-input text-sm" placeholder="15000">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <?php echo e(t('Apply to selected')); ?>
                        </button>
                    </div>
                </form>
                <?php endif; ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-theme-secondary">
                            <tr>
                                <?php if (is_admin()): ?>
                                <th class="px-3 py-2 text-left th-label">
                                    <input type="checkbox" id="bulk-select-all" class="rounded" title="<?php echo e(t('Select all')); ?>">
                                </th>
                                <?php endif; ?>
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
                                    <th class="px-3 py-2 text-left th-label report-amount-col" data-col="amount">
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
                                <tr class="report-detail-row"
                                    data-report-entry-row
                                    data-billable="<?php echo !empty($entry['is_billable']) ? '1' : '0'; ?>"
                                    data-actual-minutes="<?php echo (int) $entry['actual_minutes']; ?>"
                                    data-billable-minutes="<?php echo (int) $entry['billable_minutes']; ?>"
                                    data-original-rate="<?php echo e(number_format((float) $entry['billable_rate'], 2, '.', '')); ?>"
                                    data-original-amount="<?php echo e(number_format((float) $entry['billable_amount'], 2, '.', '')); ?>"
                                    data-cost-amount="<?php echo e(number_format((float) $entry['cost_amount'], 2, '.', '')); ?>">
                                    <?php if (is_admin()): ?>
                                    <td class="px-3 py-1.5 text-xs">
                                        <input type="checkbox" class="bulk-entry-check rounded" name="entry_ids[]" value="<?php echo $entry['id']; ?>" form="bulk-billing-form" <?php echo !empty($entry['is_billable']) ? '' : 'disabled'; ?>>
                                    </td>
                                    <?php endif; ?>
                                    <td class="px-3 py-1.5 text-xs" data-col="ticket"><a href="<?php echo url('ticket', ['id' => $entry['ticket_id']]); ?>" class="text-blue-600 hover:text-blue-800 hover:underline" data-report-entry-field="ticket"><?php echo e($entry['ticket_title']); ?></a></td>
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary" data-col="company" data-report-entry-field="client">
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
                                                <span class="text-theme-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary" data-col="duration" data-report-entry-field="minutes">
                                        <?php echo e(format_duration_minutes($entry['actual_minutes'])); ?></td>
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary" data-col="billable">
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
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary" data-col="agent" data-report-entry-field="agent">
                                        <?php echo e(trim($entry['first_name'] . ' ' . $entry['last_name'])); ?></td>
                                    <td class="px-3 py-1.5 text-xs" data-col="source">
                                        <?php echo function_exists('render_source_badge') ? render_source_badge($entry['_source'] ?? get_time_entry_source($entry)) : ''; ?></td>
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary" data-col="start"><?php echo e(format_date($entry['started_at'])); ?>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs text-theme-secondary" data-col="end">
                                        <?php echo e($entry['ended_at'] ? format_date($entry['ended_at']) : '-'); ?></td>
                                    <?php if ($show_money): ?>
                                        <td class="px-3 py-1.5 text-xs report-amount-col" data-col="amount">
                                            <div data-entry-amount data-report-entry-field="amount"><?php echo e(format_money($entry['billable_amount'])); ?></div>
                                            <div class="text-[11px] text-theme-muted" data-entry-rate data-report-entry-field="rate"><?php echo e(format_money($entry['billable_rate'])); ?>/h</div>
                                            <?php if (is_admin()): ?>
                                            <form method="post" class="entry-billing-form mt-1 flex items-center gap-1" data-entry-id="<?php echo $entry['id']; ?>">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="entry_id" value="<?php echo $entry['id']; ?>">
                                                <select name="entry_adjust_action" class="form-select text-[11px] py-1 report-adjust-action">
                                                    <?php foreach (billing_review_adjustment_actions() as $action_key => $action_label): ?>
                                                        <option value="<?php echo e($action_key); ?>"><?php echo e($action_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="number" name="entry_adjust_value" step="0.01" min="0" class="form-input text-[11px] py-1 report-adjust-value" placeholder="<?php echo e(t('Value')); ?>">
                                                <button type="submit" name="adjust_billable_entry" class="btn btn-ghost btn-sm py-1 px-2 shrink-0" title="<?php echo e(t('Save billing')); ?>">
                                                    <?php echo get_icon('check', 'w-3 h-3'); ?>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <?php if ($show_money && $has_cost_data): ?>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary" data-col="cost">
                                            <?php echo e(format_money($entry['cost_amount'])); ?></td>
                                        <td class="px-3 py-1.5 text-xs text-theme-secondary" data-col="profit"><?php echo e(format_money($entry['profit'])); ?>
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
                                                <button type="submit" name="delete_entry" class="hover:text-red-600 text-theme-muted"
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
                                                <span class="report-empty-value">—</span>
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
        <?php elseif ($tab === 'rates' && is_admin()): ?>
            <?php $agent_client_rates = function_exists('get_agent_client_billable_rates') ? get_agent_client_billable_rates() : []; ?>
            <?php $agent_default_rates = function_exists('get_agent_default_billable_rates') ? get_agent_default_billable_rates() : []; ?>
            <div class="admin-two-column">
                <div class="space-y-4">
                <div class="admin-list-card">
                    <div class="card-header">
                        <div>
                            <h3><?php echo e(t('Agent default rates')); ?></h3>
                            <p><?php echo e(t('Used when no ticket or client-specific rate is set.')); ?></p>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?php echo e(t('Agent')); ?></th>
                                    <th><?php echo e(t('Default billable rate')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agent_default_rates as $rate_row): ?>
                                    <tr>
                                        <td><?php echo e(trim(($rate_row['first_name'] ?? '') . ' ' . ($rate_row['last_name'] ?? '')) ?: $rate_row['email']); ?></td>
                                        <td><?php echo (float) ($rate_row['billable_rate'] ?? 0) > 0 ? e(format_money($rate_row['billable_rate'])) . '/h' : '<span class="text-theme-muted">-</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="admin-list-card">
                    <div class="card-header">
                        <div>
                            <h3><?php echo e(t('Agent client rates')); ?></h3>
                            <p><?php echo e(t('Override the client hourly rate for a specific agent or admin.')); ?></p>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?php echo e(t('Client')); ?></th>
                                    <th><?php echo e(t('Agent')); ?></th>
                                    <th><?php echo e(t('Billable rate')); ?></th>
                                    <th><?php echo e(t('Notes')); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($agent_client_rates)): ?>
                                    <tr>
                                        <td colspan="5" class="text-sm text-theme-muted"><?php echo e(t('No custom rates yet.')); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($agent_client_rates as $rate_row): ?>
                                    <tr>
                                        <td><?php echo e($rate_row['organization_name']); ?></td>
                                        <td><?php echo e(trim(($rate_row['first_name'] ?? '') . ' ' . ($rate_row['last_name'] ?? '')) ?: $rate_row['email']); ?></td>
                                        <td><?php echo e(format_money($rate_row['billable_rate'])); ?>/h</td>
                                        <td class="text-sm text-theme-muted"><?php echo e($rate_row['notes'] ?? ''); ?></td>
                                        <td class="text-right">
                                            <form method="post" class="inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="rate_id" value="<?php echo (int) $rate_row['id']; ?>">
                                                <button type="submit" name="delete_agent_client_rate" class="btn btn-ghost btn-xs"
                                                    onclick="return confirm('<?php echo e(t('Delete this rate?')); ?>')">
                                                    <?php echo e(t('Delete')); ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                </div>

                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <div>
                            <h3><?php echo e(t('Add rate')); ?></h3>
                            <p><?php echo e(t('Specific client rates override agent defaults.')); ?></p>
                        </div>
                    </div>
                    <form method="post" class="admin-panel-body space-y-3 border-b border-theme">
                        <?php echo csrf_field(); ?>
                        <div>
                            <label class="block text-sm font-medium mb-1"><?php echo e(t('Agent')); ?></label>
                            <select name="user_id" class="form-select" required>
                                <option value=""><?php echo e(t('Select agent')); ?></option>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?php echo (int) $agent['id']; ?>"><?php echo e(trim($agent['first_name'] . ' ' . $agent['last_name'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1"><?php echo e(t('Default billable rate')); ?></label>
                            <input type="number" name="billable_rate" step="0.01" min="0" class="form-input" placeholder="1000" required>
                        </div>
                        <button type="submit" name="save_agent_default_rate" class="btn btn-secondary w-full justify-center">
                            <?php echo e(t('Save default rate')); ?>
                        </button>
                    </form>
                    <form method="post" class="admin-panel-body space-y-3">
                        <?php echo csrf_field(); ?>
                        <div>
                            <label class="block text-sm font-medium mb-1"><?php echo e(t('Client')); ?></label>
                            <select name="organization_id" class="form-select" required>
                                <option value=""><?php echo e(t('Select client')); ?></option>
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?php echo (int) $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1"><?php echo e(t('Agent')); ?></label>
                            <select name="user_id" class="form-select" required>
                                <option value=""><?php echo e(t('Select agent')); ?></option>
                                <?php foreach ($agents as $agent): ?>
                                    <option value="<?php echo (int) $agent['id']; ?>"><?php echo e(trim($agent['first_name'] . ' ' . $agent['last_name'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1"><?php echo e(t('Billable rate (per hour)')); ?></label>
                            <input type="number" name="billable_rate" step="0.01" min="0" class="form-input" placeholder="750" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1"><?php echo e(t('Notes')); ?></label>
                            <textarea name="notes" rows="3" class="form-textarea" placeholder="<?php echo e(t('Optional')); ?>"></textarea>
                        </div>
                        <button type="submit" name="save_agent_client_rate" class="btn btn-primary w-full justify-center">
                            <?php echo e(t('Save rate')); ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php elseif ($tab === 'published'): ?>
            <div class="card card-body space-y-4">
                <h3 class="font-semibold text-theme-primary"><?php echo e(t('Share link')); ?></h3>
                <form method="post" class="space-y-4">
                    <?php echo csrf_field(); ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium" class="mb-1 text-theme-secondary"><?php echo e(t('Company')); ?></label>
                            <select name="organization_id" class="form-select">
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?php echo $org['id']; ?>"><?php echo e($org['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label
                                class="block text-sm font-medium" class="mb-1 text-theme-secondary"><?php echo e(t('Expiry (optional)')); ?></label>
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
                    <div class="border border-green-200 rounded-lg p-4 bg-theme-secondary">
                        <div class="text-sm text-green-600 mb-2"><?php echo e(t('Share link created.')); ?></div>
                        <input type="text" readonly class="form-input" value="<?php echo e($share_url); ?>" onclick="this.select()">
                    </div>
                <?php elseif ($active_share): ?>
                    <div class="border border-yellow-200 rounded-lg p-4 text-sm text-yellow-600 bg-theme-secondary">
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
                    <div class="text-sm text-theme-muted"><?php echo e(t('No active share link exists yet.')); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($tab === 'billing' || $tab === 'worklog'): ?>
    <div id="entryModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="rounded-xl shadow-xl max-w-lg w-full mx-4 p-4" style="background: var(--bg-primary);">
            <h3 class="font-semibold mb-4 text-theme-primary"><?php echo e(t('Edit time entry')); ?></h3>
            <form method="post" class="space-y-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="entry_id" id="edit_entry_id">

                <div>
                    <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Ticket ID')); ?></label>
                    <input type="text" name="ticket_id" id="edit_ticket_id" class="form-input">
                    <p class="text-xs mt-1 text-theme-muted"><?php echo e(t('Ticket code (e.g., TK-0003)')); ?></p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1 text-theme-secondary"><?php echo e(t('Ticket title')); ?></label>
                    <input type="text" name="ticket_title" id="edit_ticket_title" class="form-input">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label
                            class="block text-sm font-medium" class="mb-1 text-theme-secondary"><?php echo e(t('Start time')); ?></label>
                        <input type="datetime-local" name="started_at" id="edit_started_at" class="form-input" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" class="mb-1 text-theme-secondary"><?php echo e(t('End time')); ?></label>
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
            reportCustomRange.classList.toggle('is-open', reportRangeSelect.value === 'custom');
            document.querySelectorAll('.range-preset-btn').forEach(function(btn) {
                btn.classList.toggle('is-active', btn.dataset.range === reportRangeSelect.value);
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

    (function () {
        var selectAll = document.getElementById('bulk-select-all');
        if (!selectAll) return;
        var checks = Array.prototype.slice.call(document.querySelectorAll('.bulk-entry-check:not(:disabled)'));

        selectAll.addEventListener('change', function () {
            checks.forEach(function (check) {
                check.checked = selectAll.checked;
            });
        });

        checks.forEach(function (check) {
            check.addEventListener('change', function () {
                var checkedCount = checks.filter(function (item) { return item.checked; }).length;
                selectAll.checked = checkedCount === checks.length && checks.length > 0;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < checks.length;
            });
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
                        if (customRange) customRange.classList.add('is-open');
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
<script src="assets/js/report-billing-review.js"></script>

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
