<?php
/**
 * Image proxy for uploaded files.
 *
 * Ticket attachment images require ticket access. Non-attachment images
 * such as app logos and avatars remain public for login and shared pages.
 */

define('BASE_PATH', __DIR__);

$raw = $_GET['f'] ?? '';
$file = basename(explode('?', (string) $raw)[0]);

if ($file === '') {
    http_response_code(400);
    exit;
}

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if ($is_https) {
    ini_set('session.cookie_secure', '1');
}
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 2592000,
        'path' => '/',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (file_exists(BASE_PATH . '/config.php')) {
    require_once BASE_PATH . '/config.php';
    require_once BASE_PATH . '/includes/database.php';
    require_once BASE_PATH . '/includes/functions.php';
    require_once BASE_PATH . '/includes/auth.php';

    if (!is_logged_in() && empty($_GET['token'])) {
        validate_remember_token();
    }

    $attachment = get_attachment_by_filename($file);
    if ($attachment) {
        $token = trim((string) ($_GET['token'] ?? ''));
        if (!can_access_attachment($attachment, current_user(), $token)) {
            http_response_code(403);
            exit;
        }
        $path = resolve_attachment_file_path($attachment);
    } else {
        $path = BASE_PATH . '/uploads/' . $file;
    }
} else {
    $path = BASE_PATH . '/uploads/' . $file;
}

if (empty($path) || !file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit;
}

$allowed = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
];

$mime = @mime_content_type($path);
if (!in_array($mime, $allowed, true)) {
    http_response_code(403);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
