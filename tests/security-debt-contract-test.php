<?php

$root = dirname(__DIR__);

$functions = file_get_contents($root . '/includes/functions.php');
$uploads = file_get_contents($root . '/includes/upload-functions.php');
$image = file_get_contents($root . '/image.php');
$security = file_get_contents($root . '/includes/security-helpers.php');
$login = file_get_contents($root . '/pages/login.php');

if ($functions === false || $uploads === false || $image === false || $security === false || $login === false) {
    fwrite(STDERR, "Unable to read security debt files.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$assert(str_contains($functions, 'function safe_html_dom'), 'DOM safe_html sanitizer is missing.');
$assert(str_contains($functions, 'function safe_html_regex_fallback'), 'safe_html regex fallback is missing.');
$assert(str_contains($functions, 'safe_html_url_allowed'), 'safe_html URL allow-list helper is missing.');
$assert(!str_contains($functions, 'basename($clean);'), 'upload_url must preserve public subdirectories instead of basename-only URLs.');

$assert(str_contains($uploads, "string \$visibility = 'private'"), 'upload_file must support private/public visibility.');
$assert(str_contains($uploads, "\$subdir = \$visibility === 'public' ? 'public/' : ''"), 'public uploads must be isolated in uploads/public.');
$assert(str_contains($uploads, 'function upload_absolute_path'), 'upload path resolver is missing.');

$assert(str_contains($image, "str_contains(\$requested, '..')"), 'image proxy must reject traversal.');
$assert(str_contains($image, 'image_proxy_is_public_upload'), 'image proxy must explicitly authorize non-attachment public images.');
$assert(str_contains($image, "str_starts_with(\$relative_path, 'public/')"), 'image proxy must allow uploads/public assets.');
$assert(str_contains($image, 'comments WHERE content LIKE'), 'image proxy must preserve referenced legacy editor images.');
$assert(str_contains($image, 'attachment_user_can_access'), 'image proxy must keep attachment authorization.');

$assert(str_contains($security, 'function rate_limit_key'), 'per-subject rate limit helper is missing.');
$assert(str_contains($login, "rate_limit_key('login'"), 'login must use per-subject rate limiting.');
$assert(str_contains($security, 'Permissions-Policy'), 'Permissions-Policy security header is missing.');
$assert(str_contains($security, 'Content-Security-Policy-Report-Only'), 'CSP report-only hardening header is missing.');

echo "Security debt contract OK\n";
