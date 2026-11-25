<?php
// Run the actual editor upload handler without uploading or writing a real file.
require dirname(__DIR__) . '/includes/api/upload-handler.php';
$attachments = [];
$visibility = null;
function require_csrf_token(...$args) {}
function current_user() { return ['id' => 10]; }
function get_ticket($id) { return ['id' => $id, 'user_id' => 10]; }
function can_see_ticket($ticket, $user) { return $ticket['id'] === 1; }
function upload_file($file, $types, $unused, $visibility) {
    $GLOBALS['visibility'] = $visibility;
    return ['filename' => ($visibility === 'public' ? 'public/' : '') . 'test.png',
        'original_name' => 'test.png', 'mime_type' => 'image/png', 'file_size' => 100];
}
function db_insert($table, $row) { $GLOBALS['attachments'][] = [$table, $row]; return 20; }
class InlineUploadResponse extends Error {
    public array $data;
    function __construct(array $data) { $this->data = $data; }
}
function api_success($data) { throw new InlineUploadResponse($data); }
function api_error($message, $status = 400) { throw new Error($message, $status); }
$_SERVER['REQUEST_METHOD'] = 'POST';
$_FILES = ['file' => ['name' => 'test.png']];
$_POST = ['ticket_id' => '1'];
try { api_upload(); } catch (InlineUploadResponse $response) {}
if ($visibility !== 'private' || count($attachments) !== 1 || $attachments[0][0] !== 'attachments'
    || $attachments[0][1]['ticket_id'] !== 1 || $attachments[0][1]['uploaded_by'] !== 10
    || ($response->data['file']['attachment_id'] ?? 0) !== 20) {
    throw new RuntimeException('New comment image lacks private ticket ownership metadata.');
}
$_POST = ['purpose' => 'editor-image'];
try { api_upload(); } catch (InlineUploadResponse $response) {}
if ($visibility !== 'public' || count($attachments) !== 1 || $response->data['file']['filename'] !== 'public/test.png') {
    throw new RuntimeException('Pre-ticket editor upload no longer uses the explicit public namespace.');
}
echo "Editor images with and without a ticket retain explicit ownership/public metadata OK\n";
