<?php
/**
 * Authorized ticket attachment delivery.
 */

define('BASE_PATH', __DIR__);

if (!file_exists(BASE_PATH . '/config.php')) {
    http_response_code(404);
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

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/database.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/auth.php';

if (!is_logged_in() && empty($_GET['token'])) {
    validate_remember_token();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$filename = isset($_GET['f']) ? basename((string) $_GET['f']) : '';

if ($id <= 0 && $filename === '') {
    http_response_code(400);
    exit;
}

$attachment = $id > 0 ? get_attachment($id) : get_attachment_by_filename($filename);
if (!$attachment) {
    http_response_code(404);
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));
if (!can_access_attachment($attachment, current_user(), $token)) {
    http_response_code(403);
    exit;
}

$path = resolve_attachment_file_path($attachment);
if (!$path) {
    http_response_code(404);
    exit;
}

$mime = (string) ($attachment['mime_type'] ?? '');
if ($mime === '' || $mime === 'application/octet-stream') {
    $detected = @mime_content_type($path);
    if ($detected) {
        $mime = $detected;
    }
}
if ($mime === '') {
    $mime = 'application/octet-stream';
}

$original_name = (string) ($attachment['original_name'] ?? basename($path));
$ascii_name = preg_replace('/[^\x20-\x7E]/', '_', $original_name);
$ascii_name = $ascii_name !== '' ? $ascii_name : basename($path);
$disposition = !empty($_GET['download']) ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($ascii_name, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($original_name));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
