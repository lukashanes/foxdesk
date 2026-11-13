<?php
/**
 * Attachment Proxy — serves ticket attachment files through PHP.
 *
 * Supports both manual uploads stored in uploads/ and inbound email
 * attachments stored in the configured IMAP storage base.
 */

define('BASE_PATH', __DIR__);
define('SESSION_LIFETIME', 2592000);
define('REMEMBER_ME_DURATION', 30 * 86400);

$raw = isset($_GET['f']) ? (string) $_GET['f'] : '';
$raw = rawurldecode($raw);
$raw = str_replace('\\', '/', $raw);
$raw = trim($raw);
$share_token = trim((string) ($_GET['share_token'] ?? ''));

if ($raw === '') {
    http_response_code(400);
    exit;
}

$segments = array_values(array_filter(explode('/', ltrim($raw, '/')), static function ($segment) {
    return $segment !== '';
}));

if (empty($segments)) {
    http_response_code(400);
    exit;
}

foreach ($segments as $segment) {
    if ($segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
        http_response_code(400);
        exit;
    }
}

$requested_relative_path = implode('/', $segments);

if (file_exists(BASE_PATH . '/config.php')) {
    require_once BASE_PATH . '/config.php';
}
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
if (file_exists(BASE_PATH . '/includes/email-ingest-functions.php')) {
    require_once BASE_PATH . '/includes/email-ingest-functions.php';
}

$attachment = function_exists('find_attachment_by_relative_path')
    ? find_attachment_by_relative_path($requested_relative_path)
    : null;

$authorized = false;
if ($attachment) {
    if (!empty($share_token) && function_exists('attachment_share_token_can_access')) {
        $authorized = attachment_share_token_can_access($attachment, $share_token);
    }

    if (!$authorized && function_exists('is_logged_in') && !is_logged_in() && !empty($_COOKIE['foxdesk_remember']) && function_exists('validate_remember_token')) {
        validate_remember_token();
    }

    if (!$authorized && function_exists('attachment_user_can_access')) {
        $authorized = attachment_user_can_access($attachment);
    }
}

if (!$authorized) {
    http_response_code(403);
    exit;
}

$allowed_roots = [
    trim((defined('UPLOAD_DIR') ? UPLOAD_DIR : 'uploads/'), '/\\'),
    'storage/tickets',
];

if (function_exists('email_ingest_config')) {
    try {
        $imap_cfg = email_ingest_config();
        $imap_storage_root = trim((string) ($imap_cfg['storage_base'] ?? ''), '/\\');
        if ($imap_storage_root !== '') {
            $allowed_roots[] = $imap_storage_root;
        }
    } catch (Throwable $e) {
        // Fall back to default roots if config/settings cannot be loaded.
    }
}

$allowed_roots = array_values(array_unique(array_filter(array_map(static function ($root) {
    return trim(str_replace('\\', '/', (string) $root), '/');
}, $allowed_roots), static function ($root) {
    return $root !== '';
})));

$resolved_path = null;

foreach ($allowed_roots as $root) {
    if (
        $requested_relative_path !== $root &&
        !str_starts_with($requested_relative_path, $root . '/')
    ) {
        continue;
    }

    $candidate = BASE_PATH . '/' . $requested_relative_path;
    if (!is_file($candidate)) {
        continue;
    }

    $real_candidate = realpath($candidate);
    $real_root = realpath(BASE_PATH . '/' . $root);
    if ($real_candidate === false || $real_root === false) {
        continue;
    }

    if ($real_candidate === $real_root || str_starts_with($real_candidate, $real_root . DIRECTORY_SEPARATOR)) {
        $resolved_path = $real_candidate;
        break;
    }
}

if ($resolved_path === null) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($resolved_path);
if (!is_string($mime) || $mime === '') {
    $mime = 'application/octet-stream';
}

$basename = basename($resolved_path);
$disposition = str_starts_with($mime, 'image/') ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($resolved_path));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $basename) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($resolved_path);
exit;
