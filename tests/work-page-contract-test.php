<?php
$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$header = file_get_contents($root . '/includes/header.php');
$work = file_get_contents($root . '/pages/work.php');
$timeModel = file_get_contents($root . '/includes/modules/work/time-activity-summary.php');
$timerApi = file_get_contents($root . '/includes/api/user-handler.php');
$appFooterJs = file_get_contents($root . '/assets/js/app-footer.js');

function assert_work_page($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_work_page(strpos($index, "case 'work'") !== false, 'work route is not registered.');
assert_work_page(strpos($index, "return 'work';") !== false, 'authenticated self-hosted home should route to work.');
assert_work_page(strpos($header, "url('work')") !== false, 'sidebar should link to work.');
$flightDeck = file_get_contents($root . '/includes/modules/work/flight-deck.php');
$dashboardCss = file_get_contents($root . '/assets/css/fd-dashboard.css');
assert_work_page(strpos($work, 'work_queue_summary') !== false, 'client dashboard should use the work queue module.');
assert_work_page(strpos($work, "require_once BASE_PATH . '/includes/modules/work/flight-deck.php'") !== false, 'staff dashboard must load the flight deck read model.');
assert_work_page(strpos($work, 'work_flight_deck_money(') !== false, 'staff dashboard must render billable money from the flight deck model.');
assert_work_page(strpos($work, 'work_flight_deck_days(') !== false, 'staff dashboard must render the last-14-days chart from the flight deck model.');
assert_work_page(strpos($work, 'work_flight_deck_attention(') !== false, 'staff dashboard must render the waiting-for-you chips from the flight deck model.');
assert_work_page(strpos($work, 'work_flight_deck_feed(') !== false, 'staff dashboard must render the activity feed from the flight deck model.');
assert_work_page(strpos($work, "\$page_extra_css = ['assets/css/fd-dashboard.css']") !== false, 'staff dashboard styles must load through the page extra CSS mechanism.');
assert_work_page($dashboardCss !== false && strpos($dashboardCss, 'grid-template-columns: 1fr 1fr 1.1fr') !== false, 'top row must share one three-column grid.');
assert_work_page(strpos($dashboardCss, 'radial-gradient(var(--fdd-dot) 1.2px, transparent 1.4px)') !== false, 'chart must sit on the dotted grid background.');
assert_work_page(strpos($work, 'time_activity_work_model(') === false, 'compact dashboard must not execute duplicate legacy aggregates.');
assert_work_page(strpos($work, 'dashboard_clients_model') === false, 'flight deck replaces the client/day matrix model.');
assert_work_page(strpos($work, 'data-work-log-surface') === false, 'work logs belong to the paged Time agenda.');
assert_work_page(strpos($work, "url('time')") !== false, 'dashboard must link to the standalone Time agenda.');
assert_work_page(strpos($work, 'data-work-time-overview') !== false, 'work page must expose a stable time overview hook.');
assert_work_page(strpos($work, '<div class="fdd" data-work-time-overview>') < strpos($work, 'work-period-switch'), 'period and scope switches must live inside the partially updated region.');
assert_work_page(strpos($work, 'work-range-controls') !== false, 'work page must group preset and custom date range controls together.');
assert_work_page(strpos($work, 'class="work-custom-period"') < strpos($work, 'data-work-period-chart'), 'custom date range controls must stay above the chart.');
assert_work_page(strpos($work, 'data-work-period-chart') !== false, 'work page must render the worked-hours chart.');
assert_work_page(strpos($timeModel, "'last_30_days' => t('Last 30 days')") !== false, 'work time period switch must include the last-30-days graph preset.');
assert_work_page(strpos($timeModel, "foxdesk_work_time_period") !== false, 'work dashboard must remember the selected graph period.');
assert_work_page(strpos($timeModel, "'last_30_days'" ) !== false, 'last 30 days must remain available as a graph preset.');
assert_work_page(strpos($timeModel, "\$period = 'this_month';") !== false, 'invalid work graph periods must fall back to this month.');
assert_work_page(strpos($work, 'fdd-chart__svg') !== false, 'worked-hours chart must be a server-rendered SVG.');
assert_work_page(strpos($work, 'id="fdd-bar-gradient"') !== false, 'chart bars must use the vertical primary gradient.');
assert_work_page(strpos($work, '<clipPath id="<?php echo e($clip_id); ?>">') !== false, 'chart bars must round only the top through a clip path.');
assert_work_page(strpos($work, 'fdd-chart__bar--today') !== false, 'chart must outline today.');
assert_work_page(strpos($work, 'fdd-chart__bar--off') !== false, 'chart must render zero days as stubs.');
assert_work_page(strpos($work, 'fdd-chart__avg') !== false, 'chart must draw the dashed working-day average.');
assert_work_page(strpos($work, 'fdd-chart__label--today') !== false, 'chart must label today.');
assert_work_page(strpos($work, 'work-week-chart__bars') === false, 'work chart must not render the old calendar-like manual day grid.');
assert_work_page(strpos($work, 'chart.umd.js') === false, 'the flight deck chart must not depend on Chart.js.');
assert_work_page(strpos($work, 'data-work-current') === false, 'running timers live in the global top bar, not on the dashboard.');
assert_work_page(strpos($work, 'data-work-agent-launcher') === false, 'the agent launcher lives in the global top bar, not on the dashboard.');
assert_work_page(strpos($work, 'format_money(') !== false, 'money must be formatted through the shared currency helper.');
assert_work_page(strpos($work, "tn('work.money.clients'") !== false, 'client counts must use plural-aware copy.');
assert_work_page(strpos($work, 'fdd-chip--ok') !== false, 'calm days must render the single green chip.');
assert_work_page(strpos($work, 'fdd-feed__group') !== false, 'activity must be grouped by day.');
assert_work_page(strpos($work, "url('notifications')") !== false, 'feed must link to the full notifications list.');
assert_work_page(strpos($work, 'work_flight_deck_initials(') !== false, 'client rows must render a two-letter mark.');
assert_work_page(strpos($work, 'render_user_avatar(') !== false, 'people rows must reuse the shared avatar.');
assert_work_page(strpos($work, 'style="') === false, 'dashboard markup must not use inline styles (CSP hygiene).');
assert_work_page(strpos($work, '$work_period_url') !== false, 'selected graph period must remain navigable.');
assert_work_page(strpos($work, "'contract_surface' => 'server-work'") !== false, 'legacy six-row API hydration must not replace a server-paged queue.');
assert_work_page(strpos($work, "t('Work overview')") !== false, 'work page must use the shared work-overview chart title.');
assert_work_page(strpos($work, '$work_report_url') !== false, 'people rows must link to the underlying report log.');
assert_work_page(strpos($work, "['section' => 'reports-list']") !== false, 'admin report history must link to the client report list.');
assert_work_page(strpos($work, "e(t('Report history'))") !== false, 'client report history must be visibly labelled on the dashboard.');
assert_work_page($flightDeck !== false && strpos($flightDeck, "['section' => 'report-builder', 'edit' =>") !== false, 'report feed items must open the selected client report.');
assert_work_page(strpos($flightDeck, 'billing_review_entry_rate(') !== false, 'dashboard money must reuse the billing review rate resolution.');
assert_work_page(strpos($flightDeck, 'billing_review_entry_billable_minutes(') !== false, 'dashboard money must reuse the billing review billable minutes.');
assert_work_page(strpos($flightDeck, 'build_ticket_visibility_filters_for_user(') !== false, 'flight deck queries must be visibility-scoped.');
assert_work_page(strpos($flightDeck, "FROM activity_log al") !== false, 'activity feed must read the ticket activity log.');
assert_work_page(strpos($flightDeck, "'triage_state' => 'new'") !== false, 'triage chip must agree with the ticket list New view.');
assert_work_page(strpos(file_get_contents($root . '/includes/modules/work/recent-updates.php'), "url('ticket', ['t' => \$entry['hash']])") !== false, 'recent updates must link to the exact source ticket.');
assert_work_page(strpos($work, "my_activity") !== false, 'my work log must have an independent filter parameter.');
assert_work_page(strpos($work, "team_activity") !== false, 'team time must have an independent filter parameter.');
assert_work_page(strpos($work, "assets/js/work-dashboard.js") !== false, 'work page must load the work dashboard JS.');
$workDashboardJs = file_get_contents($root . '/assets/js/work-dashboard.js');
assert_work_page($workDashboardJs !== false && strpos($workDashboardJs, 'window.Chart') === false, 'work dashboard JS must not initialise Chart.js.');
assert_work_page(strpos($workDashboardJs, 'function initContextTabs') !== false, 'client dashboard notification tabs must keep working.');
assert_work_page(strpos($workDashboardJs, 'innerHTML') === false, 'work dashboard live timer rendering must use safe DOM methods.');
assert_work_page($appFooterJs !== false && strpos($appFooterJs, "new CustomEvent('activeTimersUpdated'") !== false, 'sidebar timer polling must broadcast the shared active timer feed.');
assert_work_page(strpos($appFooterJs, 'pollSidebarTimers();') < strpos($appFooterJs, 'setInterval(pollSidebarTimers, 30000)'), 'active timers must refresh immediately before background polling starts.');
assert_work_page($timerApi !== false && strpos($timerApi, "'elapsed_seconds'=>") !== false, 'active timer API must expose exact elapsed seconds.');
assert_work_page(strpos($timerApi, "'ticket_url'") !== false, 'active timer API must expose the selected ticket URL.');
assert_work_page(strpos($work, "t('All work')") === false, 'work page must not render the old all-work range.');
assert_work_page(strpos($work, 'workspace_render_queue_page') !== false, 'work page should use the shared workspace queue renderer.');
assert_work_page(strpos($work, "'primary_action' => ''") !== false, 'dashboard queue should not render a duplicate new-ticket action.');
assert_work_page(strpos($work, "'show_assignee' => true") !== false, 'work ticket rows should show assignee context.');
assert_work_page(strpos($work, "url('tickets', ['work_view' => 'waiting']") !== false, 'work page should link waiting queue to the ticket list view.');
assert_work_page(strpos($work, "workspace_surface_action(url('dashboard'), 'Analytics'") === false, 'work page should not expose dashboard as a parallel agenda.');
assert_work_page(strpos($work, "url('billing')") === false && strpos($work, 'data-signup-onboarding') === false, 'self-hosted dashboard has no hosted signup onboarding or billing links.');
foreach ([
    'Work queues',
    'Start with the queue that needs attention now.',
    'Current queue',
    'Tickets assigned to the current user.',
    'This queue is clear.',
] as $forbidden_copy) {
    assert_work_page(strpos($work, $forbidden_copy) === false, 'work page should not render redundant helper copy: ' . $forbidden_copy);
}
$workspaceSurface = file_get_contents($root . '/includes/components/workspace-surface.php');
assert_work_page($workspaceSurface !== false && strpos($workspaceSurface, "t('All clear')") !== false, 'empty work queue should use concise state copy from the shared renderer.');
assert_work_page(strpos($workspaceSurface, "array_key_exists('primary_action', \$options)") !== false, 'workspace queue renderer must allow pages to suppress the default primary action.');
assert_work_page(strpos($workspaceSurface, "'worked_minutes'") !== false, 'workspace ticket rows must be able to render worked time.');
$workQueues = file_get_contents($root . '/includes/modules/work/work-queues.php');
assert_work_page($workQueues !== false && strpos($workQueues, 'work_queue_attach_worked_minutes') !== false, 'work queues must enrich tickets with worked minutes.');
assert_work_page($timeModel !== false && strpos($timeModel, 'function time_activity_scope_from_request') !== false, 'time activity model must parse work activity scope.');
assert_work_page(strpos($timeModel, 'function time_activity_log_filter_from_request') !== false, 'time activity model must parse remembered work log filters.');
assert_work_page(strpos($timeModel, "'last3' => t('Last 3 tickets')") !== false, 'time activity model must expose last-three filter.');
assert_work_page(strpos($timeModel, "'search' => t('Search')") !== false, 'time activity model must expose search filter instead of all-work.');
assert_work_page(strpos($timeModel, "'foxdesk_work_my_activity_filter'") !== false, 'my work filter must be remembered in session.');
assert_work_page(strpos($timeModel, "'foxdesk_work_team_activity_filter'") !== false, 'team work filter must be remembered in session.');
assert_work_page(strpos($timeModel, "'entries' => \$entries") !== false, 'team time model must return per-agent activity entries.');
assert_work_page(strpos($timeModel, 'function time_activity_period_chart') !== false, 'time activity model must expose selected-period chart data.');
assert_work_page(strpos($timeModel, "'period_chart' => time_activity_period_chart") !== false, 'work model must pass selected-period chart data to the page.');
assert_work_page(strpos($timeModel, 'time_activity_team_summary($period, 80, $team_activity_filter[\'limit\'], $team_activity_period)') !== false, 'work model must pass the team activity filter into team summaries.');
assert_work_page(strpos($timeModel, 'function time_activity_view_scope_from_request') !== false, 'work model must expose remembered personal/team time scope.');
assert_work_page(strpos($timeModel, "foxdesk_work_time_scope") !== false, 'work model must remember the selected personal/team scope.');
assert_work_page(strpos($timeModel, "'display_totals'") !== false, 'work model must expose totals for the selected personal/team scope.');
assert_work_page(strpos($timeModel, 'function time_activity_recent_reports') !== false, 'work model must load recent client reports.');
assert_work_page(strpos($work, 'openai') === false && strpos($work, 'agent-dialog') === false, 'the hosted AI agent is not part of the self-hosted dashboard.');
assert_work_page(strpos($timeModel, "'recent_reports' => \$is_admin_user ? time_activity_recent_reports(3) : []") !== false, 'recent client reports must only be loaded for admins.');
assert_work_page(strpos($work, 'work-scope-switch') !== false, 'admins must be able to switch dashboard time between personal and team scope.');
assert_work_page(strpos($work, 'name="time_scope"') !== false, 'custom period form must preserve the selected personal/team scope.');

echo "Work page contract tests passed\n";
