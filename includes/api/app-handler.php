<?php
/**
 * API Handler: application shell contract.
 */

function api_app_shell()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    if (!function_exists('app_shell_payload')) {
        api_error('App shell is not available.', 500);
    }

    api_success([
        'app_shell' => app_shell_payload($user),
    ]);
}

function api_app_home()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        api_error('Method not allowed', 405);
    }

    $user = current_user();
    if (!$user) {
        api_error('Unauthorized', 401);
    }

    if (!function_exists('app_shell_payload') || !function_exists('app_feed_payload')) {
        api_error('App home is not available.', 500);
    }

    $limit = (int) ($_GET['limit'] ?? 5);

    api_success([
        'app_shell' => app_shell_payload($user),
        'home' => app_feed_payload($user, $limit),
    ]);
}
