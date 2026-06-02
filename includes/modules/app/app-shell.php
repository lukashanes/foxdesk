<?php
/**
 * App shell contract for web and future native clients.
 *
 * This keeps the product navigation and high-level capabilities in one stable
 * place instead of making mobile clients infer behavior from HTML pages.
 */

function app_shell_can_view_reports(array $user): bool
{
    return is_admin() || (function_exists('can_view_time') && can_view_time($user));
}

function app_shell_user(array $user): array
{
    return [
        'id' => (int) ($user['id'] ?? 0),
        'name' => trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))),
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'language' => function_exists('get_app_language') ? get_app_language() : 'en',
        'is_admin' => is_admin(),
        'is_platform_admin' => function_exists('is_platform_admin') ? is_platform_admin() : false,
    ];
}

function app_shell_navigation(array $user): array
{
    $is_client_user = ($user['role'] ?? '') === 'user';
    $items = [
        [
            'key' => 'work',
            'label' => 'Work',
            'url' => url('work'),
            'icon' => 'tasks',
            'primary' => true,
        ],
    ];

    if (!$is_client_user) {
        $items[] = [
            'key' => 'inbox',
            'label' => 'Inbox',
            'url' => url('inbox'),
            'icon' => 'inbox',
            'primary' => true,
        ];
    }

    $items[] = [
        'key' => 'tickets',
        'label' => 'Tickets',
        'url' => url('tickets'),
        'icon' => 'file-alt',
        'primary' => true,
    ];

    if (!$is_client_user) {
        $items[] = [
            'key' => 'clients',
            'label' => 'Clients',
            'url' => url('admin', ['section' => 'organizations']),
            'icon' => 'building',
            'primary' => true,
        ];
    }

    if (app_shell_can_view_reports($user)) {
        $items[] = [
            'key' => 'reports',
            'label' => 'Reports',
            'url' => url('admin', ['section' => 'reports']),
            'icon' => 'chart-bar',
            'primary' => true,
        ];
    }

    if (is_admin()) {
        $items[] = [
            'key' => 'settings',
            'label' => 'Settings',
            'url' => url('admin', ['section' => 'settings']),
            'icon' => 'cog',
            'primary' => false,
        ];
    }

    return $items;
}

function app_shell_work_queues(array $user): array
{
    if (!function_exists('work_queue_definitions')) {
        return [];
    }

    $queues = [];
    foreach (work_queue_definitions() as $key => $definition) {
        if (($definition['scope'] ?? '') === 'team' && ($user['role'] ?? '') === 'user') {
            continue;
        }
        $queues[$key] = [
            'definition' => $definition,
            'count' => function_exists('work_queue_count') ? work_queue_count($key, $user) : 0,
            'url' => url('work', ['queue' => $key]),
        ];
    }

    return $queues;
}

function app_shell_inbox_queues(array $user): array
{
    if (($user['role'] ?? '') === 'user' || !function_exists('inbox_queue_definitions')) {
        return [];
    }

    $queues = [];
    foreach (inbox_queue_definitions() as $key => $definition) {
        $queues[$key] = [
            'definition' => $definition,
            'count' => function_exists('inbox_queue_count') ? inbox_queue_count($key, $user) : 0,
            'url' => url('inbox', ['queue' => $key]),
        ];
    }

    return $queues;
}

function app_shell_search_sections(array $user): array
{
    if (!function_exists('global_search_sections')) {
        return [];
    }

    $sections = global_search_sections();
    if (($user['role'] ?? '') === 'user') {
        unset($sections['clients'], $sections['contacts'], $sections['reports']);
    }

    return $sections;
}

function app_shell_reporting(array $user): ?array
{
    if (!app_shell_can_view_reports($user) || !function_exists('reporting_flow_steps')) {
        return null;
    }

    return [
        'steps' => reporting_flow_steps(),
        'time_presets' => reporting_flow_time_presets(),
        'review_url' => reporting_flow_review_url(null, 'this_month'),
        'builder_url' => reporting_flow_builder_url(null, 'this_month'),
    ];
}

function app_shell_capabilities(array $user): array
{
    $is_client_user = ($user['role'] ?? '') === 'user';

    return [
        'create_ticket' => true,
        'triage_inbox' => !$is_client_user,
        'view_clients' => !$is_client_user,
        'view_reports' => app_shell_can_view_reports($user),
        'manage_settings' => is_admin(),
        'use_timers' => !$is_client_user,
        'upload_attachments' => true,
        'global_search' => true,
    ];
}

function app_shell_payload(array $user): array
{
    return [
        'schema_version' => 1,
        'generated_at' => date('c'),
        'home_page' => function_exists('foxdesk_authenticated_home_page') ? foxdesk_authenticated_home_page() : 'work',
        'user' => app_shell_user($user),
        'navigation' => app_shell_navigation($user),
        'capabilities' => app_shell_capabilities($user),
        'work_queues' => app_shell_work_queues($user),
        'inbox_queues' => app_shell_inbox_queues($user),
        'search_sections' => app_shell_search_sections($user),
        'reporting' => app_shell_reporting($user),
    ];
}
