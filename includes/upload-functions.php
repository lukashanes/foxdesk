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
    $storage_select = '';
    $storage_join = '';
    if (table_exists('ticket_message_attachments')) {
        $storage_select = ', tma.storage_path';
        $storage_join = ' LEFT JOIN ticket_message_attachments tma ON tma.attachment_id = a.id';
    }

    return db_fetch_all("SELECT a.*, u.first_name, u.last_name{$storage_select}
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
    $attachment = null;
    if (is_numeric($filename)) {
        $attachment = get_attachment((int) $filename);
    }

    if (!$attachment) {
        $attachment = [
            'filename' => (string) $filename,
        ];
    }

    $filepath = resolve_attachment_file_path($attachment);
    if ($filepath && file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/**
 * Get attachment by ID
 */
function get_attachment($id) {
    $storage_select = '';
    $storage_join = '';
    if (table_exists('ticket_message_attachments')) {
        $storage_select = ', tma.storage_path';
        $storage_join = ' LEFT JOIN ticket_message_attachments tma ON tma.attachment_id = a.id';
    }

    return db_fetch_one("SELECT a.*{$storage_select}
                         FROM attachments a
                         {$storage_join}
                         WHERE a.id = ?", [$id]);
}

/**
 * Get attachment metadata by stored filename.
 */
function get_attachment_by_filename($filename) {
    $filename = basename((string) $filename);
    if ($filename === '') {
        return null;
    }

    $storage_select = '';
    $storage_join = '';
    if (table_exists('ticket_message_attachments')) {
        $storage_select = ', tma.storage_path';
        $storage_join = ' LEFT JOIN ticket_message_attachments tma ON tma.attachment_id = a.id';
    }

    return db_fetch_one("SELECT a.*{$storage_select}
                         FROM attachments a
                         {$storage_join}
                         WHERE a.filename = ?
                         ORDER BY a.id DESC
                         LIMIT 1", [$filename]);
}

/**
 * URL for ticket attachments. Uses attachment.php so files are authorization-checked
 * and non-image attachments can be downloaded.
 */
function attachment_url($attachment, $token = '', $download = false) {
    $params = [];
    if (is_array($attachment) && !empty($attachment['id'])) {
        $params['id'] = (int) $attachment['id'];
    } elseif (is_array($attachment) && !empty($attachment['filename'])) {
        $params['f'] = basename((string) $attachment['filename']);
    } else {
        $params['f'] = basename((string) $attachment);
    }

    if ($token !== '') {
        $params['token'] = $token;
    }
    if ($download) {
        $params['download'] = '1';
    }

    return 'attachment.php?' . http_build_query($params);
}

/**
 * Resolve an attachment row to a safe local file path.
 */
function resolve_attachment_file_path($attachment) {
    $candidates = [];

    if (!empty($attachment['storage_path'])) {
        $candidates[] = (string) $attachment['storage_path'];
    }

    if (!empty($attachment['filename'])) {
        $filename = (string) $attachment['filename'];
        if (str_contains($filename, '/') || str_contains($filename, '\\')) {
            $candidates[] = $filename;
        }
        $upload_dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/';
        $candidates[] = rtrim($upload_dir, '/\\') . '/' . basename($filename);
    }

    $allowed_roots = [
        BASE_PATH . DIRECTORY_SEPARATOR . 'uploads',
        BASE_PATH . DIRECTORY_SEPARATOR . 'storage',
    ];

    foreach ($candidates as $candidate) {
        $path = $candidate;
        if (!preg_match('~^[A-Za-z]:[\\\\/]~', $path) && !str_starts_with($path, '/') && !str_starts_with($path, '\\')) {
            $path = BASE_PATH . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
        }

        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            continue;
        }

        foreach ($allowed_roots as $root) {
            $real_root = realpath($root);
            if ($real_root === false) {
                continue;
            }
            $normalized_real = rtrim(str_replace('\\', '/', $real), '/') . '/';
            $normalized_root = rtrim(str_replace('\\', '/', $real_root), '/') . '/';
            if (str_starts_with($normalized_real, $normalized_root)) {
                return $real;
            }
        }
    }

    return null;
}

/**
 * Check whether the current request may read an attachment.
 */
function can_access_attachment($attachment, $user = null, $token = '') {
    if (empty($attachment) || empty($attachment['ticket_id'])) {
        return false;
    }

    if ($token !== '') {
        $share = get_ticket_share_by_token($token);
        if ($share && is_ticket_share_active($share) && (int) $share['ticket_id'] === (int) $attachment['ticket_id']) {
            if (!empty($attachment['comment_id'])) {
                $comment = db_fetch_one("SELECT is_internal FROM comments WHERE id = ?", [(int) $attachment['comment_id']]);
                if ($comment && !empty($comment['is_internal'])) {
                    return false;
                }
            }
            return true;
        }
    }

    if ($user === null) {
        $user = current_user();
    }
    if (!$user) {
        return false;
    }

    $ticket = get_ticket((int) $attachment['ticket_id']);
    return $ticket && can_see_ticket($ticket, $user);
}



