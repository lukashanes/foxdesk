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

    require_csrf_token(true);

    // Validate ticket permission if ticket_id is provided
    $ticket_id = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;

    if ($ticket_id > 0) {
        $ticket = get_ticket($ticket_id);
        $user = current_user();

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
    }

    if (!isset($_FILES['file'])) {
        api_error('No file uploaded');
    }

    try {
        $result = upload_file($_FILES['file']);
        api_success(['file' => $result]);
    } catch (Exception $e) {
        api_error($e->getMessage());
    }
}


