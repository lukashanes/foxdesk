<?php

define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/database.php';
require_once BASE_PATH . '/includes/upload-functions.php';
require_once BASE_PATH . '/includes/email-ingest-functions.php';

$db = get_db();
$schema = file_get_contents(BASE_PATH . '/includes/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Unable to read schema.sql\n");
    exit(1);
}

$db->exec($schema);

$now = date('Y-m-d H:i:s');
$suffix = (string) time();

$user_id = (int) db_insert('users', [
    'email' => 'docker-test-' . $suffix . '@example.com',
    'password' => password_hash('VeryStrongPassword123!', PASSWORD_DEFAULT),
    'first_name' => 'Docker',
    'last_name' => 'Tester',
    'role' => 'admin',
    'is_active' => 1,
    'created_at' => $now,
]);

$status_id = (int) db_insert('statuses', [
    'name' => 'New ' . $suffix,
    'slug' => 'new-' . $suffix,
    'color' => '#0a84ff',
    'sort_order' => 1,
    'is_default' => 1,
    'is_closed' => 0,
]);

$ticket_id = (int) db_insert('tickets', [
    'hash' => substr(hash('sha256', 'docker-ticket-' . $suffix), 0, 16),
    'title' => 'Docker attachment test',
    'description' => 'Testing inbound attachment storage',
    'type' => 'general',
    'priority_id' => null,
    'user_id' => $user_id,
    'organization_id' => null,
    'status_id' => $status_id,
    'ticket_type_id' => null,
    'source' => 'email',
    'created_at' => $now,
]);

$ticket_message_id = (int) db_insert('ticket_messages', [
    'ticket_id' => $ticket_id,
    'direction' => 'in',
    'user_id' => $user_id,
    'comment_id' => null,
    'sender_email' => 'docker-test@example.com',
    'subject' => 'Inbound attachment test',
    'body_text' => 'Body',
    'body_html' => null,
    'body_html_raw' => null,
    'raw_headers' => "Message-ID: <docker-test@example.com>\r\n",
    'message_id' => '<docker-test-' . $suffix . '@example.com>',
    'in_reply_to' => null,
    'references_header' => null,
    'mailbox' => 'INBOX',
    'uid' => (int) $suffix,
    'created_at' => $now,
]);

$cfg = [
    'max_attachment_size' => 1024 * 1024,
    'deny_extensions' => ['php'],
    'storage_base' => 'storage/tickets',
];

$attachment_payload = [
    'filename' => 'sample.pdf',
    'mime' => 'application/pdf',
    'size' => strlen('sample-pdf-data'),
    'content_id' => null,
    'data' => 'sample-pdf-data',
];

$store = email_ingest_store_attachment(
    $ticket_id,
    $ticket_message_id,
    null,
    $user_id,
    $attachment_payload,
    $cfg
);

if (empty($store['stored'])) {
    fwrite(STDERR, "Attachment was not stored\n");
    exit(2);
}

$attachments = get_ticket_attachments($ticket_id);
if (count($attachments) !== 1) {
    fwrite(STDERR, "Expected 1 attachment, got " . count($attachments) . "\n");
    exit(3);
}

$attachment = $attachments[0];
$absolute_path = attachment_absolute_path($attachment);
$download_url = attachment_download_url($attachment);

if ($absolute_path === '' || !is_file($absolute_path)) {
    fwrite(STDERR, "Attachment file not found on disk\n");
    exit(4);
}

if (file_get_contents($absolute_path) !== 'sample-pdf-data') {
    fwrite(STDERR, "Attachment file contents mismatch\n");
    exit(5);
}

echo json_encode([
    'ticket_id' => $ticket_id,
    'ticket_message_id' => $ticket_message_id,
    'attachment_id' => $attachment['id'] ?? null,
    'filename' => $attachment['filename'] ?? null,
    'storage_path' => $attachment['storage_path'] ?? null,
    'absolute_path' => $absolute_path,
    'download_url' => $download_url,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
