<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/modules/bootstrap.php';

function assert_inbox($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$definitions = inbox_queue_definitions();
foreach (['triage', 'customer_replies', 'email_imports'] as $key) {
    assert_inbox(isset($definitions[$key]), "Missing inbox queue: {$key}");
}

$agent = ['id' => 7, 'role' => 'agent'];
$triage = inbox_queue_filters('triage', $agent, 14);
assert_inbox(!empty($triage['assignee_unassigned']), 'Triage queue should require empty assignee.');
assert_inbox(($triage['limit'] ?? null) === 14, 'Triage queue should preserve limit.');
assert_inbox(in_array('done', $triage['status_group_not'] ?? [], true), 'Triage queue should exclude done work.');

$customer_replies = inbox_queue_filters('customer_replies', $agent, 12);
assert_inbox(($customer_replies['last_public_comment_role'] ?? '') === 'user', 'Customer replies should require latest public user comment.');
assert_inbox(empty($customer_replies['assignee_unassigned']), 'Customer replies should not require empty assignee.');

$email_imports = inbox_queue_filters('email_imports', $agent, 12);
assert_inbox(($email_imports['source'] ?? '') === 'email', 'Email imports should filter source=email.');
assert_inbox(!empty($email_imports['assignee_unassigned']), 'Email imports should need triage.');

$client = ['id' => 9, 'role' => 'user'];
assert_inbox(inbox_summary($client) === [], 'Client users should not receive team inbox summary.');

echo "Inbox service tests passed\n";
