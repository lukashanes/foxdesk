<?php
/**
 * Image Proxy - serves uploaded images through PHP.
 *
 * Public uploaded assets such as avatars and logos remain cacheable. Ticket
 * attachments stored in uploads/ require the same authorization as attachment.php.
 */

defined('BASE_PATH') || define('BASE_PATH', __DIR__);
defined('SESSION_LIFETIME') || define('SESSION_LIFETIME', 2592000);
defined('REMEMBER_ME_DURATION') || define('REMEMBER_ME_DURATION', 30 * 86400);

$raw = $_GET['f'] ?? '';
$file = basename(explode('?', (string)$raw)[0]);

if ($file === '') {
    http_response_code(400);
    exit;
}

$path = BASE_PATH . '/uploads/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$attachment = null;
$is_protected_attachment = false;
$upload_dir = 'uploads';

if (file_exists(BASE_PATH . '/config.php')) {
    require_once BASE_PATH . '/config.php';
    $upload_dir = trim((defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/'), '/\\');

    if (file_exists(BASE_PATH . '/includes/session-bootstrap.php')) {
        require_once BASE_PATH . '/includes/session-bootstrap.php';
    }
    if (file_exists(BASE_PATH . '/includes/database.php')) {
        require_once BASE_PATH . '/includes/database.php';
    }
    if (function_exists('foxdesk_bootstrap_session')) {
        foxdesk_bootstrap_session();
    }
    if (file_exists(BASE_PATH . '/includes/functions.php')) {
        require_once BASE_PATH . '/includes/functions.php';
    }
    if (file_exists(BASE_PATH . '/includes/auth.php')) {
        require_once BASE_PATH . '/includes/auth.php';
    }
    if (file_exists(BASE_PATH . '/includes/ticket-functions.php')) {
        require_once BASE_PATH . '/includes/ticket-functions.php';
    }

    if (function_exists('find_attachment_by_relative_path')) {
        $attachment = find_attachment_by_relative_path($upload_dir . '/' . $file);
        $is_protected_attachment = !empty($attachment);
    }
}

if ($is_protected_attachment) {
    $authorized = false;
    $share_token = trim((string)($_GET['share_token'] ?? ($_GET['token'] ?? '')));

    if ($share_token !== '' && function_exists('attachment_share_token_can_access')) {
        $authorized = attachment_share_token_can_access($attachment, $share_token);
    }

    if (!$authorized && function_exists('is_logged_in') && !is_logged_in() && !empty($_COOKIE['foxdesk_remember']) && function_exists('validate_remember_token')) {
        validate_remember_token();
    }

    if (!$authorized && function_exists('attachment_user_can_access')) {
        $authorized = attachment_user_can_access($attachment);
    }

    if (!$authorized) {
        http_response_code(403);
        exit;
    }
}

$allowed = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
];

$mime = function_exists('mime_content_type') ? mime_content_type($path) : false;
if (!is_string($mime) || !in_array($mime, $allowed, true)) {
    http_response_code(403);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: ' . ($is_protected_attachment ? 'private, max-age=3600' : 'public, max-age=31536000, immutable'));
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
