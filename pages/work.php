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

$queue_key = trim((string) ($_GET['queue'] ?? 'mine'));
$queue_definitions = work_queue_definitions();
if (!isset($queue_definitions[$queue_key])) {
    $queue_key = 'mine';
}
if (($queue_definitions[$queue_key]['scope'] ?? '') === 'team' && !$is_staff) {
    $queue_key = 'mine';
}

$queue_summary = work_queue_summary($user, 6);
$active_queue = $queue_summary[$queue_key] ?? ($queue_summary['mine'] ?? reset($queue_summary));
$active_items = $active_queue['items'] ?? [];
$time_work = function_exists('time_activity_work_model') ? time_activity_work_model($user, $_GET) : [
    'period' => ['period' => 'this_month', 'label' => t('This month'), 'start' => null, 'end' => null, 'from_date' => '', 'to_date' => ''],
    'period_options' => [],
    'my_totals' => ['today' => 0, 'week' => 0, 'month' => 0, 'selected' => 0],
    'team' => [],
];
$time_period = $time_work['period'];
$time_period_options = $time_work['period_options'];
$activity_scope = $time_work['activity_scope'] ?? [
    'key' => 'last3',
    'limit' => 3,
    'options' => ['last3' => t('Last 3 tickets'), 'last10' => t('Last 10 tickets'), 'search' => t('Search')],
];
$my_activity_filter = $time_work['my_activity_filter'] ?? [
    'key' => 'last3',
    'label' => t('Last 3 tickets'),
    'is_search' => false,
    'options' => ['last3' => t('Last 3 tickets')],
];
$team_activity_filter = $time_work['team_activity_filter'] ?? $my_activity_filter;
$my_time_totals = $time_work['my_totals'];
$my_activity_entries = $time_work['my_entries'] ?? [];
$team_time_rows = $time_work['team'];
$current_work_timers = [];
if ($is_staff
    && function_exists('ticket_time_table_exists')
    && ticket_time_table_exists()
    && function_exists('get_user_all_active_timers')
) {
    $current_work_timers = get_user_all_active_timers((int) ($user['id'] ?? 0));
}
$selected_period_key = (string) ($time_period['period'] ?? 'this_month');
$show_selected_period_metric = !in_array($selected_period_key, ['today', 'this_week', 'this_month'], true);

$work_queue_url = static function (string $key): string {
    return url('work', $key === 'mine' ? [] : ['queue' => $key]);
};

$work_period_url = static function (string $period) use ($queue_key, $my_activity_filter, $team_activity_filter): string {
    $params = ['period' => $period];
    if ($queue_key !== 'mine') {
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

$render_activity_filter = static function (array $filter, string $param, string $label) use ($work_activity_filter_url): void {
    ?>
    <div class="fd-segmented work-activity-switch" aria-label="<?php echo e($label); ?>">
        <?php foreach (($filter['options'] ?? []) as $scope_key => $scope_label): ?>
            <a href="<?php echo e($work_activity_filter_url($param, (string) $scope_key)); ?>"
               class="fd-segmented__item work-period-link <?php echo ($filter['key'] ?? 'last3') === (string) $scope_key ? 'is-active' : ''; ?>">
                <?php echo e($scope_label); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
};

$render_work_search = static function (array $filter, string $id): void {
    if (empty($filter['is_search'])) {
        return;
    }
    ?>
    <div class="work-log-search-wrap">
        <label class="work-log-search" for="<?php echo e($id); ?>">
            <?php echo get_icon('search', 'w-4 h-4'); ?>
            <span class="sr-only"><?php echo e(t('Search work records')); ?></span>
            <input id="<?php echo e($id); ?>"
                   type="search"
                   class="form-input work-log-search__input"
                   data-work-log-search
                   placeholder="<?php echo e(t('Search work records...')); ?>"
                   autocomplete="off">
        </label>
    </div>
    <?php
};

$render_work_search_states = static function (array $filter): void {
    if (empty($filter['is_search'])) {
        return;
    }
    ?>
    <div class="work-search-empty" data-work-search-prompt><?php echo e(t('Start typing to search work records.')); ?></div>
    <div class="work-search-empty is-hidden" data-work-search-empty><?php echo e(t('No matching work records.')); ?></div>
    <?php
};

$render_work_activity_entries = static function (array $entries, bool $compact = false): void {
    if (empty($entries)) {
        ?>
        <div class="work-activity-empty"><?php echo e(t('No activity')); ?></div>
        <?php
        return;
    }
    ?>
    <div class="work-activity-list <?php echo $compact ? 'work-activity-list--compact' : ''; ?>">
        <?php foreach ($entries as $entry): ?>
            <?php
            $ticket_id = (int) ($entry['ticket_id'] ?? 0);
            $minutes = (int) ($entry['actual_minutes'] ?? $entry['duration_minutes'] ?? 0);
            $ticket_title = trim((string) ($entry['ticket_title'] ?? ''));
            $ticket_title = $ticket_title !== '' ? $ticket_title : t('Ticket');
            $ticket_url = $ticket_id > 0 ? url('ticket', ['id' => $ticket_id]) : '#';
            $meta = array_filter([
                !empty($entry['organization_name']) ? (string) $entry['organization_name'] : '',
                !empty($entry['started_at']) ? format_date($entry['started_at']) : '',
            ]);
            $search_text = trim(implode(' ', [
                $ticket_title,
                (string) ($entry['summary'] ?? ''),
                (string) ($entry['organization_name'] ?? ''),
                (string) ($entry['status_name'] ?? ''),
                (string) ($entry['started_at'] ?? ''),
            ]));
            ?>
            <article class="work-activity-item"
                     data-work-activity-item
                     data-work-search-text="<?php echo e(mb_strtolower($search_text)); ?>">
                <a href="<?php echo e($ticket_url); ?>" class="work-activity-ticket">
                    <?php echo e($ticket_title); ?>
                </a>
                <span class="work-activity-duration"><?php echo e(format_duration_minutes($minutes)); ?></span>
                <?php if (!empty($meta)): ?>
                    <span class="work-activity-meta"><?php echo e(implode(' · ', $meta)); ?></span>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
};

$render_current_work_timers = static function (array $timers): void {
    if (empty($timers)) {
        ?>
        <div class="work-current-empty"><?php echo e(t('No active work right now.')); ?></div>
        <?php
        return;
    }
    ?>
    <div class="work-current-list">
        <?php foreach ($timers as $timer): ?>
            <?php
            $ticket_id = (int) ($timer['ticket_id'] ?? 0);
            $ticket_title = trim((string) ($timer['ticket_title'] ?? ''));
            $ticket_title = $ticket_title !== '' ? $ticket_title : t('Ticket');
            $ticket_url = $ticket_id > 0 ? url('ticket', ['id' => $ticket_id]) : '#';
            $seconds = function_exists('calculate_timer_elapsed')
                ? (int) calculate_timer_elapsed($timer)
                : max(0, (int) ($timer['duration_minutes'] ?? 0) * 60);
            $minutes = max(0, (int) floor($seconds / 60));
            $timer_state = (function_exists('is_timer_paused') && is_timer_paused($timer)) ? 'Paused' : 'Running';
            ?>
            <a href="<?php echo e($ticket_url); ?>" class="work-current-item">
                <span class="work-current-main">
                    <strong><?php echo e($ticket_title); ?></strong>
                    <span>
                        <?php echo e(get_ticket_code($ticket_id)); ?>
                        <span aria-hidden="true">·</span>
                        <?php echo e(t($timer_state)); ?>
                    </span>
                </span>
                <span class="work-current-duration"><?php echo e(format_duration_minutes($minutes)); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
};

require_once BASE_PATH . '/includes/header.php';
?>

<section class="fd-card fd-page-section work-overview-card" data-work-time-overview>
    <div class="fd-section-header work-overview-head">
        <div class="fd-section-main">
            <h2 class="fd-section-title work-overview-title"><?php echo e(t('Work overview')); ?></h2>
        </div>
        <div class="fd-section-actions work-range-controls">
            <div class="fd-segmented work-period-switch" aria-label="<?php echo e(t('Time period')); ?>">
                <?php foreach ($time_period_options as $period_key => $period_label): ?>
                    <?php if ($period_key === 'custom') continue; ?>
                    <a href="<?php echo e($work_period_url((string) $period_key)); ?>"
                       class="fd-segmented__item work-period-link <?php echo ($time_period['period'] ?? '') === $period_key ? 'is-active' : ''; ?>">
                        <?php echo e($period_label); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="get" class="work-custom-period">
                <input type="hidden" name="page" value="work">
                <?php if ($queue_key !== 'mine'): ?>
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
                    <span><?php echo e(t('From')); ?></span>
                    <input type="date" name="from_date" class="form-input" value="<?php echo e($time_period['from_date'] ?? ''); ?>">
                </label>
                <label>
                    <span><?php echo e(t('To')); ?></span>
                    <input type="date" name="to_date" class="form-input" value="<?php echo e($time_period['to_date'] ?? ''); ?>">
                </label>
                <button type="submit" class="fd-button fd-button--secondary fd-button--sm btn btn-secondary btn-sm"><?php echo e(t('Apply')); ?></button>
            </form>
        </div>
    </div>

    <div class="work-time-grid">
        <div class="work-time-metric">
            <span><?php echo e(t('Today')); ?></span>
            <strong><?php echo e(format_duration_minutes((int) ($my_time_totals['today'] ?? 0))); ?></strong>
        </div>
        <div class="work-time-metric">
            <span><?php echo e(t('This week')); ?></span>
            <strong><?php echo e(format_duration_minutes((int) ($my_time_totals['week'] ?? 0))); ?></strong>
        </div>
        <div class="work-time-metric">
            <span><?php echo e(t('This month')); ?></span>
            <strong><?php echo e(format_duration_minutes((int) ($my_time_totals['month'] ?? 0))); ?></strong>
        </div>
        <?php if ($show_selected_period_metric): ?>
            <div class="work-time-metric work-time-metric--selected">
                <span><?php echo e($time_period['label'] ?? t('Selected period')); ?></span>
                <strong><?php echo e(format_duration_minutes((int) ($my_time_totals['selected'] ?? 0))); ?></strong>
            </div>
        <?php endif; ?>
    </div>

</section>

<?php if ($is_staff): ?>
<section class="fd-card fd-page-section work-current-card" data-work-current>
    <div class="fd-section-header work-team-head">
        <div class="fd-section-main">
            <h2 class="fd-section-title work-team-title"><?php echo e(t('Current work')); ?></h2>
        </div>
    </div>
    <?php $render_current_work_timers($current_work_timers); ?>
</section>
<?php endif; ?>

<section class="fd-card fd-page-section work-activity-card"
         data-work-user-activity
         data-work-log-surface
         data-work-filter-mode="<?php echo e((string) ($my_activity_filter['key'] ?? 'last3')); ?>">
    <div class="fd-section-header work-team-head">
        <div class="fd-section-main">
            <h2 class="fd-section-title work-team-title"><?php echo e(t('My work log')); ?></h2>
        </div>
        <div class="fd-section-actions work-log-controls">
            <?php $render_activity_filter($my_activity_filter, 'my_activity', t('My work log filter')); ?>
            <?php $render_work_search($my_activity_filter, 'work-my-search'); ?>
        </div>
    </div>
    <?php $render_work_activity_entries($my_activity_entries); ?>
    <?php $render_work_search_states($my_activity_filter); ?>
</section>

<?php if (is_admin()): ?>
<section class="fd-card fd-page-section work-team-card"
         data-work-team-time
         data-work-log-surface
         data-work-filter-mode="<?php echo e((string) ($team_activity_filter['key'] ?? 'last3')); ?>">
    <div class="fd-section-header work-team-head">
        <div class="fd-section-main">
            <h2 class="fd-section-title work-team-title"><?php echo e(t('Team time')); ?></h2>
        </div>
        <div class="fd-section-actions work-log-controls">
            <?php $render_activity_filter($team_activity_filter, 'team_activity', t('Team time filter')); ?>
            <?php $render_work_search($team_activity_filter, 'work-team-search'); ?>
            <a href="<?php echo e(url('admin', ['section' => 'reports'])); ?>" class="fd-button fd-button--secondary fd-button--sm btn btn-secondary btn-sm">
                <?php echo get_icon('chart-bar', 'w-4 h-4 mr-1'); ?><?php echo e(t('Open reports')); ?>
            </a>
        </div>
    </div>
    <div class="work-team-table-wrap">
        <table class="fd-table work-team-table">
            <thead>
                <tr>
                    <th><?php echo e(t('Agent')); ?></th>
                    <th><?php echo e(t('Today')); ?></th>
                    <th><?php echo e(t('This week')); ?></th>
                    <th><?php echo e(t('This month')); ?></th>
                    <?php if ($show_selected_period_metric): ?>
                        <th><?php echo e(t('Selected')); ?></th>
                    <?php endif; ?>
                    <th><?php echo e(t('Work log')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($team_time_rows)): ?>
                    <tr><td colspan="<?php echo $show_selected_period_metric ? 6 : 5; ?>" class="work-team-empty"><?php echo e(t('No team time yet.')); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($team_time_rows as $row): ?>
                    <?php
                    $staff = $row['user'] ?? [];
                    $staff_id = (int) ($staff['id'] ?? 0);
                    $totals = $row['totals'] ?? [];
                    $entries = $row['entries'] ?? [];
                    $agent_report_url = url('admin', [
                        'section' => 'reports',
                        'tab' => 'time',
                        'period' => $time_period['period'] ?? 'this_month',
                    ]) . '&agents%5B%5D=' . urlencode((string) $staff_id);
                    $team_search_text = trim(($row['name'] ?? '') . ' ' . implode(' ', array_map(
                        static fn($entry): string => (string) ($entry['ticket_title'] ?? '') . ' ' . (string) ($entry['organization_name'] ?? ''),
                        $entries
                    )));
                    ?>
                    <tr data-work-team-row data-work-search-text="<?php echo e(mb_strtolower($team_search_text)); ?>">
                        <td data-label="<?php echo e(t('Agent')); ?>">
                            <a href="<?php echo e($agent_report_url); ?>" class="work-team-agent">
                                <?php echo e($row['name'] ?? t('Agent')); ?>
                            </a>
                            <?php if (!empty($row['is_running'])): ?>
                                <span class="work-team-running"><?php echo e(t('Running')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="<?php echo e(t('Today')); ?>"><?php echo e(format_duration_minutes((int) ($totals['today'] ?? 0))); ?></td>
                        <td data-label="<?php echo e(t('This week')); ?>"><?php echo e(format_duration_minutes((int) ($totals['week'] ?? 0))); ?></td>
                        <td data-label="<?php echo e(t('This month')); ?>"><?php echo e(format_duration_minutes((int) ($totals['month'] ?? 0))); ?></td>
                        <?php if ($show_selected_period_metric): ?>
                            <td data-label="<?php echo e(t('Selected')); ?>"><strong><?php echo e(format_duration_minutes((int) ($totals['selected'] ?? 0))); ?></strong></td>
                        <?php endif; ?>
                        <td class="work-team-last" data-label="<?php echo e(t('Work log')); ?>">
                            <?php $render_work_activity_entries($entries, true); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php $render_work_search_states($team_activity_filter); ?>
</section>
<?php endif; ?>

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
]);
?>

<script defer src="assets/js/work-dashboard.js?v=<?php echo e((string) APP_VERSION); ?>"></script>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
