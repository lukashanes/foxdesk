<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/email-ingest-functions.php';

function assert_email_format_contains($haystack, $needle)
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "Missing expected fragment: {$needle}\n\nActual:\n{$haystack}\n");
        exit(1);
    }
}

$html = '
    <html><body>
        <p>Hello Lukas,</p>
        <p>we need these items:</p>
        <ul><li>first task</li><li>second task</li></ul>
        <table><tr><td>Status</td><td>Open</td></tr></table>
        <p>Regards<br>Support</p>
    </body></html>
';

$converted = email_ingest_html_to_text(email_ingest_sanitize_html($html));
assert_email_format_contains($converted, "Hello Lukas,\n\nwe need these items:");
assert_email_format_contains($converted, "- first task");
assert_email_format_contains($converted, "- second task");
assert_email_format_contains($converted, "Status Open");
assert_email_format_contains($converted, "Regards\nSupport");

$selected = email_ingest_select_display_body('Hello Lukas, we need these items: first task second task Status Open Regards Support', email_ingest_sanitize_html($html));
assert_email_format_contains($selected, "Hello Lukas,\n\nwe need these items:");
assert_email_format_contains($selected, "- first task");

$reply = "Thanks, this works now.\n\nOn Mon, Jun 1, 2026 at 10:00 AM Support wrote:\n> Old message\n> Old footer";
$clean_reply = email_ingest_cleanup_display_body($reply);
assert_email_format_contains($clean_reply, 'Thanks, this works now.');
if (strpos($clean_reply, 'Old message') !== false) {
    fwrite(STDERR, "Quoted reply was not stripped:\n{$clean_reply}\n");
    exit(1);
}

$outlook_reply = "Please check the invoice total.\n\nFrom: Support <support@example.com>\nSent: Monday, June 1, 2026 10:00\nTo: Client <client@example.com>\nSubject: Re: Invoice\n\nOld thread text";
$clean_outlook = email_ingest_cleanup_display_body($outlook_reply);
assert_email_format_contains($clean_outlook, 'Please check the invoice total.');
if (strpos($clean_outlook, 'Old thread text') !== false) {
    fwrite(STDERR, "Outlook quoted header block was not stripped:\n{$clean_outlook}\n");
    exit(1);
}

$signature = "Message body\n\n-- \nSent from my iPhone";
$clean_signature = email_ingest_cleanup_display_body($signature);
if ($clean_signature !== 'Message body') {
    fwrite(STDERR, "Signature was not stripped:\n{$clean_signature}\n");
    exit(1);
}

echo "Email formatting tests passed\n";
