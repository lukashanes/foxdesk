<?php
define('BASE_PATH', dirname(__DIR__));

function assert_contract($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$mailer = file_get_contents(BASE_PATH . '/includes/mailer.php');
$ticket_handler = file_get_contents(BASE_PATH . '/includes/components/ticket-form-handlers.php');
$notification_functions = file_get_contents(BASE_PATH . '/includes/notification-functions.php');
$ticket_events = file_get_contents(BASE_PATH . '/includes/modules/tickets/ticket-events.php');
$notification_policy = file_get_contents(BASE_PATH . '/includes/modules/notifications/notification-policy.php');
$pseudo_cron = file_get_contents(BASE_PATH . '/includes/pseudo-cron.php');
$cron = file_get_contents(BASE_PATH . '/pages/cron.php');

assert_contract(strpos($mailer, 'function send_ticket_notification_email') !== false, 'Ticket notification email renderer wrapper is missing.');
assert_contract(strpos($mailer, 'foxdesk_render_ticket_email_html') !== false, 'Ticket notifications should use the shared HTML renderer when available.');
assert_contract(strpos($mailer, 'should_send_new_ticket_admin_email') !== false, 'New-ticket admin emails must use notification policy suppression.');
assert_contract(strpos($mailer, 'should_send_ticket_confirmation_email') !== false, 'Ticket confirmations must use notification policy suppression.');
assert_contract(strpos($mailer, 'should_send_ticket_assignment_email') !== false, 'Assignment emails must use notification policy suppression.');
assert_contract(strpos($mailer, "'eyebrow' => 'Ticket received'") !== false, 'Ticket confirmation should use the shared ticket email renderer payload.');
assert_contract(strpos($mailer, 'send_email($user[\'email\'], $subject, $body)') === false, 'Ticket confirmation must not use the legacy plain-text send_email path.');
assert_contract(strpos($ticket_events, 'function ticket_event_dispatch_in_app') !== false, 'Ticket events must expose a shared in-app dispatch helper.');
assert_contract(strpos($ticket_events, 'function ticket_event_comment_name') !== false, 'Ticket events must classify public replies versus internal notes.');
assert_contract(strpos($notification_policy, 'function ticket_email_action_plan') !== false, 'Notification policy must expose a one-action-one-email planner.');
assert_contract(strpos($notification_policy, 'status_change_covered_by_reply') !== false, 'Status plus public comment must prefer one reply email.');
assert_contract(strpos($notification_policy, 'self_assignment') !== false, 'Self-assignment must be explicitly suppressible.');
assert_contract(strpos($ticket_handler, '$will_send_public_comment_notification') !== false, 'Ticket form should detect public comment notifications before status dispatch.');
assert_contract(strpos($ticket_handler, '!$will_send_public_comment_notification') !== false, 'Status notifications should be suppressed when the same submit sends a public comment.');
assert_contract(strpos($ticket_handler, 'ticket_event_dispatch_in_app') !== false, 'Ticket form should route in-app notifications through the shared ticket event helper.');
assert_contract(strpos($notification_functions, '$event_type)') !== false, 'Notification dispatcher must be readable by this contract.');
assert_contract(strpos($notification_functions, "case 'new_ticket':") !== false, 'Notification dispatcher must handle new-ticket events.');
assert_contract(strpos($notification_functions, 'array_filter($recipients, static fn($id) => (int) $id !== $assignee_id)') !== false, 'New-ticket in-app notifications must skip the assigned agent when an assignment notification will follow.');
assert_contract(strpos($pseudo_cron, 'pseudo_cron_schedule_inline_email_ingest') !== false, 'Inline email ingest fallback is missing.');
assert_contract(strpos($pseudo_cron, 'register_shutdown_function') !== false, 'Inline email ingest should run after page response shutdown.');
assert_contract(strpos($cron, '!empty($cfg[\'enabled\'])') !== false, 'Cron endpoint must respect disabled IMAP setting.');

echo "Email notification contract tests passed\n";
