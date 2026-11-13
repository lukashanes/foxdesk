<?php
/**
 * File Upload Functions
 *
 * Functions for handling file uploads and attachments.
 */

/**
 * Get ticket attachments
 */
function get_ticket_attachments($ticket_id) {
    static $has_ticket_message_attachments = null;
    if ($has_ticket_message_attachments === null) {
        try {
            $has_ticket_message_attachments = (bool) db_fetch_one("SHOW TABLES LIKE 'ticket_message_attachments'");
        } catch (Throwable $e) {
            $has_ticket_message_attachments = false;
        }
    }

    $storage_select = "NULL AS storage_path";
    $storage_join = "";

    if ($has_ticket_message_attachments) {
        $storage_select = "tma.storage_path";
        $storage_join = "LEFT JOIN (
                             SELECT attachment_id, MAX(storage_path) AS storage_path
                             FROM ticket_message_attachments
                             WHERE attachment_id IS NOT NULL
                             GROUP BY attachment_id
                         ) tma ON tma.attachment_id = a.id";
    }

    return db_fetch_all("SELECT a.*, u.first_name, u.last_name, {$storage_select}
                         FROM attachments a
                         LEFT JOIN users u ON a.uploaded_by = u.id
                         {$storage_join}
                         WHERE a.ticket_id = ?
                         ORDER BY a.created_at ASC", [$ticket_id]);
}

/**
 * Get max upload size from settings (in bytes)
 */
function get_max_upload_size() {
    // Get from setting, default to 10MB
    $size_mb = (int)get_setting('max_upload_size', '10');
    // Ensure reasonable limits (1MB - 100MB)
    $size_mb = max(1, min(100, $size_mb));
    return $size_mb * 1024 * 1024;
}

/**
 * Get max upload size in MB for display
 */
function get_max_upload_size_mb() {
    return (int)get_setting('max_upload_size', '10');
}

/**
 * Handle file upload
 */
function upload_file($file, $allowed_types = null, $max_size = null) {
    if ($allowed_types === null) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp',
                          'application/pdf', 'application/msword',
                          'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                          'application/vnd.ms-excel',
                          'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                          'text/plain', 'application/zip', 'application/x-rar-compressed'];
    }

    if ($max_size === null) {
        $max_size = get_max_upload_size();
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception(t('File upload failed.'));
    }

    if ($file['size'] > $max_size) {
        throw new Exception(t('File is too large. Maximum size is {size}.', [
            'size' => format_file_size($max_size)
        ]));
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        throw new Exception(t('This file type is not allowed.'));
    }

    $upload_dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/';
    $upload_path = BASE_PATH . '/' . $upload_dir;

    if (!is_dir($upload_path)) {
        mkdir($upload_path, 0755, true);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Block dangerous file extensions regardless of MIME type
    $blocked_extensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'shtml', 'cgi', 'pl', 'py', 'rb', 'sh', 'bat', 'cmd', 'exe', 'com', 'msi', 'jsp', 'asp', 'aspx', 'htaccess'];
    if (in_array($ext, $blocked_extensions, true)) {
        throw new Exception(t('This file type is not allowed.'));
    }

    $filename = uniqid() . '_' . time() . '.' . $ext;
    $filepath = $upload_path . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception(t('Failed to save the file.'));
    }

    return [
        'filename' => $filename,
        'original_name' => $file['name'],
        'mime_type' => $mime_type,
        'file_size' => $file['size']
    ];
}

/**
 * Delete an attachment file
 */
function delete_attachment_file($filename) {
    $upload_dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/';
    $filepath = BASE_PATH . '/' . $upload_dir . $filename;

    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/**
 * Get attachment by ID
 */
function get_attachment($id) {
    return db_fetch_one("SELECT * FROM attachments WHERE id = ?", [$id]);
}

/**
 * Resolve an attachment row from its relative storage path.
 */
function find_attachment_by_relative_path($relative_path)
{
    static $has_ticket_message_attachments = null;

    $relative_path = ltrim(str_replace('\\', '/', trim((string) $relative_path)), '/');
    if ($relative_path === '') {
        return null;
    }

    if ($has_ticket_message_attachments === null) {
        try {
            $has_ticket_message_attachments = (bool) db_fetch_one("SHOW TABLES LIKE 'ticket_message_attachments'");
        } catch (Throwable $e) {
            $has_ticket_message_attachments = false;
        }
    }

    $upload_dir = trim((defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/'), '/\\');
    if (
        $upload_dir !== ''
        && ($relative_path === $upload_dir || str_starts_with($relative_path, $upload_dir . '/'))
    ) {
        $filename = basename($relative_path);
        return db_fetch_one(
            "SELECT a.*, c.is_internal AS comment_is_internal
             FROM attachments a
             LEFT JOIN comments c ON c.id = a.comment_id
             WHERE a.filename = ?
             LIMIT 1",
            [$filename]
        );
    }

    if (!$has_ticket_message_attachments) {
        return null;
    }

    return db_fetch_one(
        "SELECT a.*, c.is_internal AS comment_is_internal, tma.storage_path
         FROM ticket_message_attachments tma
         LEFT JOIN attachments a ON a.id = tma.attachment_id
         LEFT JOIN comments c ON c.id = a.comment_id
         WHERE tma.storage_path = ?
         ORDER BY tma.id DESC
         LIMIT 1",
        [$relative_path]
    );
}

/**
 * Check whether the current logged-in user can access an attachment.
 */
function attachment_user_can_access($attachment, $user = null)
{
    if (empty($attachment['ticket_id'])) {
        return false;
    }

    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return false;
    }

    $ticket = get_ticket((int) $attachment['ticket_id']);
    if (!$ticket || !can_see_ticket($ticket, $user)) {
        return false;
    }

    if (!empty($attachment['comment_id']) && !empty($attachment['comment_is_internal']) && !is_agent()) {
        return false;
    }

    return true;
}

/**
 * Check whether a public share token can access an attachment.
 */
function attachment_share_token_can_access($attachment, $share_token)
{
    if (empty($attachment['ticket_id']) || trim((string) $share_token) === '') {
        return false;
    }

    $share = get_ticket_share_by_token(trim((string) $share_token));
    if (!$share || !is_ticket_share_active($share)) {
        return false;
    }

    if ((int) $share['ticket_id'] !== (int) $attachment['ticket_id']) {
        return false;
    }

    if (!empty($attachment['comment_id']) && !empty($attachment['comment_is_internal'])) {
        return false;
    }

    return true;
}

/**
 * Resolve attachment storage path relative to BASE_PATH.
 */
function attachment_storage_relative_path($attachment) {
    $storage_path = trim((string)($attachment['storage_path'] ?? ''));
    if ($storage_path !== '') {
        return ltrim(str_replace('\\', '/', $storage_path), '/');
    }

    $filename = basename((string)($attachment['filename'] ?? ''));
    if ($filename === '') {
        return '';
    }

    $upload_dir = trim((defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/'), '/\\');
    return $upload_dir . '/' . $filename;
}

/**
 * Resolve absolute attachment file path on disk.
 */
function attachment_absolute_path($attachment) {
    $relative_path = attachment_storage_relative_path($attachment);
    if ($relative_path === '') {
        return '';
    }

    return BASE_PATH . '/' . $relative_path;
}

/**
 * Generate URL for serving an attachment file.
 */
function attachment_download_url($attachment, $share_token = null) {
    $relative_path = attachment_storage_relative_path($attachment);
    if ($relative_path === '') {
        return '';
    }

    $url = 'attachment.php?f=' . rawurlencode($relative_path);
    if (!empty($share_token)) {
        $url .= '&share_token=' . rawurlencode((string) $share_token);
    }

    return $url;
}
