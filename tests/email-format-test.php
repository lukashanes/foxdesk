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

echo "Email formatting tests passed\n";
