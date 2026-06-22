<?php
/**
 * Work page.
 *
 * Action-first view for daily support work. Dashboard keeps analytics; this page
 * shows the queues that need attention now.
 */

$page_title = t('Work');
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
$my_time_totals = $time_work['my_totals'];
$team_time_rows = $time_work['team'];

$work_queue_url = static function (string $key): string {
    return url('work', $key === 'mine' ? [] : ['queue' => $key]);
};

$work_period_url = static function (string $period) use ($queue_key): string {
    $params = ['period' => $period];
    if ($queue_key !== 'mine') {
        $params['queue'] = $queue_key;
    }
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

require_once BASE_PATH . '/includes/header.php';
?>

<section class="work-overview-card" data-work-time-overview>
    <div class="work-overview-head">
        <div>
            <p class="work-overview-kicker"><?php echo e(t('Worked time')); ?></p>
            <h2 class="work-overview-title"><?php echo e(t('Work overview')); ?></h2>
        </div>
        <div class="work-period-switch" aria-label="<?php echo e(t('Time period')); ?>">
            <?php foreach ($time_period_options as $period_key => $period_label): ?>
                <?php if ($period_key === 'custom') continue; ?>
                <a href="<?php echo e($work_period_url((string) $period_key)); ?>"
                   class="work-period-link <?php echo ($time_period['period'] ?? '') === $period_key ? 'is-active' : ''; ?>">
                    <?php echo e($period_label); ?>
                </a>
            <?php endforeach; ?>
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
        <div class="work-time-metric work-time-metric--selected">
            <span><?php echo e($time_period['label'] ?? t('Selected period')); ?></span>
            <strong><?php echo e(format_duration_minutes((int) ($my_time_totals['selected'] ?? 0))); ?></strong>
        </div>
    </div>

    <form method="get" class="work-custom-period">
        <input type="hidden" name="page" value="work">
        <?php if ($queue_key !== 'mine'): ?>
            <input type="hidden" name="queue" value="<?php echo e($queue_key); ?>">
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
        <button type="submit" class="btn btn-secondary btn-sm"><?php echo e(t('Apply')); ?></button>
    </form>
</section>

<?php if (is_admin()): ?>
<section class="work-team-card" data-work-team-time>
    <div class="work-team-head">
        <div>
            <p class="work-overview-kicker"><?php echo e(t('Team')); ?></p>
            <h2 class="work-team-title"><?php echo e(t('Team time')); ?></h2>
        </div>
        <a href="<?php echo e(url('admin', ['section' => 'reports'])); ?>" class="btn btn-secondary btn-sm">
            <?php echo get_icon('chart-bar', 'w-4 h-4 mr-1'); ?><?php echo e(t('Open reports')); ?>
        </a>
    </div>
    <div class="work-team-table-wrap">
        <table class="work-team-table">
            <thead>
                <tr>
                    <th><?php echo e(t('Agent')); ?></th>
                    <th><?php echo e(t('Today')); ?></th>
                    <th><?php echo e(t('This week')); ?></th>
                    <th><?php echo e(t('This month')); ?></th>
                    <th><?php echo e(t('Selected')); ?></th>
                    <th><?php echo e(t('Last activity')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($team_time_rows)): ?>
                    <tr><td colspan="6" class="work-team-empty"><?php echo e(t('No team time yet.')); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($team_time_rows as $row): ?>
                    <?php
                    $staff = $row['user'] ?? [];
                    $staff_id = (int) ($staff['id'] ?? 0);
                    $totals = $row['totals'] ?? [];
                    $latest = $row['latest_entry'] ?? null;
                    $agent_report_url = url('admin', [
                        'section' => 'reports',
                        'tab' => 'time',
                        'period' => $time_period['period'] ?? 'this_month',
                    ]) . '&agents%5B%5D=' . urlencode((string) $staff_id);
                    ?>
                    <tr>
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
                        <td data-label="<?php echo e(t('Selected')); ?>"><strong><?php echo e(format_duration_minutes((int) ($totals['selected'] ?? 0))); ?></strong></td>
                        <td class="work-team-last" data-label="<?php echo e(t('Last activity')); ?>">
                            <?php if ($latest): ?>
                                <?php echo e($latest['ticket_title'] ?? t('Time entry')); ?>
                                <span><?php echo e(format_date($latest['started_at'] ?? '')); ?></span>
                            <?php else: ?>
                                <span><?php echo e(t('No activity')); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php
workspace_render_queue_page([
    'title' => 'Work',
    'summary' => $queue_summary,
    'active_key' => $queue_key,
    'active_queue' => $active_queue,
    'items' => $active_items,
    'queue_url' => $work_queue_url,
    'view_all_url' => $work_tickets_url($queue_key),
    'primary_action' => workspace_surface_action(url('new-ticket'), 'New ticket'),
    'row_options' => ['show_assignee' => true],
]);
?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
