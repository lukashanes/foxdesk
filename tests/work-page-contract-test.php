<?php
$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$header = file_get_contents($root . '/includes/header.php');
$work = file_get_contents($root . '/pages/work.php');

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
assert_work_page(strpos($work, 'workspace_render_queue_page') !== false, 'work page should use the shared workspace queue renderer.');
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

echo "Work page contract tests passed\n";
