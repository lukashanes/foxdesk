<?php
/**
 * Dashboard page.
 *
 * Staff see the flight deck: billable money for the period, people and clients,
 * the last 14 worked days and what is happening in the workspace. Clients keep
 * the compact ticket queue with their notifications.
 */

$page_title = t('Dashboard');
$page = 'work';
$user = current_user();
$is_staff = is_admin() || is_agent();
if ($is_staff) {
    require_once BASE_PATH . '/includes/modules/work/flight-deck.php';
    $page_extra_css = ['assets/css/fd-dashboard.css'];
}

$queue_key = trim((string) ($_GET['queue'] ?? 'all'));
$queue_definitions = work_queue_definitions();
if (!isset($queue_definitions[$queue_key])) {
    $queue_key = 'all';
}
if (($queue_definitions[$queue_key]['scope'] ?? '') === 'team' && !$is_staff) {
    $queue_key = 'mine';
}
// Flight deck dashboard: ticket queues live in the ticket list. Legacy queue
// links (the Inbox route, old bookmarks) keep working by landing on the view.
if ($is_staff && isset($_GET['queue'])) {
    $legacy_queue_targets = [
        'unassigned' => ['assignment' => 'unassigned'],
        'waiting' => ['work_view' => 'waiting'],
        'mine' => ['assigned_to' => (int) $user['id']],
    ];
    redirect('tickets', $legacy_queue_targets[$queue_key] ?? []);
}

$queue_summary = [];
$active_queue = null;
$active_items = [];
$queue_pages = 1;
$queue_page = 1;
if (!$is_staff) {
    $queue_summary = work_queue_summary($user, 8);
    $active_queue = $queue_summary[$queue_key] ?? ($queue_summary['mine'] ?? reset($queue_summary));
    $queue_pages = max(1, (int) ceil(($active_queue['count'] ?? 0) / 8));
    $queue_page = max(1, min($queue_pages, (int) ($_GET['queue_page'] ?? 1)));
    $active_items = work_queue_items($queue_key, $user, 8, ($queue_page - 1) * 8, 'last_updated');
}
// The overview always opens on the current month; a period chosen in the URL still wins.
$time_period = time_activity_period_from_request($_GET + ['period' => 'this_month']);
$time_period_options = time_activity_period_options();
$time_view_scope = time_activity_view_scope_from_request($_GET, is_admin());
// Legacy entry filters are not applied to the compact dashboard; the Time agenda owns work logs.
$my_activity_filter = $team_activity_filter = ['key' => 'last3'];
$selected_period_key = (string) ($time_period['period'] ?? 'this_month');
$selected_period_label = (string) ($time_period['label'] ?? t('This month'));
$flight_team = ($time_view_scope['key'] ?? 'mine') === 'team';
$display_agent_id = $flight_team ? null : (int) ($user['id'] ?? 0);

$flight_money = ['amount' => 0.0, 'has_time' => false, 'people' => [], 'clients' => [], 'client_count' => 0, 'reports_supported' => false];
$flight_days = ['days' => [], 'total_minutes' => 0, 'max_minutes' => 0, 'average_minutes' => 0, 'working_days' => 0];
$flight_attention = ['chips' => [], 'calm' => true];
$flight_feed = [];
$flight_totals = $flight_money;
$flight_recent = [];
if ($is_staff) {
    $flight_money = work_flight_deck_money($user, $time_period, $flight_team);
    $flight_days = work_flight_deck_days($user, $flight_team, 14);
    $flight_attention = work_flight_deck_attention($user, $flight_team, $flight_money);
    $flight_feed = work_flight_deck_feed_groups(work_flight_deck_feed($user, $flight_team, 10));
    // People and clients always show the whole workspace for the period; the scope switch drives the money, chart and feed.
    $flight_totals = $flight_team ? $flight_money : work_flight_deck_money($user, $time_period, true);
    $flight_recent = work_flight_deck_recent_tickets($user, $flight_team, 8);
}

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

/** Shares of the largest amount (or minutes when nothing is billable) drive the 4px mini bars. */
$work_row_share = static function (array $row, array $rows): int {
    $max_amount = 0.0;
    $max_minutes = 0;
    foreach ($rows as $candidate) {
        $max_amount = max($max_amount, (float) ($candidate['amount'] ?? 0));
        $max_minutes = max($max_minutes, (int) ($candidate['minutes'] ?? 0));
    }
    if ($max_amount > 0) {
        return (int) round(((float) ($row['amount'] ?? 0) / $max_amount) * 100);
    }
    return $max_minutes > 0 ? (int) round(((int) ($row['minutes'] ?? 0) / $max_minutes) * 100) : 0;
};

/** SVG attribute numbers: short decimals without locale separators. */
$work_svg_number = static function (float $value): string {
    $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
};

require_once BASE_PATH . '/includes/header.php';
?>

<?php if ($is_staff): ?>
<div class="fdd" data-work-time-overview>
<header class="work-canvas-intro fdd-section">
    <div class="fdd-head__title">
        <h1><?php echo e(t('Overview')); ?></h1>
        <time datetime="<?php echo e(date('Y-m-d')); ?>"><?php echo e(format_date_localized(date('Y-m-d'), 'l, F j, Y')); ?></time>
    </div>
    <div class="work-canvas-intro__controls work-range-controls">
        <?php if (!empty($time_view_scope['can_view_team'])): ?>
            <div class="fd-segmented work-scope-switch" aria-label="<?php echo e(t('Time overview')); ?>">
                <?php foreach (array_keys($time_view_scope['options'] ?? []) as $scope_key): ?>
                    <a href="<?php echo e($work_scope_url((string) $scope_key)); ?>"
                       class="fd-segmented__item work-period-link <?php echo ($time_view_scope['key'] ?? 'mine') === $scope_key ? 'is-active' : ''; ?>">
                        <?php echo e($scope_key === 'team' ? t('Team') : t('work.scope.me')); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
</header>

<section class="fdd-grid fdd-section" aria-label="<?php echo e(t('Work overview')); ?>">
    <div class="fdd-hero">
        <p class="fdd-hero__value"><?php echo e(format_money((float) ($flight_money['amount'] ?? 0))); ?></p>
        <?php if (!empty($flight_money['has_time'])): ?>
            <p class="fdd-hero__sub">
                <?php echo e(t('work.money.billable_for', ['period' => $selected_period_label])); ?><br>
                <?php if (($flight_money['change_percent'] ?? null) !== null): ?>
                    <?php $flight_change = (int) $flight_money['change_percent']; ?>
                    <span class="fdd-hero__chip<?php echo $flight_change < 0 ? ' fdd-hero__chip--down' : ''; ?>"><?php echo e(t('work.money.change_vs', ['change' => ($flight_change < 0 ? '-' : '+') . abs($flight_change) . ' %', 'period' => (string) ($flight_money['previous_label'] ?? '')])); ?></span>
                    ·
                <?php endif; ?>
                <?php echo e(tn('work.money.clients', (int) ($flight_money['client_count'] ?? 0))); ?>
            </p>
            <?php if (!empty($flight_money['reports_supported'])): ?>
                <p class="fdd-hero__line">
                    <?php echo e(t('work.money.reported', ['amount' => format_money((float) ($flight_money['reported_amount'] ?? 0))])); ?>
                    ·
                    <?php if ((float) ($flight_money['unreported_amount'] ?? 0) > 0): ?>
                        <em><?php echo e(t('work.money.to_report', ['amount' => format_money((float) $flight_money['unreported_amount'])])); ?></em>
                    <?php else: ?>
                        <?php echo e(t('work.money.all_reported')); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p class="fdd-hero__sub"><?php echo e(t('work.money.empty', ['period' => $selected_period_label])); ?></p>
        <?php endif; ?>
    </div>

    <div class="fdd-col fdd-rows">
        <div class="fdd-col__head">
            <h2><?php echo e(t('work.people.title', ['period' => $selected_period_label]) . (!$flight_team ? ' · ' . t('work.scope.team_total') : '')); ?></h2>
            <a class="fdd-link" href="<?php echo e($work_report_url($selected_period_key, $display_agent_id)); ?>"><?php echo e(t('Work log')); ?> <span aria-hidden="true">→</span></a>
        </div>
        <?php $flight_people = array_slice((array) ($flight_totals['people'] ?? []), 0, 6); ?>
        <?php if ($flight_people): ?>
            <table class="fd-table">
                <colgroup><col class="fdd-col--logo"><col><col class="fdd-col--bar"><col class="fdd-col--hours"><col class="fdd-col--amount"></colgroup>
                <caption class="sr-only"><?php echo e(t('work.people.title', ['period' => $selected_period_label]) . (!$flight_team ? ' · ' . t('work.scope.team_total') : '')); ?></caption>
                <thead class="sr-only"><tr><th scope="col"></th><th scope="col"><?php echo e(t('Team')); ?></th><th scope="col"></th><th scope="col"><?php echo e(t('Work log')); ?></th><th scope="col"><?php echo e(t('Billable')); ?></th></tr></thead>
                <tbody>
                <?php foreach ($flight_people as $person): ?>
                    <tr>
                        <td class="fdd-rows__cell--logo"><?php echo render_user_avatar($person, 'xs'); ?></td>
                        <td><span class="fdd-rows__name"><bdi dir="auto"><?php echo e((string) $person['name']); ?></bdi></span></td>
                        <td class="fdd-rows__cell--bar"><svg class="fdd-rows__bar" aria-hidden="true" focusable="false"><rect class="fdd-rows__bar-track" width="100%" height="100%" rx="2"></rect><rect class="fdd-rows__bar-fill" width="<?php echo $work_row_share($person, $flight_people); ?>%" height="100%" rx="2"></rect></svg></td>
                        <td class="fdd-rows__cell--hours"><?php echo e(format_duration_minutes((int) $person['minutes'])); ?></td>
                        <td class="fdd-rows__cell--amount"><?php echo e(format_money((float) $person['amount'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="fdd-empty"><?php echo e(t('work.people.empty')); ?></p>
        <?php endif; ?>
    </div>

    <div class="fdd-col fdd-rows" data-work-client-matrix>
        <div class="fdd-col__head">
            <h2><?php echo e(t('work.clients.title', ['period' => $selected_period_label]) . (!$flight_team ? ' · ' . t('work.scope.team_total') : '')); ?></h2>
            <?php if (is_admin()): ?>
                <a class="fdd-link" href="<?php echo e(url('admin', ['section' => 'reports-list'])); ?>"><?php echo e(t('Report history')); ?> <span aria-hidden="true">→</span></a>
            <?php else: ?>
                <a class="fdd-link" href="<?php echo e(url('tickets')); ?>"><?php echo e(t('Tickets')); ?> <span aria-hidden="true">→</span></a>
            <?php endif; ?>
        </div>
        <?php $flight_clients = array_slice((array) ($flight_totals['clients'] ?? []), 0, 6); ?>
        <?php if ($flight_clients): ?>
            <table class="fd-table">
                <colgroup><col class="fdd-col--logo"><col><col class="fdd-col--bar"><col class="fdd-col--hours"><col class="fdd-col--amount"></colgroup>
                <caption class="sr-only"><?php echo e(t('work.clients.title', ['period' => $selected_period_label]) . (!$flight_team ? ' · ' . t('work.scope.team_total') : '')); ?></caption>
                <thead class="sr-only"><tr><th scope="col"></th><th scope="col"><?php echo e(t('Client')); ?></th><th scope="col"></th><th scope="col"><?php echo e(t('Work log')); ?></th><th scope="col"><?php echo e(t('Billable')); ?></th></tr></thead>
                <tbody>
                <?php foreach ($flight_clients as $client): ?>
                    <tr>
                        <td class="fdd-rows__cell--logo"><span class="fdd-rows__logo" aria-hidden="true"><?php echo e(work_flight_deck_initials((string) $client['name'])); ?></span></td>
                        <td><span class="fdd-rows__name"><?php if ((int) $client['id'] > 0): ?><a href="<?php echo e(url('client', ['id' => (int) $client['id']])); ?>"><?php endif; ?><bdi dir="auto"><?php echo e((string) $client['name']); ?></bdi><?php if ((int) $client['id'] > 0): ?></a><?php endif; ?></span></td>
                        <td class="fdd-rows__cell--bar"><svg class="fdd-rows__bar" aria-hidden="true" focusable="false"><rect class="fdd-rows__bar-track" width="100%" height="100%" rx="2"></rect><rect class="fdd-rows__bar-fill" width="<?php echo $work_row_share($client, $flight_clients); ?>%" height="100%" rx="2"></rect></svg></td>
                        <td class="fdd-rows__cell--hours"><?php echo e(format_duration_minutes((int) $client['minutes'])); ?></td>
                        <td class="fdd-rows__cell--amount"><?php echo e(format_money((float) $client['amount'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="fdd-empty"><?php echo e(t('work.clients.empty')); ?></p>
        <?php endif; ?>
    </div>
</section>

<?php
$chart_days = (array) ($flight_days['days'] ?? []);
$chart_count = max(1, count($chart_days));
$chart_max_minutes = max(1, (int) ($flight_days['max_minutes'] ?? 0));
$chart_total_minutes = (int) ($flight_days['total_minutes'] ?? 0);
// Percent-based geometry keeps the SVG crisp at any width without a stretched viewBox.
$chart_plot_left = 3.0;
$chart_plot_right = 97.0;
$chart_plot_top = 18.0;
$chart_plot_base = 84.0;
$chart_plot_height = $chart_plot_base - $chart_plot_top;
$chart_slot = ($chart_plot_right - $chart_plot_left) / $chart_count;
$chart_bar_width = $chart_slot * 0.5;
$chart_average_ratio = min(1.0, (int) ($flight_days['average_minutes'] ?? 0) / $chart_max_minutes);
$chart_max_index = null;
foreach ($chart_days as $chart_index => $chart_day) {
    if ((int) ($chart_day['minutes'] ?? 0) > 0 && (int) $chart_day['minutes'] === (int) ($flight_days['max_minutes'] ?? 0)) {
        $chart_max_index = $chart_index;
        break;
    }
}
?>
<section id="time-overview" class="fd-card fd-page-section work-overview-card fdd-chart" data-work-period-chart data-work-hours-chart>
    <div class="fd-section-header work-overview-head fdd-chart__head">
        <div class="fd-section-main">
            <h2 class="fd-section-title work-overview-title"><?php echo e(t('Work overview')); ?></h2>
        </div>
        <div class="fd-section-actions fdd-chart__meta">
            <span><?php echo e(t('work.chart.summary', ['days' => (string) $chart_count, 'total' => format_duration_minutes($chart_total_minutes), 'average' => format_duration_minutes((int) ($flight_days['average_minutes'] ?? 0))])); ?></span>
            <a class="fdd-link" href="<?php echo e(url('time')); ?>"><?php echo e(t('Work log')); ?> <span aria-hidden="true">→</span></a>
        </div>
    </div>
    <div class="fdd-chart__plot" data-fdd-chart-plot>
        <div class="fdd-chart__tip" data-fdd-chart-tip hidden><b data-fdd-tip-value></b><span data-fdd-tip-label></span></div>
        <svg class="fdd-chart__svg" role="img" aria-label="<?php echo e(t('Hours by day')); ?>" focusable="false">
            <title><?php echo e(t('Hours by day')); ?> · <?php echo e(format_duration_minutes($chart_total_minutes)); ?></title>
            <defs>
                <linearGradient id="fdd-bar-gradient" gradientUnits="userSpaceOnUse" x1="0" y1="<?php echo e($work_svg_number($chart_plot_top)); ?>%" x2="0" y2="<?php echo e($work_svg_number($chart_plot_base)); ?>%">
                    <stop offset="0" class="fdd-chart__stop-a"></stop>
                    <stop offset="1" class="fdd-chart__stop-b"></stop>
                </linearGradient>
            </defs>
            <line class="fdd-chart__base" x1="<?php echo e($work_svg_number($chart_plot_left)); ?>%" x2="<?php echo e($work_svg_number($chart_plot_right)); ?>%" y1="<?php echo e($work_svg_number($chart_plot_base)); ?>%" y2="<?php echo e($work_svg_number($chart_plot_base)); ?>%"></line>
            <?php if ($chart_total_minutes > 0): ?>
                <?php $chart_average_y = $chart_plot_base - ($chart_average_ratio * $chart_plot_height); ?>
                <line class="fdd-chart__avg" x1="<?php echo e($work_svg_number($chart_plot_left)); ?>%" x2="<?php echo e($work_svg_number($chart_plot_right)); ?>%" y1="<?php echo e($work_svg_number($chart_average_y)); ?>%" y2="<?php echo e($work_svg_number($chart_average_y)); ?>%"></line>
                <text class="fdd-chart__avg-label" x="<?php echo e($work_svg_number($chart_plot_right)); ?>%" y="<?php echo e($work_svg_number($chart_average_y)); ?>%" dy="-4" text-anchor="end">Ø</text>
            <?php endif; ?>
            <?php foreach ($chart_days as $chart_index => $chart_day): ?>
                <?php
                $day_minutes = max(0, (int) ($chart_day['minutes'] ?? 0));
                $bar_x = $chart_plot_left + ($chart_slot * $chart_index) + (($chart_slot - $chart_bar_width) / 2);
                $bar_center = $chart_plot_left + ($chart_slot * ($chart_index + 0.5));
                $bar_ratio = $day_minutes > 0 ? min(1.0, $day_minutes / $chart_max_minutes) : 0.0;
                $bar_top = $chart_plot_base - ($bar_ratio * $chart_plot_height);
                $clip_id = 'fdd-bar-clip-' . (int) $chart_index;
                $is_today = !empty($chart_day['is_today']);
                $label_anchor = $chart_index < 2 ? 'start' : ($chart_index > $chart_count - 3 ? 'end' : 'middle');
                $label_x = $label_anchor === 'start' ? $bar_x : ($label_anchor === 'end' ? $bar_x + $chart_bar_width : $bar_center);
                ?>
                <g class="fdd-chart__day" data-fdd-day data-label="<?php echo e((string) ($chart_day['full_label'] ?? '')); ?>" data-value="<?php echo e(format_duration_minutes($day_minutes)); ?>" data-center="<?php echo e($work_svg_number($bar_center)); ?>" data-top="<?php echo e($work_svg_number($day_minutes > 0 ? $bar_top : $chart_plot_base)); ?>">
                    <title><?php echo e((string) ($chart_day['full_label'] ?? '')); ?> · <?php echo e(format_duration_minutes($day_minutes)); ?></title>
                    <rect class="fdd-chart__hit" x="<?php echo e($work_svg_number($chart_plot_left + ($chart_slot * $chart_index))); ?>%" y="0" width="<?php echo e($work_svg_number($chart_slot)); ?>%" height="100%" fill="transparent"></rect>
                    <?php if ($day_minutes > 0): ?>
                        <clipPath id="<?php echo e($clip_id); ?>">
                            <rect x="<?php echo e($work_svg_number($bar_x - 0.2)); ?>%" y="<?php echo e($work_svg_number($bar_top - 0.5)); ?>%" width="<?php echo e($work_svg_number($chart_bar_width + 0.4)); ?>%" height="<?php echo e($work_svg_number($chart_plot_base - $bar_top + 0.5)); ?>%"></rect>
                        </clipPath>
                        <rect class="fdd-chart__bar<?php echo $is_today ? ' fdd-chart__bar--today' : ''; ?>"
                              clip-path="url(#<?php echo e($clip_id); ?>)"
                              x="<?php echo e($work_svg_number($bar_x)); ?>%"
                              y="<?php echo e($work_svg_number($bar_top)); ?>%"
                              width="<?php echo e($work_svg_number($chart_bar_width)); ?>%"
                              height="<?php echo e($work_svg_number(100 - $bar_top)); ?>%"
                              rx="4" ry="4"></rect>
                    <?php else: ?>
                        <rect class="fdd-chart__bar--off"
                              x="<?php echo e($work_svg_number($bar_x)); ?>%"
                              y="<?php echo e($work_svg_number($chart_plot_base)); ?>%"
                              width="<?php echo e($work_svg_number($chart_bar_width)); ?>%"
                              height="4" rx="2" transform="translate(0 -4)"></rect>
                    <?php endif; ?>
                    <text class="fdd-chart__axis" x="<?php echo e($work_svg_number($bar_center)); ?>%" y="95%" text-anchor="middle"><?php echo e((string) ($chart_day['label'] ?? '')); ?></text>
                    <?php if ($day_minutes > 0 && $is_today): ?>
                        <text class="fdd-chart__label fdd-chart__label--today" x="<?php echo e($work_svg_number($label_x)); ?>%" y="<?php echo e($work_svg_number($bar_top)); ?>%" dy="-12" text-anchor="<?php echo e($label_anchor); ?>"><?php echo e(t('Today')); ?> · <?php echo e(format_duration_minutes($day_minutes)); ?></text>
                    <?php elseif ($day_minutes > 0 && $chart_max_index === $chart_index): ?>
                        <text class="fdd-chart__label" x="<?php echo e($work_svg_number($label_x)); ?>%" y="<?php echo e($work_svg_number($bar_top)); ?>%" dy="-12" text-anchor="<?php echo e($label_anchor); ?>"><?php echo e((string) ($chart_day['short_label'] ?? '')); ?> · <?php echo e(format_duration_minutes($day_minutes)); ?></text>
                    <?php endif; ?>
                </g>
            <?php endforeach; ?>
            <?php if ($chart_total_minutes <= 0): ?>
                <text class="fdd-chart__axis" x="50%" y="52%" text-anchor="middle"><?php echo e(t('No activity')); ?></text>
            <?php endif; ?>
        </svg>
    </div>
</section>

<div class="fdd-low">
<section class="fdd-feed" aria-label="<?php echo e(t('work.feed.title')); ?>">
    <div class="fdd-feed__head">
        <h2><?php echo e(t('work.feed.title')); ?></h2>
        <a class="fdd-link" href="<?php echo e(url('notifications')); ?>"><?php echo e(t('Notifications')); ?> <span aria-hidden="true">→</span></a>
    </div>
    <div class="fdd-need">
        <?php if (!empty($flight_attention['calm'])): ?>
            <span class="fdd-chip fdd-chip--ok"><?php echo get_icon('check', 'w-3 h-3'); ?><?php echo e(t('work.attention.calm')); ?></span>
        <?php else: ?>
            <span class="fdd-need__label"><?php echo e(t('work.attention.label')); ?></span>
            <?php foreach ((array) ($flight_attention['chips'] ?? []) as $chip): ?>
                <a class="fdd-chip fdd-chip--<?php echo e((string) $chip['tone']); ?>" href="<?php echo e((string) $chip['url']); ?>"><b><?php echo (int) $chip['count']; ?></b> <?php echo e((string) $chip['label']); ?><?php if (!empty($chip['detail'])): ?> · <em><?php echo e((string) $chip['detail']); ?></em><?php endif; ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php if (!$flight_feed): ?>
        <p class="fdd-feed__empty"><?php echo e(t('work.feed.empty')); ?></p>
    <?php endif; ?>
    <?php foreach ($flight_feed as $feed_group): ?>
        <div class="fdd-feed__group"><?php echo e((string) $feed_group['label']); ?></div>
        <?php foreach ((array) $feed_group['items'] as $feed_item): ?>
            <a class="fdd-feed__item" href="<?php echo e((string) $feed_item['url']); ?>">
                <span class="fdd-feed__time"><?php echo e((string) $feed_item['time']); ?></span>
                <span class="fdd-feed__dot fdd-feed__dot--<?php echo e((string) $feed_item['type']); ?>" aria-hidden="true"></span>
                <span class="fdd-feed__text">
                    <b><bdi dir="auto"><?php echo e((string) $feed_item['actor']); ?></bdi></b><?php echo $feed_item['type'] === 'bill' ? ' ·' : ''; ?> <?php echo e((string) $feed_item['verb']); ?><?php if ((int) ($feed_item['count'] ?? 1) > 1): ?> <span class="fdd-feed__count"><?php echo e(t('work.feed.repeat', ['count' => (string) (int) $feed_item['count']])); ?></span><?php endif; ?>
                    <?php if ((string) $feed_item['code'] !== ''): ?> · <code><?php echo e((string) $feed_item['code']); ?></code><?php endif; ?>
                    <?php if ((string) $feed_item['title'] !== ''): ?> <bdi dir="auto"><?php echo e((string) $feed_item['title']); ?></bdi><?php endif; ?>
                    <?php if ((string) $feed_item['meta'] !== ''): ?> · <bdi dir="auto"><?php echo e((string) $feed_item['meta']); ?></bdi><?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>
</section>
<section class="fdd-recent" aria-label="<?php echo e(t('work.recent.title')); ?>">
    <div class="fdd-feed__head">
        <h2><?php echo e(t('work.recent.title')); ?></h2>
        <a class="fdd-link" href="<?php echo e(url('tickets', ['sort' => 'last_updated'])); ?>"><?php echo e(t('Tickets')); ?> <span aria-hidden="true">→</span></a>
    </div>
    <?php if (!$flight_recent): ?>
        <p class="fdd-feed__empty"><?php echo e(t('work.recent.empty')); ?></p>
    <?php endif; ?>
    <?php foreach ($flight_recent as $recent_ticket): ?>
        <a class="fdd-recent__row" href="<?php echo e((string) $recent_ticket['url']); ?>">
            <span class="fdd-recent__dot fdd-recent__dot--<?php echo e((string) $recent_ticket['status_group']); ?>" aria-hidden="true"></span>
            <span class="fdd-recent__main">
                <span class="fdd-recent__title"><bdi dir="auto"><?php echo e((string) $recent_ticket['title']); ?></bdi></span>
                <span class="fdd-recent__meta"><code><?php echo e((string) $recent_ticket['code']); ?></code><?php if ($recent_ticket['client'] !== ''): ?> · <bdi dir="auto"><?php echo e((string) $recent_ticket['client']); ?></bdi><?php endif; ?> · <?php echo e((string) $recent_ticket['status']); ?></span>
            </span>
            <span class="fdd-recent__time"><?php echo e((string) $recent_ticket['updated']); ?></span>
        </a>
    <?php endforeach; ?>
</section>
</div>
</div>
<?php else: ?>
<header class="work-canvas-intro">
    <div>
        <h1><?php echo e(t('Overview')); ?></h1>
        <p><?php echo e(t('New and active tickets')); ?></p>
    </div>
    <div class="work-canvas-intro__controls">
        <time datetime="<?php echo e(date('Y-m-d')); ?>"><?php echo e(format_date_localized(date('Y-m-d'), 'l, F j, Y')); ?></time>
    </div>
</header>

<div class="work-canvas-grid work-canvas-grid--client">
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
            'render_mode_switch' => null,
            'render_content' => null,
        ]);
        ?>
        <nav class="work-queue-pagination" aria-label="<?php echo e(t('Tickets')); ?>">
            <span><?php echo $queue_page; ?> / <?php echo $queue_pages; ?> · <?php echo (int) ($active_queue['count'] ?? 0); ?> <?php echo e(t('Tickets')); ?></span>
            <?php foreach ([-1 => t('Previous'), 1 => t('Next')] as $step => $label): ?>
                <?php $page_params = $_GET; unset($page_params['page']); $page_params['queue_page'] = $queue_page + $step; ?>
                <?php if ($queue_page + $step >= 1 && $queue_page + $step <= $queue_pages): ?>
                    <a class="btn btn-secondary btn-sm" href="<?php echo e(url('work', $page_params)); ?>"><?php echo e($label); ?></a>
                <?php else: ?><button class="btn btn-secondary btn-sm" disabled><?php echo e($label); ?></button><?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>
    <section class="work-readiness-panel">
        <?php include BASE_PATH . '/includes/components/work-context.php'; ?>
    </section>
</div>
<?php endif; ?>

<script defer src="assets/js/work-dashboard.js?v=<?php echo e($work_asset_version('assets/js/work-dashboard.js')); ?>"></script>
<script defer src="assets/js/work-events.js?v=<?php echo e($work_asset_version('assets/js/work-events.js')); ?>"></script>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
