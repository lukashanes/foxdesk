<?php
/**
 * Settings view model helpers.
 */

function settings_normalize_tab(string $tab): string
{
    if (in_array($tab, ['statuses', 'priorities', 'ticket-types'], true)) {
        return 'workflow';
    }
    return $tab;
}

function settings_tab_from_request(array $request): string
{
    return settings_normalize_tab((string) ($request['tab'] ?? 'general'));
}

/**
 * Resolve a settings tab to an allowlisted view partial.
 *
 * @return string|null Absolute file path, or null for an unknown tab.
 */
function settings_view_file(string $tab): ?string
{
    $views = [
        'general' => 'general.php',
        'api' => 'api.php',
        'email' => 'email.php',
        'templates' => 'templates.php',
        'system' => 'system.php',
        'logs' => 'logs.php',
        'workflow' => 'workflow.php',
        'security' => 'security.php',
    ];

    $file = $views[$tab] ?? null;
    return $file === null ? null : __DIR__ . '/views/' . $file;
}
