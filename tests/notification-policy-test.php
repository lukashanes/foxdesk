<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/modules/bootstrap.php';

function assert_policy($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$ticket = ['id' => 1, 'title' => 'Example ticket'];
$actor = ['id' => 2, 'role' => 'agent'];
$open = ['name' => 'Open', 'is_closed' => 0];
$active = ['name' => 'In Progress', 'is_closed' => 0];
$waiting = ['name' => 'Waiting for Customer', 'is_closed' => 0];
$done = ['name' => 'Closed', 'is_closed' => 1];

assert_policy(ticket_status_group_from_status($open) === 'new', 'Open should map to new.');
assert_policy(ticket_status_group_from_status($active) === 'active', 'In Progress should map to active.');
assert_policy(ticket_status_group_from_status($waiting) === 'waiting', 'Waiting status should map to waiting.');
assert_policy(ticket_status_group_from_status($done) === 'done', 'Closed status should map to done.');

assert_policy(!should_send_ticket_email('ticket.status_changed', $ticket, $actor, [
    'old_status' => $open,
    'new_status' => $active,
]), 'Open -> active without content should not email.');

assert_policy(should_send_ticket_email('ticket.status_changed', $ticket, $actor, [
    'old_status' => $active,
    'new_status' => $waiting,
]), 'Active -> waiting should email.');

assert_policy(should_send_ticket_email('ticket.status_changed', $ticket, $actor, [
    'old_status' => $active,
    'new_status' => $done,
]), 'Active -> done should email.');

assert_policy(should_send_ticket_email('ticket.status_changed', $ticket, $actor, [
    'old_status' => $open,
    'new_status' => $active,
    'comment_text' => 'Real customer-facing update.',
]), 'Status change with comment should email.');

assert_policy(!should_send_ticket_email('ticket.updated', $ticket, $actor, [
    'field' => 'priority',
]), 'Priority update should not email.');

assert_policy(should_send_ticket_email('ticket.assigned', $ticket, $actor), 'Assignment should email.');

echo "Notification policy tests passed\n";
