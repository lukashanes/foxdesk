<?php
$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$header = file_get_contents($root . '/includes/header.php');
$work = file_get_contents($root . '/pages/work.php');
$timeModel = file_get_contents($root . '/includes/modules/work/time-activity-summary.php');

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
assert_work_page(strpos($work, 'work_queue_summary') !== false, 'work page should use the work queue module.');
assert_work_page(strpos($work, 'time_activity_work_model') !== false, 'work page should use exact worked-time summaries.');
assert_work_page(strpos($work, 'data-work-time-overview') !== false, 'work page must expose a stable time overview hook.');
assert_work_page(strpos($work, 'work-range-controls') !== false, 'work page must group preset and custom date range controls together.');
assert_work_page(strpos($work, 'class="work-custom-period"') < strpos($work, 'class="work-time-grid"'), 'custom date range controls must stay above KPI cards.');
assert_work_page(strpos($work, 'data-work-current') !== false, 'work page must expose quick access to current in-progress work.');
assert_work_page(strpos($work, 'data-work-team-time') !== false, 'admin work page must expose a stable team time hook.');
assert_work_page(strpos($work, 'data-work-user-activity') !== false, 'work page must expose a stable user activity hook.');
assert_work_page(strpos($work, 'get_user_all_active_timers') !== false, 'work page should read active timers for current work links.');
assert_work_page(strpos($work, '$work_activity_filter_url') !== false, 'work page must expose independent activity filter links.');
assert_work_page(strpos($work, '$render_work_activity_entries') !== false, 'work page must render linked activity entries.');
assert_work_page(strpos($work, "url('ticket', ['id' => \$ticket_id])") !== false, 'work activity entries must link to the source ticket.');
assert_work_page(strpos($work, 'data-work-log-surface') !== false, 'work page must expose searchable work-log surfaces.');
assert_work_page(strpos($work, 'data-work-log-search') !== false, 'work page must render dynamic search inputs for search mode.');
assert_work_page(strpos($work, "my_activity") !== false, 'my work log must have an independent filter parameter.');
assert_work_page(strpos($work, "team_activity") !== false, 'team time must have an independent filter parameter.');
assert_work_page(strpos($work, "assets/js/work-dashboard.js") !== false, 'work page must load dynamic work dashboard filtering JS.');
assert_work_page(strpos($work, "t('All work')") === false, 'work page must not render the old all-work range.');
assert_work_page(strpos($work, 'workspace_render_queue_page') !== false, 'work page should use the shared workspace queue renderer.');
assert_work_page(strpos($work, "'primary_action' => ''") !== false, 'dashboard queue should not render a duplicate new-ticket action.');
assert_work_page(strpos($work, "url('tickets', ['work_view' => 'waiting']") !== false, 'work page should link waiting queue to the ticket list view.');
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
assert_work_page(strpos($timeModel, 'time_activity_team_summary($period, 80, $team_activity_filter[\'limit\'], $team_activity_period)') !== false, 'work model must pass the team activity filter into team summaries.');

echo "Work page contract tests passed\n";
