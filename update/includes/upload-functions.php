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
    return db_fetch_all("SELECT a.*, u.first_name, u.last_name
                         FROM attachments a
                         LEFT JOIN users u ON a.uploaded_by = u.id
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



