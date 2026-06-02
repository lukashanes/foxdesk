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
$pseudo_cron = file_get_contents(BASE_PATH . '/includes/pseudo-cron.php');
$cron = file_get_contents(BASE_PATH . '/pages/cron.php');

assert_contract(strpos($mailer, 'function send_ticket_notification_email') !== false, 'Ticket notification email renderer wrapper is missing.');
assert_contract(strpos($mailer, 'foxdesk_render_ticket_email_html') !== false, 'Ticket notifications should use the shared HTML renderer when available.');
assert_contract(strpos($ticket_handler, '$will_send_public_comment_notification') !== false, 'Ticket form should detect public comment notifications before status dispatch.');
assert_contract(strpos($ticket_handler, '!$will_send_public_comment_notification') !== false, 'Status notifications should be suppressed when the same submit sends a public comment.');
assert_contract(strpos($pseudo_cron, 'pseudo_cron_schedule_inline_email_ingest') !== false, 'Inline email ingest fallback is missing.');
assert_contract(strpos($pseudo_cron, 'register_shutdown_function') !== false, 'Inline email ingest should run after page response shutdown.');
assert_contract(strpos($cron, '!empty($cfg[\'enabled\'])') !== false, 'Cron endpoint must respect disabled IMAP setting.');

echo "Email notification contract tests passed\n";
