<?php
/**
 * Image Proxy — serves uploaded images through PHP.
 *
 * Required because some hosting providers block direct Apache access
 * to the uploads/ directory.  Avatars, organisation logos and the
 * app logo are all stored there.
 *
 * Usage:  image.php?f=<filename>
 *         e.g.  image.php?f=69a027d599d19_1772103637.png
 */

// Filename from query string — basename() prevents directory traversal
// Strip any query-string suffix (?v=...) that may be appended for cache-busting
$raw = $_GET['f'] ?? '';
$file = basename(explode('?', $raw)[0]);

if ($file === '') {
    http_response_code(400);
    exit;
}

$path = __DIR__ . '/uploads/' . $file;

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit;
}

// Allowed image MIME types only
$allowed = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
];

$mime = mime_content_type($path);
if (!in_array($mime, $allowed, true)) {
    http_response_code(403);
    exit;
}

// Cache for 1 year (immutable filenames with unique hashes)
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
