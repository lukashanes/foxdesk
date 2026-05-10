<?php
/**
 * API Handlers for Update Checking & Remote Update Download
 *
 * All endpoints require admin authentication.
 */

require_once BASE_PATH . '/includes/update-check-functions.php';

/**
 * API: Check for updates (force check)
 * POST index.php?page=api&action=check-for-updates
 */
function api_check_for_updates(): void
{
    require_admin_post();

    try {
        $result = check_for_updates(true);

        if ($result) {
            api_success([
                'update_available' => true,
                'version' => $result['version'],
                'download_url' => $result['download_url'],
                'changelog' => $result['changelog'],
                'released_at' => $result['released_at'],
            ]);
        } else {
            api_success([
                'update_available' => false,
                'current_version' => defined('APP_VERSION') ? APP_VERSION : '0.0',
                'message' => t('You are running the latest version.'),
            ]);
        }
    } catch (Throwable $e) {
        api_error(t('Update check failed: {error}', ['error' => $e->getMessage()]), 500);
    }
}

/**
 * API: Download remote update package
 * POST index.php?page=api&action=download-remote-update
 */
function api_download_remote_update(): void
{
    require_admin_post();

    $update_info = get_cached_update_info();
    if (!$update_info || empty($update_info['download_url'])) {
        api_error(t('No update available to download.'), 400);
    }

    try {
        require_once BASE_PATH . '/includes/update-functions.php';

        // Download the ZIP from remote
        $local_path = download_remote_update($update_info['download_url']);
        if ($local_path === false) {
            api_error(t('Failed to download update package. Please try again or upload manually.'), 500);
        }

        // Validate the downloaded package
        $validation = validate_update_package($local_path);
        if (!$validation['valid']) {
            @unlink($local_path);
            $error_msg = $validation['error'] ?? implode(', ', $validation['errors'] ?? []);
            api_error(t('Downloaded package is invalid: {error}', ['error' => $error_msg]), 400);
        }

        // Store as pending update in session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['pending_update'] = [
            'file' => $local_path,
            'version' => $validation['version'],
            'changelog' => $validation['changelog'] ?? [],
            'uploaded_at' => time(),
            'source' => 'remote',
        ];

        api_success([
            'downloaded' => true,
            'version' => $validation['version'],
            'changelog' => $validation['changelog'] ?? [],
            'message' => t('Update package downloaded and validated. Ready to install.'),
        ]);
    } catch (Throwable $e) {
        api_error(t('Download failed: {error}', ['error' => $e->getMessage()]), 500);
    }
}

/**
 * API: Dismiss update notification for a specific version
 * POST index.php?page=api&action=dismiss-update-notice
 */
function api_dismiss_update_notice(): void
{
    require_admin_post();

    $input = json_decode(file_get_contents('php://input'), true);
    $version = trim((string) ($input['version'] ?? $_POST['version'] ?? ''));

    if ($version === '') {
        api_error('Missing version parameter', 400);
    }

    dismiss_update_notice($version);

    api_success([
        'dismissed' => true,
        'version' => $version,
    ]);
}
