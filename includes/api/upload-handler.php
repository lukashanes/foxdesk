<?php
/**
 * API Handler: File Upload
 *
 * Handles file upload operations with permission validation.
 */

/**
 * Handle file upload
 *
 * Security: Validates user has permission to access the target ticket
 * before allowing file upload.
 */
function api_upload() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }

    if (empty($GLOBALS['is_api_token_auth'])) {
        require_csrf_token(true);
    }
    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    // Validate ticket permission if ticket_id is provided
    $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
    $allowed_types = null;

    if ($ticket_id > 0) {
        $ticket = get_ticket($ticket_id);

        if (!$ticket) {
            api_error('Ticket not found', 404);
        }

        if (!can_see_ticket($ticket, $user)) {
            // Log security event for audit trail
            if (function_exists('log_security_event')) {
                log_security_event('upload_permission_denied', $user['id'] ?? null, json_encode([
                    'ticket_id' => $ticket_id,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
                ]));
            }
            api_error('You do not have permission to upload files to this ticket', 403);
        }
    } else {
        $purpose = trim((string) ($_POST['purpose'] ?? ''));
        if ($purpose !== 'editor-image') {
            api_error('Ticket ID is required for attachment uploads.', 400);
        }
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    }

    if (!isset($_FILES['file'])) {
        api_error('No file uploaded');
    }

    try {
        $visibility = $ticket_id > 0 ? 'private' : 'public';
        $result = upload_file($_FILES['file'], $allowed_types, null, $visibility);
        $result['url'] = 'image.php?f=' . rawurlencode((string) $result['filename']);

        // Inline images on a ticket are stored as ticket attachments, so the
        // protected image proxy can authorize them like any other attachment.
        if ($ticket_id > 0) {
            $attachment_row = [
                'ticket_id' => $ticket_id,
                'filename' => $result['filename'],
                'original_name' => $result['original_name'],
                'mime_type' => $result['mime_type'],
                'file_size' => $result['file_size'],
                'uploaded_by' => $user['id'],
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $attachment_id = db_insert('attachments', $attachment_row);
            $result['attachment_id'] = (int) $attachment_id;
            $attachment_row['id'] = (int) $attachment_id;
            if (function_exists('attachment_download_url')) {
                $result['url'] = attachment_download_url($attachment_row);
            }
        }

        api_success(['file' => $result]);
    } catch (Exception $e) {
        api_error($e->getMessage());
    }
}
