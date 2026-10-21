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
        <p>Open <a href="https://example.com/invoice/123">the invoice</a>.</p>
        <table><tr><td>Status</td><td>Open</td></tr></table>
        <p>Regards<br>Support</p>
    </body></html>
';

$converted = email_ingest_html_to_text(email_ingest_sanitize_html($html));
assert_email_format_contains($converted, "Hello Lukas,\n\nwe need these items:");
assert_email_format_contains($converted, "- first task");
assert_email_format_contains($converted, "- second task");
assert_email_format_contains($converted, "Open the invoice (https://example.com/invoice/123).");
assert_email_format_contains($converted, "Status Open");
assert_email_format_contains($converted, "Regards\nSupport");

$selected = email_ingest_select_display_body('Hello Lukas, we need these items: first task second task Status Open Regards Support', email_ingest_sanitize_html($html));
assert_email_format_contains($selected, "Hello Lukas,\n\nwe need these items:");
assert_email_format_contains($selected, "- first task");

$newsletter = '
    <html><body>
        <p style="text-align:right"><a href="https://example.com/view">View in browser</a></p>
        <img src="https://example.com/newsletter/hero.jpg" alt="Podcast guests" onerror="alert(1)">
        <p>Good morning, Lukas,</p>
        <p><strong><em>we have been quiet for a while because we were busy.</em></strong></p>
        <p>A listening tip: <a href="https://example.com/podcast">Marketing in context</a>.</p>
        <script>alert(1)</script>
    </body></html>
';
$prepared = email_ingest_prepare_body_content(
    'Begin forwarded message: From: Eva Subject: Why we are renaming View in browser Marketing in context (https://example.com/podcast)',
    $newsletter
);
assert_email_format_contains($prepared['display'], '<img');
assert_email_format_contains($prepared['display'], '<strong><em>we have been quiet for a while');
assert_email_format_contains($prepared['display'], '<a href="https://example.com/podcast">Marketing in context</a>');
if (strpos($prepared['display'], 'Marketing in context (https://example.com/podcast)') !== false) {
    fwrite(STDERR, "Newsletter display body must keep HTML links instead of expanded plain text:\n{$prepared['display']}\n");
    exit(1);
}
if (stripos($prepared['display'], '<script') !== false || stripos($prepared['display'], 'onerror') !== false) {
    fwrite(STDERR, "Newsletter display body must not keep scripts or event handlers:\n{$prepared['display']}\n");
    exit(1);
}
assert_email_format_contains($prepared['text'], 'Good morning, Lukas,');

$plain_only = email_ingest_prepare_body_content("Hi team,\n\nthe printer is offline again.\n\nThanks", '<p>Hi team,</p><p>the printer is offline again.</p><p>Thanks</p>');
if (strpos($plain_only['display'], '<p>') !== false) {
    fwrite(STDERR, "Simple paragraph-only mail should stay plain text:\n{$plain_only['display']}\n");
    exit(1);
}
assert_email_format_contains($plain_only['display'], "the printer is offline again.");
$empty = email_ingest_prepare_body_content('', '');
assert_email_format_contains($empty['display'], '(No content)');

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
