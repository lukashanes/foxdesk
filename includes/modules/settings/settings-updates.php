<?php
/**
 * Settings update/backup action metadata.
 */

function settings_update_action_keys(): array
{
    return [
        'check_updates_now',
        'save_update_check_settings',
        'install_remote_update',
        'download_backup',
        'upload_update',
        'apply_update',
        'cancel_update',
        'rollback_update',
        'delete_backup',
        'create_backup',
        'clear_update_history',
    ];
}

function settings_is_update_action(array $post): bool
{
    foreach (settings_update_action_keys() as $key) {
        if (isset($post[$key])) {
            return true;
        }
    }
    return false;
}

/**
 * Normalize a stored update changelog (remote release notes or an uploaded
 * package's version.json) into a list of non-empty strings for display.
 */
function settings_update_changelog_entries($changelog): array
{
    if (!is_array($changelog)) {
        return [];
    }

    $entries = [];
    foreach ($changelog as $entry) {
        if (!is_scalar($entry)) {
            continue;
        }
        $entry = trim((string) $entry);
        if ($entry !== '') {
            $entries[] = $entry;
        }
    }

    return $entries;
}

function settings_is_managed_update_action(array $post): bool
{
    foreach (['check_updates_now', 'save_update_check_settings', 'install_remote_update', 'upload_update', 'apply_update', 'cancel_update'] as $key) {
        if (isset($post[$key])) {
            return true;
        }
    }
    return false;
}
