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
assert_work_page(strpos($work, "url('tickets', ['work_view' => 'waiting']") !== false, 'work page should link waiting queue to the ticket list view.');

echo "Work page contract tests passed\n";
