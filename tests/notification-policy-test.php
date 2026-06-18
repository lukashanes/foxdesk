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

$admin = ['id' => 10, 'role' => 'admin', 'email' => 'admin@example.com'];
$other_admin = ['id' => 11, 'role' => 'admin', 'email' => 'other-admin@example.com'];
$agent = ['id' => 12, 'role' => 'agent', 'email' => 'agent@example.com'];
$customer = ['id' => 13, 'role' => 'user', 'email' => 'customer@example.com'];
$self_ticket = ['id' => 2, 'title' => 'Self ticket', 'user_id' => 10];
$customer_ticket = ['id' => 3, 'title' => 'Customer ticket', 'user_id' => 13];
$assigned_on_create_ticket = ['id' => 4, 'title' => 'Assigned on create', 'user_id' => 13, 'assignee_id' => 11];

assert_policy(!should_send_new_ticket_admin_email($self_ticket, $admin, $admin), 'New-ticket admin email should skip the ticket creator.');
assert_policy(should_send_new_ticket_admin_email($self_ticket, $other_admin, $admin), 'New-ticket admin email should still notify other admins.');
assert_policy(!should_send_new_ticket_admin_email($assigned_on_create_ticket, $other_admin, $customer), 'New-ticket admin email should skip the assigned agent because assignment email is more actionable.');
assert_policy(should_send_ticket_assignment_email($assigned_on_create_ticket, $other_admin, $customer, ['created_with_ticket' => true]), 'Assigned-on-create agent should still get the assignment email.');
assert_policy(!should_send_ticket_confirmation_email($self_ticket, $admin, $admin), 'Internal staff should not receive customer ticket confirmations.');
assert_policy(should_send_ticket_confirmation_email($customer_ticket, $customer, $admin), 'Customer ticket confirmation should still be sent.');
assert_policy(!should_send_ticket_assignment_email($self_ticket, $admin, $admin), 'Self-assignment should not send an assignment email.');
assert_policy(should_send_ticket_assignment_email($customer_ticket, $agent, $admin), 'Assignment to another agent should still send an email.');

$self_create_plan = ticket_email_action_plan([
    'ticket.created',
    'ticket.created.confirmation',
    'ticket.assigned',
], [
    'requester_is_staff' => true,
    'assignment_is_self' => true,
    'created_by_staff' => true,
    'actor_is_only_staff_recipient' => true,
]);
assert_policy($self_create_plan['email_count'] === 0, 'Creating and assigning a ticket to self should not fan out multiple emails.');
assert_policy(($self_create_plan['suppressed']['ticket.assigned'] ?? '') === 'self_assignment', 'Self-assignment suppression reason should be explicit.');
assert_policy(($self_create_plan['suppressed']['ticket.created.confirmation'] ?? '') === 'internal_requester_confirmation', 'Internal requester confirmation suppression reason should be explicit.');

$status_comment_plan = ticket_email_action_plan([
    'ticket.status_changed',
    'ticket.agent_replied',
], [
    'ticket' => $ticket,
    'actor' => $actor,
    'comment_text' => 'Done with details.',
]);
assert_policy($status_comment_plan['email_count'] === 1, 'Status plus comment should be one actionable email.');
assert_policy($status_comment_plan['email_events'] === ['ticket.agent_replied'], 'Status plus comment should prefer the reply email.');

$internal_note_plan = ticket_email_action_plan(['ticket.internal_note'], ['actor' => $actor]);
assert_policy($internal_note_plan['email_count'] === 0, 'Internal notes should not send customer-facing email.');
assert_policy(($internal_note_plan['suppressed']['ticket.internal_note'] ?? '') === 'internal_note_no_email', 'Internal note suppression reason should be explicit.');

echo "Notification policy tests passed\n";
