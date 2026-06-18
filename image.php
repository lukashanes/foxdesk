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

$raw = (string) ($_GET['f'] ?? '');
$requested = trim(str_replace('\\', '/', explode('?', $raw)[0]));
$requested = ltrim($requested, '/');

if ($requested === '' || str_contains($requested, '..') || !preg_match('/^[A-Za-z0-9._\/-]+$/', $requested)) {
    http_response_code(400);
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
}

if ($upload_dir !== '' && str_starts_with($requested, $upload_dir . '/')) {
    $requested = substr($requested, strlen($upload_dir) + 1);
}

$path = BASE_PATH . '/' . $upload_dir . '/' . $requested;
$upload_root = realpath(BASE_PATH . '/' . $upload_dir);
$real_path = realpath($path);

if ($upload_root === false || $real_path === false || !str_starts_with($real_path, rtrim($upload_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || !is_file($real_path)) {
    http_response_code(404);
    exit;
}

if (function_exists('find_attachment_by_relative_path')) {
    $attachment = find_attachment_by_relative_path($upload_dir . '/' . $requested);
    $is_protected_attachment = !empty($attachment);
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

if (!$is_protected_attachment && !image_proxy_is_public_upload($upload_dir . '/' . $requested, $requested)) {
    http_response_code(403);
    exit;
}

$allowed = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
];

$mime = function_exists('mime_content_type') ? mime_content_type($real_path) : false;
if (!is_string($mime) || !in_array($mime, $allowed, true)) {
    http_response_code(403);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real_path));
header('Cache-Control: ' . ($is_protected_attachment ? 'private, max-age=3600' : 'public, max-age=31536000, immutable'));
header('X-Content-Type-Options: nosniff');

readfile($real_path);
exit;

function image_proxy_is_public_upload(string $stored_path, string $relative_path): bool
{
    if (str_starts_with($relative_path, 'public/')) {
        return true;
    }

    if (!function_exists('db_fetch_one')) {
        return false;
    }

    $stored_path = ltrim(str_replace('\\', '/', explode('?', $stored_path)[0]), '/');
    $like = $stored_path . '?%';

    try {
        if (db_fetch_one("SELECT id FROM users WHERE avatar = ? OR avatar LIKE ? LIMIT 1", [$stored_path, $like])) {
            return true;
        }
    } catch (Throwable $e) {
    }

    try {
        if (db_fetch_one("SELECT id FROM organizations WHERE logo = ? OR logo LIKE ? LIMIT 1", [$stored_path, $like])) {
            return true;
        }
    } catch (Throwable $e) {
    }

    try {
        if (db_fetch_one(
            "SELECT id FROM settings WHERE setting_key IN ('app_logo', 'favicon', 'report_company_logo') AND (setting_value = ? OR setting_value LIKE ?) LIMIT 1",
            [$stored_path, $like]
        )) {
            return true;
        }
    } catch (Throwable $e) {
    }

    $encoded = rawurlencode($relative_path);
    $legacy_patterns = [
        '%image.php?f=' . $encoded . '%',
        '%image.php?f=' . basename($relative_path) . '%',
    ];

    try {
        if (db_fetch_one("SELECT id FROM comments WHERE content LIKE ? OR content LIKE ? LIMIT 1", $legacy_patterns)) {
            return true;
        }
    } catch (Throwable $e) {
    }

    try {
        return (bool) db_fetch_one("SELECT id FROM tickets WHERE description LIKE ? OR description LIKE ? LIMIT 1", $legacy_patterns);
    } catch (Throwable $e) {
        return false;
    }
}
