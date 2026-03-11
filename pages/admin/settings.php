<?php
/**
 * Admin - Settings
 */

$page_title = t('Settings');
$page = 'admin';
$settings = get_settings();

// Include update functions
require_once BASE_PATH . '/includes/update-functions.php';
require_once BASE_PATH . '/includes/update-check-functions.php';

$settings_audit = function ($event_type, $context = [], $level = 'info') {
    $user_id = current_user()['id'] ?? null;
    if (function_exists('log_security_event')) {
        $payload = is_string($context) ? $context : json_encode($context, JSON_UNESCAPED_UNICODE);
        log_security_event((string) $event_type, $user_id, (string) ($payload ?: ''));
    }
    if (function_exists('debug_log')) {
        debug_log((string) $event_type, $context, $level, 'settings');
    }
};

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    if (isset($_POST['clear_logs'])) {
        db_query("TRUNCATE TABLE debug_log");
        $settings_audit('debug_logs_cleared', [], 'warning');
        flash(t('Logs cleared.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'logs']);
    }

    if (isset($_POST['clear_security_logs'])) {
        if (security_log_table_exists()) {
            db_query("TRUNCATE TABLE security_log");
        }
        $settings_audit('security_logs_cleared', [], 'warning');
        flash(t('Security logs cleared.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'logs']);
    }

    if (isset($_POST['clear_update_history'])) {
        save_setting('update_history', '[]');
        save_setting('update_changelog_cache', '{}');
        $settings_audit('update_history_cleared', [], 'warning');
        flash(t('Update history cleared.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Check for updates now (force)
    if (isset($_POST['check_updates_now'])) {
        $update_result = check_for_updates(true);
        if ($update_result) {
            flash(t('FoxDesk {version} is available!', ['version' => $update_result['version']]), 'info');
        } else {
            flash(t('You are running the latest version.'), 'success');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Save auto-update check setting
    if (isset($_POST['save_update_check_settings'])) {
        $enabled = !empty($_POST['update_check_enabled']) ? '1' : '0';
        save_setting('update_check_enabled', $enabled);
        $settings_audit('update_check_settings_changed', ['enabled' => $enabled]);
        flash(t('Settings saved.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Save pseudo-cron setting
    if (isset($_POST['save_pseudo_cron_settings'])) {
        $enabled = !empty($_POST['pseudo_cron_enabled']) ? '1' : '0';
        save_setting('pseudo_cron_enabled', $enabled);

        // Generate secret token on first enable
        if ($enabled === '1' && !get_setting('pseudo_cron_secret')) {
            save_setting('pseudo_cron_secret', bin2hex(random_bytes(20)));
        }

        $settings_audit('pseudo_cron_settings_changed', ['enabled' => $enabled]);
        flash(t('Settings saved.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // One-click remote install: download + validate + apply
    if (isset($_POST['install_remote_update'])) {
        $update_info = get_cached_update_info();
        if (!$update_info || empty($update_info['download_url'])) {
            flash(t('No update available to download.'), 'error');
            redirect('admin', ['section' => 'settings', 'tab' => 'system']);
        }

        $local_path = download_remote_update($update_info['download_url']);
        if ($local_path === false) {
            flash(t('Failed to download update package. Please try again or upload manually.'), 'error');
            redirect('admin', ['section' => 'settings', 'tab' => 'system']);
        }

        $validation = validate_update_package($local_path);
        if (!$validation['valid']) {
            @unlink($local_path);
            $error_msg = $validation['error'] ?? implode(', ', $validation['errors'] ?? []);
            flash(t('Downloaded package is invalid: {error}', ['error' => $error_msg]), 'error');
            redirect('admin', ['section' => 'settings', 'tab' => 'system']);
        }

        $settings_audit('remote_update_downloaded', ['version' => $validation['version']]);

        // Apply immediately
        try {
            $result = apply_update($local_path);
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'errors' => [$e->getMessage()],
                'backup_id' => null
            ];
            error_log('settings install_remote_update fatal: ' . $e->getMessage());
        }

        @unlink($local_path);

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if (function_exists('opcache_invalidate')) {
            foreach ([
                BASE_PATH . '/index.php',
                BASE_PATH . '/includes/header.php',
                BASE_PATH . '/includes/footer.php',
                BASE_PATH . '/includes/functions.php',
                BASE_PATH . '/includes/update-functions.php',
                BASE_PATH . '/pages/admin/settings.php',
            ] as $f) {
                @opcache_invalidate($f, true);
            }
        }

        if ($result['success']) {
            $settings_audit('update_applied_from_settings', [
                'backup_id' => $result['backup_id'] ?? null,
                'new_version' => $result['new_version'] ?? null,
            ]);
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['flash'] = [
                    'message' => t('Update installed successfully! Backup: {backup}', ['backup' => $result['backup_id']]),
                    'type' => 'success'
                ];
                session_write_close();
            }
            $redirect_url = url('admin', ['section' => 'settings', 'tab' => 'system']);
            echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
            echo '<meta http-equiv="refresh" content="2;url=' . htmlspecialchars($redirect_url) . '">';
            echo '<title>Updating...</title>';
            echo '<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:system-ui,sans-serif;background:#f8fafc;color:#334155}';
            echo '.box{text-align:center;padding:2rem}.spinner{width:24px;height:24px;border:3px solid #e2e8f0;border-top-color:#3b82f6;border-radius:50%;animation:spin .6s linear infinite;margin:0 auto 1rem}';
            echo '@keyframes spin{to{transform:rotate(360deg)}}</style></head>';
            echo '<body><div class="box"><div class="spinner"></div>';
            echo '<div style="font-weight:600;font-size:1.1rem">Update complete</div>';
            echo '<div style="color:#64748b;margin-top:.5rem;font-size:.875rem">Redirecting...</div>';
            echo '</div></body></html>';
            exit;
        } else {
            $settings_audit('update_apply_failed_from_settings', [
                'error' => $result['error'] ?? '',
            ], 'error');
            flash(t('Update failed: {error}', ['error' => $result['error']]), 'error');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    if (isset($_POST['download_backup'])) {
        $backup_id = trim((string) ($_POST['backup_id'] ?? ''));
        $download_type = trim((string) ($_POST['download_type'] ?? 'bundle'));
        $download = prepare_backup_download($backup_id, $download_type);
        if (!$download['success']) {
            flash(t('Backup download failed: {error}', ['error' => $download['error'] ?? t('Unknown error')]), 'error');
            redirect('admin', ['section' => 'settings', 'tab' => 'system']);
        }

        $settings_audit('backup_download', [
            'backup_id' => $backup_id,
            'type' => $download_type,
            'filename' => $download['filename'],
        ]);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $path = (string) $download['path'];
        $filename = basename((string) $download['filename']);
        $mime = (string) ($download['mime'] ?? 'application/octet-stream');
        if (!file_exists($path) || !is_readable($path)) {
            if (!empty($download['cleanup'])) {
                @unlink($path);
            }
            flash(t('Backup download failed: {error}', ['error' => t('Backup file not available.')]), 'error');
            redirect('admin', ['section' => 'settings', 'tab' => 'system']);
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-transform, no-store, must-revalidate');
        header('Pragma: public');
        header('Expires: 0');
        readfile($path);

        if (!empty($download['cleanup'])) {
            @unlink($path);
        }
        exit;
    }

    // Handle update package upload
    if (isset($_POST['upload_update'])) {
        if (!empty($_FILES['update_package']['name']) && $_FILES['update_package']['error'] === UPLOAD_ERR_OK) {
            $tmp_path = $_FILES['update_package']['tmp_name'];
            $validation = validate_update_package($tmp_path);

            if ($validation['valid']) {
                // Store temp file info in session for confirmation
                $temp_dir = sys_get_temp_dir();
                $temp_file = $temp_dir . '/update_' . uniqid() . '.zip';
                move_uploaded_file($tmp_path, $temp_file);

                $_SESSION['pending_update'] = [
                    'file' => $temp_file,
                    'version' => $validation['version'],
                    'changelog' => $validation['changelog'] ?? [],
                    'uploaded_at' => time()
                ];

                $settings_audit('update_package_validated', [
                    'version' => $validation['version'],
                    'filename' => $_FILES['update_package']['name'] ?? '',
                ]);
                flash(t('Update package validated. Please confirm to apply.'), 'success');
            } else {
                $validation_error = trim((string) ($validation['error'] ?? ''));
                if ($validation_error === '' && !empty($validation['errors']) && is_array($validation['errors'])) {
                    $validation_error = implode(' | ', array_values(array_filter($validation['errors'], static function ($item) {
                        return is_string($item) && trim($item) !== '';
                    })));
                }
                if ($validation_error === '') {
                    $validation_error = t('Unknown error');
                }
                $settings_audit('update_package_validation_failed', [
                    'filename' => $_FILES['update_package']['name'] ?? '',
                    'error' => $validation_error,
                ], 'warning');
                flash(t('Invalid update package: {error}', ['error' => $validation_error]), 'error');
            }
        } else {
            flash(t('Please select an update package file.'), 'error');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Apply pending update
    if (isset($_POST['apply_update'])) {
        if (!empty($_SESSION['pending_update']['file']) && file_exists($_SESSION['pending_update']['file'])) {
            try {
                $result = apply_update($_SESSION['pending_update']['file']);
            } catch (Throwable $e) {
                $result = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'errors' => [$e->getMessage()],
                    'backup_id' => null
                ];
                error_log('settings apply_update fatal: ' . $e->getMessage());
            }

            // Cleanup temp file
            @unlink($_SESSION['pending_update']['file']);
            unset($_SESSION['pending_update']);

            // Aggressively flush opcache BEFORE any further code runs.
            // Older update-functions.php may not do this, so we ensure it here.
            // This prevents HTTP 500 when the next request loads stale cached bytecode.
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            // Per-file invalidation for critical files that will be required next
            if (function_exists('opcache_invalidate')) {
                $critical_files = [
                    BASE_PATH . '/index.php',
                    BASE_PATH . '/includes/header.php',
                    BASE_PATH . '/includes/footer.php',
                    BASE_PATH . '/includes/functions.php',
                    BASE_PATH . '/includes/update-functions.php',
                    BASE_PATH . '/pages/admin/settings.php',
                ];
                foreach ($critical_files as $f) {
                    @opcache_invalidate($f, true);
                }
            }

            if ($result['success']) {
                $settings_audit('update_applied_from_settings', [
                    'backup_id' => $result['backup_id'] ?? null,
                    'new_version' => $result['new_version'] ?? null,
                ]);
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['flash'] = [
                        'message' => t('Update applied successfully! Backup created: {backup}', ['backup' => $result['backup_id']]),
                        'type' => 'success'
                    ];
                    session_write_close();
                }

                // Output a minimal HTML redirect page and exit immediately.
                // This avoids loading any changed PHP files (header.php, footer.php)
                // which would crash because the in-memory code is old but files on disk are new.
                // NOTE: Newer apply_update() exits before reaching here.
                // This is a safety net for older update-functions.php versions.
                $redirect_url = url('admin', ['section' => 'settings', 'tab' => 'system']);
                echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
                echo '<meta http-equiv="refresh" content="2;url=' . htmlspecialchars($redirect_url) . '">';
                echo '<title>Updating...</title>';
                echo '<style>body{display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:system-ui,sans-serif;background:#f8fafc;color:#334155}';
                echo '.box{text-align:center;padding:2rem}.spinner{width:24px;height:24px;border:3px solid #e2e8f0;border-top-color:#3b82f6;border-radius:50%;animation:spin .6s linear infinite;margin:0 auto 1rem}';
                echo '@keyframes spin{to{transform:rotate(360deg)}}</style></head>';
                echo '<body><div class="box"><div class="spinner"></div>';
                echo '<div style="font-weight:600;font-size:1.1rem">Update complete</div>';
                echo '<div style="color:#64748b;margin-top:.5rem;font-size:.875rem">Redirecting...</div>';
                echo '</div></body></html>';
                exit;
            } else {
                $settings_audit('update_apply_failed_from_settings', [
                    'error' => $result['error'] ?? '',
                ], 'error');
                flash(t('Update failed: {error}', ['error' => $result['error']]), 'error');
            }
        } else {
            flash(t('No pending update found.'), 'error');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Cancel pending update
    if (isset($_POST['cancel_update'])) {
        if (!empty($_SESSION['pending_update']['file'])) {
            @unlink($_SESSION['pending_update']['file']);
        }
        unset($_SESSION['pending_update']);
        $settings_audit('update_cancelled');
        flash(t('Update cancelled.'), 'info');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Rollback to backup
    if (isset($_POST['rollback_update'])) {
        $backup_id = $_POST['backup_id'] ?? '';
        if ($backup_id) {
            $result = rollback_update($backup_id);
            if ($result['success']) {
                $settings_audit('backup_restored_from_settings', ['backup_id' => $backup_id]);
                flash(t('Rollback completed successfully.'), 'success');
            } else {
                $settings_audit('backup_restore_failed_from_settings', ['backup_id' => $backup_id, 'error' => $result['error'] ?? ''], 'error');
                flash(t('Rollback failed: {error}', ['error' => $result['error']]), 'error');
            }
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Delete backup
    if (isset($_POST['delete_backup'])) {
        $backup_id = $_POST['backup_id'] ?? '';
        if ($backup_id) {
            $result = delete_backup($backup_id);
            if ($result['success']) {
                $settings_audit('backup_deleted_from_settings', ['backup_id' => $backup_id], 'warning');
                flash(t('Backup deleted.'), 'success');
            } else {
                $settings_audit('backup_delete_failed_from_settings', ['backup_id' => $backup_id, 'error' => $result['error'] ?? ''], 'error');
                flash(t('Failed to delete backup: {error}', ['error' => $result['error']]), 'error');
            }
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Create manual backup
    if (isset($_POST['create_backup'])) {
        $result = create_backup();
        if ($result['success']) {
            $settings_audit('manual_backup_created', ['backup_id' => $result['backup_id'] ?? null]);
            flash(t('Backup created successfully: {backup}', ['backup' => $result['backup_id']]), 'success');
        } else {
            $error_message = $result['error'] ?? implode(', ', (array) ($result['errors'] ?? []));
            $settings_audit('manual_backup_failed', ['error' => $error_message], 'error');
            flash(t('Failed to create backup: {error}', ['error' => $error_message]), 'error');
        }
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Save upload settings
    if (isset($_POST['save_upload_settings'])) {
        $max_upload_size = (int) ($_POST['max_upload_size'] ?? 10);
        $max_upload_size = max(1, min(100, $max_upload_size)); // 1MB to 100MB
        save_setting('max_upload_size', (string) $max_upload_size);
        flash(t('Upload settings saved.'), 'success');
        redirect('admin', ['section' => 'settings', 'tab' => 'system']);
    }

    // Update general settings
    if (isset($_POST['save_general'])) {
        $app_name = trim($_POST['app_name'] ?? 'FoxDesk');
        $ticket_prefix = trim($_POST['ticket_prefix'] ?? 'TK');
        $app_language = strtolower(trim($_POST['app_language'] ?? 'en'));
        if (!in_array($app_language, ['en', 'cs', 'de', 'it', 'es'], true)) {
            $app_language = 'en';
        }
        $time_format = trim($_POST['time_format'] ?? '24');
        if (!in_array($time_format, ['12', '24'], true)) {
            $time_format = '24';
        }
        $currency = trim($_POST['currency'] ?? 'CZK');
        if ($currency === '') {
            $currency = 'CZK';
        }
        $billing_rounding = (int) ($_POST['billing_rounding'] ?? 15);
        $rounding_allowed = [1, 5, 10, 15, 30, 60];
        if (!in_array($billing_rounding, $rounding_allowed, true)) {
            $billing_rounding = 15;
        }
        save_setting('app_name', $app_name);
        save_setting('ticket_prefix', strtoupper(preg_replace('/[^A-Za-z]/', '', $ticket_prefix)) ?: 'TK');
        save_setting('login_welcome_text', trim($_POST['login_welcome_text'] ?? ''));
        save_setting('app_language', $app_language);
        $_SESSION['lang'] = $app_language;
        unset($_SESSION['lang_override']);
        save_setting('time_format', $time_format);
        save_setting('currency', $currency);
        save_setting('billing_rounding', (string) $billing_rounding);

        // Time tracking alert settings
        save_setting('timer_alert_enabled', isset($_POST['timer_alert_enabled']) ? '1' : '0');
        $timer_alert_hours = max(1, min(24, (int) ($_POST['timer_alert_hours'] ?? 3)));
        save_setting('timer_alert_hours', (string) $timer_alert_hours);
        save_setting('timer_alert_email', isset($_POST['timer_alert_email']) ? '1' : '0');

        flash(t('Settings saved.'), 'success');
        redirect('admin', ['section' => 'settings']);
    }

    // Handle favicon upload
    if (isset($_POST['save_favicon'])) {
        if (!empty($_FILES['favicon']['name']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/ico', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['favicon']['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowed)) {
                $ext = pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION);
                $filename = 'favicon.' . ($ext ?: 'ico');
                $upload_path = BASE_PATH . '/uploads/' . $filename;

                if (move_uploaded_file($_FILES['favicon']['tmp_name'], $upload_path)) {
                    save_setting('favicon', 'uploads/' . $filename . '?v=' . time());
                    flash(t('Favicon uploaded successfully.'), 'success');
                } else {
                    flash(t('Failed to upload favicon.'), 'error');
                }
            } else {
                flash(t('Invalid file type. Please upload a PNG, ICO, or GIF image.'), 'error');
            }
        } elseif (isset($_POST['remove_favicon'])) {
            // Remove custom favicon
            $current = get_setting('favicon', '');
            if ($current && file_exists(BASE_PATH . '/' . explode('?', $current)[0])) {
                @unlink(BASE_PATH . '/' . explode('?', $current)[0]);
            }
            save_setting('favicon', '');
            flash(t('Favicon removed.'), 'success');
        }
        redirect('admin', ['section' => 'settings']);
    }

    // Handle app logo upload
    if (isset($_POST['save_app_logo'])) {
        if (!empty($_FILES['app_logo']['name']) && $_FILES['app_logo']['error'] === UPLOAD_ERR_OK) {
            try {
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
                $result = upload_file($_FILES['app_logo'], $allowed, 2 * 1024 * 1024);
                // Delete old logo
                $current = get_setting('app_logo', '');
                if ($current) {
                    $clean_path = explode('?', $current)[0];
                    if (file_exists(BASE_PATH . '/' . $clean_path)) {
                        @unlink(BASE_PATH . '/' . $clean_path);
                    }
                }
                save_setting('app_logo', UPLOAD_DIR . $result['filename'] . '?v=' . time());
                flash(t('Logo uploaded successfully.'), 'success');
            } catch (Exception $e) {
                flash($e->getMessage(), 'error');
            }
        } elseif (isset($_POST['remove_app_logo'])) {
            $current = get_setting('app_logo', '');
            if ($current) {
                $clean_path = explode('?', $current)[0];
                if (file_exists(BASE_PATH . '/' . $clean_path)) {
                    @unlink(BASE_PATH . '/' . $clean_path);
                }
            }
            save_setting('app_logo', '');
            flash(t('Logo removed.'), 'success');
        }
        redirect('admin', ['section' => 'settings']);
    }

    // Update email settings OR test/run SMTP/IMAP (all save first)
    if (isset($_POST['save_email']) || isset($_POST['test_smtp']) || isset($_POST['test_imap']) || isset($_POST['run_imap_now'])) {
        // Always save settings first
        save_setting('smtp_host', trim($_POST['smtp_host'] ?? ''));
        save_setting('smtp_port', trim($_POST['smtp_port'] ?? '587'));
        save_setting('smtp_user', trim($_POST['smtp_user'] ?? ''));

        if (!empty($_POST['smtp_pass'])) {
            save_setting('smtp_pass', $_POST['smtp_pass']);
        }

        save_setting('smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
        save_setting('smtp_from_name', trim($_POST['smtp_from_name'] ?? ''));
        save_setting('smtp_encryption', $_POST['smtp_encryption'] ?? 'tls');
        save_setting('email_notifications_enabled', isset($_POST['email_notifications_enabled']) ? '1' : '0');
        save_setting('notify_on_status_change', isset($_POST['notify_on_status_change']) ? '1' : '0');
        save_setting('notify_on_new_comment', isset($_POST['notify_on_new_comment']) ? '1' : '0');
        save_setting('notify_on_new_ticket', isset($_POST['notify_on_new_ticket']) ? '1' : '0');

        $imap_encryption = strtolower(trim((string) ($_POST['imap_encryption'] ?? 'ssl')));
        if (!in_array($imap_encryption, ['ssl', 'tls', 'none'], true)) {
            $imap_encryption = 'ssl';
        }
        $imap_port = (int) ($_POST['imap_port'] ?? 993);
        if ($imap_port < 1 || $imap_port > 65535) {
            $imap_port = 993;
        }
        $imap_max_per_run = max(1, min(500, (int) ($_POST['imap_max_emails_per_run'] ?? 50)));
        $imap_max_attachment_mb = max(1, min(100, (int) ($_POST['imap_max_attachment_size_mb'] ?? 10)));

        save_setting('imap_enabled', isset($_POST['imap_enabled']) ? '1' : '0');
        save_setting('imap_host', trim((string) ($_POST['imap_host'] ?? '')));
        save_setting('imap_port', (string) $imap_port);
        save_setting('imap_encryption', $imap_encryption);
        save_setting('imap_username', trim((string) ($_POST['imap_username'] ?? '')));
        if (!empty($_POST['imap_password'])) {
            save_setting('imap_password', (string) $_POST['imap_password']);
        }
        save_setting('imap_folder', trim((string) ($_POST['imap_folder'] ?? 'INBOX')));
        save_setting('imap_processed_folder', trim((string) ($_POST['imap_processed_folder'] ?? 'Processed')));
        save_setting('imap_failed_folder', trim((string) ($_POST['imap_failed_folder'] ?? 'Failed')));
        save_setting('imap_max_emails_per_run', (string) $imap_max_per_run);
        save_setting('imap_max_attachment_size_mb', (string) $imap_max_attachment_mb);
        save_setting('imap_validate_cert', isset($_POST['imap_validate_cert']) ? '1' : '0');
        save_setting('imap_mark_seen_on_skip', isset($_POST['imap_mark_seen_on_skip']) ? '1' : '0');
        save_setting('imap_allow_unknown_senders', isset($_POST['imap_allow_unknown_senders']) ? '1' : '0');
        save_setting('imap_storage_base', trim((string) ($_POST['imap_storage_base'] ?? 'storage/tickets')));

        // If test SMTP requested
        if (isset($_POST['test_smtp'])) {
            require_once BASE_PATH . '/includes/mailer.php';

            // Use saved password if not provided
            $test_pass = !empty($_POST['smtp_pass']) ? $_POST['smtp_pass'] : get_setting('smtp_pass', '');

            $result = test_smtp_connection([
                'host' => trim($_POST['smtp_host'] ?? ''),
                'port' => (int) trim($_POST['smtp_port'] ?? '587'),
                'user' => trim($_POST['smtp_user'] ?? ''),
                'pass' => $test_pass,
                'encryption' => $_POST['smtp_encryption'] ?? 'tls'
            ]);

            if ($result['success']) {
                flash(t('Settings saved. {message}', ['message' => $result['message']]), 'success');
            } else {
                flash(t('Settings saved, but the test failed: {message}', ['message' => $result['message']]), 'error');
            }
        } elseif (isset($_POST['test_imap'])) {
            require_once BASE_PATH . '/includes/email-ingest-functions.php';

            $imap_password = !empty($_POST['imap_password']) ? (string) $_POST['imap_password'] : get_setting('imap_password', '');
            $imap_folder = trim((string) ($_POST['imap_folder'] ?? 'INBOX'));
            if ($imap_folder === '') {
                $imap_folder = 'INBOX';
            }

            try {
                $result = email_ingest_test_connection([
                    'enabled' => true,
                    'host' => trim((string) ($_POST['imap_host'] ?? '')),
                    'port' => $imap_port,
                    'encryption' => $imap_encryption,
                    'username' => trim((string) ($_POST['imap_username'] ?? '')),
                    'password' => $imap_password,
                    'folder' => $imap_folder,
                ]);

                flash(t('Settings saved. IMAP connection successful. Mailbox {mailbox} contains {count} messages.', [
                    'mailbox' => $result['mailbox'],
                    'count' => (string) $result['messages'],
                ]), 'success');
            } catch (Throwable $e) {
                flash(t('Settings saved, but IMAP test failed: {message}', ['message' => $e->getMessage()]), 'error');
            }
        } elseif (isset($_POST['run_imap_now'])) {
            require_once BASE_PATH . '/includes/email-ingest-functions.php';

            try {
                $result = email_ingest_run();
                if (!empty($result['disabled'])) {
                    flash(t('Settings saved, but IMAP run is disabled. Enable incoming email processing first.'), 'error');
                } else {
                    $failed_count = (int) ($result['failed'] ?? 0);
                    flash(
                        t(
                            'Settings saved. IMAP run completed: checked {checked}, processed {processed}, skipped {skipped}, failed {failed}.',
                            [
                                'checked' => (string) ((int) ($result['checked'] ?? 0)),
                                'processed' => (string) ((int) ($result['processed'] ?? 0)),
                                'skipped' => (string) ((int) ($result['skipped'] ?? 0)),
                                'failed' => (string) $failed_count,
                            ]
                        ),
                        $failed_count > 0 ? 'error' : 'success'
                    );
                }
            } catch (Throwable $e) {
                flash(t('Settings saved, but IMAP run failed: {message}', ['message' => $e->getMessage()]), 'error');
            }
        } else {
            flash(t('Email settings saved.'), 'success');
        }

        redirect('admin', ['section' => 'settings', 'tab' => 'email']);
    }

    // Update email template
    if (isset($_POST['save_template'])) {
        $key = $_POST['template_key'] ?? '';
        $subject = trim($_POST['template_subject'] ?? '');
        $body = trim($_POST['template_body'] ?? '');
        $lang = strtolower(trim((string) ($_POST['template_lang'] ?? 'en')));
        if (!in_array($lang, ['en', 'cs', 'de', 'it', 'es'], true)) {
            $lang = 'en';
        }

        if (!empty($key) && !empty($subject) && !empty($body)) {
            require_once BASE_PATH . '/includes/mailer.php';
            try {
                save_email_template($key, $subject, $body, $lang);
                flash(t('Template saved.'), 'success');
            } catch (Throwable $e) {
                flash(t('Failed to save template: {error}', ['error' => $e->getMessage()]), 'error');
            }
        }

        redirect('admin', ['section' => 'settings', 'tab' => 'templates', 'lang' => $lang]);
    }
}

// Refresh settings
$settings = get_settings();
$tab = $_GET['tab'] ?? 'general';

// Backward compatibility: redirect old tab URLs to merged workflow tab
if (in_array($tab, ['statuses', 'priorities', 'ticket-types'], true)) {
    $tab = 'workflow';
}

// API tab was removed — redirect to general
if ($tab === 'api') {
    redirect('admin', ['section' => 'settings', 'tab' => 'general']);
}

// Process POST handlers for workflow tab before any layout output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'workflow') {
    require_csrf_token();

    // Determine which workflow handler to call based on POST data
    $workflow_handlers = [
        'add_status' => BASE_PATH . '/pages/admin/statuses-content.php',
        'update_status' => BASE_PATH . '/pages/admin/statuses-content.php',
        'delete_status' => BASE_PATH . '/pages/admin/statuses-content.php',
        'set_default' => BASE_PATH . '/pages/admin/statuses-content.php',
        'create_priority' => BASE_PATH . '/pages/admin/priorities-content.php',
        'update_priority' => BASE_PATH . '/pages/admin/priorities-content.php',
        'delete_priority' => BASE_PATH . '/pages/admin/priorities-content.php',
        'create_type' => BASE_PATH . '/pages/admin/ticket-types-content.php',
        'update_type' => BASE_PATH . '/pages/admin/ticket-types-content.php',
        'delete_type' => BASE_PATH . '/pages/admin/ticket-types-content.php',
        'toggle_type' => BASE_PATH . '/pages/admin/ticket-types-content.php',
    ];

    // Find which handler to use
    $handler_to_use = null;
    foreach (array_keys($workflow_handlers) as $key) {
        if (isset($_POST[$key])) {
            $handler_to_use = $workflow_handlers[$key];
            break;
        }
    }

    // Include the appropriate handler
    if ($handler_to_use && file_exists($handler_to_use)) {
        include $handler_to_use;
    }
}

$incoming_mail_logs = [];
$incoming_mail_log_error = '';

$imap_enabled_default = (defined('IMAP_ENABLED') && IMAP_ENABLED)
    || (
        (defined('IMAP_HOST') && trim((string) IMAP_HOST) !== '') &&
        (defined('IMAP_USERNAME') && trim((string) IMAP_USERNAME) !== '')
    );
$imap_view = [
    'enabled' => $settings['imap_enabled'] ?? ($imap_enabled_default ? '1' : '0'),
    'host' => $settings['imap_host'] ?? (defined('IMAP_HOST') ? (string) IMAP_HOST : ''),
    'port' => $settings['imap_port'] ?? (defined('IMAP_PORT') ? (string) IMAP_PORT : '993'),
    'encryption' => $settings['imap_encryption'] ?? (defined('IMAP_ENCRYPTION') ? strtolower((string) IMAP_ENCRYPTION) : 'ssl'),
    'username' => $settings['imap_username'] ?? (defined('IMAP_USERNAME') ? (string) IMAP_USERNAME : ''),
    'password_set' => !empty($settings['imap_password']) || (defined('IMAP_PASSWORD') && trim((string) IMAP_PASSWORD) !== ''),
    'folder' => $settings['imap_folder'] ?? (defined('IMAP_FOLDER') ? (string) IMAP_FOLDER : 'INBOX'),
    'processed_folder' => $settings['imap_processed_folder'] ?? (defined('IMAP_PROCESSED_FOLDER') ? (string) IMAP_PROCESSED_FOLDER : 'Processed'),
    'failed_folder' => $settings['imap_failed_folder'] ?? (defined('IMAP_FAILED_FOLDER') ? (string) IMAP_FAILED_FOLDER : 'Failed'),
    'max_emails_per_run' => $settings['imap_max_emails_per_run'] ?? (defined('IMAP_MAX_EMAILS_PER_RUN') ? (string) IMAP_MAX_EMAILS_PER_RUN : '50'),
    'max_attachment_size_mb' => $settings['imap_max_attachment_size_mb'] ?? (string) ((int) ((defined('IMAP_MAX_ATTACHMENT_SIZE') ? (int) IMAP_MAX_ATTACHMENT_SIZE : 10485760) / 1048576)),
    'validate_cert' => $settings['imap_validate_cert'] ?? (defined('IMAP_VALIDATE_CERT') && IMAP_VALIDATE_CERT ? '1' : '0'),
    'mark_seen_on_skip' => $settings['imap_mark_seen_on_skip'] ?? (defined('IMAP_MARK_SEEN_ON_SKIP') && IMAP_MARK_SEEN_ON_SKIP ? '1' : '0'),
    'allow_unknown_senders' => $settings['imap_allow_unknown_senders'] ?? '0',
    'storage_base' => $settings['imap_storage_base'] ?? (defined('IMAP_STORAGE_BASE') ? (string) IMAP_STORAGE_BASE : 'storage/tickets'),
];

if ($tab === 'email') {
    require_once BASE_PATH . '/includes/email-ingest-functions.php';
    try {
        email_ingest_ensure_schema();
        $incoming_mail_logs = db_fetch_all("
            SELECT
                l.created_at,
                l.mailbox,
                l.uid,
                l.status,
                l.reason,
                l.error,
                COALESCE(l.sender_email, tm.sender_email) AS sender_email,
                COALESCE(l.subject, tm.subject) AS subject,
                COALESCE(l.ticket_id, tm.ticket_id) AS ticket_id,
                t.hash AS ticket_hash,
                t.title AS ticket_title
            FROM email_ingest_logs l
            LEFT JOIN ticket_messages tm
                ON tm.id = (
                    SELECT tm2.id
                    FROM ticket_messages tm2
                    WHERE (tm2.mailbox = l.mailbox AND tm2.uid = l.uid)
                       OR (l.message_id IS NOT NULL AND l.message_id <> '' AND tm2.message_id = l.message_id)
                    ORDER BY tm2.id DESC
                    LIMIT 1
                )
            LEFT JOIN tickets t ON t.id = COALESCE(l.ticket_id, tm.ticket_id)
            ORDER BY l.created_at DESC
            LIMIT 100
        ");
    } catch (Throwable $e) {
        $incoming_mail_log_error = $e->getMessage();
    }

    // Load allowed senders for the allowlist UI
    try {
        $allowed_senders = db_fetch_all(
            "SELECT s.*, CONCAT(u.first_name, ' ', u.last_name) AS user_name
             FROM allowed_senders s
             LEFT JOIN users u ON s.user_id = u.id
             ORDER BY s.type, s.value"
        );
    } catch (Throwable $e) {
        $allowed_senders = [];
    }
    $all_users = db_fetch_all("SELECT id, first_name, last_name, email FROM users WHERE is_active = 1 ORDER BY first_name, last_name");
}

// Get template language
$template_lang = strtolower(trim((string) ($_GET['lang'] ?? 'en')));
if (!in_array($template_lang, ['en', 'cs', 'de', 'it', 'es'], true)) {
    $template_lang = 'en';
}

// Get email templates for selected language
try {
    $templates = db_fetch_all("
        SELECT t.* 
        FROM email_templates t 
        WHERE t.language = ?
        ORDER BY t.template_key
    ", [$template_lang]);

    // If we have missing templates for this language, we might want to show defaults from English or code
    // But for now, let's just show what's in DB.
} catch (Exception $e) {
    $templates = [];
}

require_once BASE_PATH . '/includes/header.php';
?>

<?php
$page_header_title = $page_title;
$page_header_subtitle = t('Configure system-wide preferences.');
include BASE_PATH . '/includes/components/page-header.php';
?>

<div>
    <!-- Tabs -->
    <div class="flex flex-wrap gap-0.5 mb-2 p-0.5 rounded-lg w-fit" style="background: var(--surface-secondary);">
        <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => 'general']); ?>"
            class="px-3 py-1.5 rounded-md text-xs font-medium <?php echo $tab === 'general' ? 'shadow text-blue-600' : ''; ?>"
            style="<?php echo $tab === 'general' ? 'background: var(--bg-primary);' : 'color: var(--text-secondary);'; ?>">
            <?php echo e(t('General')); ?>
        </a>
        <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => 'email']); ?>"
            class="px-3 py-1.5 rounded-md text-xs font-medium <?php echo $tab === 'email' ? 'shadow text-blue-600' : ''; ?>"
            style="<?php echo $tab === 'email' ? 'background: var(--bg-primary);' : 'color: var(--text-secondary);'; ?>">
            <?php echo e(t('Emails')); ?>
        </a>
        <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => 'templates']); ?>"
            class="px-3 py-1.5 rounded-md text-xs font-medium <?php echo $tab === 'templates' ? 'shadow text-blue-600' : ''; ?>"
            style="<?php echo $tab === 'templates' ? 'background: var(--bg-primary);' : 'color: var(--text-secondary);'; ?>">
            <?php echo e(t('Templates')); ?>
        </a>
        <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => 'workflow']); ?>"
            class="px-3 py-1.5 rounded-md text-xs font-medium <?php echo $tab === 'workflow' ? 'shadow text-blue-600' : ''; ?>"
            style="<?php echo $tab === 'workflow' ? 'background: var(--bg-primary);' : 'color: var(--text-secondary);'; ?>">
            <?php echo e(t('Workflow')); ?>
        </a>
        <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => 'system']); ?>"
            class="px-3 py-1.5 rounded-md text-xs font-medium <?php echo $tab === 'system' ? 'shadow text-blue-600' : ''; ?>"
            style="<?php echo $tab === 'system' ? 'background: var(--bg-primary);' : 'color: var(--text-secondary);'; ?>">
            <?php echo e(t('System')); ?>
        </a>
        <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => 'logs']); ?>"
            class="px-3 py-1.5 rounded-md text-xs font-medium <?php echo $tab === 'logs' ? 'shadow text-blue-600' : ''; ?>"
            style="<?php echo $tab === 'logs' ? 'background: var(--bg-primary);' : 'color: var(--text-secondary);'; ?>">
            <?php echo e(t('Logs')); ?>
        </a>
    </div>

    <?php if ($tab === 'general'): ?>
        <!-- General Settings -->
        <div class="card card-body">
            <h3 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">
                <?php echo e(t('General settings')); ?>
            </h3>

            <form method="post" class="space-y-3">
                <?php echo csrf_field(); ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium mb-1"
                            style="color: var(--text-secondary);"><?php echo e(t('Application name')); ?></label>
                        <input type="text" name="app_name" value="<?php echo e($settings['app_name'] ?? 'FoxDesk'); ?>"
                            class="form-input">
                        <p class="text-xs mt-1" style="color: var(--text-muted);">
                            <?php echo e(t('This name appears throughout the app.')); ?>
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1"
                            style="color: var(--text-secondary);"><?php echo e(t('Ticket ID prefix')); ?></label>
                        <input type="text" name="ticket_prefix" value="<?php echo e($settings['ticket_prefix'] ?? 'TK'); ?>"
                            maxlength="5" placeholder="TK" class="form-input">
                        <p class="text-xs mt-1" style="color: var(--text-muted);">
                            <?php echo e(t('Example: TK-10001, REQ-10001 (letters only).')); ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3">
                    <div>
                        <label class="block text-sm font-medium mb-1"
                            style="color: var(--text-secondary);"><?php echo e(t('Login page welcome text')); ?></label>
                        <textarea name="login_welcome_text"
                            class="form-input h-20"><?php echo e($settings['login_welcome_text'] ?? 'Manage your tickets, track time, and support your customers with our corporate enterprise helpdesk.'); ?></textarea>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">
                            <?php echo e(t('This text appears on the login screen below the application name.')); ?></p>
                    </div>
                </div>

                <div class="max-w-sm">
                    <label class="block text-sm font-medium mb-1"
                        style="color: var(--text-secondary);"><?php echo e(t('Language')); ?></label>
                    <select name="app_language" class="form-select">
                        <option value="en" <?php echo ($settings['app_language'] ?? 'en') === 'en' ? 'selected' : ''; ?>>
                            <?php echo e(t('English')); ?>
                        </option>
                        <option value="cs" <?php echo ($settings['app_language'] ?? 'en') === 'cs' ? 'selected' : ''; ?>>
                            <?php echo e(t('Czech')); ?>
                        </option>
                        <option value="de" <?php echo ($settings['app_language'] ?? 'en') === 'de' ? 'selected' : ''; ?>>
                            <?php echo e(t('German')); ?>
                        </option>
                        <option value="it" <?php echo ($settings['app_language'] ?? 'en') === 'it' ? 'selected' : ''; ?>>
                            <?php echo e(t('Italian')); ?>
                        </option>
                        <option value="es" <?php echo ($settings['app_language'] ?? 'en') === 'es' ? 'selected' : ''; ?>>
                            <?php echo e(t('Spanish')); ?>
                        </option>
                    </select>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">
                        <?php echo e(t('Default interface language.')); ?>
                    </p>
                </div>

                <div class="max-w-sm">
                    <label class="block text-sm font-medium mb-1"
                        style="color: var(--text-secondary);"><?php echo e(t('Time format')); ?></label>
                    <select name="time_format" class="form-select">
                        <option value="24" <?php echo ($settings['time_format'] ?? '24') === '24' ? 'selected' : ''; ?>>
                            <?php echo e(t('24-hour')); ?>
                        </option>
                        <option value="12" <?php echo ($settings['time_format'] ?? '24') === '12' ? 'selected' : ''; ?>>
                            <?php echo e(t('12-hour (AM/PM)')); ?>
                        </option>
                    </select>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">
                        <?php echo e(t('Applies to timestamps across the app.')); ?>
                    </p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
                    <div>
                        <label class="block text-sm font-medium mb-1"
                            style="color: var(--text-secondary);"><?php echo e(t('Currency')); ?></label>
                        <input type="text" name="currency" value="<?php echo e($settings['currency'] ?? 'CZK'); ?>"
                            class="form-input" maxlength="10">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1"
                            style="color: var(--text-secondary);"><?php echo e(t('Billing rounding (minutes)')); ?></label>
                        <select name="billing_rounding" class="form-select">
                            <?php
                            $rounding_value = (int) ($settings['billing_rounding'] ?? 15);
                            $rounding_options = [1, 5, 10, 15, 30, 60];
                            foreach ($rounding_options as $option):
                                ?>
                                <option value="<?php echo $option; ?>" <?php echo $rounding_value === $option ? 'selected' : ''; ?>>
                                    <?php echo $option; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">
                            <?php echo e(t('Applies to billable time in reports.')); ?>
                        </p>
                    </div>
                </div>

                <!-- Time Tracking Alerts Section -->
                <div class="border-t pt-3 mt-3">
                    <h4 class="font-semibold mb-4" style="color: var(--text-primary);">
                        <?php echo e(t('Time tracking alerts')); ?>
                    </h4>

                    <div class="space-y-4">
                        <div>
                            <label class="flex items-center space-x-3">
                                <input type="checkbox" name="timer_alert_enabled" <?php echo ($settings['timer_alert_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>
                                    class="w-5 h-5 rounded text-blue-500 focus:ring-blue-500">
                                <span class="font-medium"
                                    style="color: var(--text-primary);"><?php echo e(t('Enable long timer alerts')); ?></span>
                            </label>
                            <p class="text-sm ml-8" style="color: var(--text-muted);">
                                <?php echo e(t('Notify users when their timer has been running for too long.')); ?>
                            </p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-xl">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Alert threshold (hours)')); ?></label>
                                <input type="number" name="timer_alert_hours"
                                    value="<?php echo e($settings['timer_alert_hours'] ?? '3'); ?>" min="1" max="24"
                                    class="form-input">
                                <p class="text-xs mt-1" style="color: var(--text-muted);">
                                    <?php echo e(t('Send alert when timer exceeds this duration.')); ?>
                                </p>
                            </div>
                        </div>

                        <div>
                            <label class="flex items-center space-x-3">
                                <input type="checkbox" name="timer_alert_email" <?php echo ($settings['timer_alert_email'] ?? '1') === '1' ? 'checked' : ''; ?>
                                    class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                <span
                                    style="color: var(--text-secondary);"><?php echo e(t('Send email notification to user')); ?></span>
                            </label>
                            <p class="text-xs ml-7" style="color: var(--text-muted);">
                                <?php echo e(t('User will receive an email reminder to stop their timer.')); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <button type="submit" name="save_general" class="btn btn-primary mt-3">
                    <?php echo e(t('Save settings')); ?>
                </button>
            </form>
        </div>

        <!-- Favicon Upload Section -->
        <div class="card card-body mt-3">
            <h3 class="font-semibold mb-4" style="color: var(--text-primary);"><?php echo e(t('Favicon')); ?></h3>
            <?php $current_favicon = $settings['favicon'] ?? ''; ?>
            <?php if ($current_favicon): ?>
                <div class="flex items-center gap-3 p-3 rounded-lg mb-4 w-fit" style="background: var(--surface-secondary);">
                    <img src="<?php echo e($current_favicon); ?>" alt="Current favicon" class="w-8 h-8">
                    <span class="text-sm" style="color: var(--text-secondary);"><?php echo e(t('Current favicon')); ?></span>
                    <form method="post" class="inline ml-2">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="remove_favicon" value="1">
                        <button type="submit" name="save_favicon" class="text-red-500 hover:text-red-700 text-sm">
                            <?php echo get_icon('trash', 'w-4 h-4'); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" id="favicon-form">
                <?php echo csrf_field(); ?>
                <div id="favicon-upload-zone"
                    class="rounded-lg p-4 cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors max-w-md"
                    style="border-color: var(--border-light);">
                    <input type="file" name="favicon" id="favicon-file-input"
                        accept=".ico,.png,.gif,image/x-icon,image/png,image/gif" class="hidden">
                    <div class="flex items-center gap-3">
                        <span
                            style="color: var(--text-muted);"><?php echo get_icon('cloud-upload-alt', 'text-2xl flex-shrink-0'); ?></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm" style="color: var(--text-secondary);">
                                <span class="text-blue-500 font-medium"><?php echo e(t('Click')); ?></span>
                                <?php echo e(t('or drag file')); ?>
                            </p>
                            <p class="text-xs mt-0.5" style="color: var(--text-muted);" id="favicon-file-name">
                                <?php echo e(t('No file selected')); ?>
                            </p>
                            <p class="text-xs" style="color: var(--text-muted);">
                                <?php echo e(t('Recommended: 32x32 or 16x16 pixels. Formats: ICO, PNG, GIF')); ?>
                            </p>
                        </div>
                        <button type="submit" name="save_favicon" class="btn btn-primary flex-shrink-0"
                            id="favicon-upload-btn" disabled>
                            <?php echo get_icon('upload', 'mr-1'); ?>     <?php echo e(t('Upload')); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- App Logo Upload Section -->
        <div class="card card-body mt-3">
            <h3 class="font-semibold mb-4" style="color: var(--text-primary);"><?php echo e(t('App logo')); ?></h3>
            <?php $current_app_logo = get_setting('app_logo', ''); ?>
            <?php if ($current_app_logo): ?>
                <div class="flex items-center gap-3 p-3 rounded-lg mb-4 w-fit" style="background: var(--surface-secondary);">
                    <img src="<?php echo e(upload_url($current_app_logo)); ?>" alt="Current logo"
                        class="w-10 h-10 rounded-full object-cover">
                    <span class="text-sm" style="color: var(--text-secondary);"><?php echo e(t('Current logo')); ?></span>
                    <form method="post" class="inline ml-2">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="remove_app_logo" value="1">
                        <button type="submit" name="save_app_logo" class="text-red-500 hover:text-red-700 text-sm">
                            <?php echo get_icon('trash', 'w-4 h-4'); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" id="app-logo-form">
                <?php echo csrf_field(); ?>
                <div id="app-logo-upload-zone"
                    class="rounded-lg p-4 cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors max-w-md"
                    style="border-color: var(--border-light);">
                    <input type="file" name="app_logo" id="app-logo-file-input"
                        accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" class="hidden">
                    <div class="flex items-center gap-3">
                        <span
                            style="color: var(--text-muted);"><?php echo get_icon('cloud-upload-alt', 'text-2xl flex-shrink-0'); ?></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm" style="color: var(--text-secondary);">
                                <span class="text-blue-500 font-medium"><?php echo e(t('Click')); ?></span>
                                <?php echo e(t('or drag file')); ?>
                            </p>
                            <p class="text-xs mt-0.5" style="color: var(--text-muted);" id="app-logo-file-name">
                                <?php echo e(t('No file selected')); ?>
                            </p>
                            <p class="text-xs" style="color: var(--text-muted);">
                                <?php echo e(t('Square image recommended. Formats: JPG, PNG, GIF, WebP, SVG. Max 2 MB.')); ?>
                            </p>
                        </div>
                        <button type="submit" name="save_app_logo" class="btn btn-primary flex-shrink-0"
                            id="app-logo-upload-btn" disabled>
                            <?php echo get_icon('upload', 'mr-1'); ?>     <?php echo e(t('Upload')); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>

    <?php elseif ($tab === 'email'): ?>
        <!-- Email Settings -->
        <div class="space-y-3">
            <form method="post">
                <?php echo csrf_field(); ?>
                <div class="card card-body mb-2">
                    <h3 class="font-semibold mb-4" style="color: var(--text-primary);"><?php echo e(t('SMTP settings')); ?>
                    </h3>

                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('SMTP server')); ?></label>
                                <input type="text" name="smtp_host" value="<?php echo e($settings['smtp_host'] ?? ''); ?>"
                                    placeholder="smtp.gmail.com" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Port')); ?></label>
                                <input type="number" name="smtp_port"
                                    value="<?php echo e($settings['smtp_port'] ?? '587'); ?>" class="form-input">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Username')); ?></label>
                                <input type="text" name="smtp_user" value="<?php echo e($settings['smtp_user'] ?? ''); ?>"
                                    placeholder="user@gmail.com" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Password')); ?></label>
                                <input type="password" name="smtp_pass"
                                    placeholder="<?php echo empty($settings['smtp_pass']) ? '' : '********'; ?>"
                                    class="form-input">
                                <p class="text-xs mt-1" style="color: var(--text-muted);">
                                    <?php echo e(t('Leave blank to keep current password.')); ?>
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('From email')); ?></label>
                                <input type="email" name="smtp_from_email"
                                    value="<?php echo e($settings['smtp_from_email'] ?? ''); ?>" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('From name')); ?></label>
                                <input type="text" name="smtp_from_name"
                                    value="<?php echo e($settings['smtp_from_name'] ?? ''); ?>" class="form-input">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium mb-1"
                                style="color: var(--text-secondary);"><?php echo e(t('Encryption')); ?></label>
                            <select name="smtp_encryption" class="form-select">
                                <option value="tls" <?php echo ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>><?php echo e(t('TLS (port 587)')); ?></option>
                                <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>><?php echo e(t('SSL (port 465)')); ?></option>
                                <option value="" <?php echo empty($settings['smtp_encryption']) ? 'selected' : ''; ?>>
                                    <?php echo e(t('None (port 25)')); ?>
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card card-body mb-2">
                    <div class="mb-4">
                        <h3 class="font-semibold" style="color: var(--text-primary);">
                            <?php echo e(t('Incoming email (IMAP)')); ?>
                        </h3>
                        <p class="text-sm mt-1" style="color: var(--text-muted);">
                            <?php echo e(t('Use this mailbox to create or update tickets from incoming emails.')); ?>
                        </p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="flex items-center space-x-3">
                                <input type="checkbox" name="imap_enabled" <?php echo ($imap_view['enabled'] ?? '0') === '1' ? 'checked' : ''; ?> class="w-5 h-5 rounded text-blue-500 focus:ring-blue-500">
                                <span class="font-medium"
                                    style="color: var(--text-primary);"><?php echo e(t('Enable incoming email processing')); ?></span>
                            </label>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('IMAP server')); ?></label>
                                <input type="text" name="imap_host" value="<?php echo e($imap_view['host'] ?? ''); ?>"
                                    placeholder="imap.example.com" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('IMAP port')); ?></label>
                                <input type="number" name="imap_port" value="<?php echo e($imap_view['port'] ?? '993'); ?>"
                                    class="form-input">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('IMAP username')); ?></label>
                                <input type="text" name="imap_username"
                                    value="<?php echo e($imap_view['username'] ?? ''); ?>" placeholder="support@example.com"
                                    class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('IMAP password')); ?></label>
                                <input type="password" name="imap_password"
                                    placeholder="<?php echo !empty($imap_view['password_set']) ? '********' : ''; ?>"
                                    class="form-input">
                                <p class="text-xs mt-1" style="color: var(--text-muted);">
                                    <?php echo e(t('Leave blank to keep current IMAP password.')); ?>
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('IMAP encryption')); ?></label>
                                <select name="imap_encryption" class="form-select">
                                    <option value="ssl" <?php echo ($imap_view['encryption'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>><?php echo e(t('SSL')); ?></option>
                                    <option value="tls" <?php echo ($imap_view['encryption'] ?? '') === 'tls' ? 'selected' : ''; ?>><?php echo e(t('TLS')); ?></option>
                                    <option value="none" <?php echo ($imap_view['encryption'] ?? '') === 'none' ? 'selected' : ''; ?>><?php echo e(t('None')); ?></option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Mailbox folder')); ?></label>
                                <input type="text" name="imap_folder"
                                    value="<?php echo e($imap_view['folder'] ?? 'INBOX'); ?>" class="form-input">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Processed folder')); ?></label>
                                <input type="text" name="imap_processed_folder"
                                    value="<?php echo e($imap_view['processed_folder'] ?? 'Processed'); ?>"
                                    class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Failed folder')); ?></label>
                                <input type="text" name="imap_failed_folder"
                                    value="<?php echo e($imap_view['failed_folder'] ?? 'Failed'); ?>" class="form-input">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Max emails per run')); ?></label>
                                <input type="number" min="1" max="500" name="imap_max_emails_per_run"
                                    value="<?php echo e($imap_view['max_emails_per_run'] ?? '50'); ?>" class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Max attachment size (MB)')); ?></label>
                                <input type="number" min="1" max="100" name="imap_max_attachment_size_mb"
                                    value="<?php echo e($imap_view['max_attachment_size_mb'] ?? '10'); ?>"
                                    class="form-input">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1"
                                    style="color: var(--text-secondary);"><?php echo e(t('Attachment storage path')); ?></label>
                                <input type="text" name="imap_storage_base"
                                    value="<?php echo e($imap_view['storage_base'] ?? 'storage/tickets'); ?>"
                                    class="form-input">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="flex items-center space-x-3">
                                <input type="checkbox" name="imap_validate_cert" <?php echo ($imap_view['validate_cert'] ?? '0') === '1' ? 'checked' : ''; ?>
                                    class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                <span
                                    style="color: var(--text-secondary);"><?php echo e(t('Validate TLS certificate')); ?></span>
                            </label>

                            <label class="flex items-center space-x-3">
                                <input type="checkbox" name="imap_mark_seen_on_skip" <?php echo ($imap_view['mark_seen_on_skip'] ?? '1') === '1' ? 'checked' : ''; ?>
                                    class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                <span
                                    style="color: var(--text-secondary);"><?php echo e(t('Mark skipped emails as seen')); ?></span>
                            </label>

                            <label class="flex items-center space-x-3">
                                <input type="checkbox" name="imap_allow_unknown_senders" <?php echo ($imap_view['allow_unknown_senders'] ?? '0') === '1' ? 'checked' : ''; ?>
                                    class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                <span
                                    style="color: var(--text-secondary);"><?php echo e(t('Allow unknown senders (without allowlist)')); ?></span>
                            </label>
                        </div>

                        <p class="text-xs" style="color: var(--text-muted);">
                            <?php echo e(t('Cron command: php bin/ingest-emails.php')); ?>
                        </p>
                    </div>
                </div>

                <!-- Allowed Senders -->
                <div class="card card-body mb-2">
                    <h3 class="font-semibold mb-2" style="color: var(--text-primary);">
                        <?php echo e(t('Allowed Senders')); ?>
                    </h3>
                    <p class="text-xs mb-4" style="color: var(--text-muted);">
                        <?php echo e(t('When "Allow unknown senders" is disabled, only emails from addresses or domains in this list will be accepted.')); ?>
                    </p>

                    <!-- Add sender form -->
                    <div class="flex flex-wrap gap-2 mb-4 items-end">
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-secondary);"><?php echo e(t('Type')); ?></label>
                            <select id="as-type" class="input-field text-sm" style="width: auto; min-width: 120px;">
                                <option value="email">Email</option>
                                <option value="domain"><?php echo e(t('Domain')); ?></option>
                            </select>
                        </div>
                        <div class="flex-1" style="min-width: 200px;">
                            <label class="block text-xs mb-1" style="color: var(--text-secondary);"><?php echo e(t('Email or Domain')); ?></label>
                            <input type="text" id="as-value" class="input-field text-sm" placeholder="user@example.com">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-secondary);"><?php echo e(t('Assign to user')); ?></label>
                            <select id="as-user" class="input-field text-sm" style="width: auto; min-width: 150px;">
                                <option value="">&mdash;</option>
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?php echo (int)$u['id']; ?>"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="button" onclick="addAllowedSender()" class="btn btn-primary text-sm">
                            <?php echo e(t('Add Sender')); ?>
                        </button>
                    </div>

                    <!-- Senders table -->
                    <div class="overflow-x-auto border rounded-lg" style="border-color: var(--border-color);">
                        <table class="w-full text-sm">
                            <thead>
                                <tr style="background: var(--surface-secondary);">
                                    <th class="px-4 py-2 text-left text-xs font-medium" style="color: var(--text-muted);"><?php echo e(t('Type')); ?></th>
                                    <th class="px-4 py-2 text-left text-xs font-medium" style="color: var(--text-muted);"><?php echo e(t('Value')); ?></th>
                                    <th class="px-4 py-2 text-left text-xs font-medium" style="color: var(--text-muted);"><?php echo e(t('Assign to user')); ?></th>
                                    <th class="px-4 py-2 text-left text-xs font-medium" style="color: var(--text-muted);"><?php echo e(t('Status')); ?></th>
                                    <th class="px-4 py-2 text-right text-xs font-medium" style="color: var(--text-muted);"></th>
                                </tr>
                            </thead>
                            <tbody id="allowed-senders-tbody">
                                <?php if (empty($allowed_senders)): ?>
                                    <tr>
                                        <td colspan="5" class="px-4 py-3 text-center text-xs" style="color: var(--text-muted);">
                                            <?php echo e(t('No entries')); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allowed_senders as $sender): ?>
                                        <tr class="border-t" style="border-color: var(--border-color);" id="as-row-<?php echo (int)$sender['id']; ?>">
                                            <td class="px-4 py-2" style="color: var(--text-secondary);">
                                                <?php echo $sender['type'] === 'email' ? 'Email' : e(t('Domain')); ?>
                                            </td>
                                            <td class="px-4 py-2 font-mono text-xs" style="color: var(--text-primary);">
                                                <?php echo e($sender['value']); ?>
                                            </td>
                                            <td class="px-4 py-2" style="color: var(--text-secondary);">
                                                <?php echo $sender['user_name'] ? e($sender['user_name']) : '&mdash;'; ?>
                                            </td>
                                            <td class="px-4 py-2">
                                                <?php if ($sender['active']): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><?php echo e(t('Active')); ?></span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600"><?php echo e(t('Inactive')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-2 text-right">
                                                <button type="button" onclick="toggleAllowedSender(<?php echo (int)$sender['id']; ?>)" class="text-xs hover:underline mr-2" style="color: var(--text-muted);">
                                                    <?php echo $sender['active'] ? e(t('Disable')) : e(t('Enable')); ?>
                                                </button>
                                                <button type="button" onclick="deleteAllowedSender(<?php echo (int)$sender['id']; ?>)" class="text-xs text-red-600 hover:underline">
                                                    <?php echo e(t('Delete')); ?>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card card-body mb-2">
                    <h3 class="font-semibold mb-4" style="color: var(--text-primary);">
                        <?php echo e(t('Notification settings')); ?>
                    </h3>

                    <div class="space-y-4">
                        <div>
                            <label class="flex items-center space-x-3">
                                <input type="checkbox" name="email_notifications_enabled" <?php echo ($settings['email_notifications_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>
                                    class="w-5 h-5 rounded text-blue-500 focus:ring-blue-500">
                                <span class="font-medium"
                                    style="color: var(--text-primary);"><?php echo e(t('Enable email notifications')); ?></span>
                            </label>
                            <p class="text-sm ml-8" style="color: var(--text-muted);">
                                <?php echo e(t('Master switch for all email notifications.')); ?>
                            </p>
                        </div>

                        <hr class="my-4">

                        <div class="space-y-3">
                            <label class="flex items-center space-x-3">
                                <input type="checkbox" name="notify_on_status_change" <?php echo ($settings['notify_on_status_change'] ?? '1') === '1' ? 'checked' : ''; ?>
                                    class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                <span
                                    style="color: var(--text-secondary);"><?php echo e(t('Notify on status change')); ?></span>
                            </label>

                            <label class="flex items-center space-x-3">
                                <input type="checkbox" name="notify_on_new_comment" <?php echo ($settings['notify_on_new_comment'] ?? '1') === '1' ? 'checked' : ''; ?>
                                    class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                <span
                                    style="color: var(--text-secondary);"><?php echo e(t('Notify on new comment')); ?></span>
                            </label>

                            <label class="flex items-center space-x-3">
                                <input type="checkbox" name="notify_on_new_ticket" <?php echo ($settings['notify_on_new_ticket'] ?? '1') === '1' ? 'checked' : ''; ?>
                                    class="w-4 h-4 rounded text-blue-500 focus:ring-blue-500">
                                <span
                                    style="color: var(--text-secondary);"><?php echo e(t('Notify admins on new ticket')); ?></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex items-center space-x-4">
                    <button type="submit" name="save_email" class="btn btn-primary">
                        <?php echo e(t('Save settings')); ?>
                    </button>
                    <button type="submit" name="test_smtp" class="btn btn-secondary">
                        <?php echo get_icon('plug', 'mr-2'); ?>     <?php echo e(t('Save and test SMTP')); ?>
                    </button>
                    <button type="submit" name="test_imap" class="btn btn-secondary">
                        <?php echo get_icon('plug', 'mr-2'); ?>     <?php echo e(t('Save and test IMAP')); ?>
                    </button>
                    <button type="submit" name="run_imap_now" class="btn btn-secondary">
                        <?php echo get_icon('play', 'mr-2'); ?>     <?php echo e(t('Save and run IMAP now')); ?>
                    </button>
                </div>
            </form>

            <div class="card card-body">
                <div class="flex items-center justify-between mb-4 gap-4">
                    <div>
                        <h3 class="font-semibold" style="color: var(--text-primary);">
                            <?php echo e(t('Incoming email log')); ?>
                        </h3>
                        <p class="text-sm" style="color: var(--text-muted);">
                            <?php echo e(t('Last {count} processed/skipped/failed incoming emails.', ['count' => '100'])); ?>
                        </p>
                    </div>
                </div>

                <?php if (!empty($incoming_mail_log_error)): ?>
                    <div class="text-sm text-red-600">
                        <?php echo e(t('Incoming email log is not available: {error}', ['error' => $incoming_mail_log_error])); ?>
                    </div>
                <?php elseif (empty($incoming_mail_logs)): ?>
                    <div class="text-sm" style="color: var(--text-muted);"><?php echo e(t('No incoming email records yet.')); ?>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="text-xs uppercase border-b"
                                    style="background: var(--surface-secondary); color: var(--text-muted);">
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Time')); ?></th>
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Sender')); ?></th>
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Subject')); ?></th>
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Status')); ?></th>
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Ticket')); ?></th>
                                    <th class="px-4 py-3 font-medium"><?php echo e(t('Details')); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($incoming_mail_logs as $row): ?>
                                    <?php
                                    $status = (string) ($row['status'] ?? '');
                                    $status_class = 'bg-gray-100 text-gray-700';
                                    if ($status === 'processed') {
                                        $status_class = 'bg-green-100 text-green-700';
                                    } elseif ($status === 'skipped') {
                                        $status_class = 'bg-yellow-100 text-yellow-700';
                                    } elseif ($status === 'failed') {
                                        $status_class = 'bg-red-100 text-red-700';
                                    }
                                    $ticket_id = isset($row['ticket_id']) ? (int) $row['ticket_id'] : 0;
                                    $ticket_url_value = '';
                                    if ($ticket_id > 0) {
                                        if (!empty($row['ticket_hash'])) {
                                            $ticket_url_value = url('ticket', ['t' => $row['ticket_hash']]);
                                        } else {
                                            $ticket_url_value = url('ticket', ['id' => $ticket_id]);
                                        }
                                    }
                                    ?>
                                    <tr class="tr-hover text-sm">
                                        <td class="px-4 py-3 whitespace-nowrap" style="color: var(--text-muted);">
                                            <?php echo e(date('Y-m-d H:i:s', strtotime($row['created_at']))); ?>
                                        </td>
                                        <td class="px-4 py-3" style="color: var(--text-secondary);">
                                            <?php echo e($row['sender_email'] ?? '-'); ?>
                                        </td>
                                        <td class="px-4 py-3 max-w-xs truncate" style="color: var(--text-secondary);"
                                            title="<?php echo e($row['subject'] ?? ''); ?>">
                                            <?php echo e($row['subject'] ?? '-'); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="px-2 py-1 rounded-full text-xs font-medium <?php echo e($status_class); ?>">
                                                <?php echo e(t(ucfirst($status))); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3" style="color: var(--text-secondary);">
                                            <?php if ($ticket_id > 0 && $ticket_url_value !== ''): ?>
                                                <a class="text-blue-600 hover:text-blue-800" href="<?php echo e($ticket_url_value); ?>">
                                                    #<?php echo e((string) $ticket_id); ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3" style="color: var(--text-secondary);">
                                            <?php if (!empty($row['reason'])): ?>
                                                <div><?php echo e($row['reason']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($row['error'])): ?>
                                                <div class="text-xs text-red-600 mt-1"><?php echo e($row['error']); ?></div>
                                            <?php endif; ?>
                                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                                <?php echo e((string) ($row['mailbox'] ?? '')); ?> / UID
                                                <?php echo e((string) ($row['uid'] ?? '')); ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($tab === 'templates'): ?>
        <!-- Email Templates -->
        <?php
        // Template info with available variables
        $template_info = [
            'status_change' => [
                'name' => t('Status change'),
                'description' => t('Sent to requester when ticket status changes.'),
                'variables' => [
                    '{ticket_id}' => t('Ticket ID'),
                    '{ticket_title}' => t('Ticket subject'),
                    '{old_status}' => t('Previous status'),
                    '{new_status}' => t('New status'),
                    '{comment_text}' => t('Comment text (if any)'),
                    '{time_spent}' => t('Time spent'),
                    '{ticket_url}' => t('Ticket URL'),
                    '{app_name}' => t('App name')
                ]
            ],
            'new_comment' => [
                'name' => t('New comment'),
                'description' => t('Sent to requester when a new comment is added.'),
                'variables' => [
                    '{ticket_id}' => t('Ticket ID'),
                    '{ticket_title}' => t('Ticket subject'),
                    '{comment_text}' => t('Comment text'),
                    '{commenter_name}' => t('Comment author name'),
                    '{time_spent}' => t('Time spent'),
                    '{attachments}' => t('Attachment list'),
                    '{ticket_url}' => t('Ticket URL'),
                    '{comment_url}' => t('Comment URL'),
                    '{app_name}' => t('App name')
                ]
            ],
            'new_ticket' => [
                'name' => t('New ticket'),
                'description' => t('Sent to admins when a new ticket is created.'),
                'variables' => [
                    '{ticket_id}' => t('Ticket ID'),
                    '{ticket_title}' => t('Ticket subject'),
                    '{ticket_type}' => t('Ticket type'),
                    '{priority}' => t('Priority'),
                    '{user_name}' => t('Requester name'),
                    '{user_email}' => t('Requester email'),
                    '{description}' => t('Ticket description'),
                    '{ticket_url}' => t('Ticket URL'),
                    '{app_name}' => t('App name')
                ]
            ],
            'password_reset' => [
                'name' => t('Password reset'),
                'description' => t('Sent when a password reset is requested.'),
                'variables' => [
                    '{name}' => t('User name'),
                    '{reset_link}' => t('Reset link'),
                    '{app_name}' => t('App name')
                ]
            ],
            'ticket_confirmation' => [
                'name' => t('Ticket received'),
                'description' => t('Sent to requester after a new ticket is created.'),
                'variables' => [
                    '{ticket_id}' => t('Ticket ID'),
                    '{ticket_code}' => t('Ticket code (e.g., TK-0003)'),
                    '{ticket_title}' => t('Ticket subject'),
                    '{ticket_url}' => t('Ticket URL'),
                    '{app_name}' => t('App name')
                ]
            ],
            'ticket_assignment' => [
                'name' => t('Ticket assignment'),
                'description' => t('Sent to agents when a ticket is assigned.'),
                'variables' => [
                    '{ticket_id}' => t('Ticket ID'),
                    '{ticket_code}' => t('Ticket code (e.g., TK-0003)'),
                    '{ticket_title}' => t('Ticket subject'),
                    '{agent_name}' => t('Agent first name'),
                    '{agent_full_name}' => t('Agent full name'),
                    '{assigner_name}' => t('Assigner name'),
                    '{ticket_url}' => t('Ticket URL'),
                    '{app_name}' => t('App name')
                ]
            ],
            'recurring_task_assignment' => [
                'name' => t('Recurring task assignment'),
                'description' => t('Sent when a recurring task creates a new ticket assigned to a user.'),
                'variables' => [
                    '{ticket_id}' => t('Ticket ID'),
                    '{ticket_code}' => t('Ticket code'),
                    '{ticket_title}' => t('Task title'),
                    '{ticket_description}' => t('Task description'),
                    '{due_date}' => t('Due date'),
                    '{ticket_url}' => t('Ticket URL'),
                    '{recipient_name}' => t('Recipient name'),
                    '{app_name}' => t('App name')
                ]
            ],
            'long_timer_alert' => [
                'name' => t('Long timer alert'),
                'description' => t('Sent when a user\'s timer has been running for too long.'),
                'variables' => [
                    '{user_name}' => t('User name'),
                    '{ticket_id}' => t('Ticket ID'),
                    '{ticket_code}' => t('Ticket code'),
                    '{ticket_title}' => t('Ticket title'),
                    '{elapsed_time}' => t('Elapsed time'),
                    '{started_at}' => t('Timer start time'),
                    '{ticket_url}' => t('Ticket URL'),
                    '{app_name}' => t('App name')
                ]
            ],
            'welcome_email' => [
                'name' => t('Welcome email'),
                'description' => t('Sent to new users with their login credentials when "Send login credentials via email" is checked.'),
                'variables' => [
                    '{name}' => t('User first name'),
                    '{email}' => t('User email'),
                    '{password}' => t('User password'),
                    '{login_url}' => t('Login URL'),
                    '{app_name}' => t('App name')
                ]
            ]
        ];

        // Default template content for missing templates
        $default_templates = [
            'status_change' => [
                'en' => [
                    'subject' => 'Status changed for ticket #{ticket_id}: {ticket_title}',
                    'body' => "Hello,\n\nThe status of your ticket \"{ticket_title}\" has changed.\n\nPrevious status: {old_status}\nNew status: {new_status}\n\nComment:\n{comment_text}\n\nTime spent: {time_spent}\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
                ],
                'cs' => [
                    'subject' => 'Stav změněn u požadavku #{ticket_id}: {ticket_title}',
                    'body' => "Dobrý den,\n\nStav vašeho požadavku \"{ticket_title}\" byl změněn.\n\nPředchozí stav: {old_status}\nNový stav: {new_status}\n\nKomentář:\n{comment_text}\n\nStrávený čas: {time_spent}\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n{app_name}"
                ]
            ],
            'new_comment' => [
                'en' => [
                    'subject' => 'New comment on ticket #{ticket_id}: {ticket_title}',
                    'body' => "Hello,\n\nA new comment was added to your ticket \"{ticket_title}\".\n\nFrom: {commenter_name}\nTime spent: {time_spent}\nAttachments: {attachments}\n\n---\n{comment_text}\n---\n\nView comment: {comment_url}\n\nRegards,\n{app_name}"
                ],
                'cs' => [
                    'subject' => 'Nový komentář u požadavku #{ticket_id}: {ticket_title}',
                    'body' => "Dobrý den,\n\nK vašemu požadavku \"{ticket_title}\" byl přidán nový komentář.\n\nOd: {commenter_name}\nStrávený čas: {time_spent}\nPřílohy: {attachments}\n\n---\n{comment_text}\n---\n\nZobrazit komentář: {comment_url}\n\nS pozdravem,\n{app_name}"
                ]
            ],
            'new_ticket' => [
                'en' => [
                    'subject' => 'New ticket #{ticket_id}: {ticket_title}',
                    'body' => "Hello,\n\nA new ticket has been created.\n\nSubject: {ticket_title}\nType: {ticket_type}\nPriority: {priority}\nFrom: {user_name} ({user_email})\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
                ],
                'cs' => [
                    'subject' => 'Nový požadavek #{ticket_id}: {ticket_title}',
                    'body' => "Dobrý den,\n\nByl vytvořen nový požadavek.\n\nPředmět: {ticket_title}\nTyp: {ticket_type}\nPriorita: {priority}\nOd: {user_name} ({user_email})\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n{app_name}"
                ]
            ],
            'password_reset' => [
                'en' => [
                    'subject' => 'Password reset',
                    'body' => "Hello,\n\nYou requested a password reset. Click the link below:\n{reset_link}\n\nThis link is valid for 1 hour.\n\nIf you did not request a password reset, please ignore this email.\n\nRegards,\n{app_name}"
                ],
                'cs' => [
                    'subject' => 'Obnovení hesla',
                    'body' => "Dobrý den,\n\nPožádali jste o obnovení hesla. Klikněte na odkaz níže:\n{reset_link}\n\nTento odkaz je platný 1 hodinu.\n\nPokud jste o obnovení hesla nežádali, ignorujte tento e-mail.\n\nS pozdravem,\n{app_name}"
                ]
            ],
            'ticket_confirmation' => [
                'en' => [
                    'subject' => 'Ticket received #{ticket_code}: {ticket_title}',
                    'body' => "Hello,\n\nYour ticket #{ticket_code} \"{ticket_title}\" was received successfully.\nWe will keep you updated on its progress.\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
                ],
                'cs' => [
                    'subject' => 'Požadavek přijat #{ticket_code}: {ticket_title}',
                    'body' => "Dobrý den,\n\nVáš požadavek #{ticket_code} \"{ticket_title}\" jsme úspěšně přijali.\nO jeho průběhu vás budeme informovat.\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n{app_name}"
                ]
            ],
            'ticket_assignment' => [
                'en' => [
                    'subject' => 'Ticket assigned #{ticket_code}: {ticket_title}',
                    'body' => "Hello {agent_name},\n\nYou have been assigned a ticket to handle:\n\nTicket: #{ticket_code}\nSubject: {ticket_title}\nAssigned by: {assigner_name}\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
                ],
                'cs' => [
                    'subject' => 'Přiřazen požadavek #{ticket_code}: {ticket_title}',
                    'body' => "Dobrý den {agent_name},\n\nByl vám přiřazen požadavek k řešení:\n\nPožadavek: #{ticket_code}\nPředmět: {ticket_title}\nPřiřadil: {assigner_name}\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n{app_name}"
                ]
            ],
            'recurring_task_assignment' => [
                'en' => [
                    'subject' => 'New recurring task assigned: {ticket_title}',
                    'body' => "Hello {recipient_name},\n\nA recurring task generated a new ticket for you.\n\nTicket: #{ticket_code}\nTitle: {ticket_title}\nDescription: {ticket_description}\nDue date: {due_date}\n\nView ticket: {ticket_url}\n\nRegards,\n{app_name}"
                ],
                'cs' => [
                    'subject' => 'Přiřazen nový opakující se úkol: {ticket_title}',
                    'body' => "Dobrý den {recipient_name},\n\nOpakující se úkol pro vás vytvořil nový požadavek.\n\nPožadavek: #{ticket_code}\nNázev: {ticket_title}\nPopis: {ticket_description}\nTermín: {due_date}\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n{app_name}"
                ]
            ],
            'long_timer_alert' => [
                'en' => [
                    'subject' => 'Timer Running Too Long - Ticket #{ticket_code}',
                    'body' => "Dear {user_name},\n\nYour time tracker has been running for {elapsed_time} on ticket \"{ticket_title}\".\n\nStarted at: {started_at}\nTicket: #{ticket_code} - {ticket_title}\n\nPlease check if you forgot to stop the timer.\n\nView ticket: {ticket_url}\n\nSincerely,\n\nThe {app_name} Team"
                ],
                'cs' => [
                    'subject' => 'Časovač běží příliš dlouho - Požadavek #{ticket_code}',
                    'body' => "Dobrý den,\n\nVáš časovač běží již {elapsed_time} na požadavku \"{ticket_title}\".\n\nSpuštěn v: {started_at}\nPožadavek: #{ticket_code} - {ticket_title}\n\nProsím zkontrolujte, zda jste nezapomněli zastavit časovač.\n\nZobrazit požadavek: {ticket_url}\n\nS pozdravem,\n\nTým {app_name}"
                ],
                'de' => [
                    'subject' => 'Timer läuft zu lange - Ticket #{ticket_code}',
                    'body' => "Guten Tag,\n\nIhr Zeiterfassungs-Timer läuft bereits {elapsed_time} für das Ticket \"{ticket_title}\".\n\nGestartet um: {started_at}\nTicket: #{ticket_code} - {ticket_title}\n\nBitte überprüfen Sie, ob Sie vergessen haben, den Timer zu stoppen.\n\nTicket anzeigen: {ticket_url}\n\nMit freundlichen Grüßen,\n\nIhr {app_name} Team"
                ],
                'it' => [
                    'subject' => 'Timer in esecuzione troppo a lungo - Ticket #{ticket_code}',
                    'body' => "Buongiorno,\n\nIl suo timer sta girando da {elapsed_time} sul ticket \"{ticket_title}\".\n\nAvviato alle: {started_at}\nTicket: #{ticket_code} - {ticket_title}\n\nPer favore verifichi se ha dimenticato di fermare il timer.\n\nVisualizza ticket: {ticket_url}\n\nCordiali saluti,\n\nIl team {app_name}"
                ],
                'es' => [
                    'subject' => 'Temporizador funcionando demasiado tiempo - Ticket #{ticket_code}',
                    'body' => "Estimado/a {user_name},\n\nSu temporizador ha estado funcionando durante {elapsed_time} en el ticket \"{ticket_title}\".\n\nIniciado a las: {started_at}\nTicket: #{ticket_code} - {ticket_title}\n\nPor favor, compruebe si olvidó detener el temporizador.\n\nVer ticket: {ticket_url}\n\nAtentamente,\n\nEl equipo de {app_name}"
                ]
            ],
            'welcome_email' => [
                'en' => [
                    'subject' => 'Welcome to {app_name}',
                    'body' => "Hello {name},\n\nYour account has been created.\n\nEmail: {email}\nPassword: {password}\n\nLogin: {login_url}\n\nAfter signing in, you can change your password in your profile settings.\n\nRegards,\n{app_name}"
                ],
                'cs' => [
                    'subject' => 'Vítejte v {app_name}',
                    'body' => "Dobrý den {name},\n\nVáš účet byl vytvořen.\n\nEmail: {email}\nHeslo: {password}\n\nPřihlášení: {login_url}\n\nPo přihlášení si můžete změnit heslo v nastavení profilu.\n\nS pozdravem,\n{app_name}"
                ],
                'de' => [
                    'subject' => 'Willkommen bei {app_name}',
                    'body' => "Hallo {name},\n\nIhr Konto wurde erstellt.\n\nE-Mail: {email}\nPasswort: {password}\n\nAnmeldung: {login_url}\n\nNach der Anmeldung können Sie Ihr Passwort in Ihren Profileinstellungen ändern.\n\nMit freundlichen Grüßen,\n{app_name}"
                ],
                'it' => [
                    'subject' => 'Benvenuto in {app_name}',
                    'body' => "Ciao {name},\n\nIl tuo account è stato creato.\n\nEmail: {email}\nPassword: {password}\n\nAccesso: {login_url}\n\nDopo aver effettuato l'accesso, puoi modificare la password nelle impostazioni del profilo.\n\nCordiali saluti,\n{app_name}"
                ],
                'es' => [
                    'subject' => 'Bienvenido a {app_name}',
                    'body' => "Hola {name},\n\nSu cuenta ha sido creada.\n\nCorreo electrónico: {email}\nContraseña: {password}\n\nIniciar sesión: {login_url}\n\nDespués de iniciar sesión, puede cambiar su contraseña en la configuración de su perfil.\n\nSaludos,\n{app_name}"
                ]
            ]
        ];

        // Ensure all known templates are listed even if not in DB for this language yet
        // Merge DB templates with default structure
        $template_map = [];
        foreach ($templates as $t) {
            $template_map[$t['template_key']] = $t;
        }

        $english_template_map = [];
        try {
            $english_templates = db_fetch_all("
            SELECT template_key, subject, body
            FROM email_templates
            WHERE language = 'en'
        ");
            foreach ($english_templates as $template_row) {
                $english_template_map[$template_row['template_key']] = $template_row;
            }
        } catch (Throwable $e) {
            $english_template_map = [];
        }

        $display_templates = [];
        foreach ($template_info as $key => $info) {
            if (isset($template_map[$key])) {
                $display_templates[] = $template_map[$key];
            } else {
                // create placeholder with default content if available
                $default_subject = '';
                $default_body = '';
                if (isset($default_templates[$key][$template_lang])) {
                    $default_subject = $default_templates[$key][$template_lang]['subject'];
                    $default_body = $default_templates[$key][$template_lang]['body'];
                } elseif (isset($english_template_map[$key])) {
                    $default_subject = $english_template_map[$key]['subject'];
                    $default_body = $english_template_map[$key]['body'];
                } elseif (isset($default_templates[$key]['en'])) {
                    $default_subject = $default_templates[$key]['en']['subject'];
                    $default_body = $default_templates[$key]['en']['body'];
                }
                $display_templates[] = [
                    'template_key' => $key,
                    'subject' => $default_subject,
                    'body' => $default_body,
                    'language' => $template_lang
                ];
            }
        }
        ?>

        <div class="mb-2 flex justify-between items-center">
            <h3 class="font-semibold" style="color: var(--text-primary);"><?php echo e(t('Email Templates')); ?></h3>

            <form action="" method="get" class="flex items-center space-x-2">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="section" value="settings">
                <input type="hidden" name="tab" value="templates">

                <label class="text-sm" style="color: var(--text-secondary);"><?php echo e(t('Language:')); ?></label>
                <select name="lang" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                    <option value="en" <?php echo $template_lang === 'en' ? 'selected' : ''; ?>><?php echo e(t('English')); ?>
                    </option>
                    <option value="cs" <?php echo $template_lang === 'cs' ? 'selected' : ''; ?>><?php echo e(t('Czech')); ?>
                    </option>
                    <option value="de" <?php echo $template_lang === 'de' ? 'selected' : ''; ?>><?php echo e(t('German')); ?>
                    </option>
                    <option value="it" <?php echo $template_lang === 'it' ? 'selected' : ''; ?>><?php echo e(t('Italian')); ?>
                    </option>
                    <option value="es" <?php echo $template_lang === 'es' ? 'selected' : ''; ?>><?php echo e(t('Spanish')); ?>
                    </option>
                </select>
            </form>
        </div>

        <div class="space-y-3">
            <?php foreach ($display_templates as $template):
                $info = $template_info[$template['template_key']] ?? null;
                ?>
                <div class="card overflow-hidden">
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="template_key" value="<?php echo e($template['template_key']); ?>">
                        <input type="hidden" name="template_lang" value="<?php echo e($template_lang); ?>">

                        <div class="px-6 py-3 border-b" style="background: var(--surface-secondary);">
                            <div>
                                <h4 class="font-semibold" style="color: var(--text-primary);">
                                    <?php echo e($info['name'] ?? $template['template_key']); ?>
                                </h4>
                                <?php if ($info): ?>
                                    <p class="text-sm" style="color: var(--text-muted);"><?php echo e($info['description']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="p-6">
                            <?php if ($info && !empty($info['variables'])): ?>
                                <div class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                    <div class="text-sm font-medium text-blue-800 mb-2"><?php echo e(t('Available variables:')); ?>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($info['variables'] as $var => $desc): ?>
                                            <span class="inline-flex items-center border border-blue-200 rounded px-2 py-1 text-xs"
                                                style="background: var(--bg-primary);" title="<?php echo e($desc); ?>">
                                                <code class="text-blue-600"><?php echo e($var); ?></code>
                                                <span class="ml-1" style="color: var(--text-muted);">- <?php echo e($desc); ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium mb-1"
                                        style="color: var(--text-secondary);"><?php echo e(t('Email subject')); ?></label>
                                    <input type="text" name="template_subject" value="<?php echo e($template['subject']); ?>"
                                        class="form-input">
                                    <p class="text-xs mt-1" style="color: var(--text-muted);">
                                        <?php echo e(t('You can use variables in the subject, e.g. {ticket_title}.')); ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium mb-1"
                                        style="color: var(--text-secondary);"><?php echo e(t('Email body')); ?></label>
                                    <textarea name="template_body" rows="8"
                                        class="form-textarea font-mono text-sm"><?php echo e($template['body']); ?></textarea>
                                </div>
                            </div>
                            <div class="mt-4 flex justify-end">
                                <button type="submit" name="save_template" class="btn btn-primary btn-sm">
                                    <?php echo get_icon('save', 'mr-1'); ?>         <?php echo e(t('Save')); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($tab === 'system'): ?>
        <!-- System Info -->
        <div class="card card-body">
            <h3 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">
                <?php echo e(t('System information')); ?>
            </h3>

            <dl class="space-y-0 divide-y" style="border-color: var(--border-light);">
                <div class="flex justify-between py-1.5">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('App version')); ?></dt>
                    <dd class="text-xs font-medium"><?php echo e(defined('APP_VERSION') ? APP_VERSION : '-'); ?></dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('PHP version')); ?></dt>
                    <dd class="text-xs font-medium"><?php echo e(phpversion()); ?></dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('MySQL version')); ?></dt>
                    <dd class="text-xs font-medium">
                        <?php
                        $version = db_fetch_one("SELECT VERSION() as v");
                        echo e($version['v']);
                        ?>
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Application URL')); ?></dt>
                    <dd class="text-xs font-medium"><?php echo e(defined('APP_URL') ? APP_URL : '-'); ?></dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('User count')); ?></dt>
                    <dd class="text-xs font-medium">
                        <?php echo db_fetch_one("SELECT COUNT(*) as c FROM users")['c']; ?>
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('Ticket count')); ?></dt>
                    <dd class="text-xs font-medium">
                        <?php echo db_fetch_one("SELECT COUNT(*) as c FROM tickets")['c']; ?>
                    </dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('PHP upload limit')); ?></dt>
                    <dd class="text-xs font-medium"><?php echo ini_get('upload_max_filesize'); ?></dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-xs" style="color: var(--text-muted);"><?php echo e(t('App upload limit')); ?></dt>
                    <dd class="text-xs font-medium"><?php echo e(($settings['max_upload_size'] ?? '10') . ' MB'); ?></dd>
                </div>
            </dl>
        </div>


        <?php
        // Auto-remove install.php if it still exists (no longer needed after setup)
        if (file_exists(BASE_PATH . '/install.php')) {
            @unlink(BASE_PATH . '/install.php');
        }
        ?>

        <?php
        // Post-update health check — show only right after a successful update
        // (detected by flash message containing "Update applied" or "Rollback")
        $flash_msg = $_SESSION['flash']['message'] ?? '';
        $show_health = (
            stripos($flash_msg, 'Update applied') !== false
            || stripos($flash_msg, 'Update installed') !== false
            || stripos($flash_msg, 'Rollback') !== false
        );
        if ($show_health && function_exists('post_update_health_check')):
            $health = post_update_health_check();
        ?>
            <?php if ($health['ok']): ?>
                <div class="mt-2 rounded-lg px-3 py-2 flex items-center gap-2" style="background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.3);">
                    <?php echo get_icon('check-circle', 'w-4 h-4 flex-shrink-0'); ?>
                    <p class="text-xs" style="color: var(--text-primary);">
                        <span class="font-semibold" style="color: #16a34a;"><?php echo e(t('Health check passed')); ?></span>
                        — <?php echo e(t('Database, files, session, and uploads all OK.')); ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="mt-2 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                    <div class="flex items-center gap-2">
                        <?php echo get_icon('exclamation-triangle', 'w-4 h-4 text-red-600 flex-shrink-0'); ?>
                        <p class="text-red-700 text-xs font-semibold"><?php echo e(t('Post-update health check found issues:')); ?></p>
                    </div>
                    <ul class="mt-1 text-xs text-red-600 list-disc list-inside ml-5">
                        <?php foreach ($health['errors'] as $err): ?>
                            <li><?php echo e($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Auto-Update Check Section -->
        <div class="card card-body mt-2" id="updates">
            <h3 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">
                <?php echo e(t('Automatic updates')); ?>
            </h3>

            <?php $remote_update = get_cached_update_info(); ?>

            <?php if ($remote_update): ?>
                <!-- Remote Update Available -->
                <div class="rounded-lg px-3 py-2 mb-3 border"
                    style="background: var(--surface-secondary); border-color: var(--primary);">
                    <div class="flex items-start gap-2">
                        <span class="flex-shrink-0 mt-0.5" style="color: var(--primary);"><?php echo get_icon('arrow-circle-up', 'w-5 h-5'); ?></span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-semibold" style="color: var(--text-primary);">
                                    FoxDesk <?php echo e($remote_update['version']); ?>         <?php echo e(t('is available')); ?>
                                </span>
                                <?php if (!empty($remote_update['released_at'])): ?>
                                    <span class="text-xs" style="color: var(--text-muted);">
                                        <?php echo e(t('Released')); ?>: <?php echo e($remote_update['released_at']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($remote_update['changelog'])): ?>
                                <ul class="mt-1.5 text-xs space-y-0.5" style="color: var(--text-secondary);">
                                    <?php foreach ($remote_update['changelog'] as $change): ?>
                                        <li>— <?php echo e($change); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <div class="mt-2 flex items-center gap-2">
                                <form method="post" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="install_remote_update" value="1">
                                    <button type="submit" class="btn btn-primary btn-sm text-xs"
                                        onclick="if(!confirm('<?php echo e(t('Install update? A backup will be created automatically.')); ?>'))return false; this.disabled=true; this.textContent='<?php echo e(t('Installing...')); ?>'; this.form.submit();">
                                        <?php echo get_icon('cloud-download-alt', 'mr-1'); ?>
                                        <?php echo e(t('Install update')); ?>
                                    </button>
                                </form>
                                <span class="text-[11px]" style="color: var(--text-muted);">
                                    <?php echo e(t('Current version')); ?>:
                                    <?php echo e(defined('APP_VERSION') ? APP_VERSION : '-'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Auto-Update Settings -->
            <div class="flex items-center justify-between gap-3 mb-2">
                <form method="post" class="flex items-center gap-3 flex-1">
                    <?php echo csrf_field(); ?>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="update_check_enabled" value="1" <?php echo is_update_check_enabled() ? 'checked' : ''; ?> onchange="this.form.submit();" class="form-checkbox rounded"
                            style="width: 16px; height: 16px;">
                        <span class="text-xs"
                            style="color: var(--text-secondary);"><?php echo e(t('Automatically check for updates')); ?></span>
                    </label>
                    <input type="hidden" name="save_update_check_settings" value="1">
                </form>
                <form method="post" class="flex-shrink-0">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="check_updates_now" class="btn btn-secondary btn-sm text-xs">
                        <?php echo get_icon('sync', 'mr-1'); ?>     <?php echo e(t('Check now')); ?>
                    </button>
                </form>
            </div>
            <?php
            $last_check = get_last_update_check_time();
            if ($last_check): ?>
                <p class="text-[11px] mb-1" style="color: var(--text-muted);">
                    <?php echo e(t('Last checked')); ?>: <?php echo e($last_check); ?>
                    <?php if (!$remote_update): ?>
                        — <span style="color: var(--success);"><?php echo e(t('You are running the latest version.')); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Pseudo-cron (Background Tasks) -->
        <div class="card card-body mt-2">
            <h3 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">
                <?php echo e(t('Background tasks')); ?>
            </h3>
            <p class="text-xs mb-2" style="color: var(--text-muted);">
                <?php echo e(t('Automatically run email ingestion, recurring tasks, and maintenance on page loads — no server cron required.')); ?>
            </p>

            <div class="flex items-center justify-between gap-3 mb-2">
                <form method="post" class="flex items-center gap-3 flex-1">
                    <?php echo csrf_field(); ?>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="pseudo_cron_enabled" value="1"
                            <?php echo get_setting('pseudo_cron_enabled') ? 'checked' : ''; ?>
                            onchange="this.form.submit();" class="form-checkbox rounded"
                            style="width: 16px; height: 16px;">
                        <span class="text-xs" style="color: var(--text-secondary);">
                            <?php echo e(t('Enable background tasks')); ?>
                        </span>
                    </label>
                    <input type="hidden" name="save_pseudo_cron_settings" value="1">
                </form>
            </div>

            <?php if (get_setting('pseudo_cron_enabled')): ?>
                <div class="space-y-0 divide-y text-[11px]" style="border-color: var(--border-light); color: var(--text-muted);">
                    <?php
                    $cron_tasks = [
                        ['key' => 'pseudo_cron_last_email',       'label' => t('Email ingestion'),  'interval' => t('every 5 min')],
                        ['key' => 'pseudo_cron_last_recurring',   'label' => t('Recurring tasks'),  'interval' => t('every 60 min')],
                        ['key' => 'pseudo_cron_last_maintenance', 'label' => t('Maintenance'),      'interval' => t('every 24 hours')],
                    ];
                    foreach ($cron_tasks as $ct):
                        $last = get_setting($ct['key'], '');
                        $last_fmt = $last ? date('Y-m-d H:i:s', (int) $last) : '—';
                    ?>
                        <div class="flex justify-between py-1">
                            <span><?php echo e($ct['label']); ?> <span style="opacity:.6">(<?php echo e($ct['interval']); ?>)</span></span>
                            <span><?php echo e($last_fmt); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-[11px] mt-2" style="color: var(--text-muted);">
                    <?php echo e(t('Tasks run in the background on page loads. No server cron job needed.')); ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Application Updates Section (Manual Upload) -->
        <div class="card card-body mt-2">
            <h3 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">
                <?php echo e(t('Manual update')); ?>
            </h3>

            <?php
            // Check for pending update
            $pending_update = $_SESSION['pending_update'] ?? null;
            $backups = get_backups();
            $update_history = get_update_history();
            $backup_creator_names = [];
            $backup_creator_ids = [];
            foreach ($backups as $backup_row) {
                $creator_id = (int) ($backup_row['created_by_user_id'] ?? 0);
                if ($creator_id > 0) {
                    $backup_creator_ids[$creator_id] = $creator_id;
                }
            }
            if (!empty($backup_creator_ids)) {
                $creator_placeholders = implode(',', array_fill(0, count($backup_creator_ids), '?'));
                $creator_rows = db_fetch_all(
                    "SELECT id, first_name, last_name, email FROM users WHERE id IN ($creator_placeholders)",
                    array_values($backup_creator_ids)
                );
                foreach ($creator_rows as $creator_row) {
                    $creator_name = trim((string) (($creator_row['first_name'] ?? '') . ' ' . ($creator_row['last_name'] ?? '')));
                    if ($creator_name === '') {
                        $creator_name = (string) ($creator_row['email'] ?? ('#' . $creator_row['id']));
                    }
                    $backup_creator_names[(int) $creator_row['id']] = $creator_name;
                }
            }
            $update_history_users = [];
            $history_user_ids = [];
            foreach ($update_history as $history_entry) {
                $uid = (int) ($history_entry['user_id'] ?? 0);
                if ($uid > 0) {
                    $history_user_ids[$uid] = $uid;
                }
            }
            if (!empty($history_user_ids)) {
                $placeholders = implode(',', array_fill(0, count($history_user_ids), '?'));
                $history_user_rows = db_fetch_all(
                    "SELECT id, first_name, last_name, email FROM users WHERE id IN ($placeholders)",
                    array_values($history_user_ids)
                );
                foreach ($history_user_rows as $history_user_row) {
                    $name = trim((string) (($history_user_row['first_name'] ?? '') . ' ' . ($history_user_row['last_name'] ?? '')));
                    if ($name === '') {
                        $name = (string) ($history_user_row['email'] ?? ('#' . $history_user_row['id']));
                    }
                    $update_history_users[(int) $history_user_row['id']] = $name;
                }
            }
            ?>

            <?php if ($pending_update): ?>
                <!-- Pending Update Confirmation -->
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 rounded-lg px-3 py-2 mb-2">
                    <div class="flex items-start gap-2">
                        <?php echo get_icon('info', 'w-4 h-4 text-blue-500 flex-shrink-0 mt-0.5'); ?>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span
                                    class="text-xs font-medium text-blue-800"><?php echo e(t('Update ready to install')); ?></span>
                                <span class="text-xs text-blue-600">v<?php echo e($pending_update['version']); ?></span>
                            </div>

                            <?php if (!empty($pending_update['changelog'])): ?>
                                <ul class="mt-1 text-xs text-blue-700 list-disc list-inside space-y-0.5">
                                    <?php foreach ($pending_update['changelog'] as $change): ?>
                                        <li><?php echo e($change); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <div class="mt-2 flex items-center gap-2">
                                <form method="post" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" name="apply_update" class="btn btn-primary btn-sm text-xs"
                                        onclick="return confirm('<?php echo e(t('A backup will be created before updating. Continue?')); ?>')">
                                        <?php echo get_icon('check', 'mr-1'); ?>         <?php echo e(t('Apply update')); ?>
                                    </button>
                                </form>
                                <form method="post" class="inline">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" name="cancel_update" class="btn btn-secondary btn-sm text-xs">
                                        <?php echo e(t('Cancel')); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Upload Update Package -->
                <form method="post" enctype="multipart/form-data" class="mb-2" id="update-form">
                    <?php echo csrf_field(); ?>
                    <div id="update-upload-zone"
                        class="rounded-lg px-3 py-2 cursor-pointer border-2 border-dashed hover:border-blue-300 transition-colors"
                        style="border-color: var(--border-light);">
                        <input type="file" name="update_package" id="update-file-input" accept=".zip" class="hidden" required>
                        <div class="flex items-center gap-2">
                            <span
                                style="color: var(--text-muted);"><?php echo get_icon('cloud-upload-alt', 'text-lg flex-shrink-0'); ?></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs" style="color: var(--text-secondary);">
                                    <span class="text-blue-500 font-medium"><?php echo e(t('Click')); ?></span>
                                    <?php echo e(t('or drag .zip file')); ?>
                                </p>
                                <p class="text-[11px]" style="color: var(--text-muted);" id="update-file-name">
                                    <?php echo e(t('No file selected')); ?>
                                </p>
                            </div>
                            <button type="submit" name="upload_update" class="btn btn-primary btn-sm text-xs flex-shrink-0"
                                id="update-upload-btn" disabled>
                                <?php echo get_icon('upload', 'mr-1'); ?>         <?php echo e(t('Upload')); ?>
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <!-- Create Manual Backup -->
            <div class="border-t pt-2 mb-2">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <span class="text-xs font-medium"
                            style="color: var(--text-primary);"><?php echo e(t('Manual backup')); ?></span>
                        <span class="text-[11px] ml-1"
                            style="color: var(--text-muted);"><?php echo e(t('Create a backup of application files and database.')); ?></span>
                    </div>
                    <form method="post" class="flex-shrink-0">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="create_backup" class="btn btn-secondary btn-sm text-xs"
                            onclick="return confirm('<?php echo e(t('Create a new backup?')); ?>')">
                            <?php echo get_icon('save', 'mr-1'); ?>     <?php echo e(t('Create backup')); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Available Backups -->
            <?php if (!empty($backups)): ?>
                <div class="border-t pt-2">
                    <h4 class="text-xs font-medium mb-1.5" style="color: var(--text-primary);">
                        <?php echo e(t('Available backups')); ?>
                    </h4>
                    <div class="space-y-1">
                        <?php foreach ($backups as $backup): ?>
                            <div class="flex items-center justify-between px-2.5 py-1.5 rounded-lg"
                                style="background: var(--surface-secondary);">
                                <div class="text-xs">
                                    <span class="font-medium"
                                        style="color: var(--text-primary);"><?php echo e($backup['version']); ?></span>
                                    <span class="ml-1" style="color: var(--text-muted);">
                                        <?php echo e(date('Y-m-d H:i', strtotime($backup['date']))); ?>
                                    </span>
                                    <?php
                                    $backup_creator_id = (int) ($backup['created_by_user_id'] ?? 0);
                                    $backup_creator_name = $backup_creator_names[$backup_creator_id] ?? '';
                                    ?>
                                    <?php if (!empty($backup['size'])): ?>
                                        <span class="ml-1"
                                            style="color: var(--text-muted);">(<?php echo e(format_filesize($backup['size'])); ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-1">
                                    <form method="post" class="inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="backup_id" value="<?php echo e($backup['id']); ?>">
                                        <input type="hidden" name="download_type" value="bundle">
                                        <button type="submit" name="download_backup" class="td-tool-btn"
                                            title="<?php echo e(t('Download backup package')); ?>">
                                            <?php echo get_icon('save', 'w-3.5 h-3.5'); ?>
                                        </button>
                                    </form>
                                    <?php if (!empty($backup['has_database'])): ?>
                                        <form method="post" class="inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="backup_id" value="<?php echo e($backup['id']); ?>">
                                            <input type="hidden" name="download_type" value="database">
                                            <button type="submit" name="download_backup" class="td-tool-btn"
                                                title="<?php echo e(t('Download database SQL')); ?>">
                                                <?php echo get_icon('file-alt', 'w-3.5 h-3.5'); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" class="inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="backup_id" value="<?php echo e($backup['id']); ?>">
                                        <button type="submit" name="rollback_update" class="td-tool-btn"
                                            title="<?php echo e(t('Restore')); ?>"
                                            onclick="return confirm('<?php echo e(t('Restore this backup? Current files will be overwritten.')); ?>')">
                                            <?php echo get_icon('refresh', 'w-3.5 h-3.5'); ?>
                                        </button>
                                    </form>
                                    <form method="post" class="inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="backup_id" value="<?php echo e($backup['id']); ?>">
                                        <button type="submit" name="delete_backup" class="td-tool-btn" style="color: var(--danger);"
                                            title="<?php echo e(t('Delete')); ?>"
                                            onclick="return confirm('<?php echo e(t('Delete this backup permanently?')); ?>')">
                                            <?php echo get_icon('trash', 'w-3.5 h-3.5'); ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Update History -->
            <?php if (!empty($update_history)): ?>
                <div class="border-t pt-2 mt-2">
                    <div class="flex items-center justify-between mb-1.5">
                        <h4 class="text-xs font-medium" style="color: var(--text-primary);">
                            <?php echo e(t('Update history')); ?>
                        </h4>
                        <form method="post" class="inline">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="clear_update_history"
                                class="text-[11px] text-red-500 hover:text-red-700"
                                onclick="return confirm('<?php echo e(t('Clear all update history?')); ?>')">
                                <?php echo get_icon('trash', 'w-3 h-3 mr-0.5 inline'); ?>         <?php echo e(t('Clear')); ?>
                            </button>
                        </form>
                    </div>
                    <div class="space-y-1 text-xs">
                        <?php foreach (array_slice($update_history, 0, 10) as $entry_index => $entry): ?>
                            <?php
                            $entry_changes = array_values(array_filter((array) ($entry['changelog'] ?? []), static function ($value) {
                                return is_string($value) && trim($value) !== '';
                            }));
                            $entry_messages = array_values(array_filter((array) ($entry['messages'] ?? []), static function ($value) {
                                return is_string($value) && trim($value) !== '';
                            }));
                            $entry_errors = array_values(array_filter((array) ($entry['errors'] ?? []), static function ($value) {
                                return is_string($value) && trim($value) !== '';
                            }));
                            $has_entry_details = !empty($entry_changes) || !empty($entry_messages) || !empty($entry_errors);
                            ?>
                            <details class="border rounded-lg px-2.5 py-1.5" style="background: var(--surface-secondary);" <?php echo $entry_index === 0 ? 'open' : ''; ?>>
                                <summary class="list-none cursor-pointer">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs" style="color: var(--text-secondary);">
                                            <span
                                                class="w-28 inline-block align-top font-medium"><?php echo e(date('Y-m-d H:i', strtotime($entry['date']))); ?></span>
                                            <?php if ($entry['action'] === 'update'): ?>
                                                <?php echo e(t('Updated to {version}', ['version' => $entry['version']])); ?>
                                            <?php elseif ($entry['action'] === 'rollback'): ?>
                                                <?php echo e(t('Rolled back to {version}', ['version' => $entry['version']])); ?>
                                            <?php elseif ($entry['action'] === 'backup'): ?>
                                                <?php echo e(t('Backup created: {version}', ['version' => $entry['version']])); ?>
                                            <?php endif; ?>
                                            <?php if ($entry['success']): ?>
                                                <span class="text-green-600 font-medium">(<?php echo e(t('success')); ?>)</span>
                                            <?php else: ?>
                                                <span class="text-red-600 font-medium">(<?php echo e(t('failed')); ?>)</span>
                                            <?php endif; ?>
                                            <?php
                                            $entry_user_id = (int) ($entry['user_id'] ?? 0);
                                            $entry_user_name = $update_history_users[$entry_user_id] ?? '';
                                            ?>
                                        </span>
                                        <?php if ($has_entry_details): ?>
                                            <span
                                                class="text-[11px] text-blue-600 font-medium shrink-0"><?php echo e(t('details')); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </summary>
                                <?php if ($has_entry_details): ?>
                                    <div class="mt-1.5 pt-1.5 border-t text-[11px] space-y-1" style="color: var(--text-secondary);">
                                        <?php if (!empty($entry_changes)): ?>
                                            <div>
                                                <div class="font-semibold" style="color: var(--text-primary);">
                                                    <?php echo e(t('Changes:')); ?>
                                                </div>
                                                <ul class="mt-1 list-disc list-inside space-y-0.5">
                                                    <?php foreach ($entry_changes as $change): ?>
                                                        <li><?php echo e($change); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($entry_messages)): ?>
                                            <div>
                                                <div class="font-semibold" style="color: var(--text-primary);">
                                                    <?php echo e(t('Messages')); ?>
                                                </div>
                                                <ul class="mt-1 list-disc list-inside space-y-0.5">
                                                    <?php foreach ($entry_messages as $message): ?>
                                                        <li><?php echo e($message); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($entry_errors)): ?>
                                            <div>
                                                <div class="font-semibold text-red-700"><?php echo e(t('Errors')); ?></div>
                                                <ul class="mt-1 list-disc list-inside space-y-0.5 text-red-700">
                                                    <?php foreach ($entry_errors as $entry_error): ?>
                                                        <li><?php echo e($entry_error); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-2 text-xs" style="color: var(--text-muted);">
                                        <?php echo e(t('No recorded changes for this update.')); ?>
                                    </div>
                                <?php endif; ?>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Upload Settings -->
        <div class="card card-body mt-2">
            <h3 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-muted);">
                <?php echo e(t('Upload settings')); ?>
            </h3>
            <form method="post">
                <?php echo csrf_field(); ?>
                <div class="max-w-xs">
                    <label class="block text-xs font-medium mb-1"
                        style="color: var(--text-secondary);"><?php echo e(t('Max upload size (MB)')); ?></label>
                    <input type="number" name="max_upload_size"
                        value="<?php echo e($settings['max_upload_size'] ?? '10'); ?>" min="1" max="100" class="form-input">
                    <p class="text-xs mt-1" style="color: var(--text-muted);">
                        <?php echo e(t('Maximum file size for attachments (1-100 MB).')); ?>
                    </p>
                    <p class="text-xs" style="color: var(--text-muted);">
                        <?php echo e(t('Note: PHP limit is {limit}. Files larger than this will fail regardless of app setting.', ['limit' => ini_get('upload_max_filesize')])); ?>
                    </p>
                </div>
                <button type="submit" name="save_upload_settings" class="btn btn-primary btn-sm mt-3">
                    <?php echo e(t('Save')); ?>
                </button>
            </form>
        </div>

    <?php elseif ($tab === 'logs'): ?>
        <?php
        $page_num = max(1, (int) ($_GET['p'] ?? 1));
        $per_page = 50;
        $offset = ($page_num - 1) * $per_page;

        $debug_log_available = false;
        $total_logs = 0;
        $total_pages = 1;
        $logs = [];
        try {
            $debug_log_available = (bool) db_fetch_one("SHOW TABLES LIKE 'debug_log'");
            if ($debug_log_available) {
                $total_logs = (int) (db_fetch_one("SELECT COUNT(*) as c FROM debug_log")['c'] ?? 0);
                $total_pages = (int) ceil(max(1, $total_logs) / $per_page);
                $logs = db_fetch_all("
                SELECT l.*, u.first_name, u.last_name, u.email
                FROM debug_log l
                LEFT JOIN users u ON l.user_id = u.id
                ORDER BY l.created_at DESC
                LIMIT ? OFFSET ?
            ", [(int) $per_page, (int) $offset]);
            }
        } catch (Throwable $e) {
            $debug_log_available = false;
        }

        $security_log_available = security_log_table_exists();
        $security_logs = [];
        if ($security_log_available) {
            $security_logs = db_fetch_all("
            SELECT s.*, u.first_name, u.last_name, u.email
            FROM security_log s
            LEFT JOIN users u ON s.user_id = u.id
            ORDER BY s.created_at DESC
            LIMIT 100
        ");
        }
        ?>
        <div class="space-y-3">
            <div class="card overflow-hidden">
                <div class="px-4 py-2 border-b flex justify-between items-center">
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted);">
                            <?php echo e(t('System Logs')); ?>
                        </h3>
                        <p class="text-[11px]" style="color: var(--text-muted);">
                            <?php echo e(t('Shows system and background process events.')); ?>
                        </p>
                    </div>
                    <form method="post"
                        onsubmit="return confirm('<?php echo e(t('Are you sure you want to clear all logs?')); ?>');">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="clear_logs" class="text-sm text-red-600 hover:text-red-800">
                            <?php echo get_icon('trash', 'mr-1'); ?>     <?php echo e(t('Clear all logs')); ?>
                        </button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="text-xs uppercase border-b"
                                style="background: var(--surface-secondary); color: var(--text-muted);">
                                <th class="px-6 py-3 font-medium"><?php echo e(t('Time')); ?></th>
                                <th class="px-6 py-3 font-medium"><?php echo e(t('Level')); ?></th>
                                <th class="px-6 py-3 font-medium"><?php echo e(t('Channel')); ?></th>
                                <th class="px-6 py-3 font-medium"><?php echo e(t('User')); ?></th>
                                <th class="px-6 py-3 font-medium"><?php echo e(t('Message')); ?></th>
                                <th class="px-6 py-3 font-medium w-10"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php if (!$debug_log_available): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center" style="color: var(--text-muted);">
                                        <?php echo e(t('Debug log table is not available in this installation yet.')); ?>
                                    </td>
                                </tr>
                            <?php elseif (empty($logs)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center" style="color: var(--text-muted);">
                                        <?php echo e(t('No logs found.')); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr class="tr-hover text-sm">
                                        <td class="px-6 py-3 whitespace-nowrap" style="color: var(--text-muted);">
                                            <?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-3">
                                            <?php
                                            $badge_color = 'bg-gray-100 text-gray-800';
                                            switch ($log['level']) {
                                                case 'error':
                                                    $badge_color = 'bg-red-100 text-red-800';
                                                    break;
                                                case 'warning':
                                                    $badge_color = 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'info':
                                                    $badge_color = 'bg-blue-100 text-blue-800';
                                                    break;
                                                case 'debug':
                                                    $badge_color = 'bg-purple-100 text-purple-800';
                                                    break;
                                            }
                                            ?>
                                            <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $badge_color; ?>">
                                                <?php echo strtoupper($log['level']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-3" style="color: var(--text-secondary);">
                                            <?php echo e($log['channel']); ?>
                                        </td>
                                        <td class="px-6 py-3" style="color: var(--text-secondary);">
                                            <?php if ($log['user_id']): ?>
                                                <span title="<?php echo e($log['email']); ?>">
                                                    <?php echo e(trim((string) (($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')))); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-3 max-w-md truncate" style="color: var(--text-primary);"
                                            title="<?php echo e($log['message']); ?>">
                                            <?php echo e($log['message']); ?>
                                        </td>
                                        <td class="px-6 py-3 text-right">
                                            <?php if (!empty($log['context']) && $log['context'] !== '[]'): ?>
                                                <button onclick="showLogContext(this)" data-context="<?php echo e($log['context']); ?>"
                                                    class="text-blue-600 hover:text-blue-800">
                                                    <?php echo get_icon('eye'); ?>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($debug_log_available && $total_pages > 1): ?>
                    <div class="px-6 py-3 border-t flex justify-between items-center"
                        style="background: var(--surface-secondary);">
                        <div class="text-xs" style="color: var(--text-muted);">
                            <?php echo t('Showing {start} to {end} of {total} entries', [
                                'start' => $offset + 1,
                                'end' => min($offset + $per_page, $total_logs),
                                'total' => $total_logs
                            ]); ?>
                        </div>
                        <div class="flex space-x-1">
                            <?php if ($page_num > 1): ?>
                                <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => 'logs', 'p' => $page_num - 1]); ?>"
                                    class="px-3 py-1 border rounded text-sm" style="background: var(--bg-primary);">
                                    &laquo; <?php echo e(t('Prev')); ?>
                                </a>
                            <?php endif; ?>

                            <?php if ($page_num < $total_pages): ?>
                                <a href="<?php echo url('admin', ['section' => 'settings', 'tab' => 'logs', 'p' => $page_num + 1]); ?>"
                                    class="px-3 py-1 border rounded text-sm" style="background: var(--bg-primary);">
                                    <?php echo e(t('Next')); ?> &raquo;
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($security_log_available): ?>
                <div class="card overflow-hidden">
                    <div class="px-6 py-3 border-b flex justify-between items-center">
                        <div>
                            <h3 class="font-semibold" style="color: var(--text-primary);">
                                <?php echo e(t('Security Audit Log')); ?>
                            </h3>
                            <p class="text-xs mt-1" style="color: var(--text-muted);">
                                <?php echo e(t('Tracks who did what in sensitive operations.')); ?>
                            </p>
                        </div>
                        <form method="post" onsubmit="return confirm('<?php echo e(t('Clear security logs?')); ?>');">
                            <?php echo csrf_field(); ?>
                            <button type="submit" name="clear_security_logs" class="text-sm text-red-600 hover:text-red-800">
                                <?php echo get_icon('trash', 'mr-1'); ?>         <?php echo e(t('Clear security logs')); ?>
                            </button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="text-xs uppercase border-b"
                                    style="background: var(--surface-secondary); color: var(--text-muted);">
                                    <th class="px-6 py-3 font-medium"><?php echo e(t('Time')); ?></th>
                                    <th class="px-6 py-3 font-medium"><?php echo e(t('Event')); ?></th>
                                    <th class="px-6 py-3 font-medium"><?php echo e(t('User')); ?></th>
                                    <th class="px-6 py-3 font-medium"><?php echo e(t('IP Address')); ?></th>
                                    <th class="px-6 py-3 font-medium w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php if (empty($security_logs)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-8 text-center" style="color: var(--text-muted);">
                                            <?php echo e(t('No security log entries yet.')); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($security_logs as $security_log): ?>
                                        <tr class="tr-hover text-sm">
                                            <td class="px-6 py-3 whitespace-nowrap" style="color: var(--text-muted);">
                                                <?php echo date('Y-m-d H:i:s', strtotime($security_log['created_at'])); ?>
                                            </td>
                                            <td class="px-6 py-3" style="color: var(--text-secondary);">
                                                <?php echo e($security_log['event_type']); ?>
                                            </td>
                                            <td class="px-6 py-3" style="color: var(--text-secondary);">
                                                <?php if (!empty($security_log['user_id'])): ?>
                                                    <?php
                                                    $security_user_name = trim((string) (($security_log['first_name'] ?? '') . ' ' . ($security_log['last_name'] ?? '')));
                                                    if ($security_user_name === '') {
                                                        $security_user_name = (string) ($security_log['email'] ?? ('#' . $security_log['user_id']));
                                                    }
                                                    ?>
                                                    <span
                                                        title="<?php echo e($security_log['email']); ?>"><?php echo e($security_user_name); ?></span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-3" style="color: var(--text-secondary);">
                                                <?php echo e($security_log['ip_address'] ?? '-'); ?>
                                            </td>
                                            <td class="px-6 py-3 text-right">
                                                <?php if (!empty($security_log['context'])): ?>
                                                    <button onclick="showLogContext(this)"
                                                        data-context="<?php echo e($security_log['context']); ?>"
                                                        class="text-blue-600 hover:text-blue-800">
                                                        <?php echo get_icon('eye'); ?>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="card card-body">
                    <h3 class="font-semibold mb-2" style="color: var(--text-primary);"><?php echo e(t('Security Audit Log')); ?>
                    </h3>
                    <p class="text-sm" style="color: var(--text-muted);">
                        <?php echo e(t('Security log table is not available in this installation yet.')); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($tab === 'workflow'): ?>
        <!-- Workflow Tab - Statuses, Priorities, Ticket Types -->
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

        <div class="workflow-grid">
            <!-- Statuses Card -->
            <div class="workflow-card" style="background: var(--surface-primary);">
                <div class="workflow-card-header" style="background: rgba(59, 130, 246, 0.05); border-bottom: 1px solid var(--border-light);">
                    <span style="color: #3b82f6; font-weight: 600;">
                        <?php echo get_icon('check-circle', 'w-4 h-4 inline mr-2'); ?>
                        <?php echo e(t('Statuses')); ?>
                    </span>
                    <p class="text-xs" style="color: var(--text-muted); margin-top: 0.25rem;">
                        <?php echo e(t('Manage ticket statuses')); ?>
                    </p>
                </div>
                <div class="workflow-card-body">
                    <?php include BASE_PATH . '/pages/admin/statuses-content.php'; ?>
                </div>
            </div>

            <!-- Priorities Card -->
            <div class="workflow-card" style="background: var(--surface-primary);">
                <div class="workflow-card-header" style="background: rgba(245, 158, 11, 0.05); border-bottom: 1px solid var(--border-light);">
                    <span style="color: #f59e0b; font-weight: 600;">
                        <?php echo get_icon('arrow-up', 'w-4 h-4 inline mr-2'); ?>
                        <?php echo e(t('Priorities')); ?>
                    </span>
                    <p class="text-xs" style="color: var(--text-muted); margin-top: 0.25rem;">
                        <?php echo e(t('Manage ticket priorities')); ?>
                    </p>
                </div>
                <div class="workflow-card-body">
                    <?php include BASE_PATH . '/pages/admin/priorities-content.php'; ?>
                </div>
            </div>

            <!-- Ticket Types Card -->
            <div class="workflow-card" style="background: var(--surface-primary);">
                <div class="workflow-card-header" style="background: rgba(139, 92, 246, 0.05); border-bottom: 1px solid var(--border-light);">
                    <span style="color: #8b5cf6; font-weight: 600;">
                        <?php echo get_icon('file-alt', 'w-4 h-4 inline mr-2'); ?>
                        <?php echo e(t('Ticket Types')); ?>
                    </span>
                    <p class="text-xs" style="color: var(--text-muted); margin-top: 0.25rem;">
                        <?php echo e(t('Manage ticket types')); ?>
                    </p>
                </div>
                <div class="workflow-card-body">
                    <?php include BASE_PATH . '/pages/admin/ticket-types-content.php'; ?>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php if ($tab === 'email'): ?>
    <script>
        function addAllowedSender() {
            const type = document.getElementById('as-type').value;
            const value = document.getElementById('as-value').value.trim();
            const userId = document.getElementById('as-user').value;

            if (!value) return;

            fetch('index.php?page=api&action=allowed-senders-add', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken},
                body: JSON.stringify({type, value, user_id: userId || null})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success === false) {
                    alert(data.error || 'Error');
                    return;
                }
                location.reload();
            })
            .catch(() => alert('Error'));
        }

        function deleteAllowedSender(id) {
            if (!confirm('<?php echo e(t('Are you sure?')); ?>')) return;

            fetch('index.php?page=api&action=allowed-senders-delete', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken},
                body: JSON.stringify({id})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success === false) {
                    alert(data.error || 'Error');
                    return;
                }
                const row = document.getElementById('as-row-' + id);
                if (row) row.remove();
            })
            .catch(() => alert('Error'));
        }

        function toggleAllowedSender(id) {
            fetch('index.php?page=api&action=allowed-senders-toggle', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken},
                body: JSON.stringify({id})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success === false) {
                    alert(data.error || 'Error');
                    return;
                }
                location.reload();
            })
            .catch(() => alert('Error'));
        }
    </script>
<?php endif; ?>

<?php if ($tab === 'logs'): ?>
    <script>
        function showLogContext(btn) {
            try {
                var ctx = btn.getAttribute('data-context');
                var parsed = JSON.parse(ctx);
                alert(JSON.stringify(parsed, null, 2));
            } catch (e) {
                alert(btn.getAttribute('data-context'));
            }
        }
    </script>
<?php endif; ?>

<?php if ($tab === 'system'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const uploadZone = document.getElementById('update-upload-zone');
            const fileInput = document.getElementById('update-file-input');
            const fileName = document.getElementById('update-file-name');
            const uploadBtn = document.getElementById('update-upload-btn');

            if (!uploadZone || !fileInput) return;

            // Click to open file dialog
            uploadZone.addEventListener('click', function (e) {
                if (e.target !== uploadBtn && !uploadBtn.contains(e.target)) {
                    fileInput.click();
                }
            });

            // Handle file selection
            fileInput.addEventListener('change', function () {
                if (this.files.length > 0) {
                    fileName.textContent = this.files[0].name;
                    uploadBtn.disabled = false;
                } else {
                    fileName.textContent = '<?php echo e(t('No file selected')); ?>';
                    uploadBtn.disabled = true;
                }
            });

            // Drag and drop
            uploadZone.addEventListener('dragover', function (e) {
                e.preventDefault();
                uploadZone.classList.add('border-blue-400', 'bg-blue-50');
            });

            uploadZone.addEventListener('dragleave', function (e) {
                e.preventDefault();
                uploadZone.classList.remove('border-blue-400', 'bg-blue-50');
            });

            uploadZone.addEventListener('drop', function (e) {
                e.preventDefault();
                uploadZone.classList.remove('border-blue-400', 'bg-blue-50');

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const file = files[0];
                    if (file.name.endsWith('.zip')) {
                        fileInput.files = files;
                        fileName.textContent = file.name;
                        uploadBtn.disabled = false;
                    } else {
                        if (typeof window.showAppToast === 'function') {
                            window.showAppToast('<?php echo e(t('Please select a .zip file')); ?>', 'error');
                        } else {
                            alert('<?php echo e(t('Please select a .zip file')); ?>');
                        }
                    }
                }
            });
        });
    </script>
<?php endif; ?>

<?php if ($tab === 'general'): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const uploadZone = document.getElementById('favicon-upload-zone');
            const fileInput = document.getElementById('favicon-file-input');
            const fileName = document.getElementById('favicon-file-name');
            const uploadBtn = document.getElementById('favicon-upload-btn');

            if (!uploadZone || !fileInput) return;

            uploadZone.addEventListener('click', function (e) {
                if (e.target !== uploadBtn && !uploadBtn.contains(e.target)) {
                    fileInput.click();
                }
            });

            fileInput.addEventListener('change', function () {
                if (this.files.length > 0) {
                    fileName.textContent = this.files[0].name;
                    uploadBtn.disabled = false;
                } else {
                    fileName.textContent = '<?php echo e(t('No file selected')); ?>';
                    uploadBtn.disabled = true;
                }
            });

            uploadZone.addEventListener('dragover', function (e) {
                e.preventDefault();
                uploadZone.classList.add('border-blue-400', 'bg-blue-50');
            });

            uploadZone.addEventListener('dragleave', function (e) {
                e.preventDefault();
                uploadZone.classList.remove('border-blue-400', 'bg-blue-50');
            });

            uploadZone.addEventListener('drop', function (e) {
                e.preventDefault();
                uploadZone.classList.remove('border-blue-400', 'bg-blue-50');

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const file = files[0];
                    const validTypes = ['.ico', '.png', '.gif'];
                    const ext = file.name.toLowerCase().slice(file.name.lastIndexOf('.'));
                    if (validTypes.includes(ext)) {
                        fileInput.files = files;
                        fileName.textContent = file.name;
                        uploadBtn.disabled = false;
                    } else {
                        alert('<?php echo e(t('Please select an ICO, PNG, or GIF file')); ?>');
                    }
                }
            });

            // App logo upload zone
            const logoZone = document.getElementById('app-logo-upload-zone');
            const logoInput = document.getElementById('app-logo-file-input');
            const logoFileName = document.getElementById('app-logo-file-name');
            const logoBtn = document.getElementById('app-logo-upload-btn');

            if (logoZone && logoInput) {
                logoZone.addEventListener('click', function (e) {
                    if (e.target !== logoBtn && !logoBtn.contains(e.target)) {
                        logoInput.click();
                    }
                });

                logoInput.addEventListener('change', function () {
                    if (this.files.length > 0) {
                        logoFileName.textContent = this.files[0].name;
                        logoBtn.disabled = false;
                    } else {
                        logoFileName.textContent = '<?php echo e(t('No file selected')); ?>';
                        logoBtn.disabled = true;
                    }
                });

                logoZone.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    logoZone.classList.add('border-blue-400');
                });

                logoZone.addEventListener('dragleave', function (e) {
                    e.preventDefault();
                    logoZone.classList.remove('border-blue-400');
                });

                logoZone.addEventListener('drop', function (e) {
                    e.preventDefault();
                    logoZone.classList.remove('border-blue-400');
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        const file = files[0];
                        const validTypes = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg'];
                        const ext = file.name.toLowerCase().slice(file.name.lastIndexOf('.'));
                        if (validTypes.includes(ext)) {
                            logoInput.files = files;
                            logoFileName.textContent = file.name;
                            logoBtn.disabled = false;
                        } else {
                            alert('<?php echo e(t('Please select a JPG, PNG, GIF, WebP, or SVG file')); ?>');
                        }
                    }
                });
            }
        });
    </script>
<?php endif; ?>

<?php require_once BASE_PATH . '/includes/footer.php';
