<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/modules/bootstrap.php';

function assert_work_queue($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$definitions = work_queue_definitions();
foreach (['mine', 'unassigned', 'overdue', 'waiting', 'done_today'] as $key) {
    assert_work_queue(isset($definitions[$key]), "Missing work queue: {$key}");
}

$agent = ['id' => 7, 'role' => 'agent'];
$mine = work_queue_filters('mine', $agent, 12);
assert_work_queue(($mine['assigned_to'] ?? null) === 7, 'Agent mine queue should use assigned_to.');
assert_work_queue(($mine['limit'] ?? null) === 12, 'Queue limit should be preserved.');
assert_work_queue(in_array('done', $mine['status_group_not'] ?? [], true), 'Mine queue should exclude done work.');

$client = ['id' => 9, 'role' => 'user'];
$client_mine = work_queue_filters('mine', $client, 8);
assert_work_queue(($client_mine['viewer_user_id'] ?? null) === 9, 'Client mine queue should use viewer_user_id.');

$unassigned = work_queue_filters('unassigned', $agent, 8);
assert_work_queue(!empty($unassigned['assignee_unassigned']), 'Unassigned queue should require empty assignee.');

$waiting = work_queue_filters('waiting', $agent, 8);
assert_work_queue(($waiting['status_group'] ?? '') === 'waiting', 'Waiting queue should use waiting status group.');

$done_today = work_queue_filters('done_today', $agent, 8);
assert_work_queue(($done_today['status_group'] ?? '') === 'done', 'Done today queue should use done status group.');
assert_work_queue(!empty($done_today['updated_from']) && !empty($done_today['updated_to']), 'Done today queue should use updated bounds.');

echo "Work queue tests passed\n";
