<?php
require dirname(__DIR__) . '/includes/upload-functions.php';
function db_fetch_one($sql, $params = []) {
    if (str_starts_with($sql, 'SHOW TABLES')) return ['table' => 'ticket_message_attachments'];
    if (str_contains($sql, 'WHERE a.filename = ?') && $params === ['secret.png']) return ['id' => 7, 'ticket_id' => 9];
    return null;
}
if (find_attachment_by_relative_path('uploads/public/secret.png') !== null) throw new RuntimeException('Public subdirectory aliases a protected basename.');
if ((find_attachment_by_relative_path('uploads/secret.png')['id'] ?? null) !== 7) throw new RuntimeException('Direct private attachment no longer resolves.');
echo "Attachment exact relative path regression OK\n";
