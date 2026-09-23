<?php
/**
 * Dashboard page.
 *
 * Action-first view for daily support work. Dashboard keeps analytics; this page
 * shows the queues that need attention now.
 */

$page_title = t('Dashboard');
$page = 'work';
$user = current_user();
$is_staff = is_admin() || is_agent();

$queue_key = trim((string) ($_GET['queue'] ?? 'all'));
$queue_definitions = work_queue_definitions();
if (!isset($queue_definitions[$queue_key])) {
    $queue_key = 'all';
}
if (($queue_definitions[$queue_key]['scope'] ?? '') === 'team' && !$is_staff) {
    $queue_key = 'mine';
}

$queue_summary = work_queue_summary($user, 8);
$active_queue = $queue_summary[$queue_key] ?? ($queue_summary['mine'] ?? reset($queue_summary));
$queue_pages = max(1, (int) ceil(($active_queue['count'] ?? 0) / 8));
$queue_page = max(1, min($queue_pages, (int) ($_GET['queue_page'] ?? 1)));
$active_items = work_queue_items($queue_key, $user, 8, ($queue_page - 1) * 8, 'last_updated');
$queue_mode = $is_staff && ($_GET['queue_mode'] ?? '') === 'activity' ? 'activity' : 'tickets';
require_once BASE_PATH . '/includes/modules/work/recent-updates.php';
$recent_updates = $queue_mode === 'activity' ? work_recent_updates($queue_key, $user, (int) ($_GET['queue_page'] ?? 1)) : null;
if ($recent_updates) { $queue_page = $recent_updates['page']; $queue_pages = $recent_updates['pages']; }
$render_queue_mode = $is_staff ? static function () use ($queue_mode): void {
    echo '<nav class="fd-segmented work-mode-switch" aria-label="' . e(t('View')) . '">';
    foreach (['tickets' => t('Tickets'), 'activity' => t('work.latest_updates')] as $mode => $label) {
        $params = $_GET; unset($params['page'], $params['queue_page']); $params['queue_mode'] = $mode;
        echo '<a class="fd-segmented__item ' . ($queue_mode === $mode ? 'is-active' : '') . '" href="' . e(url('work', $params)) . '" ' . ($queue_mode === $mode ? 'aria-current="page"' : '') . '>' . e($label) . '</a>';
    }
    echo '</nav>';
} : null;
$time_period = time_activity_period_from_request($_GET);
$time_period_options = time_activity_period_options();
$time_view_scope = time_activity_view_scope_from_request($_GET, is_admin());
$recent_reports = is_admin() ? time_activity_recent_reports(3) : [];
// Legacy entry filters are not applied to the compact dashboard; the Time agenda owns work logs.
$my_activity_filter = $team_activity_filter = ['key' => 'last3'];
require_once BASE_PATH . '/includes/modules/work/dashboard-clients.php';
$dashboard_clients = dashboard_clients_model($user, $time_period, ($time_view_scope['key'] ?? 'mine') === 'team');
$display_time_totals = $dashboard_clients['totals'];
$my_time_totals = $display_time_totals;
$period_chart = $dashboard_clients['chart'];
$chart_client_id = isset($_GET['chart_client']) && ctype_digit((string) $_GET['chart_client']) ? (int) $_GET['chart_client'] : null;
$chart_projection = dashboard_clients_projection($dashboard_clients, $chart_client_id, (int) ($_GET['client_page'] ?? 1));
$period_chart['days'] = $chart_projection['days'];
$period_chart['total_minutes'] = $chart_projection['total'];
$period_chart['max_minutes'] = max(array_merge([1], array_column($chart_projection['days'], 'minutes')));
$current_work_timers = [];
if ($is_staff
    && function_exists('ticket_time_table_exists')
    && ticket_time_table_exists()
    && function_exists('get_user_all_active_timers')
) {
    $current_work_timers = get_user_all_active_timers((int) ($user['id'] ?? 0));
}
$active_timer_minutes = 0;
foreach ($current_work_timers as $timer) {
    if (function_exists('calculate_timer_elapsed')) {
        $active_timer_minutes += (int) floor(calculate_timer_elapsed($timer) / 60);
        continue;
    }

    $started_at = strtotime((string) ($timer['started_at'] ?? ''));
    if ($started_at) {
        $active_timer_minutes += max(0, (int) floor((time() - $started_at) / 60));
    }
}
$primary_work_timer = !empty($current_work_timers) ? reset($current_work_timers) : null;
$primary_work_timer = is_array($primary_work_timer) ? $primary_work_timer : null;
$primary_work_timer_paused = $primary_work_timer ? is_timer_paused($primary_work_timer) : false;
$primary_work_timer_url = '#';
$primary_work_timer_seconds = 0;
if ($primary_work_timer) {
    $primary_work_timer_ticket_id = (int) ($primary_work_timer['ticket_id'] ?? 0);
    $primary_work_timer_url = $primary_work_timer_ticket_id > 0
        ? url('ticket', ['id' => $primary_work_timer_ticket_id])
        : '#';
    if (function_exists('calculate_timer_elapsed')) {
        $primary_work_timer_seconds = max(0, (int) calculate_timer_elapsed($primary_work_timer));
    }
}
$selected_period_key = (string) ($time_period['period'] ?? 'this_month');
$selected_period_label = (string) ($time_period['label'] ?? t('This month'));
$show_selected_period_metric = !in_array($selected_period_key, ['today', 'this_week', 'this_month'], true);

$work_queue_url = static function (string $key): string {
    $params = $_GET;
    unset($params['page'], $params['queue_page']);
    $params['queue'] = $key;
    return url('work', $params);
};
$work_asset_version = static function (string $path): string {
    return (defined('APP_VERSION') ? (string) APP_VERSION : '1') . '-' . (string) (@filemtime(BASE_PATH . '/' . $path) ?: '0');
};

$work_period_url = static function (string $period) use ($queue_key, $my_activity_filter, $team_activity_filter, $time_view_scope): string {
    $params = $_GET;
    unset($params['page'], $params['client_page'], $params['from_date'], $params['to_date']);
    $params['period'] = $period;
    $params['time_scope'] = (string) ($time_view_scope['key'] ?? 'mine');
    if ($queue_key !== 'all') {
        $params['queue'] = $queue_key;
    }
    if (($my_activity_filter['key'] ?? 'last3') !== 'last3') {
        $params['my_activity'] = (string) $my_activity_filter['key'];
    }
    if (($team_activity_filter['key'] ?? 'last3') !== 'last3') {
        $params['team_activity'] = (string) $team_activity_filter['key'];
    }
    return url('work', $params);
};

$work_scope_url = static function (string $scope) use ($queue_key, $my_activity_filter, $team_activity_filter, $time_period): string {
    $params = $_GET;
    unset($params['page'], $params['client_page']);
    $params['period'] = (string) ($time_period['period'] ?? 'this_month');
    $params['time_scope'] = $scope;
    if (($time_period['period'] ?? '') === 'custom') {
        $params['from_date'] = (string) ($time_period['from_date'] ?? '');
        $params['to_date'] = (string) ($time_period['to_date'] ?? '');
    }
    if ($queue_key !== 'all') {
        $params['queue'] = $queue_key;
    }
    if (($my_activity_filter['key'] ?? 'last3') !== 'last3') {
        $params['my_activity'] = (string) $my_activity_filter['key'];
    }
    if (($team_activity_filter['key'] ?? 'last3') !== 'last3') {
        $params['team_activity'] = (string) $team_activity_filter['key'];
    }
    return url('work', $params);
};

$work_activity_filter_url = static function (string $param, string $value): string {
    $params = $_GET;
    unset($params['page']);
    $params[$param] = $value;
    return url('work', $params);
};

$work_report_url = static function (string $period, ?int $agent_id = null) use ($time_view_scope, $time_period): string {
    $params = [
        'time_scope' => (string) ($time_view_scope['key'] ?? 'mine'),
        'period' => $period,
    ];
    if ($period === 'custom') {
        if (!empty($time_period['from_date'])) {
            $params['from_date'] = (string) $time_period['from_date'];
        }
        if (!empty($time_period['to_date'])) {
            $params['to_date'] = (string) $time_period['to_date'];
        }
    }
    if ($agent_id !== null && $agent_id > 0) {
        $params['agent'] = $agent_id;
    }
    return url('time', $params);
};

$work_tickets_url = static function (string $key) use ($user): string {
    switch ($key) {
        case 'mine':
            return url('tickets', ['assigned_to' => (int) ($user['id'] ?? 0)]);
        case 'overdue':
            return url('tickets', ['due_date' => 'overdue']);
        case 'waiting':
            return url('tickets', ['work_view' => 'waiting']);
        case 'done_today':
            return url('tickets', ['work_view' => 'done', 'sort' => 'last_updated']);
        case 'unassigned':
        default:
            return url('tickets');
    }
};


require_once BASE_PATH . '/includes/header.php';
?>

<header class="work-canvas-intro">
    <div>
        <h1><?php echo e(t('Overview')); ?></h1>
        <p><?php echo e(t('New and active tickets')); ?></p>
    </div>
    <div class="work-canvas-intro__controls">
            <?php if (!empty($time_view_scope['can_view_team'])): ?>
                <div class="fd-segmented work-scope-switch" aria-label="<?php echo e(t('Time overview')); ?>">
                    <?php foreach (($time_view_scope['options'] ?? []) as $scope_key => $scope_label): ?>
                        <a href="<?php echo e($work_scope_url((string) $scope_key)); ?>"
                           class="fd-segmented__item work-period-link <?php echo ($time_view_scope['key'] ?? 'mine') === $scope_key ? 'is-active' : ''; ?>">
                            <?php echo e($scope_label); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <time datetime="<?php echo e(date('Y-m-d')); ?>"><?php echo e(format_date_localized(date('Y-m-d'), 'l, F j, Y')); ?></time>
    </div>
</header>

<div class="work-canvas-grid <?php echo !$is_staff ? 'work-canvas-grid--client' : ''; ?>">
    <?php ob_start(); ?>
    <div class="work-canvas-main">
        <?php
        workspace_render_queue_page([
            'title' => 'Tickets',
            'summary' => $queue_summary,
            'active_key' => $queue_key,
            'active_queue' => $active_queue,
            'items' => $active_items,
            'queue_url' => $work_queue_url,
            'view_all_url' => $work_tickets_url($queue_key),
            'primary_action' => '',
            'row_options' => ['show_assignee' => true],
            'variant' => 'canvas',
            'contract_surface' => 'server-work',
            'render_mode_switch' => $render_queue_mode,
            'render_content' => $recent_updates ? static fn() => work_render_recent_updates($recent_updates) : null,
        ]);
        ?>
        <nav class="work-queue-pagination" aria-label="<?php echo e(t('Tickets')); ?>">
            <span><?php echo $queue_page; ?> / <?php echo $queue_pages; ?> · <?php echo (int) ($recent_updates['count'] ?? $active_queue['count'] ?? 0); ?> <?php echo e($queue_mode === 'activity' ? t('workflow.activity') : t('Tickets')); ?></span>
            <?php foreach ([-1 => t('Previous'), 1 => t('Next')] as $step => $label): ?>
                <?php $page_params = $_GET; unset($page_params['page']); $page_params['queue_page'] = $queue_page + $step; ?>
                <?php if ($queue_page + $step >= 1 && $queue_page + $step <= $queue_pages): ?>
                    <a class="btn btn-secondary btn-sm" href="<?php echo e(url('work', $page_params)); ?>"><?php echo e($label); ?></a>
                <?php else: ?><button class="btn btn-secondary btn-sm" disabled><?php echo e($label); ?></button><?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>

    <?php $dashboard_queue_html = ob_get_clean(); ?>
    <?php if ($is_staff): ?>
        <section class="work-active-panel"
                 data-work-current
                 data-tickets-url="<?php echo e(url('tickets')); ?>"
                 data-running-label="<?php echo e(t('Running')); ?>"
                 data-paused-label="<?php echo e(t('Paused')); ?>"
                 data-pause-label="<?php echo e(t('Pause')); ?>"
                 data-resume-label="<?php echo e(t('Resume')); ?>"
                 data-stop-label="<?php echo e(t('Stop timer')); ?>"
                 data-empty-label="<?php echo e(t('No active work right now.')); ?>"
                 data-view-ticket-label="<?php echo e(t('View ticket')); ?>"
                 data-view-tickets-label="<?php echo e(t('Tickets')); ?>"
                 data-ticket-label="<?php echo e(t('Ticket')); ?>"
                 data-active-timers-label="<?php echo e(t('Active Timers')); ?>">
            <div class="work-summary-metrics">
                <?php $display_agent_id = ($time_view_scope['key'] ?? 'mine') === 'mine' ? (int) ($user['id'] ?? 0) : null; ?>
                <?php foreach (['today' => ['today', t('Today')], 'week' => ['this_week', t('This week')], 'month' => ['this_month', t('This month')]] as $metric_key => [$metric_period, $metric_label]): ?>
                    <a href="<?php echo e($work_report_url($metric_period, $display_agent_id)); ?>">
                        <span><?php echo e($metric_label); ?></span>
                        <strong><?php echo e(format_duration_minutes((int) $display_time_totals[$metric_key])); ?></strong>
                        <small><?php echo e(t('Saved')); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="work-active-panel__head">
                <span><?php echo get_icon('clock', 'w-4 h-4'); ?><?php echo e(t('Current work')); ?></span>
                <?php echo get_icon('edit', 'w-4 h-4'); ?>
            </div>
            <span class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-work-current-announcement></span>
            <div data-work-current-content>
                <?php if ($primary_work_timer): ?>
                    <div class="work-active-panel__time<?php echo $primary_work_timer_paused ? '' : ' timer-display'; ?>"
                         <?php if (!$primary_work_timer_paused): ?>
                         data-started="<?php echo e((string) (strtotime((string) ($primary_work_timer['started_at'] ?? '')) ?: time())); ?>"
                         data-paused-seconds="<?php echo (int) ($primary_work_timer['paused_seconds'] ?? 0); ?>"
                         data-timer-display-seconds="1"
                         <?php endif; ?>>
                        <?php echo e(gmdate($primary_work_timer_seconds >= 3600 ? 'H:i:s' : 'i:s', $primary_work_timer_seconds)); ?>
                    </div>
                    <strong class="work-active-panel__title"><bdi dir="auto"><?php echo e((string) ($primary_work_timer['ticket_title'] ?? t('Ticket'))); ?></bdi></strong>
                    <div class="work-active-panel__meta">
                        <span class="work-active-panel__ticket"><?php echo e(get_ticket_code((int) ($primary_work_timer['ticket_id'] ?? 0))); ?></span>
                        <span class="work-active-panel__state"><?php echo e($primary_work_timer_paused ? t('Paused') : t('Running')); ?></span>
                        <?php if (count($current_work_timers) > 1): ?>
                            <span class="work-active-panel__count" title="<?php echo e(t('Active Timers')); ?>">+<?php echo count($current_work_timers) - 1; ?></span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="work-active-panel__empty">
                        <?php echo get_icon('check', 'w-5 h-5'); ?>
                        <span><?php echo e(t('No active work right now.')); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <a class="work-active-panel__action"
               data-work-current-action
               href="<?php echo e($primary_work_timer ? $primary_work_timer_url : url('tickets')); ?>">
                <?php echo get_icon('ticket-alt', 'w-4 h-4'); ?>
                <span data-work-current-action-label><?php echo e($primary_work_timer ? t('View ticket') : t('Tickets')); ?></span>
            </a>
        </section>

        <?php include BASE_PATH . '/includes/components/work-client-summary.php'; ?>
        <?php endif; ?>
        <?php echo $dashboard_queue_html; ?>
        <section class="work-readiness-panel">
            <?php include BASE_PATH . '/includes/components/work-context.php'; ?>
            <?php if ($is_staff): ?>
            <div id="work-panel-reports" role="tabpanel" aria-labelledby="work-tab-reports" data-work-context-panel="reports" hidden>
            <div class="work-readiness-panel__head">
                <h2><?php echo e(is_admin() ? t('Client Reports') : t('Reports')); ?></h2>
                <span><?php echo e(is_admin() ? t('Report history') : t('This month')); ?></span>
            </div>
            <?php if (is_admin() && !empty($recent_reports)): ?>
                <?php foreach ($recent_reports as $recent_report): ?>
                    <?php
                    $report_from = (string) ($recent_report['date_from'] ?? '');
                    $report_to = (string) ($recent_report['date_to'] ?? '');
                    $report_period = $report_from !== '' && $report_to !== ''
                        ? format_date_localized($report_from, 'd.m.Y') . ' – ' . format_date_localized($report_to, 'd.m.Y')
                        : '';
                    ?>
                    <a href="<?php echo e(url('admin', ['section' => 'report-builder', 'edit' => (int) ($recent_report['id'] ?? 0)])); ?>" class="work-readiness-row">
                        <span class="work-readiness-row__icon"><?php echo get_icon('file', 'w-4 h-4'); ?></span>
                        <span>
                            <strong><bdi dir="auto"><?php echo e((string) ($recent_report['title'] ?? t('Report'))); ?></bdi></strong>
                            <small><bdi dir="auto"><?php echo e((string) ($recent_report['organization_name'] ?? t('Client'))); ?></bdi><?php echo $report_period !== '' ? ' · ' . e($report_period) : ''; ?></small>
                        </span>
                        <?php echo get_icon('chevron-right', 'w-4 h-4 work-readiness-row__chevron'); ?>
                    </a>
                <?php endforeach; ?>
                <a href="<?php echo e(url('admin', ['section' => 'reports-list'])); ?>" class="work-readiness-panel__link">
                    <?php echo e(t('Report history')); ?><?php echo get_icon('chevron-right', 'w-4 h-4 work-readiness-row__chevron'); ?>
                </a>
            <?php elseif (is_admin()): ?>
                <a href="<?php echo e(url('admin', ['section' => 'report-builder'])); ?>" class="work-readiness-empty">
                    <span class="work-readiness-row__icon"><?php echo get_icon('file', 'w-4 h-4'); ?></span>
                    <span><strong><?php echo e(t('Client Reports')); ?></strong><small><?php echo e(t('Create client report')); ?></small></span>
                    <?php echo get_icon('chevron-right', 'w-4 h-4 work-readiness-row__chevron'); ?>
                </a>
            <?php else: ?>
                <a href="<?php echo e($work_report_url('this_month')); ?>" class="work-readiness-row">
                    <span class="work-readiness-row__icon"><?php echo get_icon('clock', 'w-4 h-4'); ?></span>
                    <span><strong><?php echo e(t('My work log')); ?></strong><small><?php echo e(format_duration_minutes((int) ($my_time_totals['month'] ?? 0))); ?></small></span>
                    <?php echo get_icon('chevron-right', 'w-4 h-4 work-readiness-row__chevron'); ?>
                </a>
                <a href="<?php echo e(url('admin', ['section' => 'reports'])); ?>" class="work-readiness-panel__link">
                    <?php echo e(t('Open reports')); ?><?php echo get_icon('chevron-right', 'w-4 h-4 work-readiness-row__chevron'); ?>
                </a>
            <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>
</div>

<?php if ($is_staff): ?>
<section id="time-overview" class="fd-card fd-page-section work-overview-card" data-work-time-overview>
    <div class="fd-section-header work-overview-head">
        <div class="fd-section-main">
            <h2 class="fd-section-title work-overview-title"><?php echo e(t('Work overview')); ?></h2>
        </div>
        <div class="fd-section-actions work-range-controls">
            <form method="get" class="work-chart-client-filter">
                <?php foreach ($_GET as $key => $value): ?>
                    <?php if (!in_array($key, ['chart_client', 'client_page', 'page'], true) && is_scalar($value)): ?><input type="hidden" name="<?php echo e($key); ?>" value="<?php echo e((string) $value); ?>"><?php endif; ?>
                <?php endforeach; ?>
                <input type="hidden" name="page" value="work">
                <select name="chart_client" data-soft-select aria-label="<?php echo e(t('Client')); ?>" onchange="this.form.submit()">
                    <option value=""><?php echo e(t('All') . ' · ' . t('Clients')); ?></option>
                    <?php foreach ($dashboard_clients['clients'] as $client): ?><option value="<?php echo (int) $client['id']; ?>" <?php echo $chart_client_id === $client['id'] ? 'selected' : ''; ?>><?php echo e($client['name']); ?></option><?php endforeach; ?>
                </select>
            </form>
            <div class="fd-segmented" aria-label="<?php echo e(t('View')); ?>">
                <button type="button" class="fd-segmented__item is-active" data-work-chart-view="chart" aria-pressed="true" aria-label="<?php echo e(t('Hours by day')); ?>"><?php echo get_icon('chart-bar', 'w-4 h-4'); ?></button>
                <button type="button" class="fd-segmented__item" data-work-chart-view="table" aria-pressed="false" aria-label="<?php echo e(t('List')); ?>"><?php echo get_icon('list', 'w-4 h-4'); ?></button>
            </div>

            <div class="fd-segmented work-period-switch" aria-label="<?php echo e(t('Time period')); ?>">
                <?php foreach ($time_period_options as $period_key => $period_label): ?>
                    <?php if ($period_key === 'custom') continue; ?>
                    <a href="<?php echo e($work_period_url((string) $period_key)); ?>"
                       class="fd-segmented__item work-period-link <?php echo ($time_period['period'] ?? '') === $period_key ? 'is-active' : ''; ?>">
                        <?php echo e($period_label); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <details class="work-custom-range"<?php echo ($time_period['period'] ?? '') === 'custom' ? ' open' : ''; ?>>
                <summary class="btn btn-secondary btn-sm"><?php echo get_icon('calendar-alt', 'w-4 h-4'); ?><span><?php echo e(t('Custom range')); ?></span><?php echo get_icon('chevron-down', 'w-4 h-4'); ?></summary>
            <form method="get" class="work-custom-period">
                <input type="hidden" name="page" value="work">
                <input type="hidden" name="time_scope" value="<?php echo e((string) ($time_view_scope['key'] ?? 'mine')); ?>">
                <?php if ($queue_key !== 'all'): ?>
                    <input type="hidden" name="queue" value="<?php echo e($queue_key); ?>">
                <?php endif; ?>
                <?php if (($my_activity_filter['key'] ?? 'last3') !== 'last3'): ?>
                    <input type="hidden" name="my_activity" value="<?php echo e((string) $my_activity_filter['key']); ?>">
                <?php endif; ?>
                <?php if (($team_activity_filter['key'] ?? 'last3') !== 'last3'): ?>
                    <input type="hidden" name="team_activity" value="<?php echo e((string) $team_activity_filter['key']); ?>">
                <?php endif; ?>
                <input type="hidden" name="period" value="custom">
                <label>
                    <span><?php echo e(t('From date')); ?></span>
                    <input type="date" name="from_date" class="form-input" value="<?php echo e($time_period['from_date'] ?? ''); ?>">
                </label>
                <label>
                    <span><?php echo e(t('To date')); ?></span>
                    <input type="date" name="to_date" class="form-input" value="<?php echo e($time_period['to_date'] ?? ''); ?>">
                </label>
                <button type="submit" class="btn btn-secondary btn-sm fd-button fd-button--secondary fd-button--sm"><?php echo e(t('Apply')); ?></button>
            </form>
            </details>
        </div>
    </div>

    <?php
    $chart_days = $period_chart['days'] ?? [];
    $chart_max_minutes = max(1, (int) ($period_chart['max_minutes'] ?? 0));
    $chart_agents = [];
    foreach ($chart_days as $day) {
        foreach (($day['users'] ?? []) as $chart_user) {
            $chart_user_name = trim((string) ($chart_user['name'] ?? t('Agent')));
            $chart_user_name = $chart_user_name !== '' ? $chart_user_name : t('Agent');
            $chart_user_id = (int) ($chart_user['user_id'] ?? 0);
            $chart_user_key = $chart_user_id > 0 ? 'u' . $chart_user_id : 'n' . mb_strtolower($chart_user_name);
            if (!isset($chart_agents[$chart_user_key])) {
                $chart_agents[$chart_user_key] = [
                    'name' => $chart_user_name,
                    'minutes' => 0,
                ];
            }
            $chart_agents[$chart_user_key]['minutes'] += max(0, (int) ($chart_user['minutes'] ?? 0));
        }
    }
    uasort($chart_agents, static fn(array $a, array $b): int => ($b['minutes'] ?? 0) <=> ($a['minutes'] ?? 0));
    $chart_agent_color_index = [];
    $chart_color_i = 0;
    foreach (array_keys($chart_agents) as $chart_agent_key) {
        $chart_agent_color_index[$chart_agent_key] = $chart_color_i % 8;
        $chart_color_i++;
    }
    // Approved light palette, shared by the canvas and accessible HTML fallback.
    $chart_palette = ['#97b7ef', '#98ccbb', '#c7b3e6', '#bfc7d5', '#e6b4bc', '#b6cddd', '#c6d5a5', '#e4c4a5'];
    $chart_labels = [];
    $chart_full_labels = [];
    $chart_agent_datasets = [];
    foreach ($chart_agents as $chart_agent_key => $chart_agent) {
        $chart_agent_datasets[$chart_agent_key] = [
            'label' => (string) ($chart_agent['name'] ?? t('Agent')),
            'data' => array_fill(0, count($chart_days), 0),
            'backgroundColor' => $chart_palette[$chart_agent_color_index[$chart_agent_key] ?? 0] ?? $chart_palette[0],
            'borderColor' => $chart_palette[$chart_agent_color_index[$chart_agent_key] ?? 0] ?? $chart_palette[0],
        ];
    }
    foreach ($chart_days as $day_index => $day) {
        $chart_labels[] = (string) ($day['label'] ?? '');
        $chart_full_labels[] = (string) ($day['full_label'] ?? $day['label'] ?? '');
        foreach (($day['users'] ?? []) as $chart_user) {
            $chart_user_name = trim((string) ($chart_user['name'] ?? t('Agent')));
            $chart_user_name = $chart_user_name !== '' ? $chart_user_name : t('Agent');
            $chart_user_id = (int) ($chart_user['user_id'] ?? 0);
            $chart_user_key = $chart_user_id > 0 ? 'u' . $chart_user_id : 'n' . mb_strtolower($chart_user_name);
            if (!isset($chart_agent_datasets[$chart_user_key])) {
                continue;
            }
            $chart_agent_datasets[$chart_user_key]['data'][$day_index] = max(0, (int) ($chart_user['minutes'] ?? 0));
        }
    }
    $work_chart_payload = [
        'labels' => $chart_labels,
        'fullLabels' => $chart_full_labels,
        'datasets' => $chart_projection['datasets'],
        'emptyLabel' => t('No activity'),
        'totalLabel' => t('Total'),
    ];
    $chart_has_minutes = (int) ($period_chart['total_minutes'] ?? 0) > 0;
    ?>
    <div class="work-week-chart work-hours-chart" data-work-week-chart data-work-period-chart data-work-hours-chart>
        <div class="work-week-chart__header">
            <h3 class="sr-only"><?php echo e(t('Hours by day')); ?></h3>
            <span><?php echo e((string) ($time_period['label'] ?? t('This month'))); ?> · <?php echo e(format_duration_minutes((int) ($period_chart['total_minutes'] ?? 0))); ?></span>
        </div>
        <div class="work-hours-chart__canvas-wrap">
            <canvas data-work-hours-chart-canvas tabindex="0" aria-describedby="work-chart-keyboard-value" aria-label="<?php echo e(t('Hours by day')); ?>"></canvas>
            <p id="work-chart-keyboard-value" class="sr-only" aria-live="polite" data-work-chart-keyboard-value></p>
        </div>
        <div class="work-hours-chart__fallback" data-work-hours-chart-fallback>
            <?php if ($chart_has_minutes): ?>
                <?php
                $svg_day_count = max(1, count($chart_days));
                $svg_width = max(720, $svg_day_count * 30);
                $svg_height = 218;
                $svg_plot_left = 18.0;
                $svg_plot_right = 12.0;
                $svg_plot_top = 22.0;
                $svg_plot_height = 142.0;
                $svg_plot_bottom = 34.0;
                $svg_slot_width = max(1.0, ($svg_width - $svg_plot_left - $svg_plot_right) / $svg_day_count);
                $svg_bar_width = max(8.0, min(24.0, $svg_slot_width * 0.58));
                $svg_dense_chart = $svg_day_count > 14;
                $svg_format = static function (float $value): string {
                    $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
                    return $formatted === '' ? '0' : $formatted;
                };
                ?>
                <div class="work-hours-chart__svg-wrap" role="img" aria-label="<?php echo e(t('Hours by day')); ?>">
                    <svg class="work-hours-chart__svg"
                         viewBox="0 0 <?php echo e((string) $svg_width); ?> <?php echo e((string) $svg_height); ?>"
                         aria-hidden="true">
                        <line class="work-hours-chart__grid-line"
                              x1="<?php echo e($svg_format($svg_plot_left)); ?>"
                              x2="<?php echo e($svg_format($svg_width - $svg_plot_right)); ?>"
                              y1="<?php echo e($svg_format($svg_plot_top + $svg_plot_height)); ?>"
                              y2="<?php echo e($svg_format($svg_plot_top + $svg_plot_height)); ?>"></line>
                        <line class="work-hours-chart__grid-line"
                              x1="<?php echo e($svg_format($svg_plot_left)); ?>"
                              x2="<?php echo e($svg_format($svg_width - $svg_plot_right)); ?>"
                              y1="<?php echo e($svg_format($svg_plot_top + ($svg_plot_height / 2))); ?>"
                              y2="<?php echo e($svg_format($svg_plot_top + ($svg_plot_height / 2))); ?>"></line>
                    <?php foreach ($chart_days as $day_index => $day): ?>
                        <?php
                        $day_minutes = (int) ($day['minutes'] ?? 0);
                        $users_for_day = $day['users'] ?? [];
                        $day_label = (string) ($day['full_label'] ?? $day['label'] ?? '');
                        $show_day_label = !$svg_dense_chart || $day_index === 0 || $day_index === count($chart_days) - 1 || $day_index % 5 === 4;
                        $axis_label = (string) ($day['label'] ?? '');
                        if ($svg_dense_chart && !empty($day['key'])) {
                            $axis_ts = strtotime((string) $day['key']);
                            if ($axis_ts !== false) {
                                $axis_label = date('j', $axis_ts);
                            }
                        }
                        $slot_x = $svg_plot_left + ($svg_slot_width * $day_index);
                        $bar_x = $slot_x + (($svg_slot_width - $svg_bar_width) / 2);
                        $baseline_y = $svg_plot_top + $svg_plot_height;
                        $positive_users_for_day = [];
                        foreach ($users_for_day as $chart_user) {
                            if (max(0, (int) ($chart_user['minutes'] ?? 0)) > 0) {
                                $positive_users_for_day[] = $chart_user;
                            }
                        }
                        $bar_height = $day_minutes > 0
                            ? max(3.0, min($svg_plot_height, ($day_minutes / max(1, $chart_max_minutes)) * $svg_plot_height))
                            : 3.0;
                        $bar_top_y = $baseline_y - $bar_height;
                        $bar_radius = min(7.0, $svg_bar_width / 2, $bar_height / 2);
                        $clip_id = 'work-hours-stack-' . (int) $day_index;
                        $clip_path = implode(' ', [
                            'M', $svg_format($bar_x), $svg_format($baseline_y),
                            'V', $svg_format($bar_top_y + $bar_radius),
                            'Q', $svg_format($bar_x), $svg_format($bar_top_y), $svg_format($bar_x + $bar_radius), $svg_format($bar_top_y),
                            'H', $svg_format($bar_x + $svg_bar_width - $bar_radius),
                            'Q', $svg_format($bar_x + $svg_bar_width), $svg_format($bar_top_y), $svg_format($bar_x + $svg_bar_width), $svg_format($bar_top_y + $bar_radius),
                            'V', $svg_format($baseline_y),
                            'Z',
                        ]);
                        ?>
                        <g>
                            <title><?php echo e(trim($day_label . ' ' . format_duration_minutes($day_minutes))); ?></title>
                            <?php if ($day_minutes > 0 && !empty($positive_users_for_day)): ?>
                                <clipPath id="<?php echo e($clip_id); ?>">
                                    <path d="<?php echo e($clip_path); ?>"></path>
                                </clipPath>
                                <g clip-path="url(#<?php echo e($clip_id); ?>)">
                                <?php
                                $segment_y = $baseline_y;
                                $remaining_height = $bar_height;
                                $segment_count = count($positive_users_for_day);
                                ?>
                                <?php foreach ($positive_users_for_day as $segment_index => $chart_user): ?>
                                    <?php
                                    $chart_user_minutes = max(0, (int) ($chart_user['minutes'] ?? 0));
                                    $chart_user_name = trim((string) ($chart_user['name'] ?? t('Agent')));
                                    $chart_user_name = $chart_user_name !== '' ? $chart_user_name : t('Agent');
                                    $chart_user_id = (int) ($chart_user['user_id'] ?? 0);
                                    $chart_user_key = $chart_user_id > 0 ? 'u' . $chart_user_id : 'n' . mb_strtolower($chart_user_name);
                                    $segment_color = $chart_palette[$chart_agent_color_index[$chart_user_key] ?? 0] ?? $chart_palette[0];
                                    if ($segment_index === $segment_count - 1) {
                                        $segment_height = $remaining_height;
                                    } else {
                                        $segment_height = ($chart_user_minutes / max(1, $day_minutes)) * $bar_height;
                                        $segment_height = max(0.5, min($remaining_height, $segment_height));
                                    }
                                    $segment_y -= $segment_height;
                                    $remaining_height = max(0, $remaining_height - $segment_height);
                                    ?>
                                    <rect class="work-hours-chart__bar-segment"
                                          x="<?php echo e($svg_format($bar_x)); ?>"
                                          y="<?php echo e($svg_format($segment_y)); ?>"
                                          width="<?php echo e($svg_format($svg_bar_width)); ?>"
                                          height="<?php echo e($svg_format($segment_height)); ?>"
                                          fill="<?php echo e($segment_color); ?>">
                                        <title><?php echo e($day_label); ?> · <?php echo e($chart_user_name); ?>: <?php echo e(format_duration_minutes($chart_user_minutes)); ?></title>
                                    </rect>
                                <?php endforeach; ?>
                                </g>
                            <?php else: ?>
                                <rect class="work-hours-chart__bar-empty"
                                      x="<?php echo e($svg_format($bar_x)); ?>"
                                      y="<?php echo e($svg_format($baseline_y - 3)); ?>"
                                      width="<?php echo e($svg_format($svg_bar_width)); ?>"
                                      height="3"
                                      rx="2"></rect>
                            <?php endif; ?>
                            <?php if ($show_day_label): ?>
                                <text class="work-hours-chart__axis"
                                      x="<?php echo e($svg_format($bar_x + ($svg_bar_width / 2))); ?>"
                                      y="<?php echo e($svg_format($svg_plot_top + $svg_plot_height + $svg_plot_bottom - 8)); ?>"
                                      text-anchor="middle"><?php echo e($axis_label); ?></text>
                            <?php endif; ?>
                        </g>
                    <?php endforeach; ?>
                    </svg>
                </div>
                <?php if (!empty($chart_agents)): ?>
                    <div class="work-week-chart__legend" aria-label="<?php echo e(t('Client')); ?>">
                        <?php foreach ($chart_agents as $chart_agent_key => $chart_agent): ?>
                            <?php $legend_color_index = (int) ($chart_agent_color_index[$chart_agent_key] ?? 0); ?>
                            <span class="work-week-chart__legend-item">
                                <span class="work-week-chart__legend-dot work-week-chart__legend-dot--<?php echo $legend_color_index; ?>"></span>
                                <?php echo e((string) ($chart_agent['name'] ?? t('Agent'))); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="work-hours-chart__empty"><?php echo e(t('No activity')); ?></div>
            <?php endif; ?>
        </div>
        <script type="application/json" data-work-hours-chart-payload><?php echo json_encode($work_chart_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    </div>
    <div class="work-client-matrix" data-work-client-matrix hidden>
        <div class="work-client-matrix__scroll">
        <table class="fd-table">
            <caption class="sr-only"><?php echo e(t('Hours by day')); ?></caption>
            <thead><tr><th scope="col"><?php echo e(t('Date')); ?></th>
            <?php foreach ($chart_projection['columns'] as $client): ?><th scope="col"><bdi dir="auto"><?php echo e($client['name']); ?></bdi></th><?php endforeach; ?>
            <th scope="col"><?php echo e(t('Total')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($chart_projection['days'] as $day): ?>
                <?php $minutes_by_client = array_column($day['users'], 'minutes', 'user_id'); ?>
                <tr><th scope="row"><?php echo e($day['full_label']); ?></th>
                <?php foreach ($chart_projection['columns'] as $client): ?><td><?php echo e(format_duration_minutes((int) ($minutes_by_client[$client['id']] ?? 0))); ?></td><?php endforeach; ?>
                <td><strong><?php echo e(format_duration_minutes($day['minutes'])); ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th scope="row"><?php echo e(t('Total')); ?></th>
            <?php foreach ($chart_projection['columns'] as $client): ?><td><?php echo e(format_duration_minutes($client['selected'])); ?></td><?php endforeach; ?>
            <td><strong><?php echo e(format_duration_minutes($chart_projection['total'])); ?></strong></td></tr></tfoot>
        </table>
        </div>
        <?php if ($chart_projection['pages'] > 1): ?>
        <nav class="work-queue-pagination" aria-label="<?php echo e(t('Clients')); ?>">
            <span><?php echo $chart_projection['page']; ?> / <?php echo $chart_projection['pages']; ?></span>
            <?php foreach ([-1 => t('Previous'), 1 => t('Next')] as $step => $label): ?>
                <?php $matrix_params = $_GET; unset($matrix_params['page']); $matrix_params['client_page'] = $chart_projection['page'] + $step; $matrix_params['chart_view'] = 'table'; ?>
                <?php if ($matrix_params['client_page'] >= 1 && $matrix_params['client_page'] <= $chart_projection['pages']): ?><a class="btn btn-secondary btn-sm" href="<?php echo e(url('work', $matrix_params)); ?>#time-overview"><?php echo e($label); ?></a><?php else: ?><button class="btn btn-secondary btn-sm" disabled><?php echo e($label); ?></button><?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </div>

</section>

<a class="work-ledger-link" href="<?php echo e(url('time')); ?>"><?php echo e(t('Work log')); ?> <?php echo get_icon('chevron-right', 'w-4 h-4'); ?></a>
<?php endif; ?>

<script src="assets/vendor/chartjs/4.4.0/chart.umd.js?v=<?php echo e($work_asset_version('assets/vendor/chartjs/4.4.0/chart.umd.js')); ?>"></script>
<script defer src="assets/js/work-dashboard.js?v=<?php echo e($work_asset_version('assets/js/work-dashboard.js')); ?>"></script>
<script defer src="assets/js/work-events.js?v=<?php echo e($work_asset_version('assets/js/work-events.js')); ?>"></script>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
