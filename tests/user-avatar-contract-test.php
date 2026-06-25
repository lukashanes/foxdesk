<?php

$root = dirname(__DIR__);

$assert = function ($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$user_functions = file_get_contents($root . '/includes/user-functions.php');
$theme = file_get_contents($root . '/theme.css');
$header = file_get_contents($root . '/includes/header.php');
$ticket_detail = file_get_contents($root . '/pages/ticket-detail.php');
$admin_users = file_get_contents($root . '/pages/admin/users.php');
$dashboard = file_get_contents($root . '/pages/dashboard.php');

$assert($user_functions !== false, 'User functions must be readable.');
$assert($theme !== false, 'Theme CSS must be readable.');
$assert(str_contains($user_functions, 'function render_user_avatar'), 'A shared user avatar renderer must exist.');
$assert(str_contains($user_functions, 'user-avatar__initials'), 'Avatar renderer must always render text initials fallback.');
$assert(str_contains($user_functions, 'user-avatar__image'), 'Avatar renderer may render an image above initials.');
$assert(str_contains($user_functions, 'onerror="this.hidden=true;this.classList.add') && str_contains($user_functions, "removeAttribute(\\'src\\')"), 'Avatar image failures must hide the image layer.');
$assert(str_contains($theme, '.user-avatar__image.is-hidden'), 'Theme must hide failed avatar image layers.');
$assert(str_contains($theme, '.user-avatar__image[hidden]'), 'Theme must hide hard-hidden avatar image layers.');
$assert(str_contains($header, 'notif-avatar-fallback'), 'Notification avatars must render a text fallback behind images.');
$assert(str_contains($header, 'if (src)'), 'Notification avatars must not render empty image URLs.');

$assert(str_contains($header, "render_user_avatar(\$user"), 'App shell must use shared avatar rendering.');
$assert(str_contains($ticket_detail, "render_user_avatar(\$comment"), 'Ticket comments must use shared avatar rendering.');
$assert(str_contains($admin_users, "render_user_avatar(\$u"), 'Admin user rows must use shared avatar rendering.');
$assert(str_contains($dashboard, "render_user_avatar(\$member"), 'Dashboard team rows must use shared avatar rendering.');

$assert(!str_contains($ticket_detail, "upload_url(\$comment['avatar'])"), 'Ticket comments must not render bare uploaded avatar images.');
$assert(!str_contains($admin_users, "upload_url(\$u['avatar'])"), 'Admin user rows must not render bare uploaded avatar images.');
$assert(!str_contains($dashboard, "upload_url(\$member['avatar'])"), 'Dashboard team rows must not render bare uploaded avatar images.');

echo "User avatar contract OK\n";
