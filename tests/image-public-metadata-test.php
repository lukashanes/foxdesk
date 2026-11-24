<?php
$scenario = 'forged_comment';
$queries = [];
function db_fetch_one($sql, $params = []) {
    $GLOBALS['queries'][] = $sql;
    if ($GLOBALS['scenario'] === 'db_error') { throw new RuntimeException('Offline metadata failure'); }
    if ($GLOBALS['scenario'] === 'foreign_attachment' && str_contains($sql, 'FROM attachments')) { return ['id' => 9]; }
    if ($GLOBALS['scenario'] === 'email_attachment' && str_contains($sql, 'FROM ticket_message_attachments')) { return ['id' => 10]; }
    if ($GLOBALS['scenario'] === 'legacy_logo' && str_contains($sql, 'FROM settings')) { return ['id' => 11]; }
    if (str_contains($sql, 'FROM comments') || str_contains($sql, 'FROM tickets')) { return ['id' => 12]; }
    return null;
}
// Load the proxy helpers without running the HTTP/file-serving entry point.
$source = file_get_contents(dirname(__DIR__) . '/image.php');
eval(substr($source, strpos($source, 'function image_proxy_is_public_upload')));
foreach (['forged_comment', 'foreign_attachment', 'email_attachment', 'db_error'] as $scenario) {
    if (image_proxy_is_public_upload('uploads/secret.png', 'secret.png')) { throw new RuntimeException($scenario . ' exposed a protected image.'); }
}
$scenario = 'foreign_attachment';
if (image_proxy_is_public_upload('uploads/public/secret.png', 'public/secret.png')) { throw new RuntimeException('Public prefix cannot override attachment metadata.'); }
$scenario = 'public_asset';
if (!image_proxy_is_public_upload('uploads/public/logo.png', 'public/logo.png')) { throw new RuntimeException('Explicit public asset must remain visible.'); }
$scenario = 'legacy_logo';
if (!image_proxy_is_public_upload('uploads/logo.png', 'logo.png')) { throw new RuntimeException('Legacy logo metadata must remain supported.'); }
foreach ($queries as $query) {
    if (str_contains($query, 'FROM comments') || str_contains($query, 'FROM tickets')) { throw new RuntimeException('User-authored content cannot grant public access.'); }
}
echo "Image public metadata regression OK\n";
